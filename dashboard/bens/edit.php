<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_security.php';
app_session_start();

if (!isset($_SESSION['id_utilizador']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_utilizador'] = (int) $_SESSION['user_id'];
}

if (!isset($_SESSION['id_utilizador'])) {
    header('Location: ../../login.php');
    exit();
}

require_once __DIR__ . '/../utilizadores/shared/common.php';
require_once __DIR__ . '/../../includes/pdo.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/activity_log.php';
require_once __DIR__ . '/../../includes/dashboard_sidebar.php';
require_once __DIR__ . '/../../model/repositories/material_repository.php';
require_once __DIR__ . '/../../model/entities/material_factory.php';

util_require_section_access($pdo, 'bens');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$repo = new MaterialRepository($pdo);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$message = null;
$messageType = 'erro';

$values = [
    'tipo' => 'maquina',
    'nome' => '',
    'marca' => '',
    'modelo' => '',
    'codigo_inventario' => '',
    'data_compra' => '',
    'local_id' => '',
    'estado' => 'Em uso',
    'data_broken' => '',
    'mac' => '',
    'sn' => '',
    'descricao' => '',
];

$locais = [];
try {
    $locais = $repo->listarLocais();
} catch (Throwable $e) {
    $message = 'Não foi possível carregar os locais.';
}

$materialAtual = null;
if ($id) {
    try {
        $materialAtual = $repo->buscarPorId($id);
        if (!$materialAtual) {
            $message = 'Item não encontrado.';
        } else {
            $values['tipo'] = $materialAtual->getTipo();
            $values['nome'] = $materialAtual->getNome();
            $values['marca'] = $materialAtual->getMarca() ?? '';
            $values['modelo'] = $materialAtual->getModelo() ?? '';
            $values['codigo_inventario'] = $materialAtual->getCodigoInventario() ?? '';
            $values['data_compra'] = $materialAtual->getDataCompra() ?? '';
            $values['estado'] = $materialAtual->isAbate()
                ? 'Abate'
                : ($materialAtual->isBroken() ? 'Avariado' : 'Em uso');
            $values['data_broken'] = $materialAtual->getDataBroken() ?? '';

            if ($materialAtual instanceof Maquina) {
                $values['local_id'] = (string) ($materialAtual->getLocalId() ?? '');
                $values['mac'] = $materialAtual->getMac() ?? '';
                $values['sn'] = $materialAtual->getNumSerie() ?? '';
            } elseif ($materialAtual instanceof Periferico) {
                $values['local_id'] = (string) ($materialAtual->getLocalId() ?? '');
            } elseif ($materialAtual instanceof Extra) {
                $values['local_id'] = (string) ($materialAtual->getLocalId() ?? '');
                $values['descricao'] = $materialAtual->getDescricao() ?? '';
            }
        }
    } catch (Throwable $e) {
        $message = 'Erro ao carregar item.';
    }
} else {
    $message = 'ID inválido.';
}

if ($id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = 'Pedido inválido. Atualize a página e tente novamente.';
        $messageType = 'erro';
    } else {
    foreach ($values as $key => $v) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $errors = [];

    if ($values['nome'] === '') {
        $errors[] = 'Nome do item é obrigatório.';
    }

    $tipo = material_normalize_tipo($values['tipo']);
    if ($tipo === null) {
        $errors[] = 'Tipo inválido. Use Máquina, Periférico ou Extra.';
    }

    $localId = filter_var($values['local_id'], FILTER_VALIDATE_INT);
    if ($localId === false || $localId <= 0) {
        $errors[] = 'Selecione um local válido.';
    }

    $dataCompra = material_clean_date($values['data_compra']);
    if ($values['data_compra'] !== '' && $dataCompra === null) {
        $errors[] = 'Data de compra inválida. Formato esperado: YYYY-MM-DD.';
    }

    $estadoNormalizado = material_normalize_estado($values['estado']);
    $isBroken = $estadoNormalizado === 'avariado';
    $isAbate = $estadoNormalizado === 'abate';
    $dataBroken = material_clean_date($values['data_broken']);
    if ($values['data_broken'] !== '' && $dataBroken === null) {
        $errors[] = 'Data de avaria inválida. Formato esperado: YYYY-MM-DD.';
    }
    if (!$isBroken) {
        $dataBroken = null;
    }
    if ($isBroken && $dataBroken === null) {
        $dataBroken = date('Y-m-d');
    }

    $disponivel = !$isBroken && !$isAbate;
    $estadoAnterior = $materialAtual && method_exists($materialAtual, 'isAbate')
        ? ($materialAtual->isAbate() ? 'abate' : ($materialAtual->isBroken() ? 'avariado' : 'em_uso'))
        : '';
    $estadoNovo = $isAbate ? 'abate' : ($isBroken ? 'avariado' : 'em_uso');

    if (!$errors && $tipo !== null && is_int($localId)) {
        try {
            $material = material_build_instance(
                $tipo,
                (int) $id,
                $values['nome'],
                $values['marca'] !== '' ? $values['marca'] : null,
                $values['modelo'] !== '' ? $values['modelo'] : null,
                $dataCompra,
                $localId,
                $isBroken,
                $isAbate,
                $dataBroken,
                $values['mac'] !== '' ? $values['mac'] : null,
                $values['sn'] !== '' ? $values['sn'] : null,
                $values['descricao'] !== '' ? $values['descricao'] : null,
                $disponivel,
                $values['codigo_inventario'] !== '' ? $values['codigo_inventario'] : null
            );

            $repo->atualizar($material);
            app_log_registar($pdo, 'Item editado', [
                'material_id' => (int) $id,
                'nome' => (string) $values['nome'],
                'tipo' => (string) $tipo,
                'estado_anterior' => (string) $estadoAnterior,
                'estado_novo' => (string) $estadoNovo,
                'codigo_inventario' => (string) $values['codigo_inventario'],
            ]);
            if ($estadoNovo === 'avariado' && $estadoAnterior !== 'avariado') {
                app_log_registar($pdo, 'Item enviado para manutenção', [
                    'material_id' => (int) $id,
                    'nome' => (string) $values['nome'],
                    'estado_anterior' => (string) $estadoAnterior,
                    'estado_novo' => 'avariado',
                ]);
            }
            if ($estadoNovo === 'abate' && $estadoAnterior !== 'abate') {
                app_log_registar($pdo, 'Item removido', [
                    'material_id' => (int) $id,
                    'nome' => (string) $values['nome'],
                    'estado_anterior' => (string) $estadoAnterior,
                    'estado_novo' => 'abate',
                ]);
            }
            $message = 'Item atualizado com sucesso.';
            $messageType = 'sucesso';
        } catch (Throwable $e) {
            $erro = trim((string) $e->getMessage());
            if ($erro === 'Código de inventário já existe.') {
                $message = $erro;
            } else {
                $message = 'Erro ao atualizar item. Verifique os dados e tente novamente.';
            }
            $messageType = 'erro';
        }
    } else {
        $message = implode(' ', $errors);
        $messageType = 'erro';
    }
}

}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ESTEL SGP - Editar Item</title>
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../../css/utilizadores.css?v=20260318b">
</head>
<body id="page-item-editar">
<header class="topbar">
  <div class="logo">ESTEL SGP</div>
  <div class="topbar-right">
    <span class="user-label">Bem-vindo(a), <strong><?php echo h($_SESSION['nome'] ?? 'Utilizador'); ?></strong></span>
    <button class="btn btn-secondary btn-small js-theme-toggle" type="button" aria-pressed="false">Modo escuro</button>
    <button class="btn btn-primary btn-small" type="button" onclick="confirmarLogout()">Sair <i class="fas fa-sign-out-alt"></i></button>
  </div>
</header>

<div class="layout">
    <aside class="sidebar">
    <?php dashboard_render_sidebar("bens"); ?>
  </aside>

  <main class="content">
    <div class="detail-head">
      <div>
        <h1 class="page-title">Editar Item</h1>
        <p class="table-note">ID: <?php echo $id ? (int) $id : 0; ?></p>
      </div>
      <div class="detail-actions">
        <a class="btn btn-secondary" href="index.php">Voltar</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="msg <?php echo $messageType === 'sucesso' ? 'msg-success' : 'msg-error'; ?>" id="msg-form" data-toast="1" data-toast-only="1">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <section class="panel panel-form" id="painel-form-item">
      <form method="post" id="form-item">
        <?php echo app_csrf_field(); ?>
        <div class="form-grid">
          <label for="tipo">
            Tipo *
            <select name="tipo" id="tipo" required>
              <option value="maquina" <?php echo $values['tipo'] === 'maquina' ? 'selected' : ''; ?>>Máquina</option>
              <option value="periferico" <?php echo $values['tipo'] === 'periferico' ? 'selected' : ''; ?>>Periférico</option>
              <option value="extra" <?php echo $values['tipo'] === 'extra' ? 'selected' : ''; ?>>Extra</option>
            </select>
          </label>

          <label for="nome">
            Item (Nome) *
            <input type="text" name="nome" id="nome" value="<?php echo h($values['nome']); ?>" required>
          </label>

          <label for="local_id">
            Local *
            <select name="local_id" id="local_id" required>
              <option value="">Selecione</option>
              <?php foreach ($locais as $local): ?>
                <?php $localId = (string) $local['id']; ?>
                <option value="<?php echo h($localId); ?>" <?php echo $values['local_id'] === $localId ? 'selected' : ''; ?>>
                  <?php echo h((string) $local['nome']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label for="estado">
            Estado
            <select name="estado" id="estado">
              <option value="Em uso" <?php echo material_normalize_estado($values['estado']) === 'em_uso' ? 'selected' : ''; ?>>Em uso</option>
              <option value="Avariado" <?php echo material_normalize_estado($values['estado']) === 'avariado' ? 'selected' : ''; ?>>Avariado</option>
              <option value="Abate" <?php echo material_normalize_estado($values['estado']) === 'abate' ? 'selected' : ''; ?>>Abate</option>
            </select>
          </label>

          <label for="marca">
            Marca
            <input type="text" name="marca" id="marca" value="<?php echo h($values['marca']); ?>">
          </label>

          <label for="modelo">
            Modelo
            <input type="text" name="modelo" id="modelo" value="<?php echo h($values['modelo']); ?>">
          </label>

          <label for="codigo_inventario">
            Código de inventário (escola)
            <input type="text" name="codigo_inventario" id="codigo_inventario" value="<?php echo h($values['codigo_inventario']); ?>">
          </label>

          <label for="data_compra">
            Data de Compra
            <input type="date" name="data_compra" id="data_compra" value="<?php echo h($values['data_compra']); ?>">
          </label>

          <label for="data_broken">
            Data de Avaria
            <input type="date" name="data_broken" id="data_broken" value="<?php echo h($values['data_broken']); ?>">
          </label>

          <label for="mac">
            MAC (apenas Máquina)
            <input type="text" name="mac" id="mac" value="<?php echo h($values['mac']); ?>">
          </label>

          <label for="sn">
            Número de Série (apenas Máquina)
            <input type="text" name="sn" id="sn" value="<?php echo h($values['sn']); ?>">
          </label>

          <label for="descricao">
            Descrição (apenas Extra)
            <textarea name="descricao" id="descricao"><?php echo h($values['descricao']); ?></textarea>
          </label>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit" id="btn-guardar">Guardar Alterações</button>
        </div>
      </form>
    </section>
  </main>
</div>

<script src="../../js/theme-toggle.js?v=20260318b"></script>
<script>
function confirmarLogout() {
  if (confirm("Tem a certeza que pretende terminar a sessão?")) {
    window.location.href = "../../login/logout.php";
  }
}
</script>
</body>
</html>








