<?php
require_once "../includes/session_security.php";
app_session_start();
require_once "../includes/pdo.php";
require_once "../includes/activity_log.php";

$tokenOk = isset($_POST['csrf_token']) && isset($_SESSION['csrf_login'])
    && hash_equals((string) $_SESSION['csrf_login'], (string) $_POST['csrf_token']);
if (!$tokenOk) {
    $_SESSION['erro_login'] = "Pedido inválido. Atualize a página e tente novamente.";
    header("Location: index.php");
    exit();
}

$nome = trim((string) ($_POST['nome'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($nome === '' || $password === '') {
    $_SESSION['erro_login'] = "Preencha nome e palavra-passe.";
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("SELECT id, nome, password, obs, ativo FROM user WHERE nome = :nome LIMIT 1");
$stmt->execute(['nome' => $nome]);
$dados = $stmt->fetch() ?: null;

if (!$dados) {
    $_SESSION['erro_login'] = "Utilizador não encontrado.";
    header("Location: index.php");
    exit();
}

if (isset($dados['ativo']) && (int) $dados['ativo'] !== 1) {
    $_SESSION['erro_login'] = "Conta desativada. Contacte o superadmin.";
    header("Location: index.php");
    exit();
}

if (!password_verify($password, $dados['password'])) {
    $_SESSION['erro_login'] = "Palavra-passe incorreta. Tente novamente.";
    header("Location: index.php");
    exit();
}

app_session_regenerate_after_login();

$_SESSION['user_id'] = (int) $dados['id'];
$_SESSION['nome'] = $dados['nome'];
$_SESSION['role'] = $dados['obs'] ?: "admin";

app_log_registar($pdo, 'Login', [
    'nome' => (string) $dados['nome'],
    'nivel' => (string) ($dados['obs'] ?? 'admin'),
], (int) $dados['id']);

$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$redirectPath = $role === 'admin'
    ? "../dashboard/emprestimo.php"
    : "../dashboard/dashboard.php";

header("Location: " . $redirectPath);
exit();

