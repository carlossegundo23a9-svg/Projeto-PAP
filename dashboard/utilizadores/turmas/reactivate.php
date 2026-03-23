<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));

$turmaId = (int) ($_POST['turma_id'] ?? 0);

if ($turmaId <= 0) {
    util_set_flash('erro', 'Turma inválida.');
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}

$stmt = $pdo->prepare("UPDATE turma SET ativa = 1 WHERE id = :id");
$stmt->execute(['id' => $turmaId]);

if ($stmt->rowCount() > 0) {
    util_set_flash('sucesso', 'Turma reativada com sucesso.');
} else {
    util_set_flash('erro', 'Turma não encontrada ou já ativa.');
}

util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));





