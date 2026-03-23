<?php
require_once __DIR__ . "/../shared/layout.php";

$role = util_require_section_access($pdo, 'configuracoes');
$isSuperadmin = $role === 'superadmin';

$stmt = $pdo->query("SELECT id, nome, email, obs FROM user WHERE obs IN ('admin', 'superadmin') AND ativo = 1 ORDER BY nome ASC");
$admins = $stmt->fetchAll();

util_render_layout_start("Administradores", "configuracoes");
util_render_module_tabs("admins");
?>
<div class="toolbar">
  <?php if ($isSuperadmin): ?>
    <a class="btn btn-primary" href="create.php">Criar Administrador</a>
  <?php else: ?>
    <button class="btn btn-disabled" type="button" disabled>Apenas superadmin pode criar</button>
  <?php endif; ?>
</div>

<section class="panel">
  <table class="table">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Email</th>
        <th>Nivel</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$admins): ?>
        <tr>
          <td colspan="4" class="empty">Sem administradores ativos.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($admins as $admin): ?>
        <?php
          $adminId = (int) $admin['id'];
          $isSelf = $adminId === (int) $_SESSION['user_id'];
          $isSuper = $admin['obs'] === 'superadmin';
        ?>
        <tr>
          <td><?php echo util_e($admin['nome']); ?></td>
          <td><?php echo util_e($admin['email']); ?></td>
          <td>
            <?php if ($isSuperadmin && !$isSelf): ?>
              <form class="inline-form" method="post" action="update_role.php">
                <?php echo util_csrf_field(); ?>
                <input type="hidden" name="admin_id" value="<?php echo $adminId; ?>">
                <select name="nivel" onchange="this.form.submit()">
                  <option value="admin" <?php echo $isSuper ? '' : 'selected'; ?>>Admin</option>
                  <option value="superadmin" <?php echo $isSuper ? 'selected' : ''; ?>>Superadmin</option>
                </select>
              </form>
            <?php else: ?>
              <span class="badge <?php echo $isSuper ? 'badge-super' : 'badge-admin'; ?>">
                <?php echo $isSuper ? 'Superadmin' : 'Admin'; ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isSelf): ?>
              <span class="table-note">Utilizador atual</span>
            <?php elseif ($isSuperadmin): ?>
              <form method="post" action="deactivate.php" onsubmit="return confirm('Desativar este administrador?');">
                <?php echo util_csrf_field(); ?>
                <input type="hidden" name="admin_id" value="<?php echo $adminId; ?>">
                <button class="btn btn-danger" type="submit">Desativar</button>
              </form>
            <?php else: ?>
              <span class="table-note">Sem permissão</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php util_render_layout_end(); ?>





