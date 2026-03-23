<?php
require_once __DIR__ . "/../shared/common.php";

util_require_superadmin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/admins/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/admins/create.php'));

$nome = trim((string) ($_POST['nome'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$nivel = (string) ($_POST['nivel'] ?? 'admin');
$nivel = $nivel === 'superadmin' ? 'superadmin' : 'admin';

if ($nome === '' || $email === '' || $password === '') {
    util_set_flash('erro', 'Preencha todos os campos obrigatórios.');
    util_redirect(util_url('dashboard/utilizadores/admins/create.php'));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    util_set_flash('erro', 'Email inválido.');
    util_redirect(util_url('dashboard/utilizadores/admins/create.php'));
}

$stmtCheckNome = $pdo->prepare("SELECT id FROM user WHERE nome = :nome LIMIT 1");
$stmtCheckNome->execute(['nome' => $nome]);
if ($stmtCheckNome->fetch()) {
    util_set_flash('erro', 'Já existe um utilizador com este nome.');
    util_redirect(util_url('dashboard/utilizadores/admins/create.php'));
}

$stmtCheck = $pdo->prepare("SELECT id FROM user WHERE email = :email LIMIT 1");
$stmtCheck->execute(['email' => $email]);
if ($stmtCheck->fetch()) {
    util_set_flash('erro', 'Já existe um utilizador com este e-mail.');
    util_redirect(util_url('dashboard/utilizadores/admins/create.php'));
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$stmtInsert = $pdo->prepare(
    "INSERT INTO user (nome, password, email, obs, ativo) VALUES (:nome, :password, :email, :obs, 1)"
);

try {
    $stmtInsert->execute([
        'nome' => $nome,
        'password' => $passwordHash,
        'email' => $email,
        'obs' => $nivel,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        $message = 'Já existe um utilizador com estes dados.';
        $errorInfo = $e->errorInfo;
        $constraint = isset($errorInfo[2]) ? (string) $errorInfo[2] : '';

        if (stripos($constraint, 'uq_user_nome') !== false) {
            $message = 'Já existe um utilizador com este nome.';
        } elseif (stripos($constraint, 'uq_user_email') !== false) {
            $message = 'Já existe um utilizador com este e-mail.';
        }

        util_set_flash('erro', $message);
        util_redirect(util_url('dashboard/utilizadores/admins/create.php'));
    }

    throw $e;
}
$novoUserId = (int) $pdo->lastInsertId();

app_log_registar($pdo, 'Utilizador criado', [
    'tipo_utilizador' => 'admin',
    'utilizador_id' => $novoUserId,
    'nome' => $nome,
    'email' => $email,
    'nivel' => $nivel,
]);

util_set_flash('sucesso', 'Administrador criado com sucesso.');
util_redirect(util_url('dashboard/utilizadores/admins/index.php'));


