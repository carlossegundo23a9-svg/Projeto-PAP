<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/formadores/index.php'));

$nome = trim((string) ($_POST['nome'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if ($nome === '' || $email === '') {
    util_set_flash('erro', 'Preencha nome e email do formador.');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    util_set_flash('erro', 'Email inválido.');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}

$stmtCheck = $pdo->prepare("SELECT id FROM formador WHERE email = :email LIMIT 1");
$stmtCheck->execute(['email' => $email]);
if ($stmtCheck->fetch()) {
    util_set_flash('erro', 'Ja existe um formador com este email.');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}

$stmtInsert = $pdo->prepare("
    INSERT INTO formador (nome, email, ativo)
    VALUES (:nome, :email, 1)
");
$stmtInsert->execute([
    'nome' => $nome,
    'email' => $email,
]);
$novoFormadorId = (int) $pdo->lastInsertId();

app_log_registar($pdo, 'Utilizador criado', [
    'tipo_utilizador' => 'formador',
    'utilizador_id' => $novoFormadorId,
    'nome' => $nome,
    'email' => $email,
]);

util_set_flash('sucesso', 'Formador adicionado com sucesso.');
util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));




