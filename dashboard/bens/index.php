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
require_once __DIR__ . '/../../includes/pdo.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/activity_log.php';
require_once __DIR__ . '/../../includes/dashboard_sidebar.php';
require_once __DIR__ . '/../../model/repositories/material_repository.php';
require_once __DIR__ . '/../../model/entities/material_factory.php';

util_require_section_access($pdo, 'bens');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function buildItensPageUrl(int $page, string $q, string $tipo, string $estado, string $localIdInput): string
{
    $params = ['page' => $page];

    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($tipo !== '') {
        $params['tipo'] = $tipo;
    }
    if ($estado !== '') {
        $params['estado'] = $estado;
    }
    if ($localIdInput !== '') {
        $params['local_id'] = $localIdInput;
    }

    return 'index.php?' . http_build_query($params);
}

/**
 * @return array<int, int|string>
 */
function buildItensPageWindow(int $currentPage, int $totalPages): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    $pages = [1];
    $start = max(2, $currentPage - 1);
    $end = min($totalPages - 1, $currentPage + 1);

    if ($start > 2) {
        $pages[] = '...';
    }

    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }

    if ($end < $totalPages - 1) {
        $pages[] = '...';
    }

    $pages[] = $totalPages;

    return $pages;
}

function sanitizeReturnQuery(?string $queryRaw): string
{
    $queryRaw = ltrim(trim((string) $queryRaw), '?');
    if ($queryRaw === '') {
        return '';
    }

    parse_str($queryRaw, $parsed);
    $allowedKeys = ['page', 'q', 'tipo', 'estado', 'local_id'];
    $clean = [];

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $parsed) || is_array($parsed[$key])) {
            continue;
        }
        $clean[$key] = (string) $parsed[$key];
    }

    if ($clean === []) {
        return '';
    }

    return '?' . http_build_query($clean);
}

$repo = new MaterialRepository($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_item') {
    $returnQuery = sanitizeReturnQuery((string) ($_POST['return_query'] ?? ''));
    $redirectUrl = 'index.php' . $returnQuery;

    if (!app_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Pedido inválido. Atualize a página e tente novamente.'];
        header('Location: ' . $redirectUrl);
        exit();
    }

    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'ID inválido.'];
        header('Location: ' . $redirectUrl);
        exit();
    }
    $materialAnterior = null;
    try {
        $materialAnterior = $repo->buscarPorId((int) $id);
    } catch (Throwable $e) {
        $materialAnterior = null;
    }

    $values = [
        'tipo' => trim((string) ($_POST['tipo'] ?? '')),
        'nome' => trim((string) ($_POST['nome'] ?? '')),
        'marca' => trim((string) ($_POST['marca'] ?? '')),
        'modelo' => trim((string) ($_POST['modelo'] ?? '')),
        'codigo_inventario' => trim((string) ($_POST['codigo_inventario'] ?? '')),
        'data_compra' => trim((string) ($_POST['data_compra'] ?? '')),
        'local_id' => trim((string) ($_POST['local_id'] ?? '')),
        'estado' => trim((string) ($_POST['estado'] ?? '')),
        'data_broken' => trim((string) ($_POST['data_broken'] ?? '')),
        'mac' => trim((string) ($_POST['mac'] ?? '')),
        'sn' => trim((string) ($_POST['sn'] ?? '')),
        'descricao' => trim((string) ($_POST['descricao'] ?? '')),
    ];

    $errors = [];

    if ($values['nome'] === '') {
        $errors[] = 'Nome do item é obrigatório.';
    }

    $tipoNormalizado = material_normalize_tipo($values['tipo']);
    if ($tipoNormalizado === null) {
        $errors[] = 'Tipo inválido.';
    }

    $localId = filter_var($values['local_id'], FILTER_VALIDATE_INT);
    if ($localId === false || $localId <= 0) {
        $errors[] = 'Selecione um local válido.';
    }

    $dataCompra = material_clean_date($values['data_compra']);
    if ($values['data_compra'] !== '' && $dataCompra === null) {
        $errors[] = 'Data de compra inválida. Use o formato YYYY-MM-DD.';
    }

    $estadoNormalizado = material_normalize_estado($values['estado']);
    $isBroken = $estadoNormalizado === 'avariado';
    $isAbate = $estadoNormalizado === 'abate';

    $dataBroken = material_clean_date($values['data_broken']);
    if ($values['data_broken'] !== '' && $dataBroken === null) {
        $errors[] = 'Data de avaria inválida. Use o formato YYYY-MM-DD.';
    }
    if (!$isBroken) {
        $dataBroken = null;
    }
    if ($isBroken && $dataBroken === null) {
        $dataBroken = date('Y-m-d');
    }

    $disponivel = !$isBroken && !$isAbate;
    $estadoAnterior = $materialAnterior && method_exists($materialAnterior, 'isAbate')
        ? ($materialAnterior->isAbate() ? 'abate' : ($materialAnterior->isBroken() ? 'avariado' : 'em_uso'))
        : '';
    $estadoNovo = $isAbate ? 'abate' : ($isBroken ? 'avariado' : 'em_uso');

    if ($errors === [] && $tipoNormalizado !== null && is_int($localId)) {
        try {
            $material = material_build_instance(
                $tipoNormalizado,
                (int) $id,
                $values['nome'],
                $values['marca'] !== '' ? $values['marca'] : null,
                $values['modelo'] !== '' ? $values['modelo'] : null,
                $dataCompra,
                $localId,
                $isBroken,
                $isAbate,
                $dataBroken,
                $values['mac'] !== '' ? $values['mac'] : null,
                $values['sn'] !== '' ? $values['sn'] : null,
                $values['descricao'] !== '' ? $values['descricao'] : null,
                $disponivel,
                $values['codigo_inventario'] !== '' ? $values['codigo_inventario'] : null
            );

            $repo->atualizar($material);
            app_log_registar($pdo, 'Item editado', [
                'material_id' => (int) $id,
                'nome' => (string) $values['nome'],
                'tipo' => (string) $tipoNormalizado,
                'estado_anterior' => (string) $estadoAnterior,
                'estado_novo' => (string) $estadoNovo,
                'codigo_inventario' => (string) $values['codigo_inventario'],
            ]);
            if ($estadoNovo === 'avariado' && $estadoAnterior !== 'avariado') {
                app_log_registar($pdo, 'Item enviado para manutenção', [
                    'material_id' => (int) $id,
                    'nome' => (string) $values['nome'],
                    'estado_anterior' => (string) $estadoAnterior,
                    'estado_novo' => 'avariado',
                ]);
            }
            if ($estadoNovo === 'abate' && $estadoAnterior !== 'abate') {
                app_log_registar($pdo, 'Item removido', [
                    'material_id' => (int) $id,
                    'nome' => (string) $values['nome'],
                    'estado_anterior' => (string) $estadoAnterior,
                    'estado_novo' => 'abate',
                ]);
            }
            $_SESSION['flash'] = ['type' => 'sucesso', 'message' => 'Item atualizado com sucesso.'];
        } catch (Throwable $e) {
            $erro = trim((string) $e->getMessage());
            if ($erro === 'Código de inventário já existe.') {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => $erro];
            } else {
                $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Erro ao atualizar item. Verifique os dados e tente novamente.'];
            }
        }
    } else {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => implode(' ', $errors)];
    }

    header('Location: ' . $redirectUrl);
    exit();
}

$q = trim((string) ($_GET['q'] ?? ''));
$tipo = strtolower(trim((string) ($_GET['tipo'] ?? '')));
$estado = strtolower(trim((string) ($_GET['estado'] ?? '')));
$localIdInput = trim((string) ($_GET['local_id'] ?? ''));
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!in_array($tipo, ['maquina', 'periferico', 'extra'], true)) {
    $tipo = '';
}

if (!in_array($estado, ['em_uso', 'avariado', 'abate'], true)) {
    $estado = '';
}

$localId = filter_var($localIdInput, FILTER_VALIDATE_INT);
if ($localId === false || $localId <= 0) {
    $localId = null;
    $localIdInput = '';
}

$filters = [
    'tipo' => $tipo,
    'estado' => $estado,
    'local_id' => $localId,
];

$itensPorPagina = 10;
$paginaAtual = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
if (!is_int($paginaAtual) || $paginaAtual <= 0) {
    $paginaAtual = 1;
}

$locais = [];
$itens = [];
$totalItens = 0;
$totalPaginas = 1;
$locaisError = null;
$itensError = null;

try {
    $locais = $repo->listarLocais();
} catch (Throwable $e) {
    $locaisError = 'Não foi possível carregar os locais.';
}

try {
    $totalItens = $repo->contar($q, $filters);
    $totalPaginas = $totalItens > 0 ? (int) ceil($totalItens / $itensPorPagina) : 1;
    if ($paginaAtual > $totalPaginas) {
        $paginaAtual = $totalPaginas;
    }

    $offset = ($paginaAtual - 1) * $itensPorPagina;
    $itens = $repo->listar($q, $filters, $itensPorPagina, $offset);
} catch (Throwable $e) {
    $itensError = 'Não foi possível carregar os itens.';
}

$primeiroItem = $totalItens > 0 ? (($paginaAtual - 1) * $itensPorPagina) + 1 : 0;
$ultimoItem = $totalItens > 0 ? min($paginaAtual * $itensPorPagina, $totalItens) : 0;
$returnQuery = sanitizeReturnQuery($_SERVER['QUERY_STRING'] ?? '');
$returnQueryHidden = ltrim($returnQuery, '?');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ESTEL SGP - Bens e Itens</title>
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../../css/utilizadores.css?v=20260318b">
</head>
<body id="page-bens-itens">
<header class="topbar">
  <div class="logo">ESTEL SGP</div>
  <div class="topbar-right">
    <span class="user-label">Bem-vindo(a), <strong><?php echo h($_SESSION['nome'] ?? 'Utilizador'); ?></strong></span>
    <button class="btn btn-secondary btn-small js-theme-toggle" type="button" aria-pressed="false">Modo escuro</button>
    <button class="btn btn-primary btn-small" type="button" onclick="confirmarLogout()">Sair <i class="fas fa-sign-out-alt"></i></button>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <?php dashboard_render_sidebar('bens'); ?>
  </aside>

  <main class="content">
    <h1 class="page-title">Inventário</h1>

    <?php if (is_array($flash) && isset($flash['type'], $flash['message'])): ?>
      <div class="msg <?php echo $flash['type'] === 'sucesso' ? 'msg-success' : 'msg-error'; ?>" data-toast="1" data-toast-only="1">
        <?php echo h((string) $flash['message']); ?>
      </div>
    <?php endif; ?>

    <?php if ($locaisError): ?>
      <div class="msg msg-error"><?php echo h($locaisError); ?></div>
    <?php endif; ?>

    <?php if ($itensError): ?>
      <div class="msg msg-error"><?php echo h($itensError); ?></div>
    <?php endif; ?>

    <div class="toolbar toolbar-wrap" id="toolbar-bens">
      <form method="get" id="form-pesquisa" class="inline-create" action="index.php">
        <input type="text" name="q" id="q" placeholder="Pesquisar por código, nome, marca, modelo ou local" value="<?php echo h($q); ?>">

        <select name="tipo" id="tipo">
          <option value="">Todas categorias</option>
          <option value="maquina" <?php echo $tipo === 'maquina' ? 'selected' : ''; ?>>Máquina</option>
          <option value="periferico" <?php echo $tipo === 'periferico' ? 'selected' : ''; ?>>Periférico</option>
          <option value="extra" <?php echo $tipo === 'extra' ? 'selected' : ''; ?>>Extra</option>
        </select>

        <select name="estado" id="estado">
          <option value="">Todos estados</option>
          <option value="em_uso" <?php echo $estado === 'em_uso' ? 'selected' : ''; ?>>Em uso</option>
          <option value="avariado" <?php echo $estado === 'avariado' ? 'selected' : ''; ?>>Avariado</option>
          <option value="abate" <?php echo $estado === 'abate' ? 'selected' : ''; ?>>Abate</option>
        </select>

        <select name="local_id" id="local_id">
          <option value="">Todos locais</option>
          <?php foreach ($locais as $local): ?>
            <?php $localOptionId = (string) $local['id']; ?>
            <option value="<?php echo h($localOptionId); ?>" <?php echo $localIdInput === $localOptionId ? 'selected' : ''; ?>>
              <?php echo h((string) $local['nome']); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button class="btn btn-secondary" type="submit" id="btn-pesquisar">Procurar</button>
        <a class="btn btn-secondary" href="index.php" id="btn-limpar-filtros">Limpar</a>
      </form>

      <div class="detail-actions">
        <a class="btn btn-primary" id="btn-adicionar" href="create.php">Adicionar Item</a>
        <a class="btn btn-secondary" id="btn-importar" href="import.php">Importar Excel</a>
      </div>
    </div>

    <section class="panel" id="painel-itens">
      <?php if (!$itensError): ?>
        <p class="table-note">A mostrar <?php echo $primeiroItem; ?>-<?php echo $ultimoItem; ?> de <?php echo $totalItens; ?> itens.</p>
      <?php endif; ?>

      <table class="table" id="tabela-itens" data-pagination-server="1">
        <thead>
          <tr>
            <th>Código</th>
            <th>Item</th>
            <th>Categoria</th>
            <th>Local</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$itensError && !$itens): ?>
            <tr>
              <td colspan="5" class="empty">Sem itens para mostrar.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($itens as $material): ?>
            <?php
              $id = $material->getId() ?? 0;
              $categoria = ucfirst($material->getTipo());
              $localNome = $material->getLocalNome() ?: '-';
              $linhaDetalhe = [];
              if ($material->getMarca()) {
                  $linhaDetalhe[] = 'Marca: ' . $material->getMarca();
              }
              if ($material->getModelo()) {
                  $linhaDetalhe[] = 'Modelo: ' . $material->getModelo();
              }
              if ($material->getCodigoInventario()) {
                  $linhaDetalhe[] = 'Inv: ' . $material->getCodigoInventario();
              }
              if ($material instanceof Máquina) {
                  if ($material->getMac()) {
                      $linhaDetalhe[] = 'MAC: ' . $material->getMac();
                  }
                  if ($material->getNumSerie()) {
                      $linhaDetalhe[] = 'SN: ' . $material->getNumSerie();
                  }
              }
              if ($material instanceof Extra && $material->getDescricao()) {
                  $linhaDetalhe[] = 'Desc: ' . $material->getDescricao();
              }

              $localIdMaterial = null;
              if ($material instanceof Máquina || $material instanceof Periférico || $material instanceof Extra) {
                  $localIdMaterial = $material->getLocalId();
              }

              $itemPayload = [
                  'id' => $id,
                  'codigo' => $material->getCodigo(),
                  'nome' => $material->getNome(),
                  'tipo' => $material->getTipo(),
                  'local_id' => $localIdMaterial,
                  'local_nome' => $localNome,
                  'estado' => $material->isAbate() ? 'abate' : ($material->isBroken() ? 'avariado' : 'em_uso'),
                  'estado_label' => $material->getEstadoLabel(),
                  'marca' => $material->getMarca() ?? '',
                  'modelo' => $material->getModelo() ?? '',
                  'codigo_inventario' => $material->getCodigoInventario() ?? '',
                  'data_compra' => $material->getDataCompra() ?? '',
                  'data_broken' => $material->getDataBroken() ?? '',
                  'mac' => $material instanceof Máquina ? ($material->getMac() ?? '') : '',
                  'sn' => $material instanceof Máquina ? ($material->getNumSerie() ?? '') : '',
                  'descricao' => $material instanceof Extra ? ($material->getDescricao() ?? '') : '',
              ];
              $itemPayloadJson = h((string) json_encode($itemPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
            <tr class="item-row js-item-row" tabindex="0" role="button" aria-label="Ver e editar <?php echo h($material->getCodigo()); ?>" data-item="<?php echo $itemPayloadJson; ?>">
              <td><?php echo h($material->getCodigo()); ?></td>
              <td>
                <?php echo h($material->getNome()); ?>
                <?php if ($linhaDetalhe): ?>
                  <div class="table-note"><?php echo h(implode(' | ', $linhaDetalhe)); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo h($categoria); ?></td>
              <td><?php echo h($localNome); ?></td>
              <td><?php echo h($material->getEstadoLabel()); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!$itensError && $totalPaginas > 1): ?>
        <?php $window = buildItensPageWindow($paginaAtual, $totalPaginas); ?>
        <nav class="pagination pagination-nav pagination-server" id="paginacao-itens" aria-label="Paginação de bens e itens">
          <?php if ($paginaAtual > 1): ?>
            <a class="pagination-link pagination-control" href="<?php echo h(buildItensPageUrl($paginaAtual - 1, $q, $tipo, $estado, $localIdInput)); ?>">&laquo; Anterior</a>
          <?php else: ?>
            <span class="pagination-link pagination-control is-disabled" aria-disabled="true">&laquo; Anterior</span>
          <?php endif; ?>

          <?php foreach ($window as $pageItem): ?>
            <?php if ($pageItem === '...'): ?>
              <span class="pagination-link pagination-ellipsis">...</span>
            <?php else: ?>
              <?php $pageNumber = (int) $pageItem; ?>
              <?php if ($pageNumber === $paginaAtual): ?>
                <span class="pagination-link is-active" aria-current="page"><?php echo $pageNumber; ?></span>
              <?php else: ?>
                <a class="pagination-link" href="<?php echo h(buildItensPageUrl($pageNumber, $q, $tipo, $estado, $localIdInput)); ?>"><?php echo $pageNumber; ?></a>
              <?php endif; ?>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($paginaAtual < $totalPaginas): ?>
            <a class="pagination-link pagination-control" href="<?php echo h(buildItensPageUrl($paginaAtual + 1, $q, $tipo, $estado, $localIdInput)); ?>">Próximo &raquo;</a>
          <?php else: ?>
            <span class="pagination-link pagination-control is-disabled" aria-disabled="true">Próximo &raquo;</span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    </section>
  </main>
</div>

<div class="item-drawer-backdrop" id="item-drawer-backdrop" hidden></div>
<aside class="item-drawer" id="item-drawer" aria-hidden="true" aria-labelledby="item-drawer-title" role="dialog">
  <div class="item-drawer-head">
    <div>
      <h2 id="item-drawer-title">Detalhes do item</h2>
      <p class="table-note" id="item-drawer-code">Selecione um item da tabela.</p>
    </div>
    <button class="btn btn-secondary btn-small" type="button" id="btn-fechar-item-drawer">Fechar</button>
  </div>

  <section class="panel panel-form item-drawer-panel">
    <form method="post" id="form-item-drawer">
      <?php echo app_csrf_field(); ?>
      <input type="hidden" name="action" value="update_item">
      <input type="hidden" name="id" id="drawer-id" value="">
      <input type="hidden" name="return_query" value="<?php echo h($returnQueryHidden); ?>">

      <div class="form-grid item-drawer-grid">
        <label for="drawer-tipo">
          Tipo *
          <select name="tipo" id="drawer-tipo" required>
            <option value="maquina">Máquina</option>
            <option value="periferico">Periférico</option>
            <option value="extra">Extra</option>
          </select>
        </label>

        <label for="drawer-nome">
          Item (Nome) *
          <input type="text" name="nome" id="drawer-nome" required>
        </label>

        <label for="drawer-local-id">
          Local *
          <select name="local_id" id="drawer-local-id" required>
            <option value="">Selecione</option>
            <?php foreach ($locais as $local): ?>
              <option value="<?php echo h((string) $local['id']); ?>"><?php echo h((string) $local['nome']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label for="drawer-estado">
          Estado
          <select name="estado" id="drawer-estado">
            <option value="em_uso">Em uso</option>
            <option value="avariado">Avariado</option>
            <option value="abate">Abate</option>
          </select>
        </label>

        <label for="drawer-marca">
          Marca
          <input type="text" name="marca" id="drawer-marca">
        </label>

        <label for="drawer-modelo">
          Modelo
          <input type="text" name="modelo" id="drawer-modelo">
        </label>

        <label for="drawer-codigo-inventario">
          Código de inventário (escola)
          <input type="text" name="codigo_inventario" id="drawer-codigo-inventario">
        </label>

        <label for="drawer-data-compra">
          Data de compra
          <input type="date" name="data_compra" id="drawer-data-compra">
        </label>

        <label for="drawer-data-broken">
          Data de avaria
          <input type="date" name="data_broken" id="drawer-data-broken">
        </label>

        <label for="drawer-mac" class="item-drawer-field js-field-maquina">
          MAC 
          <input type="text" name="mac" id="drawer-mac">
        </label>

        <label for="drawer-sn" class="item-drawer-field js-field-maquina">
          Número de série
          <input type="text" name="sn" id="drawer-sn">
        </label>

        <label for="drawer-descricao" class="item-drawer-field js-field-extra">
          Descrição
          <textarea name="descricao" id="drawer-descricao"></textarea>
        </label>
      </div>

      <div class="form-actions item-drawer-actions">
        <a class="btn btn-secondary" id="drawer-emprestar" href="../emprestimos/create.php?tipo=aluno">Emprestar</a>
        <button class="btn btn-primary" type="submit" id="drawer-guardar">Guardar alterações</button>
      </div>
    </form>
  </section>
</aside>

<script src="../../js/theme-toggle.js?v=20260318b"></script>
<script>
function confirmarLogout() {
  if (confirm("Tem a certeza de que pretende terminar a sessão?")) {
    window.location.href = "../../login/logout.php";
  }
}

(function () {
  const drawer = document.getElementById('item-drawer');
  const backdrop = document.getElementById('item-drawer-backdrop');
  const closeButton = document.getElementById('btn-fechar-item-drawer');
  const rows = document.querySelectorAll('.js-item-row');
  if (!drawer || !backdrop || !closeButton || rows.length === 0) {
    return;
  }

  const codeLine = document.getElementById('item-drawer-code');
  const idInput = document.getElementById('drawer-id');
  const tipoInput = document.getElementById('drawer-tipo');
  const nomeInput = document.getElementById('drawer-nome');
  const localInput = document.getElementById('drawer-local-id');
  const estadoInput = document.getElementById('drawer-estado');
  const marcaInput = document.getElementById('drawer-marca');
  const modeloInput = document.getElementById('drawer-modelo');
  const codigoInventarioInput = document.getElementById('drawer-codigo-inventario');
  const dataCompraInput = document.getElementById('drawer-data-compra');
  const dataBrokenInput = document.getElementById('drawer-data-broken');
  const macInput = document.getElementById('drawer-mac');
  const snInput = document.getElementById('drawer-sn');
  const descricaoInput = document.getElementById('drawer-descricao');
  const emprestarLink = document.getElementById('drawer-emprestar');

  const fieldsMáquina = document.querySelectorAll('.js-field-maquina');
  const fieldsExtra = document.querySelectorAll('.js-field-extra');

  function setFieldVisibility(fieldList, isVisible) {
    fieldList.forEach((field) => {
      field.hidden = !isVisible;
    });
  }

  function applyTipoVisibility(tipo) {
    setFieldVisibility(fieldsMáquina, tipo === 'maquina');
    setFieldVisibility(fieldsExtra, tipo === 'extra');
  }

  function buildEmprestarUrl(item) {
    const url = new URL('../emprestimos/create.php', window.location.href);
    url.searchParams.set('tipo', 'aluno');

    const codigo = String(item.codigo || '').trim();
    const nome = String(item.nome || '').trim();
    if (codigo !== '') {
      url.searchParams.set('item_codigo', codigo);
    }
    if (nome !== '') {
      url.searchParams.set('item_nome', nome);
    }

    return url.toString();
  }

  function fillDrawer(item) {
    idInput.value = String(item.id || '');
    codeLine.textContent = String(item.codigo || 'Item sem código');
    tipoInput.value = String(item.tipo || 'maquina');
    nomeInput.value = String(item.nome || '');
    localInput.value = item.local_id === null || typeof item.local_id === 'undefined' ? '' : String(item.local_id);
    estadoInput.value = String(item.estado || 'em_uso');
    marcaInput.value = String(item.marca || '');
    modeloInput.value = String(item.modelo || '');
    codigoInventarioInput.value = String(item.codigo_inventario || '');
    dataCompraInput.value = String(item.data_compra || '');
    dataBrokenInput.value = String(item.data_broken || '');
    macInput.value = String(item.mac || '');
    snInput.value = String(item.sn || '');
    descricaoInput.value = String(item.descricao || '');
    emprestarLink.href = buildEmprestarUrl(item);
    applyTipoVisibility(tipoInput.value);
  }

  function openDrawer(item) {
    fillDrawer(item);
    backdrop.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('item-drawer-open');
    window.setTimeout(() => {
      nomeInput.focus();
    }, 40);
  }

  function closeDrawer() {
    drawer.setAttribute('aria-hidden', 'true');
    backdrop.hidden = true;
    document.body.classList.remove('item-drawer-open');
  }

  function parseItem(row) {
    const json = row.getAttribute('data-item');
    if (!json) {
      return null;
    }

    try {
      const parsed = JSON.parse(json);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (_error) {
      return null;
    }
  }

  rows.forEach((row) => {
    row.addEventListener('click', () => {
      const item = parseItem(row);
      if (item) {
        openDrawer(item);
      }
    });

    row.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }

      event.preventDefault();
      const item = parseItem(row);
      if (item) {
        openDrawer(item);
      }
    });
  });

  tipoInput.addEventListener('change', () => {
    applyTipoVisibility(tipoInput.value);
  });

  backdrop.addEventListener('click', closeDrawer);
  closeButton.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('item-drawer-open')) {
      closeDrawer();
    }
  });
})();
</script>
</body>
</html>

