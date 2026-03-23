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
util_require_section_access($pdo, 'bens');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../../includes/csrf.php';

if (!app_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Pedido inválido. Atualize a página e tente novamente.'];
    header('Location: index.php');
    exit();
}

$_SESSION['flash'] = [
    'type' => 'erro',
    'message' => 'Apagar itens foi desativado. Para retirar de uso, altere o estado para Abate.',
];

header('Location: index.php');
exit();
