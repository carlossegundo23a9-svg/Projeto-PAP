<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/turmas/index.php'));

$turmaId = (int) ($_POST['turma_id'] ?? 0);

if ($turmaId <= 0) {
    util_set_flash('erro', 'Turma inválida.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

$stmt = $pdo->prepare("UPDATE turma SET ativa = 0 WHERE id = :id");
$stmt->execute(['id' => $turmaId]);

if ($stmt->rowCount() > 0) {
    util_set_flash('sucesso', 'Turma arquivada com sucesso.');
} else {
    util_set_flash('erro', 'Turma não encontrada ou já arquivada.');
}

util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));





