<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/turmas/index.php'));

$turmaId = (int) ($_POST['turma_id'] ?? 0);
$alunoId = (int) ($_POST['aluno_id'] ?? 0);

if ($turmaId <= 0 || $alunoId <= 0) {
    util_set_flash('erro', 'Dados inválidos para remover aluno.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

$stmtTurma = $pdo->prepare("SELECT id, ativa FROM turma WHERE id = :id LIMIT 1");
$stmtTurma->execute(['id' => $turmaId]);
$turma = $stmtTurma->fetch();

if (!$turma) {
    util_set_flash('erro', 'Turma não encontrada.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

if ((int) $turma['ativa'] !== 1) {
    util_set_flash('erro', 'Não pode remover alunos de turma arquivada.');
    util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));
}

$stmtDelete = $pdo->prepare("DELETE FROM turma_aluno WHERE turma_id = :turma_id AND aluno_id = :aluno_id");
$stmtDelete->execute([
    'turma_id' => $turmaId,
    'aluno_id' => $alunoId,
]);

if ($stmtDelete->rowCount() > 0) {
    util_set_flash('sucesso', 'Aluno removido da turma com sucesso.');
} else {
    util_set_flash('erro', 'Ligação aluno/turma não encontrada.');
}

util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));





