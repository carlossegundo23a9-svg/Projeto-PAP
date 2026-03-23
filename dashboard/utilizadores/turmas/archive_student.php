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
    util_set_flash('erro', 'Dados inválidos para arquivar aluno.');
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}

$stmtTurma = $pdo->prepare("SELECT id, ativa, nome FROM turma WHERE id = :id LIMIT 1");
$stmtTurma->execute(['id' => $turmaId]);
$turma = $stmtTurma->fetch();

if (!$turma) {
    util_set_flash('erro', 'Turma não encontrada.');
    util_redirect(util_url('dashboard/utilizadores/turmas/archived.php'));
}

if ((int) ($turma['ativa'] ?? 0) === 1) {
    util_set_flash('erro', 'So pode arquivar aluno em turma arquivada.');
    util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));
}

$stmtAluno = $pdo->prepare("SELECT id FROM cliente WHERE id = :id LIMIT 1");
$stmtAluno->execute(['id' => $alunoId]);
if (!$stmtAluno->fetch()) {
    util_set_flash('erro', 'Aluno não encontrado.');
    util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));
}

$stmtAssoc = $pdo->prepare(" 
    SELECT ta.turma_id, t.nome, t.ativa
    FROM turma_aluno ta
    INNER JOIN turma t ON t.id = ta.turma_id
    WHERE ta.aluno_id = :aluno_id
    LIMIT 1
");
$stmtAssoc->execute(['aluno_id' => $alunoId]);
$assoc = $stmtAssoc->fetch();

if ($assoc) {
    $assocTurmaId = (int) ($assoc['turma_id'] ?? 0);
    if ($assocTurmaId === $turmaId) {
        util_set_flash('erro', 'Este aluno ja esta nesta turma arquivada.');
    } else {
        $nomeAssoc = trim((string) ($assoc['nome'] ?? ''));
        $estadoAssoc = ((int) ($assoc['ativa'] ?? 0) === 1) ? 'ativa' : 'arquivada';
        $msg = 'Este aluno ja esta associado a outra turma';
        if ($nomeAssoc !== '') {
            $msg .= ': ' . $nomeAssoc . ' (' . $estadoAssoc . ')';
        }
        $msg .= '. Reative/remova da turma atual antes de arquivar aqui.';
        util_set_flash('erro', $msg);
    }
    util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));
}

$stmtInsert = $pdo->prepare("INSERT INTO turma_aluno (turma_id, aluno_id) VALUES (:turma_id, :aluno_id)");
$stmtInsert->execute([
    'turma_id' => $turmaId,
    'aluno_id' => $alunoId,
]);

util_set_flash('sucesso', 'Aluno arquivado na turma com sucesso.');
util_redirect(util_url('dashboard/utilizadores/turmas/detail.php?id=' . $turmaId));
