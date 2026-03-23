<?php
declare(strict_types=1);

require_once __DIR__ . '/../utilizadores/shared/layout.php';
require_once __DIR__ . '/../../model/repositories/relatorio_repository.php';

util_require_section_access($pdo, 'relatorios');

/**
 * @param array{
 *   title:string,
 *   columns:array<int, array{key:string,label:string}>,
 *   rows:array<int, array<string, string>>
 * } $relatorio
 */
function relatorio_exportar_excel(array $relatorio, string $auditoria): void
{
    require_once __DIR__ . '/../../vendor/autoload.php';

    $safeAuditoria = preg_replace('/[^a-z0-9_\\-]+/i', '_', $auditoria) ?: 'auditoria';
    $fileName = 'auditoria_' . $safeAuditoria . '_' . date('Ymd_His') . '.xlsx';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Auditoria');

    $coluna = 1;
    foreach ($relatorio['columns'] as $col) {
        $sheet->setCellValue([$coluna, 1], (string) $col['label']);
        $coluna++;
    }

    $linha = 2;
    foreach ($relatorio['rows'] as $row) {
        $coluna = 1;
        foreach ($relatorio['columns'] as $col) {
            $sheet->setCellValue([$coluna, $linha], (string) ($row[(string) $col['key']] ?? ''));
            $coluna++;
        }
        $linha++;
    }

    $ultimaColuna = count($relatorio['columns']);
    if ($ultimaColuna > 0) {
        $ultimaColunaLetra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ultimaColuna);
        $sheet->getStyle('A1:' . $ultimaColunaLetra . '1')->getFont()->setBold(true);

        for ($i = 1; $i <= $ultimaColuna; $i++) {
            $colunaLetra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colunaLetra)->setAutoSize(true);
        }
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
}

$auditorias = [
    'lista_geral' => 'Inventário geral',
    'historico_equipamento' => 'Histórico por equipamento',
    'sala' => 'Sala',
    'sem_local' => 'Sem local',
    'emprestimos' => 'Empréstimos',
    'avariados' => 'Avariados',
    'abatidos' => 'Abatidos',
];

$auditoria = strtolower(trim((string) ($_GET['auditoria'] ?? 'lista_geral')));
if ($auditoria === 'emprestimos_atuais' || $auditoria === 'emprestimos_data') {
    $auditoria = 'emprestimos';
}
if (!isset($auditorias[$auditoria])) {
    $auditoria = 'lista_geral';
}

$localIdInput = trim((string) ($_GET['local_id'] ?? ''));
$dataInicioFiltro = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFimFiltro = trim((string) ($_GET['data_fim'] ?? ''));
$dataLegadaFiltro = trim((string) ($_GET['data'] ?? ''));
$equipamentoFiltro = trim((string) ($_GET['equipamento'] ?? ''));
if ($dataInicioFiltro === '' && $dataLegadaFiltro !== '') {
    $dataInicioFiltro = $dataLegadaFiltro;
}
$exportExcel = strtolower(trim((string) ($_GET['export'] ?? ''))) === 'xlsx';

$localId = filter_var($localIdInput, FILTER_VALIDATE_INT);
if ($localId === false || $localId <= 0) {
    $localId = null;
    $localIdInput = '';
}

$locais = [];
$resumoEstados = [];
$relatorio = [
    'title' => 'Auditoria',
    'columns' => [],
    'rows' => [],
];
$erro = null;

try {
    $repo = new RelatorioRepository($pdo);
    $locais = $repo->listarLocais();
    $resumoEstados = $repo->resumoEstados();
    $relatorio = $repo->gerarAuditoria([
        'auditoria' => $auditoria,
        'local_id' => $localId,
        'data' => $dataLegadaFiltro !== '' ? $dataLegadaFiltro : null,
        'data_inicio' => $dataInicioFiltro !== '' ? $dataInicioFiltro : null,
        'data_fim' => $dataFimFiltro !== '' ? $dataFimFiltro : null,
        'equipamento' => $equipamentoFiltro !== '' ? $equipamentoFiltro : null,
    ]);

    if ($exportExcel) {
        relatorio_exportar_excel($relatorio, $auditoria);
        exit();
    }
} catch (Throwable $e) {
    $erro = trim($e->getMessage()) !== '' ? $e->getMessage() : 'Não foi possível gerar o relatório.';
}

$maxGrafico = 1;
foreach ($resumoEstados as $estado) {
    $total = (int) ($estado['total'] ?? 0);
    if ($total > $maxGrafico) {
        $maxGrafico = $total;
    }
}
$mostrarFiltroSala = $auditoria === 'sala';
$mostrarFiltroEmprestimos = $auditoria === 'emprestimos';
$mostrarFiltroEquipamento = $auditoria === 'historico_equipamento';

util_render_layout_start('Relatórios', 'relatorios');
?>
<style>
.report-grid {
  display: grid;
  gap: 16px;
}
.report-bars {
  display: grid;
  gap: 10px;
}
.report-bar-row {
  display: grid;
  grid-template-columns: 120px 1fr 60px;
  align-items: center;
  gap: 10px;
}
.report-bar-track {
  width: 100%;
  background: var(--surface-muted);
  border: 1px solid var(--line);
  border-radius: 999px;
  overflow: hidden;
  height: 12px;
}
.report-bar-fill {
  height: 100%;
}
.report-bar-fill.em_uso { background: #0ea5e9; }
.report-bar-fill.avariado { background: #f59e0b; }
.report-bar-fill.abate { background: #ef4444; }
.report-bar-total {
  font-weight: 700;
  text-align: right;
}
.report-filter-inline {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.report-filter-inline[hidden] {
  display: none !important;
}
@media (max-width: 900px) {
  .report-bar-row {
    grid-template-columns: 1fr;
    gap: 6px;
  }
  .report-bar-total {
    text-align: left;
  }
  .report-filter-inline {
    display: flex;
    width: 100%;
  }
  .report-filter-inline > * {
    width: 100%;
    min-width: 0;
  }
  #filtro-datas-wrap {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  #filtro-datas-wrap {
    grid-template-columns: 1fr;
  }
}
</style>

<?php if ($erro !== null): ?>
  <div class="msg msg-error"><?php echo util_e($erro); ?></div>
<?php endif; ?>

<div class="report-grid">
  <section class="panel">
    <form method="get" class="inline-create inline-create-wrap" action="index.php">
      <select name="auditoria" id="auditoria" required>
        <?php foreach ($auditorias as $key => $label): ?>
          <option value="<?php echo util_e($key); ?>" <?php echo $auditoria === $key ? 'selected' : ''; ?>>
            <?php echo util_e($label); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div id="filtro-local-wrap" class="report-filter-inline" <?php echo $mostrarFiltroSala ? '' : 'hidden'; ?>>
        <select name="local_id" id="local_id" <?php echo $mostrarFiltroSala ? '' : 'disabled'; ?>>
          <option value="">Selecionar sala</option>
          <?php foreach ($locais as $local): ?>
            <?php $id = (string) $local['id']; ?>
            <option value="<?php echo util_e($id); ?>" <?php echo $localIdInput === $id ? 'selected' : ''; ?>>
              <?php echo util_e((string) $local['nome']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="filtro-datas-wrap" class="report-filter-inline" <?php echo $mostrarFiltroEmprestimos ? '' : 'hidden'; ?>>
        <input
          type="date"
          name="data_inicio"
          id="data_inicio"
          value="<?php echo util_e($dataInicioFiltro); ?>"
          title="Data início"
          <?php echo $mostrarFiltroEmprestimos ? '' : 'disabled'; ?>
        >
        <input
          type="date"
          name="data_fim"
          id="data_fim"
          value="<?php echo util_e($dataFimFiltro); ?>"
          title="Data fim"
          <?php echo $mostrarFiltroEmprestimos ? '' : 'disabled'; ?>
        >
      </div>
      <div id="filtro-equipamento-wrap" class="report-filter-inline" <?php echo $mostrarFiltroEquipamento ? '' : 'hidden'; ?>>
        <input
          type="text"
          name="equipamento"
          id="equipamento"
          value="<?php echo util_e($equipamentoFiltro); ?>"
          placeholder="Ex.: MAT-0001, 1 ou nome"
          <?php echo $mostrarFiltroEquipamento ? '' : 'disabled'; ?>
        >
      </div>
      <button class="btn btn-secondary" type="submit">Procurar</button>
      <button class="btn btn-primary" type="submit" name="export" value="xlsx">Exportar Excel</button>
      <a class="btn btn-secondary" href="index.php">Limpar</a>
    </form>
    <p class="table-note">
      Sala requer seleção de sala. Empréstimos permite intervalo de datas opcional (início/fim). Histórico por equipamento aceita código, ID ou nome.
    </p>
  </section>

  <section class="panel">
    <h2 class="section-title">Gráfico simples por estado</h2>
    <div class="report-bars">
      <?php foreach ($resumoEstados as $estado): ?>
        <?php
          $estadoKey = (string) ($estado['estado_key'] ?? 'em_uso');
          $estadoLabel = (string) ($estado['estado_label'] ?? 'Estado');
          $total = (int) ($estado['total'] ?? 0);
          $percent = (int) round(($total / $maxGrafico) * 100);
        ?>
        <div class="report-bar-row">
          <span><?php echo util_e($estadoLabel); ?></span>
          <div class="report-bar-track">
            <div class="report-bar-fill <?php echo util_e($estadoKey); ?>" style="width: <?php echo $percent; ?>%;"></div>
          </div>
          <span class="report-bar-total"><?php echo $total; ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <h2 class="section-title"><?php echo util_e((string) $relatorio['title']); ?></h2>
    <table class="table">
      <thead>
        <tr>
          <?php foreach ($relatorio['columns'] as $column): ?>
            <th><?php echo util_e((string) $column['label']); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$erro && $relatorio['rows'] === []): ?>
          <tr>
            <td colspan="<?php echo count($relatorio['columns']) ?: 1; ?>" class="empty">Sem registos para os filtros escolhidos.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($relatorio['rows'] as $row): ?>
          <tr>
            <?php foreach ($relatorio['columns'] as $column): ?>
              <?php $key = (string) $column['key']; ?>
              <td><?php echo util_e((string) ($row[$key] ?? '')); ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<script>
const auditoriaSelect = document.getElementById('auditoria');
const localFilterWrap = document.getElementById('filtro-local-wrap');
const localSelect = document.getElementById('local_id');
const datasFilterWrap = document.getElementById('filtro-datas-wrap');
const dataInicioInput = document.getElementById('data_inicio');
const dataFimInput = document.getElementById('data_fim');
const equipamentoFilterWrap = document.getElementById('filtro-equipamento-wrap');
const equipamentoInput = document.getElementById('equipamento');

function updateFiltroDependente() {
  if (
    !auditoriaSelect
    || !localFilterWrap
    || !localSelect
    || !datasFilterWrap
    || !dataInicioInput
    || !dataFimInput
    || !equipamentoFilterWrap
    || !equipamentoInput
  ) {
    return;
  }

  const mode = String(auditoriaSelect.value || '');
  const mostrarSala = mode === 'sala';
  const mostrarEmprestimos = mode === 'emprestimos';
  const mostrarEquipamento = mode === 'historico_equipamento';

  localFilterWrap.hidden = !mostrarSala;
  localSelect.disabled = !mostrarSala;

  datasFilterWrap.hidden = !mostrarEmprestimos;
  dataInicioInput.disabled = !mostrarEmprestimos;
  dataFimInput.disabled = !mostrarEmprestimos;
  equipamentoFilterWrap.hidden = !mostrarEquipamento;
  equipamentoInput.disabled = !mostrarEquipamento;
}

if (auditoriaSelect) {
  auditoriaSelect.addEventListener('change', updateFiltroDependente);
}
updateFiltroDependente();
</script>
<?php util_render_layout_end(); ?>

