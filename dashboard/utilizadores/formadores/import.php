<?php
require_once __DIR__ . "/../shared/common.php";

util_require_section_access($pdo, 'utilizadores');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/formadores/index.php'));

if (!isset($_FILES['ficheiro']) || !is_array($_FILES['ficheiro'])) {
    util_set_flash('erro', 'Envie um ficheiro Excel (.xlsx ou .xls).');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}

$fileError = (int) ($_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($fileError === UPLOAD_ERR_NO_FILE) {
    util_set_flash('erro', 'Nenhum ficheiro enviado.');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}
if ($fileError !== UPLOAD_ERR_OK) {
    util_set_flash('erro', 'Erro no upload do ficheiro. Tente novamente.');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}

$fileName = (string) ($_FILES['ficheiro']['name'] ?? '');
$fileTmp = (string) ($_FILES['ficheiro']['tmp_name'] ?? '');
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'], true)) {
    util_set_flash('erro', 'Formato inválido. Envie um ficheiro Excel (.xlsx ou .xls).');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}

require_once __DIR__ . '/../../../vendor/autoload.php';

/**
 * @return array<string, string>
 */
function formador_header_map_from_row(array $row): array
{
    $map = [];
    foreach ($row as $col => $raw) {
        $key = strtolower(trim((string) $raw));
        if ($key === '') {
            continue;
        }

        $key = str_replace(['-', ' '], '_', $key);
        if ($key !== '') {
            $map[$key] = (string) $col;
        }
    }

    return $map;
}

try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmp);
    $sheet = $spreadsheet->getSheet(0);
    $rows = $sheet->toArray(null, true, true, true);

    if (!$rows || !is_array($rows[1] ?? null)) {
        util_set_flash('erro', 'Ficheiro sem dados validos para importar.');
        util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
    }

    $headerMap = formador_header_map_from_row($rows[1]);
    $colNome = $headerMap['nome'] ?? $headerMap['formador'] ?? 'A';
    $colEmail = $headerMap['email'] ?? $headerMap['e_mail'] ?? $headerMap['mail'] ?? 'B';

    $stmtFind = $pdo->prepare("SELECT id, ativo FROM formador WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmtInsert = $pdo->prepare("INSERT INTO formador (nome, email, ativo) VALUES (:nome, :email, 1)");
    $stmtReactivate = $pdo->prepare("UPDATE formador SET nome = :nome, email = :email, ativo = 1 WHERE id = :id");

    $inseridos = 0;
    $reativados = 0;
    $ignorados = 0;

    foreach ($rows as $lineNumber => $row) {
        if (!is_array($row)) {
            continue;
        }

        $nome = trim((string) ($row[$colNome] ?? ''));
        $email = strtolower(trim((string) ($row[$colEmail] ?? '')));

        if ((int) $lineNumber === 1) {
            $headerNome = strtolower($nome);
            $headerEmail = strtolower($email);
            if (
                in_array($headerNome, ['nome', 'formador'], true)
                || in_array($headerEmail, ['email', 'e-mail', 'mail'], true)
            ) {
                continue;
            }
        }

        if ($nome === '' && $email === '') {
            continue;
        }

        if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $ignorados++;
            continue;
        }

        $stmtFind->execute(['email' => $email]);
        $existing = $stmtFind->fetch();

        if ($existing) {
            $idExistente = (int) ($existing['id'] ?? 0);
            $ativoExistente = (int) ($existing['ativo'] ?? 0);

            if ($ativoExistente === 1) {
                $ignorados++;
                continue;
            }

            $stmtReactivate->execute([
                'id' => $idExistente,
                'nome' => $nome,
                'email' => $email,
            ]);
            $reativados++;
            app_log_registar($pdo, 'Utilizador criado', [
                'tipo_utilizador' => 'formador',
                'utilizador_id' => $idExistente,
                'nome' => $nome,
                'email' => $email,
                'origem' => 'import_excel',
                'acao' => 'reativado',
            ]);
            continue;
        }

        $stmtInsert->execute([
            'nome' => $nome,
            'email' => $email,
        ]);
        $novoFormadorId = (int) $pdo->lastInsertId();
        $inseridos++;
        app_log_registar($pdo, 'Utilizador criado', [
            'tipo_utilizador' => 'formador',
            'utilizador_id' => $novoFormadorId,
            'nome' => $nome,
            'email' => $email,
            'origem' => 'import_excel',
            'acao' => 'inserido',
        ]);
    }

    if ($inseridos === 0 && $reativados === 0) {
        util_set_flash('erro', 'Nenhum formador importado. Linhas ignoradas: ' . $ignorados . '.');
        util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
    }

    util_set_flash(
        'sucesso',
        'Importacao concluida. Inseridos: ' . $inseridos . '. Reativados: ' . $reativados . '. Ignorados: ' . $ignorados . '.'
    );
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
} catch (Throwable $e) {
    util_set_flash('erro', 'Erro ao ler o ficheiro Excel de formadores.');
    util_redirect(util_url('dashboard/utilizadores/formadores/index.php'));
}




