<?php
require_once __DIR__ . "/../../../includes/session_security.php";
if (session_status() === PHP_SESSION_NONE) {
    app_session_start();
}

require_once __DIR__ . "/../../../includes/pdo.php";
require_once __DIR__ . "/../../../includes/activity_log.php";

function util_url(string $path): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/dashboard/');
    $base = $pos !== false ? substr($script, 0, $pos) : '';

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function util_redirect(string $path): void
{
    header("Location: " . $path);
    exit();
}

function util_set_flash(string $type, string $message): void
{
    $_SESSION['util_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function util_take_flash(): ?array
{
    if (!isset($_SESSION['util_flash'])) {
        return null;
    }

    $flash = $_SESSION['util_flash'];
    unset($_SESSION['util_flash']);

    return $flash;
}

function util_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function util_require_login(): void
{
    if (!isset($_SESSION['id_utilizador']) && isset($_SESSION['user_id'])) {
        $_SESSION['id_utilizador'] = (int) $_SESSION['user_id'];
    }

    if (!isset($_SESSION['user_id'])) {
        util_redirect(util_url('login/index.php'));
    }
}

function util_load_role(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare("SELECT obs, ativo FROM user WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'inativo';
    }

    if (isset($row['ativo']) && (int) $row['ativo'] !== 1) {
        return 'inativo';
    }

    $role = trim((string) ($row['obs'] ?? ''));

    if ($role === 'admin' || $role === 'superadmin') {
        return $role;
    }

    return 'inativo';
}

function util_current_role(PDO $pdo): string
{
    util_require_login();

    $userId = (int) $_SESSION['user_id'];
    $role = util_load_role($pdo, $userId);

    $_SESSION['role'] = $role;

    return $role;
}

function util_require_module_access(PDO $pdo): string
{
    util_ensure_formadores_schema($pdo);

    $role = util_current_role($pdo);

    if ($role === 'inativo') {
        util_set_flash('erro', 'Conta sem permissao para aceder ao sistema.');
        util_redirect(util_url('login/logout.php'));
    }

    return $role;
}

function util_require_section_access(PDO $pdo, string $section): string
{
    $role = util_require_module_access($pdo);
    $section = strtolower(trim($section));

    $allowedByRole = [
        'admin' => ['emprestimos'],
        'superadmin' => ['dashboard', 'bens', 'localizacoes', 'relatorios', 'emprestimos', 'utilizadores', 'configuracoes'],
    ];

    $allowedSections = $allowedByRole[$role] ?? [];
    if (!in_array($section, $allowedSections, true)) {
        util_set_flash('erro', 'Sem permissao para aceder a esta area.');
        $fallbackPath = $role === 'admin'
            ? util_url('dashboard/emprestimo.php')
            : util_url('dashboard/dashboard.php');
        util_redirect($fallbackPath);
    }

    return $role;
}

function util_ensure_formadores_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS formador (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_formador_email (email),
            KEY idx_formador_nome (nome),
            KEY idx_formador_ativo_nome (ativo, nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $checked = true;
}

function util_require_superadmin(PDO $pdo): void
{
    util_require_section_access($pdo, 'configuracoes');
}

function util_csrf_token(): string
{
    if (empty($_SESSION['util_csrf_token'])) {
        $_SESSION['util_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['util_csrf_token'];
}

function util_csrf_field(): string
{
    $token = util_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . util_e($token) . '">';
}

function util_verify_csrf_or_redirect(string $redirectPath): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['util_csrf_token'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        util_set_flash('erro', 'Pedido inválido. Atualize a página e tente novamente.');
        util_redirect($redirectPath);
    }
}
