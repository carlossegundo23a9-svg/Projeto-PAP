<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_security.php';
app_session_start();

if (!isset($_SESSION['id_utilizador']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_utilizador'] = (int) $_SESSION['user_id'];
}

if (!isset($_SESSION['id_utilizador'])) {
    header('Location: ../../login.php');
    exit();
}

require_once __DIR__ . '/../utilizadores/shared/common.php';
util_require_section_access($pdo, 'localizacoes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../../includes/csrf.php';

if (!app_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash'] = [
        'type' => 'erro',
        'message' => "Pedido inv\u{00E1}lido. Atualize a p\u{00E1}gina e tente novamente.",
    ];
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../../includes/pdo.php';
require_once __DIR__ . '/../../model/repositories/local_repository.php';

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'erro',
        'message' => "ID de localiza\u{00E7}\u{00E3}o inv\u{00E1}lido.",
    ];
    header('Location: index.php');
    exit();
}

$repo = new LocalRepository($pdo);

try {
    $local = $repo->buscarPorId((int) $id);
    if (!$local) {
        $_SESSION['flash'] = [
            'type' => 'erro',
            'message' => "Localiza\u{00E7}\u{00E3}o n\u{00E3}o encontrada.",
        ];
        header('Location: index.php');
        exit();
    }

    $deps = $repo->contarDependencias((int) $id);
    if ($deps['total'] > 0) {
        $_SESSION['flash'] = [
            'type' => 'erro',
            'message' => "N\u{00E3}o foi poss\u{00ED}vel apagar. Local em uso (M: {$deps['maquinas']}, P: {$deps['perifericos']}, E: {$deps['extras']}).",
        ];
        header('Location: index.php');
        exit();
    }

    $apagado = $repo->apagar((int) $id);
    if ($apagado) {
        $_SESSION['flash'] = [
            'type' => 'sucesso',
            'message' => "Localiza\u{00E7}\u{00E3}o apagada com sucesso.",
        ];
    } else {
        $_SESSION['flash'] = [
            'type' => 'erro',
            'message' => "Localiza\u{00E7}\u{00E3}o n\u{00E3}o encontrada.",
        ];
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = [
        'type' => 'erro',
        'message' => "Erro ao apagar localiza\u{00E7}\u{00E3}o.",
    ];
}

header('Location: index.php');
exit();
