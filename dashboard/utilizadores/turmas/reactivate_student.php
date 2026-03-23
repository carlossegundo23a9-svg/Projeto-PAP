<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));

$turmaId = (int) ($_POST['turma_id'] ?? 0);
$alunoId = (int) ($_POST['aluno_id'] ?? 0);

if ($turmaId <= 0 || $alunoId <= 0) {
    util_set_flash('erro', 'Dados inválidos para reativar aluno.');
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}

$stmtTurma = $pdo->prepare("SELECT id, ativa FROM turma WHERE id = :id LIMIT 1");
$stmtTurma->execute(['id' => $turmaId]);
$turma = $stmtTurma->fetch();

if (!$turma) {
    util_set_flash('erro', 'Turma não encontrada.');
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}

if ((int) ($turma['ativa'] ?? 0) === 1) {
    util_set_flash('erro', 'A turma ja esta ativa.');
    util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));
}

$stmtRemove = $pdo->prepare("
    DELETE FROM turma_aluno
    WHERE turma_id = :turma_id
      AND aluno_id = :aluno_id
");
$stmtRemove->execute([
    'turma_id' => $turmaId,
    'aluno_id' => $alunoId,
]);

if ($stmtRemove->rowCount() > 0) {
    util_set_flash('sucesso', 'Aluno reativado com sucesso.');
} else {
    util_set_flash('erro', 'Aluno não encontrado nesta turma arquivada.');
}

util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));




