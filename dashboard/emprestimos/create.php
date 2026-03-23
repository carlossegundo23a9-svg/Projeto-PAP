<?php
declare(strict_types=1);

require_once __DIR__ . '/../utilizadores/shared/layout.php';
require_once __DIR__ . '/../../model/repositories/emprestimo_repository.php';
require_once __DIR__ . '/../../includes/emprestimo_notifier.php';

util_require_section_access($pdo, 'emprestimos');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    util_set_flash('erro', 'Sessão inválida. Inicie sessão novamente.');
    util_redirect(util_url('login/index.php'));
}

$repo = new EmprestimoRepository($pdo);
$tiposValidos = ['aluno', 'turma', 'longa_duracao'];
$tipoSelecionado = (string) ($_GET['tipo'] ?? 'aluno');
if (!in_array($tipoSelecionado, $tiposValidos, true)) {
    $tipoSelecionado = 'aluno';
}

$erroForm = null;
$resumoTurma = $_SESSION['emprestimo_turma_resumo'] ?? null;
unset($_SESSION['emprestimo_turma_resumo']);

$turmas = [];
try {
    $turmas = $repo->listarTurmasAtivasComTotais();
} catch (Throwable $e) {
    $erroForm = 'Não foi possível carregar dados para o formulário.';
}

$formAluno = [
    'aluno_nome' => '',
    'item_nome' => '',
    'item_codigo' => '',
    'aluno_email' => '',
];
$formLonga = [
    'aluno_nome' => '',
    'item_nome' => '',
    'item_codigo' => '',
    'aluno_email' => '',
    'data_prevista' => '',
];
$formTurma = [
    'turma_id' => '',
    'data_inicio' => '',
    'data_fim' => '',
];
$alunosTurma = [];
$alunosTurmaSelecionados = [];

if ($tipoSelecionado === 'turma') {
    $turmaSelecionadaGet = filter_input(INPUT_GET, 'turma_id', FILTER_VALIDATE_INT);
    if (is_int($turmaSelecionadaGet) && $turmaSelecionadaGet > 0) {
        $formTurma['turma_id'] = (string) $turmaSelecionadaGet;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $prefillItemCodigo = strtoupper(trim((string) ($_GET['item_codigo'] ?? '')));
    $prefillItemNome = trim((string) ($_GET['item_nome'] ?? ''));

    if ($prefillItemCodigo !== '' && preg_match('/^MAT-\d+$/', $prefillItemCodigo) !== 1) {
        $prefillItemCodigo = '';
    }
    if ($prefillItemNome !== '') {
        $prefillItemNome = substr($prefillItemNome, 0, 150);
    }

    if ($prefillItemCodigo !== '' || $prefillItemNome !== '') {
        if ($tipoSelecionado === 'longa_duracao') {
            $formLonga['item_codigo'] = $prefillItemCodigo;
            $formLonga['item_nome'] = $prefillItemNome !== '' ? $prefillItemNome : $prefillItemCodigo;
        } else {
            $formAluno['item_codigo'] = $prefillItemCodigo;
            $formAluno['item_nome'] = $prefillItemNome !== '' ? $prefillItemNome : $prefillItemCodigo;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    util_verify_csrf_or_redirect(util_url('dashboard/emprestimos/create.php'));

    $tipoPost = trim((string) ($_POST['tipo'] ?? ''));
    if (!in_array($tipoPost, $tiposValidos, true)) {
        $erroForm = 'Tipo de empréstimo inválido.';
    } else {
        $tipoSelecionado = $tipoPost;
    }

    if ($erroForm === null) {
        try {
            if ($tipoPost === 'aluno') {
                $formAluno['aluno_nome'] = trim((string) ($_POST['aluno_nome'] ?? ''));
                $formAluno['item_nome'] = trim((string) ($_POST['item_nome'] ?? ''));
                $formAluno['item_codigo'] = trim((string) ($_POST['item_codigo'] ?? ''));
                $formAluno['aluno_email'] = trim((string) ($_POST['aluno_email'] ?? ''));

                if ($formAluno['item_codigo'] === '' || $formAluno['aluno_email'] === '') {
                    throw new RuntimeException('Selecione um aluno válido e um item válido.');
                }

                $result = $repo->criarEmprestimoPorAluno(
                    $formAluno['item_codigo'],
                    $formAluno['aluno_email'],
                    $userId
                );

                app_log_registar($pdo, 'Empréstimo criado', [
                    'tipo' => 'aluno',
                    'emprestimo_id' => (int) ($result['emprestimo_id'] ?? 0),
                    'item_codigo' => (string) ($result['item_codigo'] ?? ''),
                    'item_nome' => (string) ($result['item_nome'] ?? ''),
                    'aluno_nome' => (string) ($result['aluno_nome'] ?? ''),
                ]);

                util_set_flash(
                    'sucesso',
                    'Empréstimo criado com sucesso: ' . $result['item_codigo'] . ' para ' . $result['aluno_nome'] . '.'
                );
                util_redirect(util_url('dashboard/emprestimos/create.php?tipo=aluno'));
            }

            if ($tipoPost === 'longa_duracao') {
                $formLonga['aluno_nome'] = trim((string) ($_POST['aluno_nome'] ?? ''));
                $formLonga['item_nome'] = trim((string) ($_POST['item_nome'] ?? ''));
                $formLonga['item_codigo'] = trim((string) ($_POST['item_codigo'] ?? ''));
                $formLonga['aluno_email'] = trim((string) ($_POST['aluno_email'] ?? ''));
                $formLonga['data_prevista'] = trim((string) ($_POST['data_prevista'] ?? ''));

                if (
                    $formLonga['item_codigo'] === ''
                    || $formLonga['aluno_email'] === ''
                    || $formLonga['data_prevista'] === ''
                ) {
                    throw new RuntimeException('Selecione um aluno valido, um item valido e data prevista.');
                }

                $result = $repo->criarEmprestimoLongaDuracao(
                    $formLonga['item_codigo'],
                    $formLonga['aluno_email'],
                    $formLonga['data_prevista'],
                    $userId
                );

                app_log_registar($pdo, 'Empréstimo criado', [
                    'tipo' => 'longa_duracao',
                    'emprestimo_id' => (int) ($result['emprestimo_id'] ?? 0),
                    'item_codigo' => (string) ($result['item_codigo'] ?? ''),
                    'item_nome' => (string) ($result['item_nome'] ?? ''),
                    'aluno_nome' => (string) ($result['aluno_nome'] ?? ''),
                    'data_prevista' => (string) $formLonga['data_prevista'],
                ]);

                $mensagem = 'Empréstimo de longa duração criado: '
                    . $result['item_codigo']
                    . ' para '
                    . $result['aluno_nome']
                    . '.';

                $erroEmailLonga = null;
                $emailLongaEnviado = false;
                try {
                    $emailLongaEnviado = app_notifier_enviar_email_longa_duracao(
                        $pdo,
                        (int) ($result['emprestimo_id'] ?? 0),
                        $erroEmailLonga
                    );
                } catch (Throwable $emailException) {
                    $erroEmailLonga = trim($emailException->getMessage()) !== ''
                        ? trim($emailException->getMessage())
                        : 'Erro ao preparar envio de email.';
                    $emailLongaEnviado = false;
                }
                if ($emailLongaEnviado) {
                    $mensagem .= ' Email de confirmação enviado para o aluno.';
                } else {
                    $mensagem .= ' Email de confirmação não enviado.';
                    if ($erroEmailLonga !== null && trim($erroEmailLonga) !== '') {
                        $mensagem .= ' Motivo: ' . trim($erroEmailLonga);
                    }
                }

                util_set_flash('sucesso', $mensagem);
                util_redirect(util_url('dashboard/emprestimos/create.php?tipo=longa_duracao'));
            }

            if ($tipoPost === 'turma') {
                $formTurma['turma_id'] = trim((string) ($_POST['turma_id'] ?? ''));
                $formTurma['data_inicio'] = trim((string) ($_POST['data_inicio'] ?? ''));
                $formTurma['data_fim'] = trim((string) ($_POST['data_fim'] ?? ''));
                $alunosTurmaSelecionados = array_values((array) ($_POST['aluno_ids'] ?? []));

                $turmaId = filter_var($formTurma['turma_id'], FILTER_VALIDATE_INT);

                if ($turmaId === false) {
                    throw new RuntimeException('Selecione uma turma válida.');
                }

                $resumo = $repo->criarEmprestimosPorTurma(
                    (int) $turmaId,
                    $formTurma['data_inicio'],
                    $formTurma['data_fim'],
                    $alunosTurmaSelecionados,
                    $userId
                );

                $emprestimoIds = [];
                foreach ((array) ($resumo['atribuicoes'] ?? []) as $atribuicao) {
                    $idAtribuido = (int) ($atribuicao['emprestimo_id'] ?? 0);
                    if ($idAtribuido > 0) {
                        $emprestimoIds[] = $idAtribuido;
                    }
                }
                app_log_registar($pdo, 'Empréstimo criado', [
                    'tipo' => 'turma',
                    'turma_id' => (int) $turmaId,
                    'turma_nome' => (string) ($resumo['turma_nome'] ?? ''),
                    'quantidade' => (int) ($resumo['quantidade'] ?? 0),
                    'emprestimo_ids' => $emprestimoIds,
                ]);

                $_SESSION['emprestimo_turma_resumo'] = $resumo;
                util_set_flash(
                    'sucesso',
                    'Empréstimos por turma criados com sucesso. Total de portáteis atribuídos: ' . (int) $resumo['quantidade'] . '.'
                );
                util_redirect(util_url('dashboard/emprestimos/create.php?tipo=turma&turma_id=' . (int) $turmaId));
            }
        } catch (Throwable $e) {
            $erroForm = trim($e->getMessage()) !== '' ? $e->getMessage() : 'Erro ao criar empréstimo.';
        }
    }
}

if ($tipoSelecionado === 'turma') {
    $turmaSelecionada = filter_var($formTurma['turma_id'], FILTER_VALIDATE_INT);
    if (is_int($turmaSelecionada) && $turmaSelecionada > 0) {
        try {
            $alunosTurma = $repo->listarAlunosDaTurma($turmaSelecionada);
        } catch (Throwable $e) {
            if ($erroForm === null) {
                $erroForm = 'Não foi possível carregar os alunos da turma selecionada.';
            }
        }
    }
}

$amanha = date('Y-m-d', strtotime('+1 day'));

util_render_layout_start('Criar Empréstimo', 'emprestimos');
?>
<div class="detail-head">
  <div class="detail-actions">
    <a class="btn btn-secondary" href="index.php">Voltar</a>
    <a class="btn btn-secondary" href="receive.php">Receber item</a>
  </div>
</div>

<div class="module-tabs">
  <a href="create.php?tipo=aluno" <?php echo $tipoSelecionado === 'aluno' ? 'class="active"' : ''; ?>>Por aluno</a>
  <a href="create.php?tipo=turma" <?php echo $tipoSelecionado === 'turma' ? 'class="active"' : ''; ?>>Por turma</a>
  <a href="create.php?tipo=longa_duracao" <?php echo $tipoSelecionado === 'longa_duracao' ? 'class="active"' : ''; ?>>Longa duração</a>
</div>

<?php if ($erroForm !== null): ?>
  <div class="msg msg-error"><?php echo util_e($erroForm); ?></div>
<?php endif; ?>

<?php if ($tipoSelecionado === 'aluno'): ?>
  <section class="panel panel-form">
    <form method="post" id="form-emprestimo-aluno">
      <?php echo util_csrf_field(); ?>
      <input type="hidden" name="tipo" value="aluno">

      <div class="form-grid">
        <label for="aluno_nome_aluno">
          Aluno *
          <input
            type="text"
            id="aluno_nome_aluno"
            name="aluno_nome"
            list="lista_alunos_aluno"
            placeholder="Pesquisar aluno por nome..."
            value="<?php echo util_e($formAluno['aluno_nome']); ?>"
            autocomplete="off"
            required
          >
          <datalist id="lista_alunos_aluno"></datalist>
          <input type="hidden" id="aluno_email_aluno" name="aluno_email" value="<?php echo util_e($formAluno['aluno_email']); ?>">
        </label>

        <label for="aluno_vagas_aluno">
          Empréstimos restantes
          <input type="text" id="aluno_vagas_aluno" value="" readonly>
        </label>
      </div>

      <div id="resto_form_aluno" hidden>
        <div class="form-grid">
          <label for="item_nome_aluno">
            Item *
            <input
              type="text"
              id="item_nome_aluno"
              name="item_nome"
              list="lista_itens_aluno"
              placeholder="Pesquisar equipamento por nome..."
              value="<?php echo util_e($formAluno['item_nome']); ?>"
              autocomplete="off"
              required
              disabled
            >
            <datalist id="lista_itens_aluno"></datalist>
            <input type="hidden" id="item_codigo_aluno" name="item_codigo" value="<?php echo util_e($formAluno['item_codigo']); ?>">
          </label>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" id="submit_aluno" type="submit" disabled>Confirmar empréstimo</button>
        </div>
      </div>
    </form>
  </section>
<?php endif; ?>

<?php if ($tipoSelecionado === 'longa_duracao'): ?>
  <section class="panel panel-form">
    <form method="post" id="form-emprestimo-longa">
      <?php echo util_csrf_field(); ?>
      <input type="hidden" name="tipo" value="longa_duracao">

      <div class="form-grid">
        <label for="aluno_nome_longa">
          Aluno *
          <input
            type="text"
            id="aluno_nome_longa"
            name="aluno_nome"
            list="lista_alunos_longa"
            placeholder="Pesquisar aluno por nome..."
            value="<?php echo util_e($formLonga['aluno_nome']); ?>"
            autocomplete="off"
            required
          >
          <datalist id="lista_alunos_longa"></datalist>
          <input type="hidden" id="aluno_email_longa" name="aluno_email" value="<?php echo util_e($formLonga['aluno_email']); ?>">
        </label>

        <label for="aluno_vagas_longa">
          Empréstimos restantes
          <input type="text" id="aluno_vagas_longa" value="" readonly>
        </label>
      </div>

      <div id="resto_form_longa" hidden>
        <div class="form-grid">
          <label for="item_nome_longa">
            Item *
            <input
              type="text"
              id="item_nome_longa"
              name="item_nome"
              list="lista_itens_longa"
              placeholder="Pesquisar equipamento por nome..."
              value="<?php echo util_e($formLonga['item_nome']); ?>"
              autocomplete="off"
              required
              disabled
            >
            <datalist id="lista_itens_longa"></datalist>
            <input type="hidden" id="item_codigo_longa" name="item_codigo" value="<?php echo util_e($formLonga['item_codigo']); ?>">
          </label>

          <label for="data_prevista_longa">
            Data prevista de devolução *
            <input
              type="date"
              id="data_prevista_longa"
              name="data_prevista"
              min="<?php echo util_e($amanha); ?>"
              value="<?php echo util_e($formLonga['data_prevista']); ?>"
              required
              disabled
            >
          </label>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" id="submit_longa" type="submit" disabled>Confirmar empréstimo</button>
        </div>
      </div>
    </form>
  </section>
<?php endif; ?>

<?php if ($tipoSelecionado === 'turma'): ?>
  <section class="panel panel-form">
    <form method="post" id="form-emprestimo-turma">
      <?php echo util_csrf_field(); ?>
      <input type="hidden" name="tipo" value="turma">

      <div class="form-grid">
        <label for="turma_id_turma">
          Turma *
          <select id="turma_id_turma" name="turma_id" required onchange="atualizarTurmaSelecionada(this.value)">
            <option value="">Selecionar turma...</option>
            <?php foreach ($turmas as $turma): ?>
              <?php $turmaId = (string) $turma['id']; ?>
              <option value="<?php echo util_e($turmaId); ?>" <?php echo $formTurma['turma_id'] === $turmaId ? 'selected' : ''; ?>>
                <?php echo util_e($turma['nome']); ?> (<?php echo (int) $turma['total_alunos']; ?> aluno(s))
              </option>
            <?php endforeach; ?>
          </select>
        </label>

      </div>



      <?php if ($formTurma['turma_id'] === ''): ?>
        <p class="table-note">Selecione uma turma para listar os alunos e poder marcar quem recebe empréstimo.</p>
      <?php else: ?>
        <section class="panel panel-spaced">
          <div class="detail-head detail-head-compact">
            <div>
              <h2 class="section-heading-small">Alunos da Turma</h2>
              <p class="table-note">Marque todos ou apenas os alunos que quer incluir neste empréstimo.</p>
            </div>
            <div class="detail-actions">
              <button class="btn btn-secondary btn-small" type="button" id="btn-marcar-todos-turma">Marcar todos elegíveis</button>
              <button class="btn btn-secondary btn-small" type="button" id="btn-limpar-marcacoes-turma">Limpar marcações</button>
            </div>
          </div>

          <table class="table">
            <thead>
              <tr>
                <th class="table-col-select">Marcar</th>
                <th>Aluno</th>
                <th>Email</th>
                <th>Ativos</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($alunosTurma === []): ?>
                <tr>
                  <td colspan="5" class="empty">Sem alunos para esta turma.</td>
                </tr>
              <?php endif; ?>

              <?php foreach ($alunosTurma as $alunoTurma): ?>
                <?php
                  $alunoIdTurma = (int) ($alunoTurma['id'] ?? 0);
                  $elegivelTurma = (bool) ($alunoTurma['elegivel_turma'] ?? false);
                  $checkedTurma = in_array((string) $alunoIdTurma, array_map('strval', $alunosTurmaSelecionados), true);
                  $ativosTotal = (int) ($alunoTurma['ativos_total'] ?? 0);
                  $ativosMaquina = (int) ($alunoTurma['ativos_maquina'] ?? 0);
                  $ativosPeriferico = (int) ($alunoTurma['ativos_periferico'] ?? 0);
                ?>
                <tr>
                  <td>
                    <input
                      type="checkbox"
                      class="js-aluno-turma-check"
                      name="aluno_ids[]"
                      value="<?php echo $alunoIdTurma; ?>"
                      <?php echo $checkedTurma ? 'checked' : ''; ?>
                      <?php echo $elegivelTurma ? '' : 'disabled'; ?>
                    >
                  </td>
                  <td><?php echo util_e((string) ($alunoTurma['nome'] ?? '')); ?></td>
                  <td><?php echo util_e((string) ($alunoTurma['email'] ?? '')); ?></td>
                  <td><?php echo $ativosTotal; ?> (M: <?php echo $ativosMaquina; ?> / P: <?php echo $ativosPeriferico; ?>)</td>
                  <td>
                    <?php if ($elegivelTurma): ?>
                      <span class="badge badge-super">Elegível</span>
                    <?php else: ?>
                      <span class="badge badge-admin">Não Elegível</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php endif; ?>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Criar empréstimos da turma</button>
      </div>
    </form>
  </section>

  <?php if (is_array($resumoTurma) && isset($resumoTurma['atribuicoes']) && is_array($resumoTurma['atribuicoes'])): ?>
    <section class="panel">
      <h2 class="section-title">Resumo de atribuição - <?php echo util_e((string) ($resumoTurma['turma_nome'] ?? 'Turma')); ?></h2>

      <table class="table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Nome do item</th>
            <th>Aluno</th>
            <th>Email</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$resumoTurma['atribuicoes']): ?>
            <tr>
              <td colspan="4" class="empty">Sem atribuições para mostrar.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($resumoTurma['atribuicoes'] as $linha): ?>
            <tr>
              <td><?php echo util_e((string) $linha['item_codigo']); ?></td>
              <td><?php echo util_e((string) $linha['item_nome']); ?></td>
              <td><?php echo util_e((string) $linha['aluno_nome']); ?></td>
              <td><?php echo util_e((string) $linha['aluno_email']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>
<?php endif; ?>

<script>
const TYPEAHEAD_ENDPOINT = 'typeahead.php';

function normalizeText(raw) {
  return String(raw || '').trim().toLowerCase();
}

function toInt(raw) {
  const number = Number.parseInt(String(raw ?? ''), 10);
  return Number.isFinite(number) ? number : 0;
}

function debounce(fn, waitMs) {
  let timerId = null;
  return (...args) => {
    if (timerId !== null) {
      window.clearTimeout(timerId);
    }
    timerId = window.setTimeout(() => {
      timerId = null;
      fn(...args);
    }, waitMs);
  };
}

async function fetchTypeahead(params) {
  const url = new URL(TYPEAHEAD_ENDPOINT, window.location.href);
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined) {
      url.searchParams.set(key, String(value));
    }
  }

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: {
      Accept: 'application/json',
    },
    credentials: 'same-origin',
  });

  let payload = null;
  try {
    payload = await response.json();
  } catch (_error) {
    return { ok: false, dados: [] };
  }

  if (!payload || typeof payload !== 'object') {
    return { ok: false, dados: [] };
  }

  return payload;
}

function setSectionEnabled(container, enabled) {
  if (!container) {
    return;
  }

  const controls = container.querySelectorAll('input, select, textarea, button');
  for (const control of controls) {
    control.disabled = !enabled;
  }
}

function preencherDatalistAlunos(listEl, alunos) {
  if (!listEl) {
    return;
  }

  const fragment = document.createDocumentFragment();
  for (const aluno of alunos) {
    const optionEl = document.createElement('option');
    optionEl.value = String(aluno.nome || '').trim();
    fragment.appendChild(optionEl);
  }

  listEl.innerHTML = '';
  listEl.appendChild(fragment);
}

function preencherDatalistItens(listEl, itens) {
  if (!listEl) {
    return;
  }

  const fragment = document.createDocumentFragment();
  for (const item of itens) {
    const optionEl = document.createElement('option');
    optionEl.value = String(item.nome || '').trim();
    optionEl.label = String(item.codigo || '').trim().toUpperCase();
    fragment.appendChild(optionEl);
  }

  listEl.innerHTML = '';
  listEl.appendChild(fragment);
}

function bindEmprestimoForm(config) {
  const alunoInput = document.getElementById(config.alunoInputId);
  const alunoList = document.getElementById(config.alunoListId);
  const alunoEmailInput = document.getElementById(config.alunoEmailId);
  const vagasInput = document.getElementById(config.vagasId);
  const restoContainer = document.getElementById(config.restoId);
  const itemInput = document.getElementById(config.itemInputId);
  const itemList = document.getElementById(config.itemListId);
  const itemCodigoInput = document.getElementById(config.itemCodigoId);

  if (
    !alunoInput
    || !alunoList
    || !alunoEmailInput
    || !vagasInput
    || !restoContainer
    || !itemInput
    || !itemList
    || !itemCodigoInput
  ) {
    return;
  }

  let sugestoesAlunos = [];
  let sugestoesItens = [];
  let buscaAlunoSequencia = 0;
  let buscaItemSequencia = 0;

  const itemNomeInicial = String(itemInput.value || '').trim();
  const itemCodigoInicial = String(itemCodigoInput.value || '').trim().toUpperCase();
  let shouldPreservePrefill = itemNomeInicial !== '' || itemCodigoInicial !== '';

  const limparItens = (limparTexto = true) => {
    sugestoesItens = [];
    preencherDatalistItens(itemList, []);
    itemCodigoInput.value = '';
    if (limparTexto) {
      itemInput.value = '';
    }
  };

  const limparPermissaoAluno = () => {
    alunoEmailInput.value = '';
    vagasInput.value = '';
    restoContainer.hidden = true;
    setSectionEnabled(restoContainer, false);
    limparItens(true);
  };

  const pesquisarAlunos = debounce(async () => {
    const termo = String(alunoInput.value || '').trim();
    if (termo.length < 2) {
      sugestoesAlunos = [];
      preencherDatalistAlunos(alunoList, []);
      return;
    }

    const tokenAtual = ++buscaAlunoSequencia;
    const response = await fetchTypeahead({
      acao: 'alunos',
      q: termo,
    });
    if (tokenAtual !== buscaAlunoSequencia) {
      return;
    }

    if (!response.ok || !Array.isArray(response.dados)) {
      sugestoesAlunos = [];
      preencherDatalistAlunos(alunoList, []);
      return;
    }

    sugestoesAlunos = response.dados;
    preencherDatalistAlunos(alunoList, sugestoesAlunos);
  }, 250);

  const encontrarAlunoPorNome = (nome) => {
    const nomeNormalizado = normalizeText(nome);
    if (nomeNormalizado === '') {
      return null;
    }

    const correspondencias = sugestoesAlunos.filter((aluno) =>
      normalizeText(aluno.nome) === nomeNormalizado
    );

    if (correspondencias.length === 0) {
      return null;
    }

    return correspondencias[0];
  };

  const pesquisarItens = debounce(async () => {
    const termo = String(itemInput.value || '').trim();
    const alunoEmail = normalizeText(alunoEmailInput.value);
    if (alunoEmail === '') {
      sugestoesItens = [];
      preencherDatalistItens(itemList, []);
      return;
    }
    if (termo.length < 2) {
      sugestoesItens = [];
      preencherDatalistItens(itemList, []);
      return;
    }

    const tokenAtual = ++buscaItemSequencia;
    const response = await fetchTypeahead({
      acao: 'itens',
      aluno_email: alunoEmailInput.value,
      q: termo,
    });
    if (tokenAtual !== buscaItemSequencia) {
      return;
    }

    if (!response.ok || !Array.isArray(response.dados)) {
      sugestoesItens = [];
      preencherDatalistItens(itemList, []);
      return;
    }

    sugestoesItens = response.dados;
    preencherDatalistItens(itemList, sugestoesItens);
  }, 250);

  const encontrarItemPorTexto = (texto) => {
    const termo = normalizeText(texto);
    if (termo === '') {
      return null;
    }

    const correspondencias = sugestoesItens.filter((item) =>
      normalizeText(item.nome) === termo
      || normalizeText(item.codigo) === termo
    );

    if (correspondencias.length === 0) {
      return null;
    }

    return correspondencias[0];
  };

  const tentarSelecionarItem = async () => {
    const textoDigitado = String(itemInput.value || '').trim();
    if (textoDigitado === '') {
      itemCodigoInput.value = '';
      return;
    }

    let item = encontrarItemPorTexto(textoDigitado);
    if (!item) {
      const response = await fetchTypeahead({
        acao: 'itens',
        aluno_email: alunoEmailInput.value,
        q: textoDigitado,
      });
      if (response.ok && Array.isArray(response.dados)) {
        sugestoesItens = response.dados;
        preencherDatalistItens(itemList, sugestoesItens);
        item = encontrarItemPorTexto(textoDigitado);
      }
    }

    if (!item) {
      itemCodigoInput.value = '';
      return;
    }

    itemInput.value = String(item.nome || '').trim();
    itemCodigoInput.value = String(item.codigo || '').trim().toUpperCase();
  };

  const aplicarAlunoSelecionado = async (aluno, preservarItem = false) => {
    alunoInput.value = String(aluno.nome || '').trim();
    alunoEmailInput.value = String(aluno.email || '').trim();

    const restantes = toInt(aluno.restantes);
    const pode = Boolean(aluno.pode) && restantes > 0;
    vagasInput.value = String(restantes);

    if (!pode) {
      restoContainer.hidden = true;
      setSectionEnabled(restoContainer, false);
      limparItens(true);
      return;
    }

    restoContainer.hidden = false;
    setSectionEnabled(restoContainer, true);

    if (!preservarItem) {
      limparItens(true);
      return;
    }

    if (itemNomeInicial !== '') {
      itemInput.value = itemNomeInicial;
      await tentarSelecionarItem();
      return;
    }

    if (itemCodigoInicial !== '') {
      itemInput.value = itemCodigoInicial;
      await tentarSelecionarItem();
      return;
    }

    limparItens(true);
  };

  const tentarSelecionarAluno = async () => {
    const nomeDigitado = String(alunoInput.value || '').trim();
    if (nomeDigitado === '') {
      limparPermissaoAluno();
      return;
    }

    let aluno = encontrarAlunoPorNome(nomeDigitado);
    if (!aluno) {
      const response = await fetchTypeahead({
        acao: 'alunos',
        q: nomeDigitado,
      });
      if (response.ok && Array.isArray(response.dados)) {
        sugestoesAlunos = response.dados;
        preencherDatalistAlunos(alunoList, sugestoesAlunos);
        aluno = encontrarAlunoPorNome(nomeDigitado);
      }
    }

    if (!aluno) {
      limparPermissaoAluno();
      return;
    }

    await aplicarAlunoSelecionado(aluno, shouldPreservePrefill);
    shouldPreservePrefill = false;
  };

  const carregarAlunoInicial = async () => {
    const emailInicial = normalizeText(alunoEmailInput.value);
    if (emailInicial === '') {
      limparPermissaoAluno();
      return;
    }

    const response = await fetchTypeahead({
      acao: 'aluno',
      aluno_email: emailInicial,
    });
    if (!response.ok || !response.dados || typeof response.dados !== 'object') {
      limparPermissaoAluno();
      return;
    }

    await aplicarAlunoSelecionado(response.dados, true);
  };

  alunoInput.addEventListener('input', () => {
    limparPermissaoAluno();
    pesquisarAlunos();
  });
  alunoInput.addEventListener('change', () => {
    void tentarSelecionarAluno();
  });
  alunoInput.addEventListener('blur', () => {
    window.setTimeout(() => {
      void tentarSelecionarAluno();
    }, 120);
  });

  itemInput.addEventListener('input', () => {
    itemCodigoInput.value = '';
    pesquisarItens();
  });
  itemInput.addEventListener('change', () => {
    void tentarSelecionarItem();
  });
  itemInput.addEventListener('blur', () => {
    window.setTimeout(() => {
      void tentarSelecionarItem();
    }, 120);
  });

  void carregarAlunoInicial();
}

function atualizarTurmaSelecionada(turmaId) {
  const url = new URL(window.location.href);
  url.searchParams.set('tipo', 'turma');
  if (String(turmaId || '').trim() === '') {
    url.searchParams.delete('turma_id');
  } else {
    url.searchParams.set('turma_id', String(turmaId));
  }
  window.location.href = url.toString();
}

function initTurmaChecklist() {
  const btnMarcarTodos = document.getElementById('btn-marcar-todos-turma');
  const btnLimparMarcacoes = document.getElementById('btn-limpar-marcacoes-turma');
  if (!btnMarcarTodos || !btnLimparMarcacoes) {
    return;
  }

  btnMarcarTodos.addEventListener('click', () => {
    const checks = document.querySelectorAll('.js-aluno-turma-check');
    for (const check of checks) {
      if (!check.disabled) {
        check.checked = true;
      }
    }
  });

  btnLimparMarcacoes.addEventListener('click', () => {
    const checks = document.querySelectorAll('.js-aluno-turma-check');
    for (const check of checks) {
      check.checked = false;
    }
  });
}

initTurmaChecklist();

bindEmprestimoForm({
  alunoInputId: 'aluno_nome_aluno',
  alunoListId: 'lista_alunos_aluno',
  alunoEmailId: 'aluno_email_aluno',
  vagasId: 'aluno_vagas_aluno',
  restoId: 'resto_form_aluno',
  itemInputId: 'item_nome_aluno',
  itemListId: 'lista_itens_aluno',
  itemCodigoId: 'item_codigo_aluno',
});

bindEmprestimoForm({
  alunoInputId: 'aluno_nome_longa',
  alunoListId: 'lista_alunos_longa',
  alunoEmailId: 'aluno_email_longa',
  vagasId: 'aluno_vagas_longa',
  restoId: 'resto_form_longa',
  itemInputId: 'item_nome_longa',
  itemListId: 'lista_itens_longa',
  itemCodigoId: 'item_codigo_longa',
});
</script>
<?php util_render_layout_end(); ?>
