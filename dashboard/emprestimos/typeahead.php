<?php
declare(strict_types=1);

require_once __DIR__ . '/../utilizadores/shared/common.php';
require_once __DIR__ . '/../../model/repositories/emprestimo_repository.php';

util_require_section_access($pdo, 'emprestimos');

header('Content-Type: application/json; charset=UTF-8');

$repo = new EmprestimoRepository($pdo);

/**
 * @param array<string, mixed> $payload
 */
function ta_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * @param array<string, mixed> $aluno
 * @return array{restantes:int,pode:bool,pode_maquina:bool,pode_periferico:bool}
 */
function ta_calcular_permissao(array $aluno): array
{
    $ativosTotal = (int) ($aluno['ativos_total'] ?? 0);
    $ativosMaquina = (int) ($aluno['ativos_maquina'] ?? 0);
    $ativosPeriferico = (int) ($aluno['ativos_periferico'] ?? 0);

    $podeMaquina = $ativosMaquina < 1;
    $podePeriferico = $ativosPeriferico < 1;

    $porCategoria = ($podeMaquina ? 1 : 0) + ($podePeriferico ? 1 : 0);
    $porTotal = max(0, 2 - $ativosTotal);
    $restantes = min($porCategoria, $porTotal);
    $pode = $restantes > 0;

    if (!$pode) {
        $podeMaquina = false;
        $podePeriferico = false;
    }

    return [
        'restantes' => $restantes,
        'pode' => $pode,
        'pode_maquina' => $podeMaquina,
        'pode_periferico' => $podePeriferico,
    ];
}

/**
 * @param array<string, mixed> $aluno
 * @return array{nome:string,email:string,restantes:int,pode:bool,pode_maquina:bool,pode_periferico:bool}
 */
function ta_payload_aluno(array $aluno): array
{
    $permissao = ta_calcular_permissao($aluno);

    return [
        'nome' => trim((string) ($aluno['nome'] ?? '')),
        'email' => trim((string) ($aluno['email'] ?? '')),
        'restantes' => $permissao['restantes'],
        'pode' => $permissao['pode'],
        'pode_maquina' => $permissao['pode_maquina'],
        'pode_periferico' => $permissao['pode_periferico'],
    ];
}

try {
    $acao = trim((string) ($_GET['acao'] ?? ''));
    $alunos = $repo->listarAlunosLookup();

    if ($acao === 'alunos') {
        $termo = trim((string) ($_GET['q'] ?? ''));
        if (strlen($termo) < 2) {
            ta_json(['ok' => true, 'dados' => []]);
        }

        $resultado = [];
        foreach ($alunos as $aluno) {
            $nome = trim((string) ($aluno['nome'] ?? ''));
            $email = trim((string) ($aluno['email'] ?? ''));
            if (
                stripos($nome, $termo) === false
                && stripos($email, $termo) === false
            ) {
                continue;
            }

            $resultado[] = ta_payload_aluno($aluno);
            if (count($resultado) >= 15) {
                break;
            }
        }

        ta_json(['ok' => true, 'dados' => $resultado]);
    }

    if ($acao === 'aluno') {
        $emailPesquisa = strtolower(trim((string) ($_GET['aluno_email'] ?? '')));
        if ($emailPesquisa === '') {
            ta_json(['ok' => false, 'erro' => 'Aluno inválido.'], 400);
        }

        foreach ($alunos as $aluno) {
            $emailAluno = strtolower(trim((string) ($aluno['email'] ?? '')));
            if ($emailAluno === $emailPesquisa) {
                ta_json(['ok' => true, 'dados' => ta_payload_aluno($aluno)]);
            }
        }

        ta_json(['ok' => false, 'erro' => 'Aluno não encontrado.'], 404);
    }

    if ($acao === 'itens') {
        $emailPesquisa = strtolower(trim((string) ($_GET['aluno_email'] ?? '')));
        $termoPesquisa = trim((string) ($_GET['q'] ?? ''));
        if ($emailPesquisa === '') {
            ta_json(['ok' => true, 'dados' => []]);
        }

        $alunoEncontrado = null;
        foreach ($alunos as $aluno) {
            $emailAluno = strtolower(trim((string) ($aluno['email'] ?? '')));
            if ($emailAluno === $emailPesquisa) {
                $alunoEncontrado = $aluno;
                break;
            }
        }

        if (!is_array($alunoEncontrado)) {
            ta_json(['ok' => true, 'dados' => []]);
        }

        $permissao = ta_calcular_permissao($alunoEncontrado);
        if (!$permissao['pode']) {
            ta_json(['ok' => true, 'dados' => []]);
        }

        $materiais = $repo->listarMateriaisLookup();
        $itens = [];
        foreach ($materiais as $item) {
            $categoria = trim((string) ($item['categoria'] ?? ''));
            if ($categoria !== 'maquina' && $categoria !== 'periferico') {
                continue;
            }

            $disponivel = (bool) ($item['disponivel'] ?? false);
            $avariado = (bool) ($item['avariado'] ?? false);
            $abate = (bool) ($item['abate'] ?? false);
            if (!$disponivel || $avariado || $abate) {
                continue;
            }

            if ($categoria === 'maquina' && !$permissao['pode_maquina']) {
                continue;
            }
            if ($categoria === 'periferico' && !$permissao['pode_periferico']) {
                continue;
            }

            $itens[] = [
                'codigo' => trim((string) ($item['codigo'] ?? '')),
                'nome' => trim((string) ($item['nome'] ?? '')),
                'categoria' => $categoria,
            ];
        }

        if ($termoPesquisa !== '') {
            $itens = array_values(
                array_filter(
                    $itens,
                    static function (array $item) use ($termoPesquisa): bool {
                        $nome = trim((string) ($item['nome'] ?? ''));
                        $codigo = trim((string) ($item['codigo'] ?? ''));
                        return stripos($nome, $termoPesquisa) !== false
                            || stripos($codigo, $termoPesquisa) !== false;
                    }
                )
            );
        }

        usort(
            $itens,
            static function (array $a, array $b): int {
                $labelA = $a['nome'] . '|' . $a['codigo'];
                $labelB = $b['nome'] . '|' . $b['codigo'];
                return strcmp($labelA, $labelB);
            }
        );

        if (count($itens) > 20) {
            $itens = array_slice($itens, 0, 20);
        }

        ta_json(['ok' => true, 'dados' => $itens]);
    }

    ta_json(['ok' => false, 'erro' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    ta_json(['ok' => false, 'erro' => 'Falha ao processar o pedido.'], 500);
}
