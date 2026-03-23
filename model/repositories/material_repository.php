<?php
declare(strict_types=1);

require_once __DIR__ . '/../entities/material.php';
require_once __DIR__ . '/../entities/maquina.php';
require_once __DIR__ . '/../entities/periferico.php';
require_once __DIR__ . '/../entities/extra.php';

final class MaterialRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureMaterialSchema();
    }

    /**
     * @return array<int, array{id:int, nome:string}>
     */
    public function listarLocais(): array
    {
        $stmt = $this->pdo->query("SELECT id, nome FROM local ORDER BY nome ASC");
        return $stmt->fetchAll();
    }

    public function resolverLocalId(string $input): ?int
    {
        $value = trim($input);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $stmt = $this->pdo->prepare("SELECT id FROM local WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => (int) $value]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        }

        $stmt = $this->pdo->prepare("SELECT id FROM local WHERE LOWER(nome) = LOWER(:nome) LIMIT 1");
        $stmt->execute(['nome' => $value]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * @param array{tipo?:string,estado?:string,local_id?:int|null} $filters
     * @param int|null $limit
     * @param int|null $offset
     * @return Material[]
     */
    public function listar(string $search = '', array $filters = [], ?int $limit = null, ?int $offset = null): array
    {
        $parts = $this->buildListQueryParts($search, $filters);
        $categoryExpr = $parts['category_expr'];
        $statusExpr = $parts['status_expr'];
        $conditions = $parts['conditions'];
        $params = $parts['params'];

        $sql = "
            SELECT
                m.id,
                m.nome,
                m.marca,
                m.modelo,
                m.codigo_inventario,
                m.data_compra,
                m.isBroken,
                m.isAbate,
                m.disponibilidade,
                m.data_broken,
                ma.mac,
                ma.sn,
                ma.local_id AS maquina_local_id,
                lm.nome AS maquina_local_nome,
                p.local_id AS periferico_local_id,
                lp.nome AS periferico_local_nome,
                e.`desc` AS extra_desc,
                e.local_id AS extra_local_id,
                le.nome AS extra_local_nome,
                ea.aluno_nome AS emprestimo_aluno_nome,
                COALESCE(ea.aluno_nome, lm.nome, lp.nome, le.nome) AS local_exibicao_nome,
                {$categoryExpr} AS categoria,
                {$statusExpr} AS estado
            FROM material m
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN local lm ON lm.id = ma.local_id
            LEFT JOIN periferico p ON p.id = m.id
            LEFT JOIN local lp ON lp.id = p.local_id
            LEFT JOIN extra e ON e.id = m.id
            LEFT JOIN local le ON le.id = e.local_id
            LEFT JOIN (
                SELECT
                    em.material_id,
                    c.nome AS aluno_nome
                FROM emprestimo_material em
                INNER JOIN emprestimo e ON e.id = em.emprestimo_id
                INNER JOIN cliente c ON c.id = e.cliente_id
                INNER JOIN (
                    SELECT
                        em2.material_id,
                        MAX(e2.id) AS emprestimo_id
                    FROM emprestimo_material em2
                    INNER JOIN emprestimo e2 ON e2.id = em2.emprestimo_id
                    WHERE (e2.data_fim IS NULL OR e2.data_fim > NOW())
                    GROUP BY em2.material_id
                ) emprestimo_ativo
                    ON emprestimo_ativo.material_id = em.material_id
                   AND emprestimo_ativo.emprestimo_id = e.id
            ) ea ON ea.material_id = m.id
        ";

        if ($conditions !== []) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY m.id DESC";

        if (is_int($limit) && $limit > 0) {
            $sql .= " LIMIT :limit";
            $params['limit'] = $limit;

            if (is_int($offset) && $offset >= 0) {
                $sql .= " OFFSET :offset";
                $params['offset'] = $offset;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue(':' . $param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrateMaterial($row);
        }

        return $result;
    }

    /**
     * @param array{tipo?:string,estado?:string,local_id?:int|null} $filters
     */
    public function contar(string $search = '', array $filters = []): int
    {
        $parts = $this->buildListQueryParts($search, $filters);
        $conditions = $parts['conditions'];
        $params = $parts['params'];

        $sql = "
            SELECT COUNT(*) AS total
            FROM material m
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN local lm ON lm.id = ma.local_id
            LEFT JOIN periferico p ON p.id = m.id
            LEFT JOIN local lp ON lp.id = p.local_id
            LEFT JOIN extra e ON e.id = m.id
            LEFT JOIN local le ON le.id = e.local_id
        ";

        if ($conditions !== []) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue(':' . $param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function buscarPorId(int $id): ?Material
    {
        $stmt = $this->pdo->prepare("
            SELECT
                m.id,
                m.nome,
                m.marca,
                m.modelo,
                m.codigo_inventario,
                m.data_compra,
                m.isBroken,
                m.isAbate,
                m.disponibilidade,
                m.data_broken,
                ma.mac,
                ma.sn,
                ma.local_id AS maquina_local_id,
                lm.nome AS maquina_local_nome,
                p.local_id AS periferico_local_id,
                lp.nome AS periferico_local_nome,
                e.`desc` AS extra_desc,
                e.local_id AS extra_local_id,
                le.nome AS extra_local_nome
            FROM material m
            LEFT JOIN maquina ma ON ma.id = m.id
            LEFT JOIN local lm ON lm.id = ma.local_id
            LEFT JOIN periferico p ON p.id = m.id
            LEFT JOIN local lp ON lp.id = p.local_id
            LEFT JOIN extra e ON e.id = m.id
            LEFT JOIN local le ON le.id = e.local_id
            WHERE m.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateMaterial($row);
    }

    public function criar(Material $material): int
    {
        $this->pdo->beginTransaction();

        try {
            $this->validarCodigoInventarioUnico($material->getCodigoInventario(), null);

            $stmt = $this->pdo->prepare("
                INSERT INTO material (nome, marca, modelo, codigo_inventario, data_compra, isBroken, isAbate, disponibilidade, data_broken)
                VALUES (:nome, :marca, :modelo, :codigo_inventario, :data_compra, :isBroken, :isAbate, :disponibilidade, :data_broken)
            ");
            $stmt->execute([
                'nome' => $material->getNome(),
                'marca' => $material->getMarca(),
                'modelo' => $material->getModelo(),
                'codigo_inventario' => $material->getCodigoInventario(),
                'data_compra' => $material->getDataCompra(),
                'isBroken' => $material->isBroken() ? 1 : 0,
                'isAbate' => $material->isAbate() ? 1 : 0,
                'disponibilidade' => $material->isDisponivel() ? 1 : 0,
                'data_broken' => $material->getDataBroken(),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $material->setId($id);

            $this->inserirFilho($material);

            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function atualizar(Material $material): void
    {
        $id = $material->getId();
        if ($id === null) {
            throw new InvalidArgumentException('Material sem ID para atualizar.');
        }

        $this->pdo->beginTransaction();

        try {
            $this->validarCodigoInventarioUnico($material->getCodigoInventario(), $id);

            $disponibilidade = $this->resolverDisponibilidadeAtualizacao($id, $material);

            $stmt = $this->pdo->prepare("
                UPDATE material
                SET nome = :nome,
                    marca = :marca,
                    modelo = :modelo,
                    codigo_inventario = :codigo_inventario,
                    data_compra = :data_compra,
                    isBroken = :isBroken,
                    isAbate = :isAbate,
                    disponibilidade = :disponibilidade,
                    data_broken = :data_broken
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $id,
                'nome' => $material->getNome(),
                'marca' => $material->getMarca(),
                'modelo' => $material->getModelo(),
                'codigo_inventario' => $material->getCodigoInventario(),
                'data_compra' => $material->getDataCompra(),
                'isBroken' => $material->isBroken() ? 1 : 0,
                'isAbate' => $material->isAbate() ? 1 : 0,
                'disponibilidade' => $disponibilidade ? 1 : 0,
                'data_broken' => $material->getDataBroken(),
            ]);

            $this->apagarFilhosPorId($id);
            $this->inserirFilho($material);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function apagar(int $id): bool
    {
        $this->pdo->beginTransaction();

        try {
            $this->apagarFilhosPorId($id);

            $stmt = $this->pdo->prepare("DELETE FROM material WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $deleted = $stmt->rowCount() > 0;

            $this->pdo->commit();
            return $deleted;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function reservarParaEmprestimo(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE material
            SET disponibilidade = 0
            WHERE id = :id
              AND disponibilidade = 1
        ");
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function marcarComoDisponivel(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE material
            SET disponibilidade = 1
            WHERE id = :id
              AND disponibilidade = 0
        ");
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    private function resolverDisponibilidadeAtualizacao(int $materialId, Material $material): bool
    {
        if ($material->isBroken() || $material->isAbate()) {
            return false;
        }

        if ($this->temEmprestimoAtivo($materialId)) {
            return false;
        }

        return true;
    }

    private function temEmprestimoAtivo(int $materialId): bool
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

    private function apagarFilhosPorId(int $id): void
    {
        $stmtMaquina = $this->pdo->prepare("DELETE FROM maquina WHERE id = :id");
        $stmtPeriferico = $this->pdo->prepare("DELETE FROM periferico WHERE id = :id");
        $stmtExtra = $this->pdo->prepare("DELETE FROM extra WHERE id = :id");

        $stmtMaquina->execute(['id' => $id]);
        $stmtPeriferico->execute(['id' => $id]);
        $stmtExtra->execute(['id' => $id]);
    }

    private function inserirFilho(Material $material): void
    {
        $id = $material->getId();
        if ($id === null) {
            throw new InvalidArgumentException('Material sem ID para inserir tipo filho.');
        }

        if ($material instanceof Maquina) {
            $localId = $material->getLocalId();
            if ($localId === null) {
                throw new InvalidArgumentException('Máquina precisa de local.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO maquina (id, mac, sn, local_id)
                VALUES (:id, :mac, :sn, :local_id)
            ");
            $stmt->execute([
                'id' => $id,
                'mac' => $material->getMac(),
                'sn' => $material->getNumSerie(),
                'local_id' => $localId,
            ]);
            return;
        }

        if ($material instanceof Periferico) {
            $localId = $material->getLocalId();
            if ($localId === null) {
                throw new InvalidArgumentException('Periférico precisa de local.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO periferico (id, local_id)
                VALUES (:id, :local_id)
            ");
            $stmt->execute([
                'id' => $id,
                'local_id' => $localId,
            ]);
            return;
        }

        if ($material instanceof Extra) {
            $localId = $material->getLocalId();
            if ($localId === null) {
                throw new InvalidArgumentException('Extra precisa de local.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO extra (id, `desc`, local_id)
                VALUES (:id, :descricao, :local_id)
            ");
            $stmt->execute([
                'id' => $id,
                'descricao' => $material->getDescricao(),
                'local_id' => $localId,
            ]);
            return;
        }

        throw new InvalidArgumentException('Tipo de material não suportado.');
    }

    private function hydrateMaterial(array $row): Material
    {
        $id = (int) $row['id'];
        $nome = (string) $row['nome'];
        $marca = isset($row['marca']) ? (string) $row['marca'] : null;
        $modelo = isset($row['modelo']) ? (string) $row['modelo'] : null;
        $codigoInventario = isset($row['codigo_inventario']) ? (string) $row['codigo_inventario'] : null;
        $dataCompra = isset($row['data_compra']) ? (string) $row['data_compra'] : null;
        $isBroken = (int) ($row['isBroken'] ?? 0) === 1;
        $isAbate = (int) ($row['isAbate'] ?? 0) === 1;
        $disponivel = (int) ($row['disponibilidade'] ?? 1) === 1;
        $dataBroken = isset($row['data_broken']) ? (string) $row['data_broken'] : null;
        $localExibicaoNome = null;
        if (isset($row['local_exibicao_nome'])) {
            $localExibicaoNome = trim((string) $row['local_exibicao_nome']);
            if ($localExibicaoNome === '') {
                $localExibicaoNome = null;
            }
        }

        if (isset($row['maquina_local_id']) && $row['maquina_local_id'] !== null) {
            $material = new Maquina(
                $id,
                $nome,
                $marca,
                $modelo,
                $dataCompra,
                (int) $row['maquina_local_id'],
                isset($row['mac']) ? (string) $row['mac'] : null,
                isset($row['sn']) ? (string) $row['sn'] : null,
                $isBroken,
                $isAbate,
                $dataBroken,
                $disponivel,
                $codigoInventario
            );
            $localPadrao = isset($row['maquina_local_nome']) ? (string) $row['maquina_local_nome'] : null;
            $material->setLocalNome($localExibicaoNome ?? $localPadrao);
            return $material;
        }

        if (isset($row['periferico_local_id']) && $row['periferico_local_id'] !== null) {
            $material = new Periferico(
                $id,
                $nome,
                $marca,
                $modelo,
                $dataCompra,
                (int) $row['periferico_local_id'],
                $isBroken,
                $isAbate,
                $dataBroken,
                $disponivel,
                $codigoInventario
            );
            $localPadrao = isset($row['periferico_local_nome']) ? (string) $row['periferico_local_nome'] : null;
            $material->setLocalNome($localExibicaoNome ?? $localPadrao);
            return $material;
        }

        $material = new Extra(
            $id,
            $nome,
            $marca,
            $modelo,
            $dataCompra,
            isset($row['extra_local_id']) && $row['extra_local_id'] !== null ? (int) $row['extra_local_id'] : null,
            isset($row['extra_desc']) ? (string) $row['extra_desc'] : null,
            $isBroken,
            $isAbate,
            $dataBroken,
            $disponivel,
            $codigoInventario
        );
        $localPadrao = isset($row['extra_local_nome']) ? (string) $row['extra_local_nome'] : null;
        $material->setLocalNome($localExibicaoNome ?? $localPadrao);
        return $material;
    }

    /**
     * @param array{tipo?:string,estado?:string,local_id?:int|null} $filters
     * @return array{
     *   category_expr:string,
     *   status_expr:string,
     *   conditions:array<int, string>,
     *   params:array<string, int|string>
     * }
     */
    private function buildListQueryParts(string $search, array $filters): array
    {
        $categoryExpr = "CASE
            WHEN ma.id IS NOT NULL THEN 'Máquina'
            WHEN p.id IS NOT NULL THEN 'Periférico'
            WHEN e.id IS NOT NULL THEN 'Extra'
            ELSE 'Material'
        END";

        $statusExpr = "CASE
            WHEN m.isAbate = 1 THEN 'Abate'
            WHEN m.isBroken = 1 THEN 'Avariado'
            ELSE 'Em uso'
        END";

        $localIdExpr = "COALESCE(ma.local_id, p.local_id, e.local_id)";
        $localNomeExpr = "COALESCE(lm.nome, lp.nome, le.nome, '')";

        $params = [];
        $conditions = [];

        $search = trim($search);
        if ($search !== '') {
            $searchParam = '%' . $search . '%';
            $conditions[] = "(
                CAST(m.id AS CHAR) LIKE :q_id
                OR COALESCE(m.codigo_inventario, '') LIKE :q_codigo_inventario
                OR m.nome LIKE :q_nome
                OR COALESCE(m.marca, '') LIKE :q_marca
                OR COALESCE(m.modelo, '') LIKE :q_modelo
                OR {$localNomeExpr} LIKE :q_local
                OR {$categoryExpr} LIKE :q_categoria
                OR {$statusExpr} LIKE :q_estado
            )";
            $params['q_id'] = $searchParam;
            $params['q_codigo_inventario'] = $searchParam;
            $params['q_nome'] = $searchParam;
            $params['q_marca'] = $searchParam;
            $params['q_modelo'] = $searchParam;
            $params['q_local'] = $searchParam;
            $params['q_categoria'] = $searchParam;
            $params['q_estado'] = $searchParam;
        }

        $tipo = strtolower(trim((string) ($filters['tipo'] ?? '')));
        if ($tipo === 'maquina') {
            $conditions[] = "ma.id IS NOT NULL";
        } elseif ($tipo === 'periferico') {
            $conditions[] = "p.id IS NOT NULL";
        } elseif ($tipo === 'extra') {
            $conditions[] = "e.id IS NOT NULL";
        }

        $estado = strtolower(trim((string) ($filters['estado'] ?? '')));
        if ($estado === 'em_uso') {
            $conditions[] = "m.isBroken = 0 AND m.isAbate = 0";
        } elseif ($estado === 'avariado') {
            $conditions[] = "m.isBroken = 1 AND m.isAbate = 0";
        } elseif ($estado === 'abate') {
            $conditions[] = "m.isAbate = 1";
        }

        $localId = $filters['local_id'] ?? null;
        if (is_int($localId) && $localId > 0) {
            $conditions[] = "{$localIdExpr} = :local_id";
            $params['local_id'] = $localId;
        }

        return [
            'category_expr' => $categoryExpr,
            'status_expr' => $statusExpr,
            'conditions' => $conditions,
            'params' => $params,
        ];
    }

    private function ensureMaterialSchema(): void
    {
        $stmtIsAbate = $this->pdo->query("SHOW COLUMNS FROM material LIKE 'isAbate'");
        $hasIsAbate = $stmtIsAbate->fetch() !== false;
        if (!$hasIsAbate) {
            $this->pdo->exec("
                ALTER TABLE material
                ADD COLUMN isAbate TINYINT(1) NOT NULL DEFAULT 0 AFTER isBroken
            ");
        }

        $stmtCodigoInventario = $this->pdo->query("SHOW COLUMNS FROM material LIKE 'codigo_inventario'");
        $hasCodigoInventario = $stmtCodigoInventario->fetch() !== false;
        if (!$hasCodigoInventario) {
            $this->pdo->exec("
                ALTER TABLE material
                ADD COLUMN codigo_inventario VARCHAR(120) NULL AFTER modelo
            ");
        }

        $this->pdo->exec("
            UPDATE material
            SET codigo_inventario = NULL
            WHERE codigo_inventario IS NOT NULL
              AND TRIM(codigo_inventario) = ''
        ");

        $stmtIndex = $this->pdo->query("SHOW INDEX FROM material WHERE Key_name = 'uq_material_codigo_inventario'");
        $hasUniqueIndex = $stmtIndex->fetch() !== false;
        if (!$hasUniqueIndex) {
            $stmtDuplicados = $this->pdo->query("
                SELECT codigo_inventario, COUNT(*) AS total
                FROM material
                WHERE codigo_inventario IS NOT NULL
                  AND TRIM(codigo_inventario) <> ''
                GROUP BY codigo_inventario
                HAVING COUNT(*) > 1
                LIMIT 1
            ");
            $temDuplicados = $stmtDuplicados->fetch() !== false;
            if (!$temDuplicados) {
                $this->pdo->exec("
                    ALTER TABLE material
                    ADD UNIQUE KEY uq_material_codigo_inventario (codigo_inventario)
                ");
            }
        }
    }

    private function validarCodigoInventarioUnico(?string $codigoInventario, ?int $ignorarId = null): void
    {
        $codigoInventario = trim((string) $codigoInventario);
        if ($codigoInventario === '') {
            return;
        }

        if ($ignorarId !== null && $ignorarId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM material
                WHERE codigo_inventario = :codigo
                  AND id <> :id
                LIMIT 1
            ");
            $stmt->execute([
                'codigo' => $codigoInventario,
                'id' => $ignorarId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id
                FROM material
                WHERE codigo_inventario = :codigo
                LIMIT 1
            ");
            $stmt->execute([
                'codigo' => $codigoInventario,
            ]);
        }

        $exists = $stmt->fetch() !== false;
        if ($exists) {
            throw new RuntimeException('Código de inventário já existe.');
        }
    }
}
