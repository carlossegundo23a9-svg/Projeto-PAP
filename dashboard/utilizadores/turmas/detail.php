<?php
require_once __DIR__ . "/../shared/layout.php";

util_require_section_access($pdo, 'utilizadores');

$turmaId = (int) ($_GET['id'] ?? 0);

if ($turmaId <= 0) {
    util_set_flash('erro', 'Turma inválida.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

$stmtTurma = $pdo->prepare(
    "SELECT t.id, t.nome, t.ativa, COUNT(ta.id) AS total_alunos
     FROM turma t
     LEFT JOIN turma_aluno ta ON ta.turma_id = t.id
     WHERE t.id = :id
     GROUP BY t.id, t.nome, t.ativa"
);
$stmtTurma->execute(['id' => $turmaId]);
$turma = $stmtTurma->fetch();

if (!$turma) {
    util_set_flash('erro', 'Turma não encontrada.');
    util_redirect(util_url('dashboard/utilizadores/turmas/index.php'));
}

$stmtAlunos = $pdo->prepare(
    "SELECT c.id, c.nome, c.email
     FROM turma_aluno ta
     INNER JOIN cliente c ON c.id = ta.aluno_id
     WHERE ta.turma_id = :id
     ORDER BY c.nome ASC"
);
$stmtAlunos->execute(['id' => $turmaId]);
$alunos = $stmtAlunos->fetchAll();

$stmtDisponiveis = $pdo->prepare(
    "SELECT c.id, c.nome, c.email
     FROM cliente c
     WHERE NOT EXISTS (
        SELECT 1
        FROM turma_aluno ta
        WHERE ta.aluno_id = c.id
     )
     ORDER BY c.nome ASC"
);
$stmtDisponiveis->execute();
$alunosDisponiveis = $stmtDisponiveis->fetchAll();

$isAtiva = (int) $turma['ativa'] === 1;

util_render_layout_start("Detalhe da Turma", "utilizadores");
util_render_module_tabs($isAtiva ? "turmas" : "arquivadas");
?>
<div class="detail-head">
  <div>
    <h2><?php echo util_e($turma['nome']); ?></h2>
    <p><?php echo (int) $turma['total_alunos']; ?> aluno(s)</p>
  </div>

  <div class="detail-actions">
    <?php if ($isAtiva): ?>
      <form method="post" action="archive.php" onsubmit="return confirm('Arquivar esta turma?');">
        <?php echo util_csrf_field(); ?>
        <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
        <button class="btn btn-danger" type="submit">Arquivar turma</button>
      </form>
    <?php else: ?>
      <form method="post" action="reactivate.php" onsubmit="return confirm('Reativar esta turma?');">
        <?php echo util_csrf_field(); ?>
        <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
        <button class="btn btn-primary" type="submit">Reativar turma</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAtiva): ?>
<section class="panel panel-form">
  <form class="inline-create" method="post" action="add_student.php">
    <?php echo util_csrf_field(); ?>
    <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
    <select name="aluno_id" required>
      <option value="">Selecionar aluno...</option>
      <?php foreach ($alunosDisponiveis as $alunoDisp): ?>
        <option value="<?php echo (int) $alunoDisp['id']; ?>">
          <?php echo util_e($alunoDisp['nome']); ?> (<?php echo util_e($alunoDisp['email']); ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Adicionar aluno</button>
  </form>
  <?php if (!$alunosDisponiveis): ?>
    <p class="inline-hint">Todos os alunos ja estao associados a uma turma.</p>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="panel panel-form">
  <form class="inline-create" method="post" action="archive_student.php">
    <?php echo util_csrf_field(); ?>
    <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
    <select name="aluno_id" required>
      <option value="">Selecionar aluno...</option>
      <?php foreach ($alunosDisponiveis as $alunoDisp): ?>
        <option value="<?php echo (int) $alunoDisp['id']; ?>">
          <?php echo util_e($alunoDisp['nome']); ?> (<?php echo util_e($alunoDisp['email']); ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Arquivar aluno</button>
  </form>
  <?php if (!$alunosDisponiveis): ?>
    <p class="inline-hint">Sem alunos disponiveis (todos ja estao associados a uma turma).</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Email</th>
        <th>Acoes</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$alunos): ?>
        <tr>
          <td colspan="3" class="empty">Sem alunos nesta turma.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($alunos as $aluno): ?>
        <tr>
          <td><?php echo util_e($aluno['nome']); ?></td>
          <td><?php echo util_e($aluno['email']); ?></td>
          <td>
            <?php if ($isAtiva): ?>
              <form method="post" action="remove_student.php" onsubmit="return confirm('Remover aluno desta turma?');">
                <?php echo util_csrf_field(); ?>
                <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
                <input type="hidden" name="aluno_id" value="<?php echo (int) $aluno['id']; ?>">
                <button class="btn btn-secondary" type="submit">Remover</button>
              </form>
            <?php else: ?>
              <form method="post" action="reactivate_student.php" onsubmit="return confirm('Reativar aluno desta turma arquivada?');">
                <?php echo util_csrf_field(); ?>
                <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
                <input type="hidden" name="aluno_id" value="<?php echo (int) $aluno['id']; ?>">
                <button class="btn btn-primary" type="submit">Reativar aluno</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php util_render_layout_end(); ?>


