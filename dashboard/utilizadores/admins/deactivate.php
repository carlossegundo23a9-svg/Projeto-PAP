<?php
require_once __DIR__ . "/../shared/common.php";

util_require_superadmin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    util_redirect(util_url('dashboard/utilizadores/admins/index.php'));
}
util_verify_csrf_or_redirect(util_url('dashboard/utilizadores/admins/index.php'));

$adminId = (int) ($_POST['admin_id'] ?? 0);

if ($adminId <= 0) {
    util_set_flash('erro', 'Administrador inválido.');
    util_redirect(util_url('dashboard/utilizadores/admins/index.php'));
}

if ($adminId === (int) $_SESSION['user_id']) {
    util_set_flash('erro', 'Não pode desativar o próprio utilizador.');
    util_redirect(util_url('dashboard/utilizadores/admins/index.php'));
}

$stmtFind = $pdo->prepare("SELECT id, obs FROM user WHERE id = :id AND obs IN ('admin', 'superadmin') AND ativo = 1 LIMIT 1");
$stmtFind->execute(['id' => $adminId]);
$admin = $stmtFind->fetch();

if (!$admin) {
    util_set_flash('erro', 'Administrador não encontrado.');
    util_redirect(util_url('dashboard/utilizadores/admins/index.php'));
}

if ($admin['obs'] === 'superadmin') {
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM user WHERE obs = 'superadmin' AND ativo = 1");
    $totalSuperadmins = (int) $stmtCount->fetchColumn();

    if ($totalSuperadmins <= 1) {
        util_set_flash('erro', 'Não pode desativar o último superadmin.');
        util_redirect(util_url('dashboard/utilizadores/admins/index.php'));
    }
}

$stmtUpdate = $pdo->prepare("UPDATE user SET ativo = 0 WHERE id = :id");
$stmtUpdate->execute(['id' => $adminId]);

util_set_flash('sucesso', 'Administrador desativado com sucesso.');
util_redirect(util_url('dashboard/utilizadores/admins/index.php'));


