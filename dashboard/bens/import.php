<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_security.php';
app_session_start();

if (!isset($_SESSION['id_utilizador']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_utilizador'] = (int) $_SESSION['user_id'];
}

if (!isset($_SESSION['id_utilizador'])) {
    header('Location: ../../login.php');
    exit();
}

require_once __DIR__ . '/../utilizadores/shared/common.php';
require_once __DIR__ . '/../../includes/pdo.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/dashboard_sidebar.php';
require_once __DIR__ . '/../../model/repositories/material_repository.php';
require_once __DIR__ . '/../../model/entities/material_factory.php';

util_require_section_access($pdo, 'bens');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function import_normalize_header_key(string $value): string
{
    $key = trim($value);
    if (function_exists('mb_strtolower')) {
        $key = mb_strtolower($key, 'UTF-8');
    } else {
        $key = strtolower($key);
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
    if (is_string($ascii) && $ascii !== '') {
        $key = $ascii;
    }

    $key = str_replace([' ', '-'], '_', $key);
    $key = (string) preg_replace('/[^a-z0-9_]/', '_', $key);
    $key = (string) preg_replace('/_+/', '_', $key);

    return trim($key, '_');
}

function header_map_from_row(array $row): array
{
    $map = [];
    foreach ($row as $col => $value) {
        $key = import_normalize_header_key((string) $value);
        if ($key !== '') {
            $map[$key] = (string) $col;
        }
    }

    return $map;
}

function import_get_value(array $headerMap, array $source, array $keys): string
{
    foreach ($keys as $key) {
        $normalizedKey = import_normalize_header_key((string) $key);
        if ($normalizedKey === '' || !isset($headerMap[$normalizedKey])) {
            continue;
        }

        $col = $headerMap[$normalizedKey];

        return trim((string) ($source[$col] ?? ''));
    }

    return '';
}

function import_required_header_aliases(): array
{
    return [
        ['nome_do_item', 'nome', 'item'],
        ['categoria', 'tipo'],
        ['local'],
        ['codigo_inventario', 'codigo_de_inventario', 'codigoinventario', 'cod_inventario'],
    ];
}

function import_mode_from_header(array $headerMap): ?string
{
    foreach (import_required_header_aliases() as $aliases) {
        $found = false;
        foreach ($aliases as $alias) {
            $normalized = import_normalize_header_key($alias);
            if ($normalized !== '' && isset($headerMap[$normalized])) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }
    }

    return 'template';
}

function import_parse_date(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $direct = material_clean_date($value);
    if ($direct !== null) {
        return $direct;
    }

    $ddmmyyyy = DateTime::createFromFormat('d-m-Y', $value);
    if ($ddmmyyyy && $ddmmyyyy->format('d-m-Y') === $value) {
        return $ddmmyyyy->format('Y-m-d');
    }

    return null;
}

function import_codigo_key(string $value): string
{
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function import_codigo_inventario_exists(PDO $pdo, string $codigo, array &$cache): bool
{
    $key = import_codigo_key($codigo);
    if ($key === '') {
        return false;
    }

    if (array_key_exists($key, $cache)) {
        return (bool) $cache[$key];
    }

    $stmt = $pdo->prepare('SELECT 1 FROM material WHERE codigo_inventario = :codigo LIMIT 1');
    $stmt->execute(['codigo' => trim($codigo)]);
    $exists = (bool) $stmt->fetchColumn();
    $cache[$key] = $exists;

    return $exists;
}

function import_is_codigo_duplicado_error(Throwable $e): bool
{
    $normalized = import_normalize_header_key((string) $e->getMessage());
    if ($normalized === '') {
        return false;
    }

    if (strpos($normalized, 'uq_material_codigo_inventario') !== false) {
        return true;
    }

    return strpos($normalized, 'codigo') !== false
        && strpos($normalized, 'inventario') !== false
        && strpos($normalized, 'existe') !== false;
}

function import_collect_preview_rows(MaterialRepository $repo, PDO $pdo, array $rows): array
{
    $errors = [];
    $preparedRows = [];
    $linhasComDados = 0;
    $codesInFile = [];
    $codeExistsCache = [];
    $headerMap = header_map_from_row($rows[1] ?? []);

    foreach ($rows as $lineNumber => $row) {
        if ((int) $lineNumber === 1 || !is_array($row)) {
            continue;
        }
        $nome = import_get_value($headerMap, $row, ['nome_do_item', 'nome', 'item']);
        $tipoRaw = import_get_value($headerMap, $row, ['categoria', 'tipo']);
        $localRaw = import_get_value($headerMap, $row, ['local']);

        $estadoRaw = import_get_value($headerMap, $row, ['estado']);
        $marca = import_get_value($headerMap, $row, ['marca']);
        $modelo = import_get_value($headerMap, $row, ['modelo']);
        $codigoInventario = import_get_value(
            $headerMap,
            $row,
            ['codigo_inventario', 'codigo_de_inventario', 'codigoinventario', 'cod_inventario']
        );
        $dataCompraRaw = import_get_value($headerMap, $row, ['data_de_compra', 'data_compra']);
        $dataBrokenRaw = import_get_value($headerMap, $row, ['data_broken']);
        $mac = import_get_value($headerMap, $row, ['mac']);
        $sn = import_get_value($headerMap, $row, ['numero_de_serie', 'numero_serie', 'num_serie', 'sn']);
        $desc = import_get_value($headerMap, $row, ['desc']);
        if ($desc === '') {
            $desc = import_get_value($headerMap, $row, ['descricao']);
        }
        $disponibilidadeRaw = import_get_value($headerMap, $row, ['disponibilidade']);
        if ($disponibilidadeRaw === '') {
            $disponibilidadeRaw = import_get_value($headerMap, $row, ['disponivel']);
        }

        if (
            $nome === ''
            && $tipoRaw === ''
            && $localRaw === ''
            && $marca === ''
            && $modelo === ''
            && $estadoRaw === ''
            && $codigoInventario === ''
            && $mac === ''
            && $sn === ''
            && $desc === ''
        ) {
            continue;
        }

        $linhasComDados++;
        $tipo = material_normalize_tipo($tipoRaw);
        if ($tipo === null) {
            $errors[] = "Linha {$lineNumber}: tipo/categoria inválido(a).";
            continue;
        }

        if ($nome === '') {
            $errors[] = "Linha {$lineNumber}: nome do item obrigatorio.";
            continue;
        }

        if ($codigoInventario === '') {
            $errors[] = "Linha {$lineNumber}: código de inventário obrigatório.";
            continue;
        }

        $codigoKey = import_codigo_key($codigoInventario);
        if (isset($codesInFile[$codigoKey])) {
            $linhaOriginal = (int) $codesInFile[$codigoKey];
            $errors[] = "Linha {$lineNumber}: código de inventário duplicado no ficheiro (já usado na linha {$linhaOriginal}).";
            continue;
        }

        if (import_codigo_inventario_exists($pdo, $codigoInventario, $codeExistsCache)) {
            $errors[] = "Linha {$lineNumber}: código de inventário já existe na base de dados.";
            continue;
        }

        $localId = $repo->resolverLocalId($localRaw);
        if ($localId === null) {
            $errors[] = "Linha {$lineNumber}: local não encontrado ({$localRaw}).";
            continue;
        }

        $dataCompra = import_parse_date($dataCompraRaw !== '' ? $dataCompraRaw : null);
        if ($dataCompraRaw !== '' && $dataCompra === null) {
            $errors[] = "Linha {$lineNumber}: data_compra invalida (use DD-MM-YYYY).";
            continue;
        }

        $estadoNormalizado = material_normalize_estado($estadoRaw);
        $isBroken = $estadoNormalizado === 'avariado';
        $isAbate = $estadoNormalizado === 'abate';
        $dataBroken = import_parse_date($dataBrokenRaw !== '' ? $dataBrokenRaw : null);
        if ($dataBrokenRaw !== '' && $dataBroken === null) {
            $errors[] = "Linha {$lineNumber}: data_broken invalida (use DD-MM-YYYY).";
            continue;
        }
        if (!$isBroken) {
            $dataBroken = null;
        }
        if ($isBroken && $dataBroken === null) {
            $dataBroken = date('Y-m-d');
        }
        $disponivel = material_is_disponivel_from_input($disponibilidadeRaw);
        if ($isBroken || $isAbate) {
            $disponivel = false;
        }

        $codesInFile[$codigoKey] = (int) $lineNumber;
        $preparedRows[] = [
            'line' => (int) $lineNumber,
            'tipo' => $tipo,
            'nome' => $nome,
            'marca' => $marca !== '' ? $marca : null,
            'modelo' => $modelo !== '' ? $modelo : null,
            'data_compra' => $dataCompra,
            'local_id' => (int) $localId,
            'local_label' => $localRaw,
            'is_broken' => $isBroken,
            'is_abate' => $isAbate,
            'data_broken' => $dataBroken,
            'mac' => $mac !== '' ? $mac : null,
            'sn' => $sn !== '' ? $sn : null,
            'descricao' => $desc !== '' ? $desc : null,
            'disponivel' => $disponivel,
            'codigo_inventario' => $codigoInventario,
        ];
    }

    return [
        'prepared_rows' => $preparedRows,
        'errors' => $errors,
        'linhas_com_dados' => $linhasComDados,
    ];
}

function import_preview_key(): string
{
    return 'material_import_preview';
}

function import_clear_preview_session(): void
{
    unset($_SESSION[import_preview_key()]);
}

function import_create_preview_token(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return sha1(uniqid('import_preview_', true));
    }
}

function import_store_preview_session(array $rows, array $summary): string
{
    $token = import_create_preview_token();
    $_SESSION[import_preview_key()] = [
        'token' => $token,
        'rows' => $rows,
        'summary' => $summary,
        'created_at' => time(),
    ];

    return $token;
}

function import_load_preview_session(): ?array
{
    $payload = $_SESSION[import_preview_key()] ?? null;
    if (!is_array($payload)) {
        return null;
    }

    $rows = $payload['rows'] ?? null;
    $token = $payload['token'] ?? null;
    $summary = $payload['summary'] ?? null;
    if (!is_array($rows) || !is_string($token) || $token === '' || !is_array($summary)) {
        return null;
    }

    return [
        'rows' => $rows,
        'token' => $token,
        'summary' => $summary,
    ];
}

function import_date_for_preview(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $date = DateTime::createFromFormat('Y-m-d', trim($value));
    if (!$date) {
        return (string) $value;
    }

    return $date->format('d-m-Y');
}

$repo = new MaterialRepository($pdo);
$message = null;
$messageType = 'erro';
$inserted = 0;
$errors = [];
$previewRows = [];
$previewToken = null;
$previewSummary = [
    'linhas_com_dados' => 0,
    'validos' => 0,
    'erros' => 0,
];
$previewLimit = 25;

$sessionPreview = import_load_preview_session();
if ($sessionPreview !== null) {
    $previewRows = $sessionPreview['rows'];
    $previewToken = $sessionPreview['token'];
    $previewSummary = array_merge($previewSummary, $sessionPreview['summary']);
}

if (isset($_GET['template']) && (string) $_GET['template'] === '1') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $headers = [
        'Nome do Item',
        'Categoria',
        'Local',
        'Código_Inventário',
        'Marca',
        'Modelo',
        'Data de compra',
        'MAC',
        'Número de série',
    ];
    $sampleRow = [
        'Portatil HP 15',
        'Maquina',
        'Laboratorio 2',
        'INV-2026-0001',
        'HP',
        '15s-fq5000',
        '10-09-2025',
        '00:1A:2B:3C:4D:5E',
        'SNHP001122',
    ];

    $sheet->fromArray($headers, null, 'A1');
    $sheet->fromArray($sampleRow, null, 'A2');
    $sheet->freezePane('A2');
    foreach (range('A', 'I') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_import_material.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!app_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $message = 'Pedido inválido. Atualize a página e tente novamente.';
        $messageType = 'erro';
    } else {
        $action = (string) ($_POST['acao'] ?? 'preview');
        if ($action === 'confirmar_importacao') {
            $postedToken = trim((string) ($_POST['preview_token'] ?? ''));
            $storedPreview = import_load_preview_session();
            if ($storedPreview === null || $postedToken === '' || !hash_equals((string) $storedPreview['token'], $postedToken)) {
                import_clear_preview_session();
                $previewRows = [];
                $previewToken = null;
                $previewSummary = [
                    'linhas_com_dados' => 0,
                    'validos' => 0,
                    'erros' => 0,
                ];
                $message = 'Pré-visualização inválida ou expirada. Gere uma nova pré-visualização.';
                $messageType = 'erro';
            } else {
                $confirmErrors = [];
                foreach ((array) $storedPreview['rows'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    try {
                        $material = material_build_instance(
                            (string) ($row['tipo'] ?? ''),
                            null,
                            (string) ($row['nome'] ?? ''),
                            isset($row['marca']) ? (string) $row['marca'] : null,
                            isset($row['modelo']) ? (string) $row['modelo'] : null,
                            isset($row['data_compra']) ? (string) $row['data_compra'] : null,
                            (int) ($row['local_id'] ?? 0),
                            (bool) ($row['is_broken'] ?? false),
                            (bool) ($row['is_abate'] ?? false),
                            isset($row['data_broken']) ? (string) $row['data_broken'] : null,
                            isset($row['mac']) ? (string) $row['mac'] : null,
                            isset($row['sn']) ? (string) $row['sn'] : null,
                            isset($row['descricao']) ? (string) $row['descricao'] : null,
                            (bool) ($row['disponivel'] ?? true),
                            isset($row['codigo_inventario']) ? (string) $row['codigo_inventario'] : null
                        );
                        $repo->criar($material);
                        $inserted++;
                    } catch (Throwable $e) {
                        $line = (int) ($row['line'] ?? 0);
                        if (import_is_codigo_duplicado_error($e)) {
                            $confirmErrors[] = "Linha {$line}: código de inventário já existe.";
                        } else {
                            $confirmErrors[] = "Linha {$line}: erro ao inserir na base de dados.";
                        }
                    }
                }

                import_clear_preview_session();
                $previewRows = [];
                $previewToken = null;
                $previewSummary = [
                    'linhas_com_dados' => 0,
                    'validos' => 0,
                    'erros' => 0,
                ];
                $errors = $confirmErrors;

                if ($inserted > 0) {
                    $message = "Importacao concluida. Inseridos: {$inserted}.";
                    if ($confirmErrors) {
                        $message .= ' Alguns registos falharam.';
                    }
                    $messageType = 'sucesso';
                } else {
                    $message = 'Nenhum registo foi inserido.';
                    $messageType = 'erro';
                }
            }
        } else {
            import_clear_preview_session();
            $previewRows = [];
            $previewToken = null;
            $previewSummary = [
                'linhas_com_dados' => 0,
                'validos' => 0,
                'erros' => 0,
            ];

            if (!isset($_FILES['ficheiro']) || !is_array($_FILES['ficheiro'])) {
                $message = 'Envie um ficheiro Excel (.xlsx ou .xls).';
            } elseif (($_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $message = 'Nenhum ficheiro enviado.';
            } elseif (($_FILES['ficheiro']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $message = 'Erro no upload do ficheiro. Tente novamente.';
            } else {
                $name = (string) ($_FILES['ficheiro']['name'] ?? '');
                $tmp = (string) ($_FILES['ficheiro']['tmp_name'] ?? '');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if (!in_array($ext, ['xlsx', 'xls'], true)) {
                    $message = 'Formato inválido. Envie um ficheiro Excel (.xlsx ou .xls).';
                } else {
                    require_once __DIR__ . '/../../vendor/autoload.php';

                    try {
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                        $sheet = $spreadsheet->getSheet(0);
                        $rows = $sheet->toArray(null, true, true, true);

                        if (!$rows || !is_array($rows[1] ?? null)) {
                            throw new RuntimeException('Cabeçalho em falta.');
                        }

                        $headerMap = header_map_from_row($rows[1]);
                        $mode = import_mode_from_header($headerMap);

                        if ($mode === null) {
                            $message = 'Cabeçalho inválido. Use: Nome do Item, Categoria, Local, Código_Inventário.';
                        } else {
                            $previewData = import_collect_preview_rows($repo, $pdo, $rows);
                            $errors = (array) ($previewData['errors'] ?? []);
                            $previewRows = (array) ($previewData['prepared_rows'] ?? []);
                            $previewSummary = [
                                'linhas_com_dados' => (int) ($previewData['linhas_com_dados'] ?? 0),
                                'validos' => count($previewRows),
                                'erros' => count($errors),
                            ];

                            if ($previewRows === []) {
                                $message = 'Nenhuma linha valida encontrada para importar.';
                                $messageType = 'erro';
                            } else {
                                $previewToken = import_store_preview_session($previewRows, $previewSummary);
                                $message = 'Pré-visualização pronta. Revise os dados e confirme a importação.';
                                $messageType = 'sucesso';
                            }
                        }
                    } catch (Throwable $e) {
                        $message = 'Erro ao ler o ficheiro Excel ou ao validar o preview.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ESTEL SGP - Importar Itens</title>
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../../css/utilizadores.css?v=20260318b">
  <style>
    #page-importar-itens .import-guide {
      margin-bottom: 12px;
      border: 1px solid var(--line-strong);
      background:
        linear-gradient(160deg, rgba(18, 110, 130, 0.08), rgba(51, 65, 85, 0.04)),
        var(--surface);
    }

    #page-importar-itens .import-guide-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 6px;
    }

    #page-importar-itens .import-guide-head .section-title {
      margin: 0;
    }

    #page-importar-itens .import-guide-meta {
      margin: 0;
      color: var(--muted);
      font-size: 12px;
    }

    #page-importar-itens .import-sequence-wrap {
      margin: 8px 0 10px;
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 5px 6px;
      background: var(--surface);
      overflow-x: auto;
      white-space: nowrap;
    }

    #page-importar-itens .import-chip-list {
      display: flex;
      flex-wrap: nowrap;
      gap: 5px;
      margin: 0;
      padding: 0;
      list-style: none;
      width: max-content;
      align-items: center;
    }

    #page-importar-itens .import-chip {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      border-radius: 999px;
      border: 1px solid var(--line-strong);
      background: var(--surface-muted);
      padding: 3px 8px;
      font-size: 11px;
      font-weight: 600;
      color: var(--text);
      white-space: nowrap;
    }

    #page-importar-itens .import-chip-index {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 14px;
      height: 14px;
      border-radius: 999px;
      background: rgba(100, 116, 139, 0.18);
      font-size: 9px;
      font-weight: 700;
      color: var(--text);
      flex: 0 0 auto;
    }

    #page-importar-itens .import-chip-type {
      font-size: 8px;
      letter-spacing: 0.2px;
      text-transform: uppercase;
      opacity: 0.85;
      font-weight: 700;
    }

    #page-importar-itens .import-chip-req {
      border-color: rgba(239, 68, 68, 0.75);
      background: rgba(239, 68, 68, 0.16);
      box-shadow: inset 0 0 0 1px rgba(239, 68, 68, 0.35);
    }

    #page-importar-itens .import-chip-req .import-chip-index {
      background: rgba(239, 68, 68, 0.3);
      color: #ffe4e6;
    }

    #page-importar-itens .import-chip-opt {
      border-color: rgba(100, 116, 139, 0.45);
      background: rgba(148, 163, 184, 0.12);
      opacity: 0.9;
    }

    #page-importar-itens .import-sep {
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
      user-select: none;
    }

    #page-importar-itens .import-preview {
      overflow: hidden;
      border-radius: 12px;
      border: 1px solid var(--line);
      background: var(--surface);
    }

    #page-importar-itens .import-preview .table {
      width: 100%;
      font-size: 13px;
      min-width: 100%;
    }

    #page-importar-itens .import-preview .table th,
    #page-importar-itens .import-preview .table td {
      padding: 9px 10px;
      white-space: nowrap;
    }

    #page-importar-itens .import-preview-example .table tr:last-child td {
      border-bottom: 0;
      color: var(--muted);
      font-style: italic;
    }

    #page-importar-itens .import-guide-actions {
      margin-top: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    #page-importar-itens .copy-status {
      font-size: 12px;
      color: var(--muted);
      min-height: 16px;
    }

    #page-importar-itens .preview-summary {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 0 0 8px;
    }

    #page-importar-itens .preview-pill {
      border: 1px solid var(--line-strong);
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 12px;
      background: var(--surface-muted);
      color: var(--text);
      white-space: nowrap;
    }

    #page-importar-itens .preview-pill-valid {
      border-color: rgba(16, 185, 129, 0.45);
      background: rgba(16, 185, 129, 0.12);
    }

    #page-importar-itens .preview-pill-error {
      border-color: rgba(239, 68, 68, 0.45);
      background: rgba(239, 68, 68, 0.12);
    }

    #page-importar-itens #painel-preview-importar .form-actions {
      justify-content: flex-start;
      margin-top: 10px;
    }

    @media (max-width: 900px) {
      #page-importar-itens .import-guide-actions,
      #page-importar-itens .preview-summary,
      #page-importar-itens #painel-preview-importar .form-actions {
        width: 100%;
      }
    }

    @media (max-width: 760px) {
      #page-importar-itens .import-guide-head {
        flex-direction: column;
        align-items: stretch;
      }

      #page-importar-itens .import-preview {
        overflow-x: auto;
      }
    }

    @media (max-width: 700px) {
      #page-importar-itens .import-preview {
        overflow: visible;
        border: 0;
        background: transparent;
      }

      #page-importar-itens .import-preview .table {
        min-width: 0 !important;
      }

      #page-importar-itens .import-guide-actions,
      #page-importar-itens #painel-preview-importar .form-actions,
      #page-importar-itens .preview-summary {
        flex-direction: column;
        align-items: stretch;
      }

      #page-importar-itens .import-guide-actions .btn,
      #page-importar-itens #painel-preview-importar .form-actions .btn {
        width: 100%;
      }

      #page-importar-itens .preview-pill {
        text-align: center;
      }
    }
  </style>
</head>
<body id="page-importar-itens">
<header class="topbar">
  <div class="logo">ESTEL SGP</div>
  <div class="topbar-right">
    <span class="user-label">Bem-vindo(a), <strong><?php echo h($_SESSION['nome'] ?? 'Utilizador'); ?></strong></span>
    <button class="btn btn-secondary btn-small js-theme-toggle" type="button" aria-pressed="false">Modo escuro</button>
    <button class="btn btn-primary btn-small" type="button" onclick="confirmarLogout()">Sair <i class="fas fa-sign-out-alt"></i></button>
  </div>
</header>

<div class="layout">
    <aside class="sidebar">
    <?php dashboard_render_sidebar('bens'); ?>
  </aside>

  <main class="content">
    <div class="detail-head">
      <div>
        <h1 class="page-title">Importar Itens</h1>
        <p class="table-note">Use o template com cabeçalho obrigatório e colunas opcionais para reduzir erros na importação.</p>
      </div>
      <div class="detail-actions">
        <a class="btn btn-secondary" href="index.php">Voltar</a>
      </div>
    </div>

    <section class="panel import-guide" id="painel-guia-importacao">
      <div class="import-guide-head">
        <h2 class="section-title">Guia visual do ficheiro</h2>
        <button class="btn btn-secondary btn-small" type="button" id="btn-copiar-cabecalho">Copiar cabeçalho</button>
      </div>
      <p class="import-guide-meta">Primeira linha obrigatoria: os nomes devem ficar iguais ao exemplo abaixo.</p>

      <div class="import-sequence-wrap" aria-label="Sequencia de colunas">
        <ul class="import-chip-list">
          <li class="import-chip import-chip-req"><span class="import-chip-index">1</span><span class="import-chip-type">obg</span> Nome do Item</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-req"><span class="import-chip-index">2</span><span class="import-chip-type">obg</span> Categoria</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-req"><span class="import-chip-index">3</span><span class="import-chip-type">obg</span> Local</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-req"><span class="import-chip-index">4</span><span class="import-chip-type">obg</span> Código_Inventário</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-opt"><span class="import-chip-index">5</span><span class="import-chip-type">opc</span> Marca</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-opt"><span class="import-chip-index">6</span><span class="import-chip-type">opc</span> Modelo</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-opt"><span class="import-chip-index">7</span><span class="import-chip-type">opc</span> Data de compra</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-opt"><span class="import-chip-index">8</span><span class="import-chip-type">opc</span> MAC</li>
          <li class="import-sep">></li>
          <li class="import-chip import-chip-opt"><span class="import-chip-index">9</span><span class="import-chip-type">opc</span> Número de série</li>
        </ul>
      </div>

      <div class="import-preview import-preview-example" aria-label="Exemplo visual da folha">
        <table class="table" data-pagination-server="1">
          <thead>
            <tr>
              <th>Nome do Item</th>
              <th>Categoria</th>
              <th>Local</th>
              <th>Código_Inventário</th>
              <th>Marca</th>
              <th>Modelo</th>
              <th>Data de compra</th>
              <th>MAC</th>
              <th>Número de série</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Portatil HP 15</td>
              <td>Máquina</td>
              <td>Laboratorio 2</td>
              <td>INV-2026-0001</td>
              <td>HP</td>
              <td>15s-fq5000</td>
              <td>10-09-2025</td>
              <td>00:1A:2B:3C:4D:5E</td>
              <td>SNHP001122</td>
            </tr>
            <tr>
              <td colspan="9">Dica: Data de compra deve usar formato DD-MM-YYYY.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="import-guide-actions">
        <a class="btn btn-primary btn-small" href="import.php?template=1">Baixar template XLSX</a>
        <span class="copy-status" id="copy-cabecalho-status"></span>
      </div>
    </section>

    <?php if ($message): ?>
      <div class="msg <?php echo $messageType === 'sucesso' ? 'msg-success' : 'msg-error'; ?>" id="msg-importar" data-toast="1" data-toast-only="1">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <section class="panel panel-form" id="painel-importar">
      <form method="post" enctype="multipart/form-data" id="form-importar">
        <?php echo app_csrf_field(); ?>
        <input type="hidden" name="acao" value="preview">
        <div class="form-grid">
          <label for="ficheiro">
            Ficheiro Excel *
            <input type="file" name="ficheiro" id="ficheiro" accept=".xlsx,.xls" required>
          </label>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit" id="btn-importar-excel">Pre-visualizar</button>
        </div>
      </form>
    </section>

    <?php if ($previewRows): ?>
      <?php $previewRowsToRender = array_slice($previewRows, 0, $previewLimit); ?>
      <section class="panel" id="painel-preview-importar">
        <h2 class="section-title">Pré-visualização do import</h2>
        <div class="preview-summary">
          <span class="preview-pill">Linhas com dados: <?php echo (int) ($previewSummary['linhas_com_dados'] ?? 0); ?></span>
          <span class="preview-pill preview-pill-valid">Validas: <?php echo (int) ($previewSummary['validos'] ?? 0); ?></span>
          <span class="preview-pill preview-pill-error">Com erro: <?php echo (int) ($previewSummary['erros'] ?? 0); ?></span>
        </div>
        <p class="table-note">A pré-visualização não grava na base de dados. Clique em confirmar para concluir.</p>

        <div class="import-preview" aria-label="Linhas validadas para importacao">
          <table class="table" data-pagination-server="1">
            <thead>
              <tr>
                <th>Linha</th>
                <th>Nome</th>
                <th>Categoria</th>
                <th>Local</th>
                <th>Código_Inventário</th>
                <th>Data compra</th>
                <th>MAC</th>
                <th>Número de série</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($previewRowsToRender as $rowPreview): ?>
                <tr>
                  <td><?php echo (int) ($rowPreview['line'] ?? 0); ?></td>
                  <td><?php echo h((string) ($rowPreview['nome'] ?? '')); ?></td>
                  <td><?php echo h((string) ($rowPreview['tipo'] ?? '')); ?></td>
                  <td><?php echo h((string) ($rowPreview['local_label'] ?? '')); ?></td>
                  <td><?php echo h((string) ($rowPreview['codigo_inventario'] ?? '')); ?></td>
                  <td><?php echo h(import_date_for_preview(isset($rowPreview['data_compra']) ? (string) $rowPreview['data_compra'] : null)); ?></td>
                  <td><?php echo h((string) ($rowPreview['mac'] ?? '-')); ?></td>
                  <td><?php echo h((string) ($rowPreview['sn'] ?? '-')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (count($previewRows) > $previewLimit): ?>
          <p class="table-note">A mostrar primeiras <?php echo (int) $previewLimit; ?> linhas validas de <?php echo (int) count($previewRows); ?>.</p>
        <?php endif; ?>

        <form method="post" id="form-confirmar-importacao">
          <?php echo app_csrf_field(); ?>
          <input type="hidden" name="acao" value="confirmar_importacao">
          <input type="hidden" name="preview_token" value="<?php echo h($previewToken ?? ''); ?>">
          <div class="form-actions">
            <button class="btn btn-primary" type="submit" id="btn-confirmar-importacao">Confirmar importacao</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($errors): ?>
      <section class="panel" id="painel-erros">
        <h2 class="section-title">Erros</h2>
        <ul id="lista-erros">
          <?php foreach ($errors as $err): ?>
            <li><?php echo h($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </main>
</div>

<script src="../../js/theme-toggle.js?v=20260318b"></script>
<script>
function confirmarLogout() {
  if (confirm('Tem a certeza de que pretende terminar a sessão?')) {
    window.location.href = '../../login/logout.php';
  }
}

(function () {
  const copyButton = document.getElementById('btn-copiar-cabecalho');
  const status = document.getElementById('copy-cabecalho-status');
  if (!copyButton || !status) {
    return;
  }

  const headerLine = [
    'Nome do Item',
    'Categoria',
    'Local',
    'Código_Inventário',
    'Marca',
    'Modelo',
    'Data de compra',
    'MAC',
    'Número de série'
  ].join('\t');

  function setStatus(message) {
    status.textContent = message;
  }

  copyButton.addEventListener('click', function () {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(headerLine).then(function () {
        setStatus('Cabeçalho copiado. Cole no Excel na primeira linha.');
      }).catch(function () {
        setStatus('Não foi possível copiar automaticamente.');
      });
      return;
    }

    const helper = document.createElement('textarea');
    helper.value = headerLine;
    document.body.appendChild(helper);
    helper.select();
    try {
      document.execCommand('copy');
      setStatus('Cabeçalho copiado. Cole no Excel na primeira linha.');
    } catch (error) {
      setStatus('Não foi possível copiar automaticamente.');
    }
    document.body.removeChild(helper);
  });
})();
</script>
</body>
</html>
