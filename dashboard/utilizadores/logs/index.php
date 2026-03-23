<?php
declare(strict_types=1);

require_once __DIR__ . "/../shared/layout.php";
require_once __DIR__ . "/../../../vendor/autoload.php";

util_require_section_access($pdo, 'configuracoes');

/**
 * @param mixed $detalhes
 */
function logs_resumir_detalhes($detalhes): string
{
    if (!is_string($detalhes) || trim($detalhes) === '') {
        return '-';
    }

    $decoded = json_decode($detalhes, true);
    if (!is_array($decoded) || $decoded === []) {
        return trim($detalhes) !== '' ? trim($detalhes) : '-';
    }

    $partes = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } else {
            $value = (string) $value;
        }

        $partes[] = ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . $value;
    }

    $resumo = implode(' | ', $partes);
    return $resumo !== '' ? $resumo : '-';
}

/**
 * @param mixed $detalhes
 */
function logs_formatar_detalhes($detalhes): string
{
    return util_e(logs_resumir_detalhes($detalhes));
}

function logs_get_text_filter(string $key, int $max = 160): string
{
    $raw = trim((string) ($_GET[$key] ?? ''));
    if ($raw === '') {
        return '';
    }

    return mb_substr($raw, 0, $max);
}

function logs_get_date_filter(string $key): string
{
    $raw = trim((string) ($_GET[$key] ?? ''));
    if ($raw === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        return '';
    }

    return $raw;
}

/**
 * @param array<string,string> $filtros
 * @param array<string,string> $extra
 */
function logs_build_query(array $filtros, array $extra = []): string
{
    $params = [];

    foreach ($filtros as $key => $value) {
        $clean = trim($value);
        if ($clean !== '') {
            $params[$key] = $clean;
        }
    }

    foreach ($extra as $key => $value) {
        $clean = trim($value);
        if ($clean !== '') {
            $params[$key] = $clean;
        }
    }

    return http_build_query($params);
}

function logs_badge_severidade_class(string $severidade): string
{
    $key = strtolower(trim($severidade));
    if ($key === 'warning') {
        return 'badge-severity-warning';
    }
    if ($key === 'erro') {
        return 'badge-severity-erro';
    }
    if ($key === 'critico') {
        return 'badge-severity-critico';
    }

    return 'badge-severity-info';
}

function logs_badge_estado_class(string $estado): string
{
    $key = strtolower(trim($estado));
    if ($key === 'erro') {
        return 'badge-state-erro';
    }

    return 'badge-state-sucesso';
}

function logs_label_modulo(string $modulo): string
{
    $map = app_log_modulos_validos();
    $key = strtolower(trim($modulo));

    return $map[$key] ?? ucfirst($key);
}

function logs_label_severidade(string $severidade): string
{
    $map = app_log_severidades_validas();
    $key = strtolower(trim($severidade));

    return $map[$key] ?? ucfirst($key);
}

function logs_label_estado(string $estado): string
{
    $map = app_log_estados_validos();
    $key = strtolower(trim($estado));

    return $map[$key] ?? ucfirst($key);
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function logs_exportar_xlsx(array $rows): void
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('Biblioteca de exportação XLSX indisponível.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Logs');

    $headers = ['Data', 'Ação', 'Módulo', 'Severidade', 'Estado', 'Utilizador', 'IP', 'Detalhes'];
    $colunas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    foreach ($headers as $index => $header) {
        $sheet->setCellValue($colunas[$index] . '1', $header);
    }

    $linha = 2;
    foreach ($rows as $row) {
        $sheet->setCellValue('A' . $linha, (string) ($row['created_at'] ?? ''));
        $sheet->setCellValue('B' . $linha, (string) ($row['acao'] ?? ''));
        $sheet->setCellValue('C' . $linha, logs_label_modulo((string) ($row['modulo'] ?? 'sistema')));
        $sheet->setCellValue('D' . $linha, logs_label_severidade((string) ($row['severidade'] ?? 'info')));
        $sheet->setCellValue('E' . $linha, logs_label_estado((string) ($row['estado'] ?? 'sucesso')));
        $sheet->setCellValue('F' . $linha, (string) ($row['ator'] ?? 'Sistema'));
        $sheet->setCellValue('G' . $linha, (string) ($row['ip'] ?? ''));
        $sheet->setCellValue('H' . $linha, logs_resumir_detalhes($row['detalhes'] ?? null));
        $linha++;
    }

    $ultimaLinha = max(1, $linha - 1);
    $sheet->setAutoFilter('A1:H' . $ultimaLinha);
    $sheet->freezePane('A2');

    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    $sheet->getStyle('A1:H1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()
        ->setARGB('FFEAF0F6');

    foreach ($colunas as $coluna) {
        $sheet->getColumnDimension($coluna)->setAutoSize(true);
    }

    $filename = 'logs_estel_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
}

$modulosDisponiveis = app_log_modulos_validos();
$severidadesDisponiveis = app_log_severidades_validas();
$estadosDisponiveis = app_log_estados_validos();

$filtros = [
    'q' => logs_get_text_filter('q', 180),
    'utilizador' => logs_get_text_filter('utilizador', 120),
    'acao' => logs_get_text_filter('acao', 120),
    'modulo' => logs_get_text_filter('modulo', 40),
    'severidade' => logs_get_text_filter('severidade', 20),
    'estado' => logs_get_text_filter('estado', 20),
    'data_inicio' => logs_get_date_filter('data_inicio'),
    'data_fim' => logs_get_date_filter('data_fim'),
];

$filtros['modulo'] = array_key_exists($filtros['modulo'], $modulosDisponiveis) ? $filtros['modulo'] : '';
$filtros['severidade'] = array_key_exists($filtros['severidade'], $severidadesDisponiveis) ? $filtros['severidade'] : '';
$filtros['estado'] = array_key_exists($filtros['estado'], $estadosDisponiveis) ? $filtros['estado'] : '';

if (
    $filtros['data_inicio'] !== ''
    && $filtros['data_fim'] !== ''
    && $filtros['data_inicio'] > $filtros['data_fim']
) {
    $tmp = $filtros['data_inicio'];
    $filtros['data_inicio'] = $filtros['data_fim'];
    $filtros['data_fim'] = $tmp;
}

$exportarXlsx = strtolower(trim((string) ($_GET['exportar'] ?? ''))) === 'xlsx';
$erro = null;
$logs = [];
$acoesDisponiveis = [];

try {
    $acoesDisponiveis = app_log_listar_acoes_disponiveis($pdo, 150);
    if ($filtros['acao'] !== '' && !in_array($filtros['acao'], $acoesDisponiveis, true)) {
        array_unshift($acoesDisponiveis, $filtros['acao']);
    }

    if ($exportarXlsx) {
        $rows = app_log_listar_filtrado($pdo, $filtros, 5000);
        logs_exportar_xlsx($rows);
        exit;
    }

    $logs = app_log_listar_filtrado($pdo, $filtros, 500);
} catch (Throwable $e) {
    $erro = 'Não foi possível carregar os logs.';
}

$exportHrefXlsx = 'index.php?' . logs_build_query($filtros, ['exportar' => 'xlsx']);

util_render_layout_start("Logs", "configuracoes");
util_render_module_tabs("logs");
?>
<section class="panel logs-panel">
  <h2 class="section-title">Atividade Recente</h2>

  <form method="get" class="logs-filters" action="index.php">
    <div class="logs-filters-grid">
      <label>
        Pesquisa
        <input type="text" name="q" value="<?php echo util_e($filtros['q']); ?>" placeholder="Ação, detalhe, IP...">
      </label>

      <label>
        Utilizador
        <input type="text" name="utilizador" value="<?php echo util_e($filtros['utilizador']); ?>" placeholder="Nome ou email">
      </label>

      <label>
        Ação
        <select name="acao">
          <option value="">Todas</option>
          <?php foreach ($acoesDisponiveis as $acao): ?>
            <option value="<?php echo util_e($acao); ?>" <?php echo $filtros['acao'] === $acao ? 'selected' : ''; ?>>
              <?php echo util_e($acao); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Módulo
        <select name="modulo">
          <option value="">Todos</option>
          <?php foreach ($modulosDisponiveis as $key => $label): ?>
            <option value="<?php echo util_e($key); ?>" <?php echo $filtros['modulo'] === $key ? 'selected' : ''; ?>>
              <?php echo util_e($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Severidade
        <select name="severidade">
          <option value="">Todas</option>
          <?php foreach ($severidadesDisponiveis as $key => $label): ?>
            <option value="<?php echo util_e($key); ?>" <?php echo $filtros['severidade'] === $key ? 'selected' : ''; ?>>
              <?php echo util_e($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Estado
        <select name="estado">
          <option value="">Todos</option>
          <?php foreach ($estadosDisponiveis as $key => $label): ?>
            <option value="<?php echo util_e($key); ?>" <?php echo $filtros['estado'] === $key ? 'selected' : ''; ?>>
              <?php echo util_e($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Data início
        <input type="date" name="data_inicio" value="<?php echo util_e($filtros['data_inicio']); ?>">
      </label>

      <label>
        Data fim
        <input type="date" name="data_fim" value="<?php echo util_e($filtros['data_fim']); ?>">
      </label>
    </div>

    <div class="form-actions logs-filter-actions">
      <button class="btn btn-primary" type="submit">Filtrar</button>
      <a class="btn btn-secondary" href="index.php">Limpar</a>
      <a class="btn btn-secondary" href="<?php echo util_e($exportHrefXlsx); ?>">Exportar XLSX</a>
    </div>
  </form>

  <?php if ($erro !== null): ?>
    <div class="msg msg-error"><?php echo util_e($erro); ?></div>
  <?php endif; ?>

  <table class="table">
    <thead>
      <tr>
        <th>Data</th>
        <th>Ação</th>
        <th>Módulo</th>
        <th>Severidade</th>
        <th>Estado</th>
        <th>Utilizador</th>
        <th>Detalhes</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($erro === null && $logs === []): ?>
        <tr>
          <td colspan="8" class="empty">Sem registos para os filtros selecionados.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($logs as $log): ?>
        <?php
          $modulo = (string) ($log['modulo'] ?? 'sistema');
          $severidade = (string) ($log['severidade'] ?? 'info');
          $estado = (string) ($log['estado'] ?? 'sucesso');
        ?>
        <tr>
          <td><?php echo util_e((string) ($log['created_at'] ?? '')); ?></td>
          <td><?php echo util_e((string) ($log['acao'] ?? '')); ?></td>
          <td><?php echo util_e(logs_label_modulo($modulo)); ?></td>
          <td>
            <span class="badge <?php echo util_e(logs_badge_severidade_class($severidade)); ?>">
              <?php echo util_e(logs_label_severidade($severidade)); ?>
            </span>
          </td>
          <td>
            <span class="badge <?php echo util_e(logs_badge_estado_class($estado)); ?>">
              <?php echo util_e(logs_label_estado($estado)); ?>
            </span>
          </td>
          <td><?php echo util_e((string) ($log['ator'] ?? 'Sistema')); ?></td>
          <td><?php echo logs_formatar_detalhes($log['detalhes'] ?? null); ?></td>
          <td><?php echo util_e((string) ($log['ip'] ?? '-')); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php util_render_layout_end(); ?>
