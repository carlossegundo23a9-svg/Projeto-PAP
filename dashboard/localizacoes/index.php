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
$q = trim((string) ($_GET['q'] ?? ''));
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$locais = [];
$dbError = null;

try {
    $locais = $repo->listar($q);
} catch (Throwable $e) {
    $dbError = "N\u{00E3}o foi poss\u{00ED}vel carregar as localiza\u{00E7}\u{00F5}es.";
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ESTEL SGP - Localiza&ccedil;&otilde;es</title>
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../../css/utilizadores.css?v=20260318b">
</head>
<body id="page-localizacoes">
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
    <h1 class="page-title">Localiza&ccedil;&otilde;es</h1>

    <?php if (is_array($flash) && isset($flash['type'], $flash['message'])): ?>
      <div class="msg <?php echo $flash['type'] === 'sucesso' ? 'msg-success' : 'msg-error'; ?>" data-toast="1" data-toast-only="1">
        <?php echo h((string) $flash['message']); ?>
      </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
      <div class="msg msg-error"><?php echo h($dbError); ?></div>
    <?php endif; ?>

    <div class="toolbar toolbar-wrap" id="toolbar-localizacoes">
      <form method="get" id="form-pesquisa-local" class="inline-create" action="index.php">
        <input type="text" name="q" id="q" placeholder="Pesquisar por ID, nome ou descri&ccedil;&atilde;o" value="<?php echo h($q); ?>">
        <button class="btn btn-secondary" type="submit">Pesquisar</button>
        <a class="btn btn-secondary" href="index.php">Limpar</a>
      </form>

      <div class="detail-actions">
        <a class="btn btn-primary" href="create.php">Adicionar Local</a>
      </div>
    </div>

    <section class="panel" id="painel-localizacoes">
      <table class="table" id="tabela-localizacoes">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nome</th>
            <th>Descri&ccedil;&atilde;o</th>
            <th>Itens</th>
            <th>A&ccedil;&otilde;es</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$dbError && !$locais): ?>
            <tr>
              <td colspan="5" class="empty">Sem localiza&ccedil;&otilde;es para mostrar.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($locais as $local): ?>
            <?php
              $id = (int) $local['id'];
              $nome = (string) $local['nome'];
              $descricao = (string) ($local['descricao'] ?? '');
              $maquinasCount = (int) ($local['maquinas_count'] ?? 0);
              $perifericosCount = (int) ($local['perifericos_count'] ?? 0);
              $extrasCount = (int) ($local['extras_count'] ?? 0);
              $totalItensCount = (int) ($local['total_itens_count'] ?? 0);
              $verItensUrl = '../bens/index.php?local_id=' . $id;
            ?>
            <tr>
              <td><?php echo $id; ?></td>
              <td><?php echo h($nome); ?></td>
              <td>
                <?php if ($descricao !== ''): ?>
                  <?php echo h($descricao); ?>
                <?php else: ?>
                  <span class="table-note">Sem descri&ccedil;&atilde;o</span>
                <?php endif; ?>
              </td>
              <td>
                <strong>Total: <?php echo $totalItensCount; ?></strong>
                <div class="table-note">
                  M&aacute;quinas: <?php echo $maquinasCount; ?> |
                  Perif&eacute;ricos: <?php echo $perifericosCount; ?> |
                  Extras: <?php echo $extrasCount; ?>
                </div>
              </td>
              <td>
                <a class="btn btn-secondary btn-small" href="<?php echo h($verItensUrl); ?>">Ver itens</a>
                <a class="btn btn-secondary btn-small" href="edit.php?id=<?php echo $id; ?>">Editar</a>
                <form method="post" action="delete.php" class="inline-form inline-form-inline" onsubmit="return confirm('Apagar esta localiza\u00E7\u00E3o?');">
                  <?php echo app_csrf_field(); ?>
                  <input type="hidden" name="id" value="<?php echo $id; ?>">
                  <button class="btn btn-danger btn-small" type="submit">Apagar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
