<?php
require_once __DIR__ . "/../shared/layout.php";

util_require_superadmin($pdo);

util_render_layout_start("Criar Administrador", "configuracoes");
util_render_module_tabs("admins");
?>
<section class="panel panel-form">
  <form method="post" action="store.php">
    <?php echo util_csrf_field(); ?>
    <div class="form-grid">
      <label>
        Nome
        <input type="text" name="nome" required maxlength="100">
      </label>

      <label>
        Email
        <input type="email" name="email" required maxlength="100">
      </label>

      <label>
        Password
        <input type="password" name="password" required minlength="6" maxlength="255">
      </label>

      <label>
        Nivel
        <select name="nivel" required>
          <option value="admin">Admin</option>
          <option value="superadmin">Superadmin</option>
        </select>
      </label>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</section>
<?php util_render_layout_end(); ?>

