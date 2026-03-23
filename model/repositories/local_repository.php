<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/text_normalizer.php';

final class LocalRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   nome:string,
     *   descricao:?string,
     *   maquinas_count:int,
     *   perifericos_count:int,
     *   extras_count:int,
     *   total_itens_count:int
     * }>
     */
    public function listar(string $search = ''): array
    {
        $sql = "
            SELECT
                l.id,
                l.nome,
                l.`desc` AS descricao,
                COALESCE(ma_cnt.total, 0) AS maquinas_count,
                COALESCE(p_cnt.total, 0) AS perifericos_count,
                COALESCE(e_cnt.total, 0) AS extras_count,
                (
                    COALESCE(ma_cnt.total, 0)
                    + COALESCE(p_cnt.total, 0)
                    + COALESCE(e_cnt.total, 0)
                ) AS total_itens_count
            FROM local l
            LEFT JOIN (
                SELECT ma.local_id, COUNT(*) AS total
                FROM maquina ma
                GROUP BY ma.local_id
            ) ma_cnt ON ma_cnt.local_id = l.id
            LEFT JOIN (
                SELECT p.local_id, COUNT(*) AS total
                FROM periferico p
                GROUP BY p.local_id
            ) p_cnt ON p_cnt.local_id = l.id
            LEFT JOIN (
                SELECT e.local_id, COUNT(*) AS total
                FROM extra e
                GROUP BY e.local_id
            ) e_cnt ON e_cnt.local_id = l.id
        ";

        $params = [];
        $search = trim((string) app_text_fix_mojibake($search));

        if ($search !== '') {
            $sql .= "
                WHERE (
                    CAST(l.id AS CHAR) LIKE :q
                    OR l.nome LIKE :q
                    OR COALESCE(l.`desc`, '') LIKE :q
                )
            ";
            $params['q'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY l.nome ASC, l.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'nome' => $this->cleanText((string) $row['nome']),
                'descricao' => $this->cleanNullableString(isset($row['descricao']) ? (string) $row['descricao'] : null),
                'maquinas_count' => (int) ($row['maquinas_count'] ?? 0),
                'perifericos_count' => (int) ($row['perifericos_count'] ?? 0),
                'extras_count' => (int) ($row['extras_count'] ?? 0),
                'total_itens_count' => (int) ($row['total_itens_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array{id:int,nome:string,descricao:?string}|null
     */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                nome,
                `desc` AS descricao
            FROM local
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'nome' => $this->cleanText((string) $row['nome']),
            'descricao' => $this->cleanNullableString(isset($row['descricao']) ? (string) $row['descricao'] : null),
        ];
    }

    public function nomeExiste(string $nome, ?int $ignorarId = null): bool
    {
        $nome = $this->cleanText($nome);
        if ($nome === '') {
            return false;
        }

        $sql = "
            SELECT id
            FROM local
            WHERE LOWER(nome) = LOWER(:nome)
        ";
        $params = ['nome' => $nome];

        if ($ignorarId !== null) {
            $sql .= " AND id <> :ignorar_id";
            $params['ignorar_id'] = $ignorarId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    public function criar(string $nome, ?string $descricao): int
    {
        $nome = $this->cleanText($nome);
        if ($nome === '') {
            throw new InvalidArgumentException("Nome do local \u{00E9} obrigat\u{00F3}rio.");
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO local (nome, `desc`)
            VALUES (:nome, :descricao)
        ");
        $stmt->execute([
            'nome' => $nome,
            'descricao' => $this->cleanNullableString($descricao),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function atualizar(int $id, string $nome, ?string $descricao): bool
    {
        $nome = $this->cleanText($nome);
        if ($nome === '') {
            throw new InvalidArgumentException("Nome do local \u{00E9} obrigat\u{00F3}rio.");
        }

        if (!$this->existePorId($id)) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE local
            SET nome = :nome,
                `desc` = :descricao
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $id,
            'nome' => $nome,
            'descricao' => $this->cleanNullableString($descricao),
        ]);

        return true;
    }

    public function apagar(int $id): bool
    {
        $deps = $this->contarDependencias($id);
        if ($deps['total'] > 0) {
            throw new RuntimeException("Local possui itens vinculados e n\u{00E3}o pode ser removido.");
        }

        $stmt = $this->pdo->prepare("DELETE FROM local WHERE id = :id");
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{maquinas:int,perifericos:int,extras:int,total:int}
     */
    public function contarDependencias(int $id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM maquina ma
                    WHERE ma.local_id = :id_maquinas
                ) AS maquinas,
                (
                    SELECT COUNT(*)
                    FROM periferico p
                    WHERE p.local_id = :id_perifericos
                ) AS perifericos,
                (
                    SELECT COUNT(*)
                    FROM extra e
                    WHERE e.local_id = :id_extras
                ) AS extras
        ");
        $stmt->execute([
            'id_maquinas' => $id,
            'id_perifericos' => $id,
            'id_extras' => $id,
        ]);
        $row = $stmt->fetch();

        $maquinas = (int) ($row['maquinas'] ?? 0);
        $perifericos = (int) ($row['perifericos'] ?? 0);
        $extras = (int) ($row['extras'] ?? 0);

        return [
            'maquinas' => $maquinas,
            'perifericos' => $perifericos,
            'extras' => $extras,
            'total' => $maquinas + $perifericos + $extras,
        ];
    }

    private function existePorId(int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM local WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetch();
    }

    private function cleanNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) app_text_fix_mojibake($value));
        return $trimmed === '' ? null : $trimmed;
    }

    private function cleanText(string $value): string
    {
        return trim((string) app_text_fix_mojibake($value));
    }
}
