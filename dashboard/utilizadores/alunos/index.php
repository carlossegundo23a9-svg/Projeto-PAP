<?php
require_once __DIR__ . "/../shared/layout.php";

util_require_section_access($pdo, 'utilizadores');

$stmt = $pdo->query(
    "SELECT
        c.id,
        c.nome,
        c.email,
        GROUP_CONCAT(DISTINCT t.nome ORDER BY t.nome SEPARATOR ', ') AS turmas
     FROM cliente c
     LEFT JOIN turma_aluno ta ON ta.aluno_id = c.id
     LEFT JOIN turma t ON t.id = ta.turma_id AND t.ativa = 1
     GROUP BY c.id, c.nome, c.email
     ORDER BY c.nome ASC"
);
$alunos = $stmt->fetchAll();

util_render_layout_start("Alunos", "utilizadores");
util_render_module_tabs("alunos");
?>
<div class="toolbar toolbar-wrap">
  <form class="inline-create" method="post" action="create.php">
    <?php echo util_csrf_field(); ?>
    <input type="text" name="nome" placeholder="Nome do aluno" maxlength="100" required>
    <input type="email" name="email" placeholder="Email do aluno" maxlength="100" required>
    <button class="btn btn-primary" type="submit">Adicionar aluno</button>
  </form>
</div>

<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Email</th>
        <th>Turma</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$alunos): ?>
        <tr>
          <td colspan="3" class="empty">Sem alunos registados.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($alunos as $aluno): ?>
        <tr>
          <td><?php echo util_e($aluno['nome']); ?></td>
          <td><?php echo util_e($aluno['email']); ?></td>
          <td><?php echo util_e((string) ($aluno['turmas'] ?? '-')); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php util_render_layout_end(); ?>

