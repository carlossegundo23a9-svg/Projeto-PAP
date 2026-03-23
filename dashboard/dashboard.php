<?php
require_once __DIR__ . "/utilizadores/shared/common.php";
require_once "../includes/dashboard_sidebar.php";
require_once "../includes/activity_log.php";

util_require_section_access($pdo, 'dashboard');

if (empty($_SESSION['csrf_notificar_atraso'])) {
    $_SESSION['csrf_notificar_atraso'] = bin2hex(random_bytes(32));
}
$csrfNotificarAtraso = (string) $_SESSION['csrf_notificar_atraso'];

$flashNotificacaoAtraso = null;
if (isset($_SESSION['flash_notificacao_atraso']) && is_array($_SESSION['flash_notificacao_atraso'])) {
    $flashNotificacaoAtraso = $_SESSION['flash_notificacao_atraso'];
}
unset($_SESSION['flash_notificacao_atraso']);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDateBr(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return '-';
    }

    return date('d/m/Y', $ts);
}

/**
 * @return array<string, mixed>
 */
function dashboardParseLogDetalhes(?string $raw): array
{
    $payload = trim((string) $raw);
    if ($payload === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $detalhes
 */
function dashboardResumoAtividade(string $acaoLabel, array $detalhes): string
{
    if ($acaoLabel === 'Empréstimo criado') {
        $tipo = trim((string) ($detalhes['tipo'] ?? ''));
        if ($tipo === 'turma') {
            $turma = trim((string) ($detalhes['turma_nome'] ?? ''));
            $quantidade = (int) ($detalhes['quantidade'] ?? 0);
            if ($turma !== '') {
                return 'Turma ' . $turma . ' - ' . $quantidade . ' item(ns)';
            }
            return $quantidade . ' item(ns) por turma';
        }

        $itemNome = trim((string) ($detalhes['item_nome'] ?? ''));
        $itemCodigo = trim((string) ($detalhes['item_codigo'] ?? ''));
        $aluno = trim((string) ($detalhes['aluno_nome'] ?? ''));
        $item = $itemNome !== '' ? $itemNome : $itemCodigo;
        if ($item !== '' && $aluno !== '') {
            return $item . ' para ' . $aluno;
        }
        return 'Registo de empréstimo';
    }

    if ($acaoLabel === 'Item recebido') {
        $itensNomes = trim((string) ($detalhes['itens_nomes'] ?? ''));
        if ($itensNomes !== '') {
            $sufixo = strpos($itensNomes, ',') !== false ? 'Recebidos' : 'Recebido';
            return $itensNomes . ' ' . $sufixo;
        }

        $emprestimoId = (int) ($detalhes['emprestimo_id'] ?? 0);
        $itens = (int) ($detalhes['itens'] ?? 0);
        if ($emprestimoId > 0) {
            return 'Empréstimo #' . $emprestimoId . ' - Itens: ' . $itens;
        }
        return 'Item devolvido';
    }

    if ($acaoLabel === 'Atraso notificado') {
        $emprestimoId = (int) ($detalhes['emprestimo_id'] ?? 0);
        $destinatario = trim((string) ($detalhes['destinatario'] ?? ''));
        if ($emprestimoId > 0 && $destinatario !== '') {
            return 'Empréstimo #' . $emprestimoId . ' - ' . $destinatario;
        }
        return 'Email de atraso enviado';
    }

    return '-';
}

$total_itens = 0;
$itensEmManutencao = 0;
$itensAbatidos = 0;
$emprestados = 0;
$pendencias = 0;
$devolucoes = [];
$atividadesRecentes = [];
$dashboardDbError = null;

try {

    $total_itens = (int) $pdo->query("SELECT COUNT(*) FROM material")->fetchColumn();

    $itensEmManutencao = (int) $pdo->query("
        SELECT COUNT(*) AS total
        FROM material
        WHERE isBroken = 1
          AND isAbate = 0
    ")->fetchColumn();

    $itensAbatidos = (int) $pdo->query("
        SELECT COUNT(*) AS total
        FROM material
        WHERE isAbate = 1
    ")->fetchColumn();

    $emprestados = (int) $pdo->query("
        SELECT COUNT(DISTINCT em.material_id) AS total
        FROM emprestimo_material em
        JOIN emprestimo e ON em.emprestimo_id = e.id
        WHERE e.data_fim IS NULL
    ")->fetchColumn();

    $pendencias = (int) $pdo->query("
        SELECT COUNT(*) AS total
        FROM emprestimo
        WHERE data_fim IS NULL
          AND prazo_entrega < CURDATE()
    ")->fetchColumn();

    $devolucoes = $pdo->query("
        SELECT
            e.id AS emprestimo_id,
            c.nome AS aluno_nome,
            c.email,
            GROUP_CONCAT(DISTINCT m.nome ORDER BY m.nome SEPARATOR ', ') AS itens,
            e.data_inicio,
            e.prazo_entrega
        FROM emprestimo e
        JOIN cliente c ON e.cliente_id = c.id
        JOIN emprestimo_material em ON em.emprestimo_id = e.id
        JOIN material m ON m.id = em.material_id
        WHERE e.data_fim IS NULL
          AND e.prazo_entrega < CURDATE()
        GROUP BY e.id, c.nome, c.email, e.data_inicio, e.prazo_entrega
        ORDER BY e.prazo_entrega ASC, e.id DESC
    ")->fetchAll();

    $logsAtividade = app_log_listar($pdo, 180);
    foreach ($logsAtividade as $log) {
        $acaoOriginal = trim((string) ($log['acao'] ?? ''));
        $acaoLower = strtolower($acaoOriginal);
        $detalhes = dashboardParseLogDetalhes(isset($log['detalhes']) ? (string) $log['detalhes'] : null);
        $acaoLabel = '';

        if (strpos($acaoLower, 'empr') !== false && strpos($acaoLower, 'criad') !== false) {
            $acaoLabel = 'Empréstimo criado';
        } elseif (
            strpos($acaoLower, 'item devolvido') !== false
            || strpos($acaoLower, 'item recebido') !== false
        ) {
            $acaoLabel = 'Item recebido';
        } elseif (strpos($acaoLower, 'notifica') !== false && (($detalhes['tipo'] ?? '') === 'email_atraso_emprestimo')) {
            $acaoLabel = 'Atraso notificado';
        }

        if ($acaoLabel === '') {
            continue;
        }

        $atividadesRecentes[] = [
            'data' => (string) ($log['created_at'] ?? ''),
            'acao' => $acaoLabel,
            'utilizador' => (string) ($log['ator'] ?? 'Sistema'),
            'resumo' => dashboardResumoAtividade($acaoLabel, $detalhes),
        ];

        if (count($atividadesRecentes) >= 4) {
            break;
        }
    }

    if ($pendencias > 0) {
        array_unshift($atividadesRecentes, [
            'data' => date('Y-m-d H:i:s'),
            'acao' => 'Atraso identificado',
            'utilizador' => 'Sistema',
            'resumo' => $pendencias . ' pendencia(s) em aberto',
        ]);
    }

    if (count($atividadesRecentes) > 4) {
        $atividadesRecentes = array_slice($atividadesRecentes, 0, 4);
    }

} catch (Throwable $e) {
    $dashboardDbError = 'Não foi possível carregar os dados do painel.';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ESTEL SGP - Painel de Controlo</title>
  <link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../css/utilizadores.css?v=20260318b">
</head>
<body>

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
    <?php dashboard_render_sidebar("dashboard"); ?>
  </aside>

  <main class="content">
    <h1 class="page-title">Painel de Controlo</h1>
    <?php if (is_array($flashNotificacaoAtraso) && isset($flashNotificacaoAtraso['message'])): ?>
      <?php $flashClass = (($flashNotificacaoAtraso['type'] ?? 'error') === 'success') ? 'msg msg-success' : 'msg msg-error'; ?>
      <div class="<?php echo h($flashClass); ?>" data-toast="1" data-toast-only="1"><?php echo h((string) $flashNotificacaoAtraso['message']); ?></div>
    <?php endif; ?>
    <?php if ($dashboardDbError !== null): ?>
      <div class="msg msg-error"><?php echo h($dashboardDbError); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
      <a class="stat-card stat-card-link" href="bens/index.php" aria-label="Abrir inventário completo">
        <div class="stat-label">Total de Itens</div>
        <div class="stat-value"><?php echo (int) $total_itens; ?></div>
      </a>

      <a class="stat-card stat-card-link" href="bens/index.php?estado=avariado" aria-label="Abrir inventário filtrado por itens avariados">
        <div class="stat-label">Itens em Manutenção</div>
        <div class="stat-value warning"><?php echo (int) $itensEmManutencao; ?></div>
      </a>

      <a class="stat-card stat-card-link" href="bens/index.php?estado=abate" aria-label="Abrir inventário filtrado por itens abatidos">
        <div class="stat-label">Itens Abatidos</div>
        <div class="stat-value danger"><?php echo (int) $itensAbatidos; ?></div>
      </a>

      <a class="stat-card stat-card-link" href="emprestimos/index.php" aria-label="Abrir índice de empréstimos">
        <div class="stat-label">Emprestados Atualmente</div>
        <div class="stat-value warning"><?php echo (int) $emprestados; ?></div>
      </a>

      <a class="stat-card stat-card-link" href="#devolucoes-pendentes" aria-label="Ir para devoluções em atraso">
        <div class="stat-label">Devoluções Em Atraso</div>
        <div class="stat-value danger"><?php echo (int) $pendencias; ?></div>
      </a>
    </div>

    <section class="panel">
      <h2 class="section-title"><i class="fas fa-clock"></i> Atividade Recente</h2>

      <table class="table">
        <thead>
          <tr>
            <th>Data</th>
            <th>Atividade</th>
            <th>Utilizador</th>
            <th>Detalhes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($atividadesRecentes !== []): ?>
            <?php foreach ($atividadesRecentes as $atividade): ?>
              <tr>
                <td><?php echo h((string) ($atividade['data'] ?? '-')); ?></td>
                <td><?php echo h((string) ($atividade['acao'] ?? '-')); ?></td>
                <td><?php echo h((string) ($atividade['utilizador'] ?? 'Sistema')); ?></td>
                <td><?php echo h((string) ($atividade['resumo'] ?? '-')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" class="empty">Sem atividade recente.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="panel" id="devolucoes-pendentes">
      <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Devoluções Pendentes</h2>

      <table class="table">
        <thead>
          <tr>
            <th>Aluno</th>
            <th>Itens</th>
            <th>Data do Empréstimo</th>
            <th>Previsão de Entrega</th>
            <th>Estado</th>
            <th>Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($devolucoes !== []): ?>
            <?php foreach ($devolucoes as $d): ?>
              <?php
                $alunoNome = trim((string) ($d['aluno_nome'] ?? ''));
                $alunoLabel = $alunoNome !== '' ? $alunoNome : '-';
                if ($alunoLabel === '') {
                    $alunoLabel = '-';
                }
              ?>
              <tr>
                <td><?php echo h($alunoLabel); ?></td>
                <td><?php echo h((string) ($d['itens'] ?? '-')); ?></td>
                <td> <?php echo formatDateBr($d['data_inicio'] ?? null); ?></td>
                <td><?php echo formatDateBr($d['prazo_entrega'] ?? null); ?></td>
                <td><span class="badge badge-admin">Atrasado</span></td>
                <td>
                  <form method="post" action="emprestimos/notificar_atraso.php" class="inline-form inline-form-inline" onsubmit="return confirm('Enviar notificação de atraso por email?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfNotificarAtraso); ?>">
                    <input type="hidden" name="emprestimo_id" value="<?php echo (int) ($d['emprestimo_id'] ?? 0); ?>">
                    <button class="btn btn-secondary" type="submit">Notificar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="empty">Sem devoluções pendentes.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

  </main>
</div>

<script src="../js/theme-toggle.js?v=20260318b"></script>
<script>
function confirmarLogout() {
  if (confirm("Tem a certeza que pretende terminar a sessão?")) {
    window.location.href = "../login/logout.php";
  }
}
</script>
</body>
</html>

