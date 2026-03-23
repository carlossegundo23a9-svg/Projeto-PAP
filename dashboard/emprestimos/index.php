<?php
declare(strict_types=1);

require_once __DIR__ . '/../utilizadores/shared/layout.php';

util_require_section_access($pdo, 'emprestimos');

function emprestimo_h(?string $value): string
{
    return util_e($value);
}

function emprestimo_format_date_br(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return '-';
    }

    return date('d/m/Y', $ts);
}

$registosHoje = [];
$erroRegistosHoje = null;

try {
    $registosHoje = $pdo->query("
        SELECT
            e.id AS emprestimo_id,
            c.nome AS aluno_nome,
            GROUP_CONCAT(DISTINCT m.nome ORDER BY m.nome SEPARATOR ', ') AS itens,
            e.data_inicio,
            e.prazo_entrega,
            e.data_fim,
            e.obs_inicio
        FROM emprestimo e
        JOIN cliente c ON e.cliente_id = c.id
        JOIN emprestimo_material em ON em.emprestimo_id = e.id
        JOIN material m ON m.id = em.material_id
        WHERE e.data_inicio = CURDATE()
        GROUP BY e.id, c.nome, e.data_inicio, e.prazo_entrega, e.data_fim, e.obs_inicio
        ORDER BY e.id DESC
    ")->fetchAll();
} catch (Throwable $e) {
    $erroRegistosHoje = 'Não foi possível carregar os registos de empréstimos de hoje.';
}

util_render_layout_start('Empréstimos', 'emprestimos');
?>
<div class="home-grid">
  <a class="home-card" href="create.php">
    <h2>Criar Empréstimo</h2>
    <p>Criar empréstimos por aluno, por turma ou de longa duração.</p>
  </a>

  <a class="home-card" href="receive.php">
    <h2>Receber Item</h2>
    <p>Consultar empréstimos ativos e registar devoluções.</p>
  </a>
</div>

<section class="panel">
  <h2 class="section-title"><i class="fas fa-history"></i> Registos de Hoje</h2>

  <?php if ($erroRegistosHoje !== null): ?>
    <div class="msg msg-error"><?php echo emprestimo_h($erroRegistosHoje); ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Empréstimo</th>
          <th>Aluno</th>
          <th>Itens</th>
          <th>Tipo</th>
          <th>Data início</th>
          <th>Data fim</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($registosHoje !== []): ?>
          <?php foreach ($registosHoje as $r): ?>
            <?php
              $tipoEmprestimo = '';
              if (isset($r['obs_inicio']) && is_string($r['obs_inicio']) && trim($r['obs_inicio']) !== '') {
                  $meta = json_decode((string) $r['obs_inicio'], true);
                  if (is_array($meta) && isset($meta['tipo'])) {
                      $tipoEmprestimo = (string) $meta['tipo'];
                  }
              }

              $tipoLabel = 'Aluno';
              if ($tipoEmprestimo === 'longa_duracao') {
                  $tipoLabel = 'Longa duração';
              } elseif ($tipoEmprestimo === 'turma') {
                  $tipoLabel = 'Turma';
              }

              $prazoEntrega = trim((string) ($r['prazo_entrega'] ?? ''));
              $isSimples = ($tipoEmprestimo === 'aluno') || $prazoEntrega === '' || $prazoEntrega === '9999-12-31';
              $estadoLabel = trim((string) ($r['data_fim'] ?? '')) !== '' ? 'Recebido' : 'Ativo';
            ?>
            <tr>
              <td>#<?php echo (int) ($r['emprestimo_id'] ?? 0); ?></td>
              <td><?php echo emprestimo_h((string) ($r['aluno_nome'] ?? '-')); ?></td>
              <td><?php echo emprestimo_h((string) ($r['itens'] ?? '-')); ?></td>
              <td><?php echo emprestimo_h($tipoLabel); ?></td>
              <td><?php echo emprestimo_format_date_br($r['data_inicio'] ?? null); ?></td>
              <td><?php echo $isSimples ? '-' : emprestimo_format_date_br($prazoEntrega); ?></td>
              <td><?php echo emprestimo_h($estadoLabel); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" class="empty">Sem registos de empréstimos hoje.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php util_render_layout_end(); ?>
