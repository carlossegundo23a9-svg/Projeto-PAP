<?php
declare(strict_types=1);

final class EmprestimoRepository
{
    private const DATE_SEM_PRAZO = '9999-12-31';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureMaterialSchema();
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   nome:string,
     *   email:string,
     *   ativos_total:int,
     *   ativos_maquina:int,
     *   ativos_periferico:int,
     *   itens_ativos:array<int, array{codigo:string,nome:string,categoria:string}>
     * }>
     */
    public function listarAlunosLookup(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, nome, email
            FROM cliente
            WHERE email IS NOT NULL
              AND email <> ''
            ORDER BY nome ASC
        ");
        $alunos = $stmt->fetchAll();

        $stmtAtivos = $this->pdo->query("
            SELECT
                e.cliente_id AS aluno_id,
                m.id AS material_id,
                m.nome AS material_nome,
                CASE
                    WHEN ma.id IS NOT NULL THEN 'maquina'
                    WHEN p.id IS NOT NULL THEN 'periferico'
                    ELSE 'outro'
                END AS categoria
            FROM emprestimo e
            INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
            INNER JOIN material m ON m.id = em.material_id
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN periferico p ON p.id = m.id
            WHERE (e.data_fim IS NULL OR e.data_fim > NOW())
            ORDER BY e.cliente_id ASC, e.id DESC
        ");
        $ativosRows = $stmtAtivos->fetchAll();

        $resumoPorAluno = [];
        foreach ($ativosRows as $row) {
            $alunoId = (int) ($row['aluno_id'] ?? 0);
            if ($alunoId <= 0) {
                continue;
            }

            if (!isset($resumoPorAluno[$alunoId])) {
                $resumoPorAluno[$alunoId] = [
                    'ativos_total' => 0,
                    'ativos_maquina' => 0,
                    'ativos_periferico' => 0,
                    'itens_ativos' => [],
                ];
            }

            $categoria = (string) ($row['categoria'] ?? 'outro');
            $resumoPorAluno[$alunoId]['ativos_total']++;
            if ($categoria === 'maquina') {
                $resumoPorAluno[$alunoId]['ativos_maquina']++;
            } elseif ($categoria === 'periferico') {
                $resumoPorAluno[$alunoId]['ativos_periferico']++;
            }

            $materialId = (int) ($row['material_id'] ?? 0);
            $resumoPorAluno[$alunoId]['itens_ativos'][] = [
                'codigo' => self::formatCodigoMaterial($materialId),
                'nome' => (string) ($row['material_nome'] ?? ''),
                'categoria' => $categoria,
            ];
        }

        $result = [];
        foreach ($alunos as $aluno) {
            $alunoId = (int) ($aluno['id'] ?? 0);
            $resumo = $resumoPorAluno[$alunoId] ?? [
                'ativos_total' => 0,
                'ativos_maquina' => 0,
                'ativos_periferico' => 0,
                'itens_ativos' => [],
            ];

            $result[] = [
                'id' => $alunoId,
                'nome' => (string) ($aluno['nome'] ?? ''),
                'email' => (string) ($aluno['email'] ?? ''),
                'ativos_total' => (int) $resumo['ativos_total'],
                'ativos_maquina' => (int) $resumo['ativos_maquina'],
                'ativos_periferico' => (int) $resumo['ativos_periferico'],
                'itens_ativos' => $resumo['itens_ativos'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   codigo:string,
     *   nome:string,
     *   categoria:string,
     *   disponivel:bool,
     *   avariado:bool,
     *   abate:bool,
     *   estado:string
     * }>
     */
    public function listarMateriaisLookup(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                m.id,
                m.nome,
                m.disponibilidade,
                m.isBroken,
                m.isAbate,
                CASE
                    WHEN ma.id IS NOT NULL THEN 'maquina'
                    WHEN p.id IS NOT NULL THEN 'periferico'
                    ELSE 'outro'
                END AS categoria
            FROM material m
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN periferico p ON p.id = m.id
            ORDER BY m.id ASC
        ");

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result[] = [
                'id' => $id,
                'codigo' => self::formatCodigoMaterial($id),
                'nome' => (string) $row['nome'],
                'categoria' => (string) ($row['categoria'] ?? 'outro'),
                'disponivel' => (int) $row['disponibilidade'] === 1,
                'avariado' => (int) $row['isBroken'] === 1,
                'abate' => (int) ($row['isAbate'] ?? 0) === 1,
                'estado' => ((int) ($row['isAbate'] ?? 0) === 1)
                    ? 'abate'
                    : (((int) $row['isBroken'] === 1) ? 'avariado' : 'em_uso'),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{id:int,nome:string,total_alunos:int}>
     */
    public function listarTurmasAtivasComTotais(): array
    {
        $stmt = $this->pdo->query("
            SELECT t.id, t.nome, COUNT(ta.id) AS total_alunos
            FROM turma t
            LEFT JOIN turma_aluno ta ON ta.turma_id = t.id
            WHERE t.ativa = 1
            GROUP BY t.id, t.nome
            ORDER BY t.nome ASC
        ");

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'nome' => (string) $row['nome'],
                'total_alunos' => (int) $row['total_alunos'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   nome:string,
     *   email:string,
     *   ativos_total:int,
     *   ativos_maquina:int,
     *   ativos_periferico:int,
     *   elegivel_turma:bool
     * }>
     */
    public function listarAlunosDaTurma(int $turmaId): array
    {
        if ($turmaId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT c.id, c.nome, c.email
            FROM turma_aluno ta
            INNER JOIN cliente c ON c.id = ta.aluno_id
            WHERE ta.turma_id = :turma_id
            ORDER BY c.nome ASC
        ");
        $stmt->execute(['turma_id' => $turmaId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $alunoId = (int) ($row['id'] ?? 0);
            $resumo = $this->resumoEmprestimosAtivosAluno($alunoId);
            $elegivel = $resumo['maquina'] < 1 && $resumo['total'] < 2;

            $result[] = [
                'id' => $alunoId,
                'nome' => (string) ($row['nome'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'ativos_total' => (int) $resumo['total'],
                'ativos_maquina' => (int) $resumo['maquina'],
                'ativos_periferico' => (int) $resumo['periferico'],
                'elegivel_turma' => $elegivel,
            ];
        }

        return $result;
    }

    /**
     * @return array{emprestimo_id:int,item_codigo:string,item_nome:string,aluno_nome:string}
     */
    public function criarEmprestimoPorAluno(string $itemCodigo, string $alunoEmail, int $userId): array
    {
        return $this->criarEmprestimoUnitario(
            $itemCodigo,
            $alunoEmail,
            $userId,
            date('Y-m-d'),
            self::DATE_SEM_PRAZO,
            ['tipo' => 'aluno']
        );
    }

    /**
     * @return array{emprestimo_id:int,item_codigo:string,item_nome:string,aluno_nome:string}
     */
    public function criarEmprestimoLongaDuracao(
        string $itemCodigo,
        string $alunoEmail,
        string $dataPrevista,
        int $userId
    ): array {
        if (!$this->isDateYmd($dataPrevista)) {
            throw new RuntimeException('Data prevista inválida. Use o formato YYYY-MM-DD.');
        }

        $hoje = date('Y-m-d');
        if ($dataPrevista <= $hoje) {
            throw new RuntimeException('A data prevista de devolução deve ser superior a hoje.');
        }

        return $this->criarEmprestimoUnitario(
            $itemCodigo,
            $alunoEmail,
            $userId,
            $hoje,
            $dataPrevista,
            ['tipo' => 'longa_duracao']
        );
    }

    /**
     * @return array{
     *   turma_nome:string,
     *   quantidade:int,
     *   atribuicoes:array<int, array{
     *     emprestimo_id:int,
     *     item_codigo:string,
     *     item_nome:string,
     *     aluno_nome:string,
     *     aluno_email:string
     *   }>
     * }
     */
    public function criarEmprestimosPorTurma(
        int $turmaId,
        ?string $dataInicio,
        ?string $dataFim,
        array $alunoIds,
        int $userId
    ): array {
        if ($turmaId <= 0) {
            throw new RuntimeException('Selecione uma turma válida.');
        }
        $dataInicio = trim((string) $dataInicio);
        $dataFim = trim((string) $dataFim);
        if ($dataInicio === '') {
            $dataInicio = date('Y-m-d');
        }
        if ($dataFim === '') {
            $dataFim = self::DATE_SEM_PRAZO;
        }
        if (!$this->isDateYmd($dataInicio) || !$this->isDateYmd($dataFim)) {
            throw new RuntimeException('Datas inválidas. Use o formato YYYY-MM-DD.');
        }
        if ($dataFim !== self::DATE_SEM_PRAZO && $dataFim < $dataInicio) {
            throw new RuntimeException('A data fim deve ser igual ou superior à data de início.');
        }

        $selectedIdsMap = [];
        foreach ($alunoIds as $rawAlunoId) {
            $alunoId = filter_var($rawAlunoId, FILTER_VALIDATE_INT);
            if ($alunoId !== false && $alunoId > 0) {
                $selectedIdsMap[(int) $alunoId] = true;
            }
        }
        $selectedIds = array_keys($selectedIdsMap);
        if ($selectedIds === []) {
            throw new RuntimeException('Selecione pelo menos um aluno da turma.');
        }

        $this->pdo->beginTransaction();

        try {
            $stmtTurma = $this->pdo->prepare(" 
                SELECT id, nome, ativa
                FROM turma
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $stmtTurma->execute(['id' => $turmaId]);
            $turma = $stmtTurma->fetch();

            if (!$turma) {
                throw new RuntimeException('Turma não encontrada.');
            }
            if ((int) $turma['ativa'] !== 1) {
                throw new RuntimeException('A turma selecionada está arquivada.');
            }

            $stmtAlunos = $this->pdo->prepare(" 
                SELECT c.id, c.nome, c.email
                FROM turma_aluno ta
                INNER JOIN cliente c ON c.id = ta.aluno_id
                WHERE ta.turma_id = :turma_id
                ORDER BY c.id ASC
            ");
            $stmtAlunos->execute(['turma_id' => $turmaId]);
            $alunosTurma = $stmtAlunos->fetchAll();

            if ($alunosTurma === []) {
                throw new RuntimeException('A turma não tem alunos associados.');
            }

            $alunosTurmaMap = [];
            foreach ($alunosTurma as $alunoTurma) {
                $alunoId = (int) ($alunoTurma['id'] ?? 0);
                if ($alunoId > 0) {
                    $alunosTurmaMap[$alunoId] = $alunoTurma;
                }
            }

            $alunosSelecionados = [];
            $idsInvalidos = [];
            foreach ($selectedIds as $selectedAlunoId) {
                if (!isset($alunosTurmaMap[$selectedAlunoId])) {
                    $idsInvalidos[] = $selectedAlunoId;
                    continue;
                }
                $alunosSelecionados[] = $alunosTurmaMap[$selectedAlunoId];
            }

            if ($idsInvalidos !== []) {
                throw new RuntimeException('Existem alunos selecionados que não pertencem à turma.');
            }

            $alunosSemVaga = [];
            foreach ($alunosSelecionados as $aluno) {
                $alunoId = (int) ($aluno['id'] ?? 0);
                $resumoAtivos = $this->resumoEmprestimosAtivosAluno($alunoId);
                $temVagaMaquina = $resumoAtivos['maquina'] < 1;
                $temVagaTotal = $resumoAtivos['total'] < 2;
                if (!$temVagaMaquina || !$temVagaTotal) {
                    $alunosSemVaga[] = (string) ($aluno['nome'] ?? ('ID ' . $alunoId));
                }
            }

            if ($alunosSemVaga !== []) {
                $nomes = implode(', ', array_slice($alunosSemVaga, 0, 5));
                if (count($alunosSemVaga) > 5) {
                    $nomes .= ', ...';
                }

                throw new RuntimeException(
                    'Alguns alunos selecionados não podem receber novo empréstimo agora: ' . $nomes .
                    '. Regra: máximo 1 máquina e 2 empréstimos ativos por aluno.'
                );
            }

            $quantidade = count($alunosSelecionados);

            $stmtMateriais = $this->pdo->query(" 
                SELECT m.id, m.nome
                FROM material m
                INNER JOIN maquina ma ON ma.id = m.id
                WHERE m.disponibilidade = 1
                  AND m.isBroken = 0
                  AND m.isAbate = 0
                  AND NOT EXISTS (
                      SELECT 1
                      FROM emprestimo_material em2
                      INNER JOIN emprestimo e2 ON e2.id = em2.emprestimo_id
                      WHERE em2.material_id = m.id
                        AND (e2.data_fim IS NULL OR e2.data_fim > NOW())
                   )
                ORDER BY m.id ASC
                FOR UPDATE
            ");
            $materiaisDisponiveis = $stmtMateriais->fetchAll();
            $disponiveis = count($materiaisDisponiveis);

            if ($disponiveis < $quantidade) {
                throw new RuntimeException(
                    "Não existem portáteis suficientes disponíveis. Disponíveis: {$disponiveis}. Pedidos: {$quantidade}."
                );
            }

            $materiaisSelecionados = array_slice($materiaisDisponiveis, 0, $quantidade);

            $atribuicoes = [];
            for ($i = 0; $i < $quantidade; $i++) {
                $material = $materiaisSelecionados[$i];
                $aluno = $alunosSelecionados[$i];
                $alunoId = (int) ($aluno['id'] ?? 0);
                $materialId = (int) ($material['id'] ?? 0);

                $this->validarLimiteEmprestimoAluno($alunoId, $materialId);

                $meta = [
                    'tipo' => 'turma',
                    'turma_id' => (int) $turma['id'],
                    'turma_nome' => (string) $turma['nome'],
                    'aluno_nome' => (string) ($aluno['nome'] ?? ''),
                    'aluno_email' => (string) ($aluno['email'] ?? ''),
                ];

                $emprestimoId = $this->inserirEmprestimo(
                    $dataInicio,
                    $dataFim,
                    $userId,
                    $alunoId,
                    $meta
                );
                $this->vincularMaterialAoEmprestimo($emprestimoId, $materialId);
                $this->marcarMaterialEmprestado($materialId);

                $atribuicoes[] = [
                    'emprestimo_id' => $emprestimoId,
                    'item_codigo' => self::formatCodigoMaterial($materialId),
                    'item_nome' => (string) ($material['nome'] ?? ''),
                    'aluno_nome' => (string) ($aluno['nome'] ?? ''),
                    'aluno_email' => (string) ($aluno['email'] ?? ''),
                ];
            }

            $this->pdo->commit();

            return [
                'turma_nome' => (string) $turma['nome'],
                'quantidade' => $quantidade,
                'atribuicoes' => $atribuicoes,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    /**
     * @return array<int, array{
     *   emprestimo_id:int,
     *   item_nome:string,
     *   item_codigo:string,
     *   quem:string,
     *   tipo:string,
     *   tipo_label:string,
     *   data_prevista:?string
     * }>
     */
    public function listarEmprestimosAtivos(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                e.id,
                e.data_inicio,
                e.prazo_entrega,
                e.obs_inicio,
                c.nome AS cliente_nome,
                c.email AS cliente_email,
                m.id AS material_id,
                m.nome AS material_nome
            FROM emprestimo e
            INNER JOIN cliente c ON c.id = e.cliente_id
            INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
            INNER JOIN material m ON m.id = em.material_id
            WHERE (e.data_fim IS NULL OR e.data_fim > NOW())
            ORDER BY e.data_inicio DESC, e.id DESC
        ");

        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $meta = $this->decodeMetaInicio(isset($row['obs_inicio']) ? (string) $row['obs_inicio'] : null);
            $tipo = $this->resolverTipo($meta, (string) $row['prazo_entrega']);

            $quem = (string) $row['cliente_nome'];
            if ($tipo === 'turma' && isset($meta['turma_nome']) && trim((string) $meta['turma_nome']) !== '') {
                $quem = trim((string) $meta['turma_nome']);
                $alunoNome = trim((string) ($meta['aluno_nome'] ?? ''));
                if ($alunoNome !== '') {
                    $quem .= ' (' . $alunoNome . ')';
                }
            }

            $dataPrevista = (string) $row['prazo_entrega'];
            if ($tipo === 'aluno' || $dataPrevista === self::DATE_SEM_PRAZO) {
                $dataPrevista = null;
            }

            $result[] = [
                'emprestimo_id' => (int) $row['id'],
                'item_nome' => (string) $row['material_nome'],
                'item_codigo' => self::formatCodigoMaterial((int) $row['material_id']),
                'quem' => $quem,
                'tipo' => $tipo,
                'tipo_label' => $this->tipoLabel($tipo),
                'data_prevista' => $dataPrevista,
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   emprestimo_id:int,
     *   item_codigo:string,
     *   item_nome:string,
     *   quem:string,
     *   tipo:string,
     *   tipo_label:string
     * }|null
     */
    public function buscarEmprestimoAtivoPorCodigoItem(string $itemCodigo): ?array
    {
        $raw = trim($itemCodigo);
        if ($raw === '') {
            return null;
        }

        $materialId = $this->parseMaterialId($raw);
        if ($materialId === null) {
            $materialId = $this->buscarMaterialIdPorCodigoInventario($raw);
        }
        if ($materialId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                e.id,
                e.data_inicio,
                e.prazo_entrega,
                e.obs_inicio,
                c.nome AS cliente_nome,
                m.id AS material_id,
                m.nome AS material_nome
            FROM emprestimo e
            INNER JOIN cliente c ON c.id = e.cliente_id
            INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
            INNER JOIN material m ON m.id = em.material_id
            WHERE em.material_id = :material_id
              AND (e.data_fim IS NULL OR e.data_fim > NOW())
            ORDER BY e.data_inicio DESC, e.id DESC
            LIMIT 1
        ");
        $stmt->execute(['material_id' => $materialId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $meta = $this->decodeMetaInicio(isset($row['obs_inicio']) ? (string) $row['obs_inicio'] : null);
        $tipo = $this->resolverTipo($meta, (string) ($row['prazo_entrega'] ?? ''));

        $quem = (string) ($row['cliente_nome'] ?? '');
        if ($tipo === 'turma' && isset($meta['turma_nome']) && trim((string) $meta['turma_nome']) !== '') {
            $quem = trim((string) $meta['turma_nome']);
            $alunoNome = trim((string) ($meta['aluno_nome'] ?? ''));
            if ($alunoNome !== '') {
                $quem .= ' (' . $alunoNome . ')';
            }
        }

        return [
            'emprestimo_id' => (int) ($row['id'] ?? 0),
            'item_codigo' => self::formatCodigoMaterial((int) ($row['material_id'] ?? 0)),
            'item_nome' => (string) ($row['material_nome'] ?? ''),
            'quem' => $quem,
            'tipo' => $tipo,
            'tipo_label' => $this->tipoLabel($tipo),
        ];
    }

    /**
     * @return array{emprestimo_id:int,itens:int}
     */
    public function receberEmprestimo(int $emprestimoId): array
    {
        if ($emprestimoId <= 0) {
            throw new RuntimeException('Empréstimo inválido.');
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT e.id, e.data_fim, em.material_id
                FROM emprestimo e
                INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
                WHERE e.id = :id
                FOR UPDATE
            ");
            $stmt->execute(['id' => $emprestimoId]);
            $rows = $stmt->fetchAll();

            if ($rows === []) {
                throw new RuntimeException('Empréstimo não encontrado.');
            }

            $dataFim = $rows[0]['data_fim'] ?? null;
            if ($dataFim !== null && trim((string) $dataFim) !== '') {
                throw new RuntimeException('Este empréstimo já foi recebido.');
            }

            $stmtUpdateEmprestimo = $this->pdo->prepare("
                UPDATE emprestimo
                SET data_fim = CURDATE()
                WHERE id = :id
                  AND data_fim IS NULL
            ");
            $stmtUpdateEmprestimo->execute(['id' => $emprestimoId]);

            if ($stmtUpdateEmprestimo->rowCount() !== 1) {
                throw new RuntimeException('Não foi possível fechar o empréstimo.');
            }

            $materialIds = [];
            foreach ($rows as $row) {
                $materialIds[(int) $row['material_id']] = true;
            }

            $stmtUpdateMaterial = $this->pdo->prepare("
                UPDATE material
                SET disponibilidade = CASE
                    WHEN isBroken = 1 OR isAbate = 1 THEN 0
                    ELSE 1
                END
                WHERE id = :id
            ");
            foreach (array_keys($materialIds) as $materialId) {
                $stmtUpdateMaterial->execute(['id' => (int) $materialId]);
            }

            $this->pdo->commit();

            return [
                'emprestimo_id' => $emprestimoId,
                'itens' => count($materialIds),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function formatCodigoMaterial(int $id): string
    {
        return 'MAT-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function criarEmprestimoUnitario(
        string $itemCodigo,
        string $alunoEmail,
        int $userId,
        string $dataInicio,
        string $prazoEntrega,
        array $meta
    ): array {
        $materialId = $this->parseMaterialId($itemCodigo);
        if ($materialId === null) {
            throw new RuntimeException('Código do item inválido. Use o formato MAT-0001 ou ID numérico.');
        }

        $aluno = $this->buscarAlunoPorEmail($alunoEmail);
        if ($aluno === null) {
            throw new RuntimeException('Aluno não encontrado com o email indicado.');
        }

        $this->pdo->beginTransaction();

        try {
            $material = $this->buscarMaterialPorIdForUpdate($materialId);
            if ($material === null) {
                throw new RuntimeException('Item não encontrado para o código indicado.');
            }

            if ((int) $material['isBroken'] === 1) {
                throw new RuntimeException('O item está avariado e não pode ser emprestado.');
            }
            if ((int) ($material['isAbate'] ?? 0) === 1) {
                throw new RuntimeException('O item foi abatido e não pode ser emprestado.');
            }

            if ((int) $material['disponibilidade'] !== 1) {
                throw new RuntimeException('O item já se encontra emprestado.');
            }

            if ($this->existeEmprestimoAtivoParaMaterial($materialId)) {
                throw new RuntimeException('O item já possui um empréstimo ativo.');
            }

            $this->validarLimiteEmprestimoAluno((int) $aluno['id'], $materialId);

            $emprestimoId = $this->inserirEmprestimo(
                $dataInicio,
                $prazoEntrega,
                $userId,
                (int) $aluno['id'],
                $meta
            );
            $this->vincularMaterialAoEmprestimo($emprestimoId, $materialId);
            $this->marcarMaterialEmprestado($materialId);

            $this->pdo->commit();

            return [
                'emprestimo_id' => $emprestimoId,
                'item_codigo' => self::formatCodigoMaterial($materialId),
                'item_nome' => (string) $material['nome'],
                'aluno_nome' => (string) $aluno['nome'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{id:int,nome:string,isBroken:int,isAbate:int,disponibilidade:int}|null
     */
    private function buscarMaterialPorIdForUpdate(int $materialId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nome, isBroken, isAbate, disponibilidade
            FROM material
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['id' => $materialId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array{id:int,nome:string,email:string}|null
     */
    private function buscarAlunoPorEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT id, nome, email
            FROM cliente
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Regra de negócio para qualquer empréstimo individual:
     * - máximo 1 máquina ativa
     * - máximo 1 periférico ativo
     */
    private function validarLimiteEmprestimoAluno(int $alunoId, int $materialId): void
    {
        $categoriaNovoItem = $this->categoriaMaterial($materialId);
        if (!in_array($categoriaNovoItem, ['maquina', 'periferico'], true)) {
            throw new RuntimeException('Apenas máquina ou periférico são permitidos para este tipo de empréstimo.');
        }

        $resumoAtivos = $this->resumoEmprestimosAtivosAluno($alunoId);
        $totalAtivos = $resumoAtivos['total'];
        $temMaquina = $resumoAtivos['maquina'] > 0;
        $temPeriferico = $resumoAtivos['periferico'] > 0;

        if ($totalAtivos >= 2) {
            throw new RuntimeException('Aluno já atingiu o máximo de 2 empréstimos ativos.');
        }

        if ($temMaquina && $temPeriferico) {
            throw new RuntimeException('Aluno já possui 1 máquina e 1 periférico ativos. Não pode novo empréstimo.');
        }

        if ($categoriaNovoItem === 'maquina' && $temMaquina) {
            throw new RuntimeException('Aluno já possui uma máquina ativa. Só pode ter uma máquina por vez.');
        }

        if ($categoriaNovoItem === 'periferico' && $temPeriferico) {
            throw new RuntimeException('Aluno já possui um periférico ativo. Só pode ter um periférico por vez.');
        }
    }

    /**
     * @return array{total:int,maquina:int,periferico:int,outro:int}
     */
    private function resumoEmprestimosAtivosAluno(int $alunoId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ma.id IS NOT NULL THEN 1 ELSE 0 END) AS total_maquina,
                SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS total_periferico,
                SUM(CASE WHEN ma.id IS NULL AND p.id IS NULL THEN 1 ELSE 0 END) AS total_outro
            FROM emprestimo e
            INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
            INNER JOIN material m ON m.id = em.material_id
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN periferico p ON p.id = m.id
            WHERE e.cliente_id = :aluno_id
              AND (e.data_fim IS NULL OR e.data_fim > NOW())
        ");
        $stmt->execute(['aluno_id' => $alunoId]);
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'maquina' => (int) ($row['total_maquina'] ?? 0),
            'periferico' => (int) ($row['total_periferico'] ?? 0),
            'outro' => (int) ($row['total_outro'] ?? 0),
        ];
    }

    private function categoriaMaterial(int $materialId): string
    {
        $stmt = $this->pdo->prepare("
            SELECT
                CASE
                    WHEN ma.id IS NOT NULL THEN 'maquina'
                    WHEN p.id IS NOT NULL THEN 'periferico'
                    ELSE 'outro'
                END AS categoria
            FROM material m
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN periferico p ON p.id = m.id
            WHERE m.id = :material_id
            LIMIT 1
        ");
        $stmt->execute(['material_id' => $materialId]);
        $row = $stmt->fetch();

        return (string) ($row['categoria'] ?? 'outro');
    }

    private function inserirEmprestimo(
        string $dataInicio,
        string $prazoEntrega,
        int $userId,
        int $clienteId,
        array $meta
    ): int {
        $obsInicio = $this->encodeMetaInicio($meta);

        $stmt = $this->pdo->prepare("
            INSERT INTO emprestimo (
                data_inicio,
                prazo_entrega,
                data_fim,
                obs_inicio,
                obs_fim,
                user_id,
                cliente_id
            ) VALUES (
                :data_inicio,
                :prazo_entrega,
                NULL,
                :obs_inicio,
                NULL,
                :user_id,
                :cliente_id
            )
        ");
        $stmt->execute([
            'data_inicio' => $dataInicio,
            'prazo_entrega' => $prazoEntrega,
            'obs_inicio' => $obsInicio,
            'user_id' => $userId,
            'cliente_id' => $clienteId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function vincularMaterialAoEmprestimo(int $emprestimoId, int $materialId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO emprestimo_material (emprestimo_id, material_id)
            VALUES (:emprestimo_id, :material_id)
        ");
        $stmt->execute([
            'emprestimo_id' => $emprestimoId,
            'material_id' => $materialId,
        ]);
    }

    private function marcarMaterialEmprestado(int $materialId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE material
            SET disponibilidade = 0
            WHERE id = :id
              AND disponibilidade = 1
        ");
        $stmt->execute(['id' => $materialId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('O item já se encontra emprestado.');
        }
    }

    private function existeEmprestimoAtivoParaMaterial(int $materialId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total
            FROM emprestimo_material em
            INNER JOIN emprestimo e ON e.id = em.emprestimo_id
            WHERE em.material_id = :material_id
              AND (e.data_fim IS NULL OR e.data_fim > NOW())
        ");
        $stmt->execute(['material_id' => $materialId]);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0) > 0;
    }

    private function parseMaterialId(string $input): ?int
    {
        $value = strtoupper(trim($input));
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }

        if (preg_match('/^MAT-(\d+)$/', $value, $matches) === 1) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function buscarMaterialIdPorCodigoInventario(string $codigoInventario): ?int
    {
        $codigoInventario = trim($codigoInventario);
        if ($codigoInventario === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM material
            WHERE codigo_inventario = :codigo
            LIMIT 1
        ");
        $stmt->execute(['codigo' => $codigoInventario]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        $materialId = (int) $id;
        return $materialId > 0 ? $materialId : null;
    }

    private function isDateYmd(string $value): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    private function encodeMetaInicio(array $meta): ?string
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $clean[(string) $key] = $value;
        }

        if ($clean === []) {
            return null;
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetaInicio(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolverTipo(array $meta, string $prazoEntrega): string
    {
        $tipo = isset($meta['tipo']) ? (string) $meta['tipo'] : '';
        if (in_array($tipo, ['aluno', 'longa_duracao', 'turma'], true)) {
            return $tipo;
        }

        if ($prazoEntrega === self::DATE_SEM_PRAZO) {
            return 'aluno';
        }

        return 'longa_duracao';
    }

    private function tipoLabel(string $tipo): string
    {
        if ($tipo === 'aluno') {
            return 'Aluno';
        }
        if ($tipo === 'turma') {
            return 'Turma';
        }
        if ($tipo === 'longa_duracao') {
            return 'Longa duração';
        }

        return 'Desconhecido';
    }

    private function ensureMaterialSchema(): void
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM material LIKE 'isAbate'");
        $hasIsAbate = $stmt->fetch() !== false;
        if ($hasIsAbate) {
            return;
        }

        $this->pdo->exec("
            ALTER TABLE material
            ADD COLUMN isAbate TINYINT(1) NOT NULL DEFAULT 0 AFTER isBroken
        ");
    }
}

