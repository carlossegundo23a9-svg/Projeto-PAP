<?php
require_once __DIR__ . "/../shared/layout.php";

util_require_section_access($pdo, 'utilizadores');

$stmtTurmas = $pdo->query(
    "SELECT t.id, t.nome, t.ativa, COUNT(ta.id) AS total_alunos
     FROM turma t
     LEFT JOIN turma_aluno ta ON ta.turma_id = t.id
     WHERE t.ativa = 0
     GROUP BY t.id, t.nome, t.ativa
     ORDER BY t.nome ASC"
);
$turmas = $stmtTurmas->fetchAll();

util_render_layout_start("Turmas arquivadas", "utilizadores");
util_render_module_tabs("arquivadas");
?>
<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Turma</th>
        <th>Alunos</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$turmas): ?>
        <tr>
          <td colspan="3" class="empty">Sem turmas arquivadas.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($turmas as $turma): ?>
        <tr>
          <td>
            <a class="table-link" href="detail.php?id=<?php echo (int) $turma['id']; ?>">
              <?php echo util_e($turma['nome']); ?>
            </a>
          </td>
          <td><?php echo (int) $turma['total_alunos']; ?></td>
          <td>
            <form method="post" action="reactivate.php" onsubmit="return confirm('Reativar está turma?');">
              <?php echo util_csrf_field(); ?>
              <input type="hidden" name="turma_id" value="<?php echo (int) $turma['id']; ?>">
              <button class="btn btn-primary" type="submit">Reativar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php util_render_layout_end(); ?>





