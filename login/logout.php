<?php
require_once "../includes/session_security.php";
app_session_start();
require_once "../includes/pdo.php";
require_once "../includes/activity_log.php";

$logoutUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$logoutNome = trim((string) ($_SESSION['nome'] ?? ''));

if (($logoutUserId !== null && $logoutUserId > 0) || $logoutNome !== '') {
    app_log_registar($pdo, 'Logout', [
        'nome' => $logoutNome,
    ], $logoutUserId !== null && $logoutUserId > 0 ? $logoutUserId : null);
}

/* Limpa todas as variáveis de sessão */
$_SESSION = [];

/* Destrói a sessão */
session_destroy();

/* Redireciona para o login */
header("Location: ../login/index.php");
exit();
