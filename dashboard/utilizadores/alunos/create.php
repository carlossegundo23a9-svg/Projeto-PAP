<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/alunos/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/alunos/index.php'));

$nome = trim((string) ($_POST['nome'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if ($nome === '' || $email === '') {
    util_set_flash('erro', 'Preencha nome e email do aluno.');
    util_redirect(util_url('dashboard/utilizadores/alunos/index.php'));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    util_set_flash('erro', 'Email inválido.');
    util_redirect(util_url('dashboard/utilizadores/alunos/index.php'));
}

$stmtCheck = $pdo->prepare("SELECT id FROM cliente WHERE email = :email LIMIT 1");
$stmtCheck->execute(['email' => $email]);

if ($stmtCheck->fetch()) {
    util_set_flash('erro', 'Já existe um aluno com este e-mail.');
    util_redirect(util_url('dashboard/utilizadores/alunos/index.php'));
}

$stmtInsert = $pdo->prepare("INSERT INTO cliente (nome, email) VALUES (:nome, :email)");
$stmtInsert->execute([
    'nome' => $nome,
    'email' => $email,
]);
$novoAlunoId = (int) $pdo->lastInsertId();

app_log_registar($pdo, 'Utilizador criado', [
    'tipo_utilizador' => 'aluno',
    'utilizador_id' => $novoAlunoId,
    'nome' => $nome,
    'email' => $email,
]);

util_set_flash('sucesso', 'Aluno adicionado com sucesso.');
util_redirect(util_url('dashboard/utilizadores/alunos/index.php'));


