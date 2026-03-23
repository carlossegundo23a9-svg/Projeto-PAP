<?php
require_once __DIR__ . "/../shared/layout.php";

util_require_section_access($pdo, 'utilizadores');

$stmt = $pdo->query("
    SELECT id, nome, email
    FROM formador
    WHERE ativo = 1
    ORDER BY nome ASC
");
$formadores = $stmt->fetchAll();

util_render_layout_start("Formadores", "utilizadores");
util_render_module_tabs("formadores");
?>
<div class="toolbar toolbar-wrap">
  <form class="inline-create" method="post" action="create.php">
    <?php echo util_csrf_field(); ?>
    <input type="text" name="nome" placeholder="Nome do formador" maxlength="100" required>
    <input type="email" name="email" placeholder="Email do formador" maxlength="150" required>
    <button class="btn btn-primary" type="submit">Adicionar formador</button>
  </form>

  <form class="inline-create inline-create-file" method="post" action="import.php" enctype="multipart/form-data">
    <?php echo util_csrf_field(); ?>
    <input type="file" name="ficheiro" accept=".xlsx,.xls" required>
    <button class="btn btn-secondary" type="submit">Importar Excel</button>
    <span class="inline-hint">Colunas esperadas: Nome e Email.</span>
  </form>
</div>

<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Email</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$formadores): ?>
        <tr>
          <td colspan="2" class="empty">Sem formadores registados.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($formadores as $formador): ?>
        <tr>
          <td><?php echo util_e((string) $formador['nome']); ?></td>
          <td><?php echo util_e((string) $formador['email']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php util_render_layout_end(); ?>



