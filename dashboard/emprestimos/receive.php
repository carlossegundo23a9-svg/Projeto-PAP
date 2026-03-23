<?php
declare(strict_types=1);

require_once __DIR__ . '/../utilizadores/shared/layout.php';
require_once __DIR__ . '/../../model/repositories/emprestimo_repository.php';

util_require_section_access($pdo, 'emprestimos');

$repo = new EmprestimoRepository($pdo);
$processarRececao = static function (EmprestimoRepository $repo, PDO $pdo, int $emprestimoId, string $itemCodigoInput = ''): void {
    try {
        $result = $repo->receberEmprestimo($emprestimoId);
        $itensNomes = '';
        try {
            $stmtItens = $pdo->prepare(" 
                SELECT GROUP_CONCAT(DISTINCT m.nome ORDER BY m.nome SEPARATOR ', ') AS itens_nomes
                FROM emprestimo_material em
                INNER JOIN material m ON m.id = em.material_id
                WHERE em.emprestimo_id = :id
            ");
            $stmtItens->execute(['id' => (int) ($result['emprestimo_id'] ?? 0)]);
            $itensNomes = trim((string) $stmtItens->fetchColumn());
        } catch (Throwable $e) {
            $itensNomes = '';
        }

        app_log_registar($pdo, 'Item devolvido', [
            'emprestimo_id' => (int) ($result['emprestimo_id'] ?? 0),
            'itens' => (int) ($result['itens'] ?? 0),
            'itens_nomes' => $itensNomes,
        ]);

        $mensagemSucesso = 'Empréstimo #' . (int) $result['emprestimo_id'] . ' recebido com sucesso.';
        if ($itemCodigoInput !== '') {
            $mensagemSucesso = 'Item ' . $itemCodigoInput . ' recebido com sucesso (Empréstimo #' . (int) $result['emprestimo_id'] . ').';
        }

        util_set_flash('sucesso', $mensagemSucesso);
    } catch (Throwable $e) {
        util_set_flash('erro', trim((string) $e->getMessage()) !== '' ? $e->getMessage() : 'Erro ao receber empréstimo.');
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    util_verify_csrf_or_redirect(util_url('dashboard/emprestimos/receive.php'));

    $postAction = trim((string) ($_POST['action'] ?? 'confirm_receive'));

    if ($postAction === 'lookup_code') {
        $codigoLido = strtoupper(trim((string) ($_POST['item_codigo'] ?? '')));
        if ($codigoLido === '') {
            util_set_flash('erro', 'Código inválido. Tente novamente.');
            util_redirect(util_url('dashboard/emprestimos/receive.php'));
        }

        try {
            $lookupInfo = $repo->buscarEmprestimoAtivoPorCodigoItem($codigoLido);
        } catch (Throwable $e) {
            $lookupInfo = null;
        }

        if (!is_array($lookupInfo) || (int) ($lookupInfo['emprestimo_id'] ?? 0) <= 0) {
            util_set_flash('erro', 'Não existe empréstimo ativo para o código: ' . $codigoLido . '.');
            util_redirect(util_url('dashboard/emprestimos/receive.php'));
        }

        $emprestimoId = (int) ($lookupInfo['emprestimo_id'] ?? 0);
        $itemCodigoResolvido = strtoupper(trim((string) ($lookupInfo['item_codigo'] ?? $codigoLido)));
        if ($itemCodigoResolvido === '') {
            $itemCodigoResolvido = $codigoLido;
        }

        $processarRececao($repo, $pdo, $emprestimoId, $itemCodigoResolvido);
        util_redirect(util_url('dashboard/emprestimos/receive.php'));
    }

    $emprestimoId = filter_var($_POST['emprestimo_id'] ?? null, FILTER_VALIDATE_INT);
    $itemCodigoInput = strtoupper(trim((string) ($_POST['item_codigo'] ?? '')));

    if ($emprestimoId === false || $emprestimoId <= 0) {
        util_set_flash('erro', 'Empréstimo inválido para receber.');
        util_redirect(util_url('dashboard/emprestimos/receive.php'));
    }

    $processarRececao($repo, $pdo, (int) $emprestimoId, $itemCodigoInput);
    util_redirect(util_url('dashboard/emprestimos/receive.php'));
}

$erroLista = null;
$emprestimosAtivos = [];
try {
    $emprestimosAtivos = $repo->listarEmprestimosAtivos();
} catch (Throwable $e) {
    $erroLista = 'Não foi possível carregar os empréstimos ativos.';
}
util_render_layout_start('Receber Item', 'emprestimos');
?>
<div class="detail-head">
  <div class="detail-actions">
    <a class="btn btn-secondary" href="index.php">Voltar</a>
    <a class="btn btn-secondary" href="create.php">Criar Empréstimo</a>
    <button class="btn btn-primary" type="button" id="btn-barcode-reader">Receber por código</button>
  </div>
</div>

<?php if ($erroLista !== null): ?>
  <div class="msg msg-error"><?php echo util_e($erroLista); ?></div>
<?php endif; ?>

<section class="panel">
  <h2 class="section-title">Todos os empréstimos ativos</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Item</th>
        <th>Quem tem</th>
        <th>Tipo</th>
        <th>Data prevista entrega</th>
        <th>Ação</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$erroLista && !$emprestimosAtivos): ?>
        <tr>
          <td colspan="5" class="empty">Não existem empréstimos ativos.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($emprestimosAtivos as $emprestimo): ?>
        <tr>
          <td>
            <?php echo util_e((string) $emprestimo['item_nome']); ?>
            <div class="table-note"><?php echo util_e((string) $emprestimo['item_codigo']); ?></div>
          </td>
          <td><?php echo util_e((string) $emprestimo['quem']); ?></td>
          <td><?php echo util_e((string) $emprestimo['tipo_label']); ?></td>
          <td>
            <?php if ($emprestimo['data_prevista'] !== null): ?>
              <?php echo util_e(date('d/m/Y', strtotime((string) $emprestimo['data_prevista']))); ?>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('Confirmar devolução deste item?');">
              <?php echo util_csrf_field(); ?>
              <input type="hidden" name="action" value="confirm_receive">
              <input type="hidden" name="emprestimo_id" value="<?php echo (int) $emprestimo['emprestimo_id']; ?>">
              <button class="btn btn-primary btn-small" type="submit">Receber Item</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<form method="post" id="form-lookup-codigo" hidden>
  <?php echo util_csrf_field(); ?>
  <input type="hidden" name="action" value="lookup_code">
  <input type="hidden" name="item_codigo" id="item_codigo_lookup" value="">
</form>

<div class="barcode-modal" id="barcode-modal" hidden>
  <div class="barcode-modal-card" role="dialog" aria-modal="true" aria-labelledby="barcode-modal-title">
    <h2 id="barcode-modal-title">Leitura de código de barras</h2>
    <p>Leia o código do equipamento. A devolução é feita automaticamente.</p>
    <form id="barcode-reader-form">
      <label for="barcode-reader-input">
        Código lido
        <input type="text" id="barcode-reader-input" autocomplete="off" spellcheck="false">
      </label>
      <div class="form-actions">
        <button class="btn btn-secondary" type="button" id="barcode-close-btn">Fechar</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const openBtn = document.getElementById('btn-barcode-reader');
  const closeBtn = document.getElementById('barcode-close-btn');
  const modal = document.getElementById('barcode-modal');
  const form = document.getElementById('barcode-reader-form');
  const readerInput = document.getElementById('barcode-reader-input');
  const lookupForm = document.getElementById('form-lookup-codigo');
  const lookupInput = document.getElementById('item_codigo_lookup');
  let autoTimer = null;
  let isSubmittingLookup = false;

  if (!openBtn || !closeBtn || !modal || !form || !readerInput || !lookupForm || !lookupInput) {
    return;
  }

  function openModal() {
    if (autoTimer !== null) {
      window.clearTimeout(autoTimer);
      autoTimer = null;
    }
    isSubmittingLookup = false;
    modal.hidden = false;
    readerInput.value = '';
    setTimeout(() => readerInput.focus(), 0);
  }

  function closeModal() {
    modal.hidden = true;
  }

  function submitLookup() {
    if (isSubmittingLookup) {
      return;
    }

    const value = String(readerInput.value || '').trim();
    if (value === '') {
      readerInput.focus();
      return;
    }

    isSubmittingLookup = true;
    lookupInput.value = value;
    closeModal();
    lookupForm.submit();
  }

  openBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);

  modal.addEventListener('click', function (event) {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    submitLookup();
  });

  readerInput.addEventListener('input', function () {
    if (isSubmittingLookup) {
      return;
    }

    const value = String(readerInput.value || '').trim();
    if (value === '') {
      if (autoTimer !== null) {
        window.clearTimeout(autoTimer);
        autoTimer = null;
      }
      return;
    }

    if (autoTimer !== null) {
      window.clearTimeout(autoTimer);
    }
    autoTimer = window.setTimeout(function () {
      autoTimer = null;
      submitLookup();
    }, 180);
  });

  readerInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      if (autoTimer !== null) {
        window.clearTimeout(autoTimer);
        autoTimer = null;
      }
      submitLookup();
    }
  });
})();
</script>
<?php util_render_layout_end(); ?>
