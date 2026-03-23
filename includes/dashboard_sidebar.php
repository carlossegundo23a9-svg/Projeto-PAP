<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

if (!function_exists('dashboard_sidebar_escape')) {
    function dashboard_sidebar_escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dashboard_sidebar_base_path')) {
    function dashboard_sidebar_base_path(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $pos = strpos($script, '/dashboard/');
        $base = $pos !== false ? substr($script, 0, $pos) : '';

        return rtrim($base, '/');
    }
}

if (!function_exists('dashboard_sidebar_url')) {
    function dashboard_sidebar_url(string $path): string
    {
        $base = dashboard_sidebar_base_path();
        $cleanPath = '/' . ltrim($path, '/');

        return $base . $cleanPath;
    }
}

if (!function_exists('dashboard_render_sidebar')) {
    function dashboard_render_sidebar(string $activeSection): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            app_session_start();
        }

        $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
        $isSuperadmin = $role === 'superadmin';

        if ($role === 'admin') {
            $items = [
                ['key' => 'emprestimos', 'icon' => 'fas fa-exchange-alt', 'label' => 'Empréstimos', 'href' => 'dashboard/emprestimo.php'],
            ];
        } else {
            $items = [
                ['key' => 'dashboard', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Painel de Controlo', 'href' => 'dashboard/dashboard.php'],
                ['key' => 'bens', 'icon' => 'fas fa-boxes', 'label' => 'Inventário', 'href' => 'dashboard/bens.php'],
                ['key' => 'localizacoes', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Localizações', 'href' => 'dashboard/localizacoes.php'],
                ['key' => 'relatorios', 'icon' => 'fas fa-chart-bar', 'label' => 'Relatórios', 'href' => 'dashboard/relatorios.php'],
                ['key' => 'emprestimos', 'icon' => 'fas fa-exchange-alt', 'label' => 'Empréstimos', 'href' => 'dashboard/emprestimo.php'],
                ['key' => 'utilizadores', 'icon' => 'fas fa-users', 'label' => 'Utilizadores', 'href' => 'dashboard/utilizadores.php'],
            ];

            if ($isSuperadmin) {
                $items[] = ['key' => 'configuracoes', 'icon' => 'fas fa-cog', 'label' => 'Configurações', 'href' => 'dashboard/configuracoes.php'];
            }
        }

        echo '<div class="sidebar-head">';
        echo '  <button class="btn btn-secondary btn-small btn-icon js-sidebar-toggle" type="button" aria-pressed="false">';
        echo '    <span class="sr-only">Alternar menu lateral</span>';
        echo '    <i class="fas fa-angles-left" aria-hidden="true"></i>';
        echo '  </button>';
        echo '</div>';

        echo '<nav class="menu" id="menu-principal">';
        foreach ($items as $item) {
            $isActive = $item['key'] === $activeSection;
            $class = $isActive ? ' class="active"' : '';
            $label = (string) $item['label'];
            $href = (string) $item['href'];
            $url = $href === '#' ? '#' : dashboard_sidebar_url($href);

            echo '<a href="' . dashboard_sidebar_escape($url) . '"' . $class . ' data-label="' . dashboard_sidebar_escape($label) . '">';
            echo '<i class="' . dashboard_sidebar_escape((string) $item['icon']) . '"></i> ' . dashboard_sidebar_escape($label);
            echo '</a>';
        }
        echo '</nav>';
        echo '<div class="sidebar-copyright">';
        echo '&copy; Este projeto foi desenvolvido por <b>Carlos Segundo</b> como projeto PAP';
        echo '</div>';
    }
}
