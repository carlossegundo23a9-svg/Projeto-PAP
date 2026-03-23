<?php
declare(strict_types=1);

final class RelatorioRepository
{
    private const DATE_SEM_PRAZO = '9999-12-31';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureViews();
    }

    /**
     * @return array<int, array{id:int,nome:string}>
     */
    public function listarLocais(): array
    {
        $stmt = $this->pdo->query("SELECT id, nome FROM local ORDER BY nome ASC");
        return $stmt->fetchAll();
    }

    /**
     * @param array{auditoria:string,local_id:?int,data:?string,data_inicio:?string,data_fim:?string,equipamento:?string} $filtros
     * @return array{
     *   title:string,
     *   columns:array<int, array{key:string,label:string}>,
     *   rows:array<int, array<string, string>>
     * }
     */
    public function gerarAuditoria(array $filtros): array
    {
        $auditoria = strtolower(trim((string) ($filtros['auditoria'] ?? '')));
        if ($auditoria === 'emprestimos_atuais' || $auditoria === 'emprestimos_data') {
            $auditoria = 'emprestimos';
        }

        $localId = $filtros['local_id'] ?? null;
        $dataInicio = $filtros['data_inicio'] ?? null;
        $dataFim = $filtros['data_fim'] ?? null;
        $dataLegada = $filtros['data'] ?? null;
        $equipamento = trim((string) ($filtros['equipamento'] ?? ''));

        if ($auditoria === 'sala') {
            if (!is_int($localId) || $localId <= 0) {
                throw new InvalidArgumentException('Selecione uma sala para auditoria por sala.');
            }

            return $this->auditoriaItens(
                "Auditoria por Sala",
                "WHERE local_id = :local_id",
                ['local_id' => $localId]
            );
        }

        if ($auditoria === 'sem_local') {
            return $this->auditoriaItens(
                "Auditoria de Itens Sem Local",
                "WHERE local_id IS NULL"
            );
        }

        if ($auditoria === 'avariados') {
            return $this->auditoriaItens(
                "Auditoria de Itens Avariados",
                "WHERE estado_key = 'avariado'"
            );
        }

        if ($auditoria === 'abatidos') {
            return $this->auditoriaItens(
                "Auditoria de Itens Abatidos",
                "WHERE estado_key = 'abate'"
            );
        }

        if ($auditoria === 'historico_equipamento') {
            if ($equipamento === '') {
                throw new InvalidArgumentException('Indique o código, ID ou nome do equipamento.');
            }

            return $this->auditoriaHistoricoEquipamento(
                'Histórico por Equipamento',
                $equipamento
            );
        }

        if ($auditoria === 'emprestimos') {
            // Compatibilidade com o filtro antigo por data unica.
            if (trim((string) $dataInicio) === '' && trim((string) $dataLegada) !== '') {
                $dataInicio = $dataLegada;
            }

            $temInicio = trim((string) $dataInicio) !== '';
            $temFim = trim((string) $dataFim) !== '';

            if ($temInicio || $temFim) {
                $inicio = null;
                $fim = null;

                if ($temInicio) {
                    $inicio = $this->sanitizeDate($dataInicio);
                    if ($inicio === null) {
                        throw new InvalidArgumentException('Selecione uma data inicial válida para auditoria de empréstimos.');
                    }
                }

                if ($temFim) {
                    $fim = $this->sanitizeDate($dataFim);
                    if ($fim === null) {
                        throw new InvalidArgumentException('Selecione uma data final válida para auditoria de empréstimos.');
                    }
                }

                if ($inicio !== null && $fim !== null && $inicio > $fim) {
                    throw new InvalidArgumentException('A data inicial não pode ser superior à data final.');
                }

                $where = [];
                $params = [];

                if ($inicio !== null) {
                    $where[] = "data_inicio >= :data_inicio";
                    $params['data_inicio'] = $inicio;
                }
                if ($fim !== null) {
                    $where[] = "data_inicio <= :data_fim";
                    $params['data_fim'] = $fim;
                }

                return $this->auditoriaEmprestimos(
                    "Auditoria de Empréstimos",
                    "WHERE " . implode(' AND ', $where),
                    $params
                );
            }

            return $this->auditoriaEmprestimos(
                "Auditoria de Empréstimos",
                "WHERE ativo = 1"
            );
        }

        return $this->auditoriaItens("Auditoria Geral de Itens");
    }

    /**
     * @return array<int, array{estado_key:string,estado_label:string,total:int}>
     */
    public function resumoEstados(): array
    {
        $stmt = $this->pdo->query("
            SELECT estado_key, estado_label, COUNT(*) AS total
            FROM vw_auditoria_itens
            GROUP BY estado_key, estado_label
        ");
        $rows = $stmt->fetchAll();

        $byKey = [
            'em_uso' => ['estado_key' => 'em_uso', 'estado_label' => 'Em uso', 'total' => 0],
            'avariado' => ['estado_key' => 'avariado', 'estado_label' => 'Avariado', 'total' => 0],
            'abate' => ['estado_key' => 'abate', 'estado_label' => 'Abate', 'total' => 0],
        ];

        foreach ($rows as $row) {
            $key = (string) ($row['estado_key'] ?? '');
            if (!isset($byKey[$key])) {
                continue;
            }

            $byKey[$key]['total'] = (int) ($row['total'] ?? 0);
        }

        return array_values($byKey);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   title:string,
     *   columns:array<int, array{key:string,label:string}>,
     *   rows:array<int, array<string, string>>
     * }
     */
    private function auditoriaItens(string $title, string $where = '', array $params = []): array
    {
        $sql = "
            SELECT
                codigo,
                item_nome,
                categoria,
                COALESCE(sala, 'Sem local') AS sala,
                estado_label,
                disponibilidade_label
            FROM vw_auditoria_itens
            {$where}
            ORDER BY material_id DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rawRows = $stmt->fetchAll();

        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = [
                'codigo' => (string) ($row['codigo'] ?? ''),
                'item' => (string) ($row['item_nome'] ?? ''),
                'categoria' => (string) ($row['categoria'] ?? ''),
                'sala' => (string) ($row['sala'] ?? 'Sem local'),
                'estado' => (string) ($row['estado_label'] ?? ''),
                'disponibilidade' => (string) ($row['disponibilidade_label'] ?? ''),
            ];
        }

        return [
            'title' => $title,
            'columns' => [
                ['key' => 'codigo', 'label' => 'Código'],
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'categoria', 'label' => 'Categoria'],
                ['key' => 'sala', 'label' => 'Sala'],
                ['key' => 'estado', 'label' => 'Estado'],
                ['key' => 'disponibilidade', 'label' => 'Disponibilidade'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *   title:string,
     *   columns:array<int, array{key:string,label:string}>,
     *   rows:array<int, array<string, string>>
     * }
     */
    private function auditoriaHistoricoEquipamento(string $title, string $equipamento): array
    {
        $equipamento = trim($equipamento);
        $whereParts = [];
        $params = [];

        $materialId = $this->parseMaterialId($equipamento);
        if ($materialId !== null) {
            $whereParts[] = "material_id = :material_id";
            $params['material_id'] = $materialId;
        } else {
            $whereParts[] = "(item_codigo LIKE :codigo OR item_nome LIKE :item_nome)";
            $search = '%' . $equipamento . '%';
            $params['codigo'] = $search;
            $params['item_nome'] = $search;
        }

        $sql = "
            SELECT
                emprestimo_id,
                item_codigo,
                item_nome,
                cliente_nome,
                cliente_email,
                data_inicio,
                prazo_entrega,
                data_fim,
                ativo,
                sala
            FROM vw_auditoria_emprestimos
            WHERE " . implode(' AND ', $whereParts) . "
            ORDER BY data_inicio DESC, emprestimo_id DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rawRows = $stmt->fetchAll();

        $rows = [];
        foreach ($rawRows as $row) {
            $prazo = (string) ($row['prazo_entrega'] ?? '');
            if ($prazo === self::DATE_SEM_PRAZO) {
                $prazo = '';
            }

            $rows[] = [
                'item_codigo' => (string) ($row['item_codigo'] ?? ''),
                'item' => (string) ($row['item_nome'] ?? ''),
                'pessoa' => (string) ($row['cliente_nome'] ?? ''),
                'email' => (string) ($row['cliente_email'] ?? ''),
                'sala' => (string) ($row['sala'] ?? 'Sem local'),
                'data_inicio' => $this->formatDateBr((string) ($row['data_inicio'] ?? '')),
                'data_fim' => $this->formatDateBr((string) ($row['data_fim'] ?? '')),
                'prazo' => $prazo === '' ? '-' : $this->formatDateBr($prazo),
                'estado' => ((int) ($row['ativo'] ?? 0) === 1) ? 'Ativo' : 'Fechado',
                'emprestimo' => '#' . (string) ($row['emprestimo_id'] ?? ''),
            ];
        }

        return [
            'title' => $title . ': ' . $equipamento,
            'columns' => [
                ['key' => 'item_codigo', 'label' => 'Código Item'],
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'pessoa', 'label' => 'Pessoa'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'sala', 'label' => 'Sala'],
                ['key' => 'data_inicio', 'label' => 'Data Início'],
                ['key' => 'data_fim', 'label' => 'Data Fim'],
                ['key' => 'prazo', 'label' => 'Prazo'],
                ['key' => 'estado', 'label' => 'Estado Empréstimo'],
                ['key' => 'emprestimo', 'label' => 'Empréstimo'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   title:string,
     *   columns:array<int, array{key:string,label:string}>,
     *   rows:array<int, array<string, string>>
     * }
     */
    private function auditoriaEmprestimos(string $title, string $where = '', array $params = []): array
    {
        $sql = "
            SELECT
                emprestimo_id,
                item_codigo,
                item_nome,
                cliente_nome,
                cliente_email,
                data_inicio,
                prazo_entrega,
                data_fim,
                ativo,
                sala
            FROM vw_auditoria_emprestimos
            {$where}
            ORDER BY data_inicio DESC, emprestimo_id DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rawRows = $stmt->fetchAll();

        $rows = [];
        foreach ($rawRows as $row) {
            $dataPrevista = (string) ($row['prazo_entrega'] ?? '');
            if ($dataPrevista === self::DATE_SEM_PRAZO) {
                $dataPrevista = '';
            }

            $rows[] = [
                'emprestimo' => '#' . (string) ($row['emprestimo_id'] ?? ''),
                'item_codigo' => (string) ($row['item_codigo'] ?? ''),
                'item' => (string) ($row['item_nome'] ?? ''),
                'cliente' => (string) ($row['cliente_nome'] ?? ''),
                'email' => (string) ($row['cliente_email'] ?? ''),
                'sala' => (string) ($row['sala'] ?? 'Sem local'),
                'data_inicio' => $this->formatDateBr((string) ($row['data_inicio'] ?? '')),
                'prazo' => $dataPrevista === '' ? '-' : $this->formatDateBr($dataPrevista),
                'data_fim' => $this->formatDateBr((string) ($row['data_fim'] ?? '')),
                'estado' => ((int) ($row['ativo'] ?? 0) === 1) ? 'Ativo' : 'Fechado',
            ];
        }

        return [
            'title' => $title,
            'columns' => [
                ['key' => 'emprestimo', 'label' => 'Empréstimo'],
                ['key' => 'item_codigo', 'label' => 'Código Item'],
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'sala', 'label' => 'Sala'],
                ['key' => 'data_inicio', 'label' => 'Data Início'],
                ['key' => 'data_fim', 'label' => 'Data Fim'],
                ['key' => 'prazo', 'label' => 'Prazo'],
                ['key' => 'estado', 'label' => 'Estado Empréstimo'],
            ],
            'rows' => $rows,
        ];
    }

    private function sanitizeDate(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            return null;
        }

        return $raw;
    }

    private function formatDateBr(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '-';
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return '-';
        }

        return date('d/m/Y', $ts);
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

    private function ensureViews(): void
    {
        $this->pdo->exec("
            CREATE OR REPLACE VIEW vw_auditoria_itens AS
            SELECT
                m.id AS material_id,
                CONCAT('MAT-', LPAD(m.id, 4, '0')) AS codigo,
                m.nome AS item_nome,
                CASE
                    WHEN ma.id IS NOT NULL THEN 'Máquina'
                    WHEN p.id IS NOT NULL THEN 'Periférico'
                    WHEN e.id IS NOT NULL THEN 'Extra'
                    ELSE 'Material'
                END AS categoria,
                COALESCE(ma.local_id, p.local_id, e.local_id) AS local_id,
                COALESCE(lm.nome, lp.nome, le.nome) AS sala,
                m.isBroken,
                m.isAbate,
                m.disponibilidade,
                CASE
                    WHEN m.isAbate = 1 THEN 'abate'
                    WHEN m.isBroken = 1 THEN 'avariado'
                    ELSE 'em_uso'
                END AS estado_key,
                CASE
                    WHEN m.isAbate = 1 THEN 'Abate'
                    WHEN m.isBroken = 1 THEN 'Avariado'
                    ELSE 'Em uso'
                END AS estado_label,
                CASE
                    WHEN m.disponibilidade = 1 THEN 'Disponível'
                    ELSE 'Indisponível'
                END AS disponibilidade_label
            FROM material m
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN local lm ON lm.id = ma.local_id
            LEFT JOIN periferico p ON p.id = m.id
            LEFT JOIN local lp ON lp.id = p.local_id
            LEFT JOIN extra e ON e.id = m.id
            LEFT JOIN local le ON le.id = e.local_id
        ");

        $this->pdo->exec("
            CREATE OR REPLACE VIEW vw_auditoria_emprestimos AS
            SELECT
                e.id AS emprestimo_id,
                e.data_inicio,
                e.prazo_entrega,
                e.data_fim,
                CASE WHEN e.data_fim IS NULL THEN 1 ELSE 0 END AS ativo,
                c.nome AS cliente_nome,
                c.email AS cliente_email,
                m.id AS material_id,
                CONCAT('MAT-', LPAD(m.id, 4, '0')) AS item_codigo,
                m.nome AS item_nome,
                COALESCE(lm.nome, lp.nome, le.nome, 'Sem local') AS sala
            FROM emprestimo e
            INNER JOIN cliente c ON c.id = e.cliente_id
            INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
            INNER JOIN material m ON m.id = em.material_id
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN local lm ON lm.id = ma.local_id
            LEFT JOIN periferico p ON p.id = m.id
            LEFT JOIN local lp ON lp.id = p.local_id
            LEFT JOIN extra ex ON ex.id = m.id
            LEFT JOIN local le ON le.id = ex.local_id
        ");
    }
}
