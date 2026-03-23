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
require_once __DIR__ . '/../../includes/dashboard_sidebar.php';
require_once __DIR__ . '/../../model/repositories/local_repository.php';

util_require_section_access($pdo, 'localizacoes');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$repo = new LocalRepository($pdo);
$message = null;
$messageType = 'erro';

$values = [
    'nome' => '',
    'descricao' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = "Pedido inv\u{00E1}lido. Atualize a p\u{00E1}gina e tente novamente.";
        $messageType = 'erro';
    } else {
        foreach ($values as $key => $v) {
            $values[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        $errors = [];

        if ($values['nome'] === '') {
            $errors[] = "Nome da localiza\u{00E7}\u{00E3}o \u{00E9} obrigat\u{00F3}rio.";
        } elseif (mb_strlen($values['nome']) > 100) {
            $errors[] = "Nome da localiza\u{00E7}\u{00E3}o deve ter no m\u{00E1}ximo 100 caracteres.";
        }

        if ($errors === []) {
            try {
                if ($repo->nomeExiste($values['nome'])) {
                    $errors[] = "J\u{00E1} existe uma localiza\u{00E7}\u{00E3}o com este nome.";
                } else {
                    $id = $repo->criar($values['nome'], $values['descricao'] !== '' ? $values['descricao'] : null);
                    $_SESSION['flash'] = [
                        'type' => 'sucesso',
                        'message' => "Localiza\u{00E7}\u{00E3}o criada com sucesso (ID {$id}).",
                    ];
                    header('Location: index.php');
                    exit();
                }
            } catch (Throwable $e) {
                $errors[] = "Erro ao criar localiza\u{00E7}\u{00E3}o. Verifique os dados e tente novamente.";
            }
        }

        if ($errors !== []) {
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
  <title>ESTEL SGP - Adicionar Localiza&ccedil;&atilde;o</title>
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../../css/utilizadores.css?v=20260318b">
</head>
<body id="page-localizacao-criar">
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
    <?php dashboard_render_sidebar('localizacoes'); ?>
  </aside>

  <main class="content">
    <div class="detail-head">
      <div>
        <h1 class="page-title">Adicionar Localiza&ccedil;&atilde;o</h1>
        <p class="table-note">Cadastre os locais usados na aloca&ccedil;&atilde;o de bens.</p>
      </div>
      <div class="detail-actions">
        <a class="btn btn-secondary" href="index.php">Voltar</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="msg <?php echo $messageType === 'sucesso' ? 'msg-success' : 'msg-error'; ?>" data-toast="1" data-toast-only="1">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <section class="panel panel-form" id="painel-form-local">
      <form method="post" id="form-local">
        <?php echo app_csrf_field(); ?>
        <div class="form-grid">
          <label for="nome">
            Nome *
            <input type="text" name="nome" id="nome" maxlength="100" value="<?php echo h($values['nome']); ?>" required>
          </label>

          <label for="descricao">
            Descri&ccedil;&atilde;o
            <textarea name="descricao" id="descricao"><?php echo h($values['descricao']); ?></textarea>
          </label>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
      </form>
    </section>
  </main>
</div>

<script src="../../js/theme-toggle.js?v=20260318b"></script>
<script>
function confirmarLogout() {
  if (confirm('Tem a certeza que pretende terminar a sess\u00E3o?')) {
    window.location.href = '../../login/logout.php';
  }
}
</script>
</body>
</html>
