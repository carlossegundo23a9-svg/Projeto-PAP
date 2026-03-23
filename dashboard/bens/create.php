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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if (!$errors && $tipo !== null && is_int($localId)) {
        try {
            $material = material_build_instance(
                $tipo,
                null,
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

            $newId = $repo->criar($material);
            app_log_registar($pdo, 'Item adicionado ao inventário', [
                'material_id' => (int) $newId,
                'item_codigo' => 'MAT-' . str_pad((string) $newId, 4, '0', STR_PAD_LEFT),
                'nome' => (string) $values['nome'],
                'tipo' => (string) $tipo,
                'estado' => (string) $estadoNormalizado,
                'codigo_inventario' => (string) $values['codigo_inventario'],
            ]);
            $message = 'Item criado com sucesso. Código: MAT-' . str_pad((string) $newId, 4, '0', STR_PAD_LEFT);
            $messageType = 'sucesso';

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
        } catch (Throwable $e) {
            $erro = trim((string) $e->getMessage());
            if ($erro === 'Código de inventário já existe.') {
                $message = $erro;
            } else {
                $message = 'Erro ao criar item. Verifique os dados e tente novamente.';
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
  <title>ESTEL SGP - Adicionar Item</title>
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../../css/utilizadores.css?v=20260318b">
</head>
<body id="page-item-criar">
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
        <h1 class="page-title">Adicionar Item</h1>
        
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
            Código de inventário
            <input type="text" name="codigo_inventario" id="codigo_inventario" value="<?php echo h($values['codigo_inventario']); ?>">
            <div class="barcode-row">
              <button class="btn btn-secondary btn-small" type="button" id="btn-barcode-reader">
                Código de barras
              </button>
              <span class="barcode-feedback" id="barcode-feedback"></span>
            </div>
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
            MAC 
            <input type="text" name="mac" id="mac" value="<?php echo h($values['mac']); ?>">
          </label>

          <label for="sn">
            Número de Série 
            <input type="text" name="sn" id="sn" value="<?php echo h($values['sn']); ?>">
          </label>

          <label for="descricao">
            Descrição 
            <textarea name="descricao" id="descricao"><?php echo h($values['descricao']); ?></textarea>
          </label>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit" id="btn-guardar">Guardar</button>
        </div>
      </form>
    </section>
    <div class="barcode-modal" id="barcode-modal" hidden>
      <div class="barcode-modal-card" role="dialog" aria-modal="true" aria-labelledby="barcode-modal-title">
        <h2 id="barcode-modal-title">Leitura de código de barras</h2>
        <p>Use o leitor externo e confirme com Enter. O valor será aplicado no campo Código de inventário.</p>
        <form id="barcode-reader-form">
          <label for="barcode-reader-input">
            Código lido
            <input type="text" id="barcode-reader-input" autocomplete="off" spellcheck="false">
          </label>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Aplicar</button>
            <button class="btn btn-secondary" type="button" id="barcode-close-btn">Fechar</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>

<script src="../../js/theme-toggle.js?v=20260318b"></script>
<script>
function confirmarLogout() {
  if (confirm("Tem a certeza que pretende terminar a sessão?")) {
    window.location.href = "../../login/logout.php";
  }
}
(function () {
  const openBtn = document.getElementById('btn-barcode-reader');
  const closeBtn = document.getElementById('barcode-close-btn');
  const modal = document.getElementById('barcode-modal');
  const form = document.getElementById('barcode-reader-form');
  const readerInput = document.getElementById('barcode-reader-input');
  const inventoryCodeInput = document.getElementById('codigo_inventario');
  const feedback = document.getElementById('barcode-feedback');

  if (!openBtn || !closeBtn || !modal || !form || !readerInput || !inventoryCodeInput) {
    return;
  }

  function openModal() {
    modal.hidden = false;
    readerInput.value = '';
    setTimeout(() => readerInput.focus(), 0);
  }

  function closeModal() {
    modal.hidden = true;
  }

  function applyBarcode() {
    const value = readerInput.value.trim();
    if (value === '') {
      readerInput.focus();
      return;
    }

    inventoryCodeInput.value = value;
    inventoryCodeInput.dispatchEvent(new Event('input', { bubbles: true }));
    if (feedback) {
      feedback.textContent = 'Código lido: ' + value;
    }
    closeModal();
  }

  openBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);

  modal.addEventListener('click', function (event) {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    applyBarcode();
  });

  readerInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      applyBarcode();
    }
  });
})();
</script>
</body>
</html>

