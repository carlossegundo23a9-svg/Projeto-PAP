<?php
declare(strict_types=1);

require_once __DIR__ . '/utilizadores/shared/common.php';
util_require_section_access($pdo, 'configuracoes');

header('Location: ./utilizadores/admins/index.php');
exit();
