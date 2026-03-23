<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/turmas/index.php'));

$nome = trim((string) ($_POST['nome'] ?? ''));
$hasFile = isset($_FILES['ficheiro']) && $_FILES['ficheiro']['error'] !== UPLOAD_ERR_NO_FILE;

if ($nome === '') {
    util_set_flash('erro', 'Indique o nome da turma.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

if ($hasFile && $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) {
    util_set_flash('erro', 'Erro no upload do ficheiro. Tente novamente.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

if ($hasFile) {
    $ext = strtolower(pathinfo((string) $_FILES['ficheiro']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        util_set_flash('erro', 'Formato inválido. Envie um ficheiro Excel (.xlsx ou .xls).');
        util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
    }
}

$stmtCheck = $pdo->prepare("SELECT id, ativa FROM turma WHERE nome = :nome LIMIT 1");
$stmtCheck->execute(['nome' => $nome]);
$turma = $stmtCheck->fetch();

if ($turma) {
    if ((int) $turma['ativa'] === 0) {
        util_set_flash('erro', 'A turma já existe e está arquivada. Reative em Turmas Arquivadas.');
    } else {
        util_set_flash('erro', 'Já existe uma turma com este nome.');
    }
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

$stmtInsert = $pdo->prepare("INSERT INTO turma (nome, ativa) VALUES (:nome, 1)");
$stmtInsert->execute(['nome' => $nome]);
$turmaId = (int) $pdo->lastInsertId();

if (!$hasFile) {
    util_set_flash('sucesso', 'Turma criada com sucesso.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

require_once __DIR__ . '/../../../vendor/autoload.php';

$tmp = (string) $_FILES['ficheiro']['tmp_name'];

try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    $importados = 0;
    $ignorados = 0;

    $stmtAlunoSel = $pdo->prepare("SELECT id FROM cliente WHERE email = :email LIMIT 1");
    $stmtAlunoIns = $pdo->prepare("INSERT INTO cliente (nome, email) VALUES (:nome, :email)");
    $stmtTurmaAlunoIns = $pdo->prepare("INSERT IGNORE INTO turma_aluno (turma_id, aluno_id) VALUES (:turma_id, :aluno_id)");

    foreach ($rows as $i => $r) {
        $alunoNome = trim((string) ($r['A'] ?? ''));
        $email = strtolower(trim((string) ($r['B'] ?? '')));

        if ($alunoNome === '' && $email === '') {
            continue;
        }

        if ((int) $i === 1) {
            $a = strtolower($alunoNome);
            $b = strtolower($email);
            if ($a === 'nome' || $b === 'email') {
                continue;
            }
        }

        if ($alunoNome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $ignorados++;
            continue;
        }

        $stmtAlunoSel->execute(['email' => $email]);
        $aluno = $stmtAlunoSel->fetch();

        if ($aluno) {
            $alunoId = (int) $aluno['id'];
        } else {
            $stmtAlunoIns->execute([
                'nome' => $alunoNome,
                'email' => $email,
            ]);
            $alunoId = (int) $pdo->lastInsertId();
        }

        $stmtTurmaAlunoIns->execute([
            'turma_id' => $turmaId,
            'aluno_id' => $alunoId,
        ]);

        if ($stmtTurmaAlunoIns->rowCount() > 0) {
            $importados++;
        } else {
            $ignorados++;
        }
    }

    util_set_flash('sucesso', "Turma criada e importação concluída: {$importados} aluno(s) inserido(s), {$ignorados} linha(s) ignorada(s).");
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
} catch (Throwable $e) {
    util_set_flash('erro', 'Turma criada, mas houve erro ao ler o ficheiro Excel.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}





