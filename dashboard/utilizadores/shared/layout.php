<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../../includes/dashboard_sidebar.php';

function util_render_layout_start(string $title, string $activeSection): void
{
    $userName = util_e($_SESSION['nome'] ?? 'Utilizador');
    $flash = util_take_flash();

    echo '<!DOCTYPE html>';
    echo '<html lang="pt-PT">';
    echo '<head>';
    echo '  <meta charset="UTF-8">';
    echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '  <title>ESTEL SGP - ' . util_e($title) . '</title>';
    echo '  <link rel="icon" type="image/x-icon" href="' . util_e(util_url('assets/favicon.ico')) . '">';
    echo '  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
    echo '  <link rel="stylesheet" href="' . util_e(util_url('css/utilizadores.css?v=20260318b')) . '">';
    echo '</head>';
    echo '<body>';
    echo '<header class="topbar">';
    echo '  <div class="logo">ESTEL SGP</div>';
    echo '  <div class="topbar-right">';
    echo '    <span class="user-label">Bem-vindo(a), <strong>' . $userName . '</strong></span>';
    echo '    <button class="btn btn-secondary btn-small js-theme-toggle" type="button" aria-pressed="false">Modo escuro</button>';
    echo '    <button class="btn btn-primary btn-small" type="button" onclick="confirmarLogout()">Sair <i class="fas fa-sign-out-alt"></i></button>';
    echo '  </div>';
    echo '</header>';
    echo '<div class="layout">';
    echo '  <aside class="sidebar">';
    dashboard_render_sidebar($activeSection);
    echo '  </aside>';
    echo '  <main class="content">';
    echo '    <h1 class="page-title">' . util_e($title) . '</h1>';

    if ($flash) {
        $className = $flash['type'] === 'sucesso' ? 'msg msg-success' : 'msg msg-error';
        echo '    <div class="' . $className . '" data-toast="1" data-toast-only="1">' . util_e((string) $flash['message']) . '</div>';
    }
}

function util_render_module_tabs(string $active): void
{
    echo '<div class="module-tabs">';

    if ($active === 'admins' || $active === 'logs') {
        echo '  <a href="' . util_e(util_url('dashboard/utilizadores/admins/index.php')) . '"' . ($active === 'admins' ? ' class="active"' : '') . '>Administradores</a>';
        echo '  <a href="' . util_e(util_url('dashboard/utilizadores/logs/index.php')) . '"' . ($active === 'logs' ? ' class="active"' : '') . '>Logs</a>';
    } else {
        echo '  <a href="' . util_e(util_url('dashboard/utilizadores/turmas/index.php')) . '"' . ($active === 'turmas' ? ' class="active"' : '') . '>Turmas</a>';
        echo '  <a href="' . util_e(util_url('dashboard/utilizadores/alunos/index.php')) . '"' . ($active === 'alunos' ? ' class="active"' : '') . '>Alunos</a>';
        echo '  <a href="' . util_e(util_url('dashboard/utilizadores/formadores/index.php')) . '"' . ($active === 'formadores' ? ' class="active"' : '') . '>Formadores</a>';
        echo '  <a href="' . util_e(util_url('dashboard/utilizadores/turmas/archived.php')) . '"' . ($active === 'arquivadas' ? ' class="active"' : '') . '>Turmas Arquivadas</a>';
    }

    echo '</div>';
}

function util_render_layout_end(): void
{
    echo '  </main>';
    echo '</div>';
    echo '<script src="' . util_e(util_url('js/theme-toggle.js?v=20260318b')) . '"></script>';
    echo '<script>';
    echo 'function confirmarLogout(){ if(confirm("Tem a certeza que pretende terminar a sessão?")){ window.location.href = "' . util_e(util_url('login/logout.php')) . '"; }}';
    echo '</script>';
    echo '</body>';
    echo '</html>';
}

