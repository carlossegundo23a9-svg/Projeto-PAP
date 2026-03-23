<?php
declare(strict_types=1);

require_once __DIR__ . '/../utilizadores/shared/common.php';
require_once __DIR__ . '/../../includes/emprestimo_notifier.php';

util_require_section_access($pdo, 'emprestimos');

/**
 * @param array{type:string,message:string} $flash
 */
function atraso_set_flash(array $flash): void
{
    $_SESSION['flash_notificacao_atraso'] = $flash;
}

function atraso_redirect_dashboard(): void
{
    util_redirect(util_url('dashboard/dashboard.php'));
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    atraso_set_flash([
        'type' => 'error',
        'message' => 'Pedido inválido para notificação.',
    ]);
    atraso_redirect_dashboard();
}

$csrfPost = (string) ($_POST['csrf_token'] ?? '');
$csrfSession = (string) ($_SESSION['csrf_notificar_atraso'] ?? '');
if ($csrfPost === '' || $csrfSession === '' || !hash_equals($csrfSession, $csrfPost)) {
    atraso_set_flash([
        'type' => 'error',
        'message' => 'Token inválido. Atualize a página e tente novamente.',
    ]);
    atraso_redirect_dashboard();
}

$emprestimoId = filter_var($_POST['emprestimo_id'] ?? null, FILTER_VALIDATE_INT);
if ($emprestimoId === false || $emprestimoId <= 0) {
    atraso_set_flash([
        'type' => 'error',
        'message' => 'Empréstimo inválido para notificação.',
    ]);
    atraso_redirect_dashboard();
}

try {
    $erroEnvio = null;
    $ok = app_notifier_enviar_email_atraso_emprestimo($pdo, (int) $emprestimoId, $erroEnvio);
    if ($ok) {
        atraso_set_flash([
            'type' => 'success',
            'message' => 'Notificação de atraso enviada com sucesso.',
        ]);
        atraso_redirect_dashboard();
    }

    $mensagemErro = 'Não foi possível enviar a notificação de atraso.';
    if ($erroEnvio !== null && trim($erroEnvio) !== '') {
        $mensagemErro .= ' ' . trim($erroEnvio);
    }

    atraso_set_flash([
        'type' => 'error',
        'message' => $mensagemErro,
    ]);
    atraso_redirect_dashboard();
} catch (Throwable $e) {
    atraso_set_flash([
        'type' => 'error',
        'message' => trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'Erro ao enviar notificação de atraso.',
    ]);
    atraso_redirect_dashboard();
}
