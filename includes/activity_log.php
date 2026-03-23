<?php
declare(strict_types=1);

require_once __DIR__ . '/text_normalizer.php';

if (!function_exists('app_log_modulos_validos')) {
    /**
     * @return array<string,string>
     */
    function app_log_modulos_validos(): array
    {
        return [
            'sistema' => 'Sistema',
            'autenticacao' => 'Autenticação',
            'dashboard' => 'Dashboard',
            'bens' => 'Bens',
            'localizacoes' => 'Localizações',
            'relatorios' => 'Relatórios',
            'emprestimos' => 'Empréstimos',
            'utilizadores' => 'Utilizadores',
            'configuracoes' => 'Configurações',
            'notificacoes' => 'Notificações',
        ];
    }
}

if (!function_exists('app_log_severidades_validas')) {
    /**
     * @return array<string,string>
     */
    function app_log_severidades_validas(): array
    {
        return [
            'info' => 'Info',
            'warning' => 'Warning',
            'erro' => 'Erro',
            'critico' => 'Crítico',
        ];
    }
}

if (!function_exists('app_log_estados_validos')) {
    /**
     * @return array<string,string>
     */
    function app_log_estados_validos(): array
    {
        return [
            'sucesso' => 'Sucesso',
            'erro' => 'Erro',
        ];
    }
}

if (!function_exists('app_log_column_exists')) {
    function app_log_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('app_log_index_exists')) {
    function app_log_index_exists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND INDEX_NAME = :index_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('app_log_ensure_schema')) {
    function app_log_ensure_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acao VARCHAR(120) NOT NULL,
                modulo VARCHAR(40) NULL,
                severidade VARCHAR(12) NULL,
                estado VARCHAR(10) NULL,
                detalhes LONGTEXT NULL,
                user_id INT UNSIGNED NULL,
                ator_nome VARCHAR(120) NULL,
                ator_email VARCHAR(180) NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                PRIMARY KEY (id),
                KEY idx_app_logs_created_at (created_at),
                KEY idx_app_logs_acao (acao),
                KEY idx_app_logs_modulo (modulo),
                KEY idx_app_logs_severidade (severidade),
                KEY idx_app_logs_estado (estado),
                KEY idx_app_logs_user_id (user_id),
                CONSTRAINT fk_app_logs_user
                    FOREIGN KEY (user_id) REFERENCES user(id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!app_log_column_exists($pdo, 'app_logs', 'modulo')) {
            $pdo->exec("ALTER TABLE app_logs ADD COLUMN modulo VARCHAR(40) NULL AFTER acao");
        }
        if (!app_log_column_exists($pdo, 'app_logs', 'severidade')) {
            $pdo->exec("ALTER TABLE app_logs ADD COLUMN severidade VARCHAR(12) NULL AFTER modulo");
        }
        if (!app_log_column_exists($pdo, 'app_logs', 'estado')) {
            $pdo->exec("ALTER TABLE app_logs ADD COLUMN estado VARCHAR(10) NULL AFTER severidade");
        }

        if (!app_log_index_exists($pdo, 'app_logs', 'idx_app_logs_modulo')) {
            $pdo->exec("ALTER TABLE app_logs ADD KEY idx_app_logs_modulo (modulo)");
        }
        if (!app_log_index_exists($pdo, 'app_logs', 'idx_app_logs_severidade')) {
            $pdo->exec("ALTER TABLE app_logs ADD KEY idx_app_logs_severidade (severidade)");
        }
        if (!app_log_index_exists($pdo, 'app_logs', 'idx_app_logs_estado')) {
            $pdo->exec("ALTER TABLE app_logs ADD KEY idx_app_logs_estado (estado)");
        }

        app_log_backfill_metadata($pdo);

        $checked = true;
    }
}

if (!function_exists('app_log_backfill_metadata')) {
    function app_log_backfill_metadata(PDO $pdo): void
    {
        $maxCiclos = 20;
        $tamanhoLote = 500;
        $ciclo = 0;

        $select = $pdo->prepare("
            SELECT id, acao, detalhes
            FROM app_logs
            WHERE modulo IS NULL OR modulo = ''
               OR severidade IS NULL OR severidade = ''
               OR estado IS NULL OR estado = ''
            ORDER BY id DESC
            LIMIT :limite
        ");

        $update = $pdo->prepare("
            UPDATE app_logs
            SET modulo = :modulo,
                severidade = :severidade,
                estado = :estado
            WHERE id = :id
        ");

        while ($ciclo < $maxCiclos) {
            $select->bindValue(':limite', $tamanhoLote, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll();

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $acao = (string) ($row['acao'] ?? '');
                $detalhesRaw = isset($row['detalhes']) ? (string) $row['detalhes'] : '';
                $detalhes = [];

                if ($detalhesRaw !== '') {
                    $decoded = json_decode($detalhesRaw, true);
                    if (is_array($decoded)) {
                        $detalhes = $decoded;
                    }
                }

                $meta = app_log_classificar_meta($acao, $detalhes);
                $update->execute([
                    'modulo' => $meta['modulo'],
                    'severidade' => $meta['severidade'],
                    'estado' => $meta['estado'],
                    'id' => (int) ($row['id'] ?? 0),
                ]);
            }

            $ciclo++;
        }
    }
}

if (!function_exists('app_log_normalize_term')) {
    function app_log_normalize_term(string $value): string
    {
        $clean = trim((string) app_text_fix_mojibake($value));
        $clean = mb_strtolower($clean, 'UTF-8');

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
        if (is_string($ascii) && $ascii !== '') {
            $clean = $ascii;
        }

        return mb_strtolower($clean, 'UTF-8');
    }
}

if (!function_exists('app_log_contains_any')) {
    /**
     * @param array<int,string> $terms
     */
    function app_log_contains_any(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && strpos($haystack, $term) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('app_log_sanitize_modulo')) {
    function app_log_sanitize_modulo(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $modulo = strtolower($raw);
        $validos = app_log_modulos_validos();

        return isset($validos[$modulo]) ? $modulo : null;
    }
}

if (!function_exists('app_log_sanitize_severidade')) {
    function app_log_sanitize_severidade(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $severidade = strtolower($raw);
        $validos = app_log_severidades_validas();

        return isset($validos[$severidade]) ? $severidade : null;
    }
}

if (!function_exists('app_log_sanitize_estado')) {
    function app_log_sanitize_estado(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $estado = strtolower($raw);
        $validos = app_log_estados_validos();

        return isset($validos[$estado]) ? $estado : null;
    }
}

if (!function_exists('app_log_infer_modulo')) {
    /**
     * @param array<string,mixed> $detalhes
     */
    function app_log_infer_modulo(string $acao, array $detalhes = []): string
    {
        $normalized = app_log_normalize_term($acao);
        $tipoDetalhe = app_log_normalize_term((string) ($detalhes['tipo'] ?? ''));

        if (app_log_contains_any($normalized, ['login', 'logout', 'sessao'])) {
            return 'autenticacao';
        }
        if (
            app_log_contains_any($normalized, ['emprest', 'devolv', 'recebid'])
            || app_log_contains_any($tipoDetalhe, ['emprestimo'])
        ) {
            return 'emprestimos';
        }
        if (
            app_log_contains_any($normalized, ['notific'])
            || app_log_contains_any($tipoDetalhe, ['email_atraso', 'email_confirmacao', 'notific'])
        ) {
            return 'notificacoes';
        }
        if (app_log_contains_any($normalized, ['invent', 'item adicionado', 'item editado', 'item removido', 'manutencao', 'abate'])) {
            return 'bens';
        }
        if (app_log_contains_any($normalized, ['localizac', 'local'])) {
            return 'localizacoes';
        }
        if (app_log_contains_any($normalized, ['utilizador', 'admin', 'aluno', 'formador', 'turma'])) {
            return 'utilizadores';
        }
        if (app_log_contains_any($normalized, ['relatorio'])) {
            return 'relatorios';
        }
        if (app_log_contains_any($normalized, ['configurac'])) {
            return 'configuracoes';
        }
        if (app_log_contains_any($normalized, ['dashboard', 'painel'])) {
            return 'dashboard';
        }

        return 'sistema';
    }
}

if (!function_exists('app_log_infer_severidade')) {
    /**
     * @param array<string,mixed> $detalhes
     */
    function app_log_infer_severidade(string $acao, array $detalhes = []): string
    {
        $normalized = app_log_normalize_term($acao);
        $statusDetalhe = app_log_normalize_term((string) ($detalhes['status'] ?? ''));

        if (
            app_log_contains_any($normalized, ['erro', 'falha', 'inval', 'negad', 'bloque'])
            || app_log_contains_any($statusDetalhe, ['erro', 'falha'])
        ) {
            return 'erro';
        }

        if (app_log_contains_any($normalized, ['remov', 'apag', 'elimin'])) {
            return 'critico';
        }

        if (app_log_contains_any($normalized, ['desativ', 'arquiv', 'abate', 'manutenc', 'atras', 'notific'])) {
            return 'warning';
        }

        return 'info';
    }
}

if (!function_exists('app_log_infer_estado')) {
    /**
     * @param array<string,mixed> $detalhes
     */
    function app_log_infer_estado(string $acao, array $detalhes = []): string
    {
        $statusDetalhe = app_log_normalize_term((string) ($detalhes['status'] ?? ''));
        if ($statusDetalhe === 'erro' || $statusDetalhe === 'falha') {
            return 'erro';
        }

        $normalized = app_log_normalize_term($acao);
        if (app_log_contains_any($normalized, ['erro', 'falha', 'inval', 'negad', 'bloque'])) {
            return 'erro';
        }

        return 'sucesso';
    }
}

if (!function_exists('app_log_classificar_meta')) {
    /**
     * @param array<string,mixed> $detalhes
     * @return array{modulo:string,severidade:string,estado:string}
     */
    function app_log_classificar_meta(string $acao, array $detalhes = []): array
    {
        $modulo = app_log_infer_modulo($acao, $detalhes);
        $severidade = app_log_infer_severidade($acao, $detalhes);
        $estado = app_log_infer_estado($acao, $detalhes);

        if ($estado === 'erro' && $severidade === 'info') {
            $severidade = 'erro';
        }

        return [
            'modulo' => $modulo,
            'severidade' => $severidade,
            'estado' => $estado,
        ];
    }
}

if (!function_exists('app_log_current_user_id')) {
    function app_log_current_user_id(): ?int
    {
        $raw = $_SESSION['user_id'] ?? ($_SESSION['id_utilizador'] ?? null);
        if ($raw === null) {
            return null;
        }

        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('app_log_actor_nome')) {
    function app_log_actor_nome(): ?string
    {
        $nome = trim((string) ($_SESSION['nome'] ?? ''));
        return $nome !== '' ? $nome : null;
    }
}

if (!function_exists('app_log_lookup_email')) {
    function app_log_lookup_email(PDO $pdo, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT email FROM user WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $email = trim((string) $stmt->fetchColumn());

        return $email !== '' ? $email : null;
    }
}

if (!function_exists('app_log_registar')) {
    /**
     * @param array<string,mixed> $detalhes
     */
    function app_log_registar(
        PDO $pdo,
        string $acao,
        array $detalhes = [],
        ?int $userId = null,
        ?string $modulo = null,
        ?string $severidade = null,
        ?string $estado = null
    ): void {
        try {
            app_log_ensure_schema($pdo);

            $acao = trim((string) app_text_fix_mojibake($acao));
            if ($acao === '') {
                return;
            }

            if (strlen($acao) > 120) {
                $acao = substr($acao, 0, 120);
            }

            $actorId = $userId;
            if ($actorId === null) {
                $actorId = app_log_current_user_id();
            }

            $atorNome = app_log_actor_nome();
            $atorNome = $atorNome !== null ? app_text_fix_mojibake($atorNome) : null;
            $atorEmail = $actorId !== null ? app_log_lookup_email($pdo, $actorId) : null;

            $payload = null;
            $detalhesFixos = [];
            if ($detalhes !== []) {
                $detalhesFixos = app_text_fix_mojibake_deep($detalhes);
                $json = json_encode($detalhesFixos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== false) {
                    $payload = $json;
                }
            }

            $meta = app_log_classificar_meta($acao, $detalhesFixos);
            $moduloFinal = app_log_sanitize_modulo($modulo) ?? $meta['modulo'];
            $severidadeFinal = app_log_sanitize_severidade($severidade) ?? $meta['severidade'];
            $estadoFinal = app_log_sanitize_estado($estado) ?? $meta['estado'];

            $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $ip = $ip !== '' ? substr($ip, 0, 45) : null;

            $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $userAgent = $userAgent !== '' ? substr($userAgent, 0, 255) : null;

            $stmt = $pdo->prepare("
                INSERT INTO app_logs (
                    acao,
                    modulo,
                    severidade,
                    estado,
                    detalhes,
                    user_id,
                    ator_nome,
                    ator_email,
                    ip,
                    user_agent
                ) VALUES (
                    :acao,
                    :modulo,
                    :severidade,
                    :estado,
                    :detalhes,
                    :user_id,
                    :ator_nome,
                    :ator_email,
                    :ip,
                    :user_agent
                )
            ");
            $stmt->execute([
                'acao' => $acao,
                'modulo' => $moduloFinal,
                'severidade' => $severidadeFinal,
                'estado' => $estadoFinal,
                'detalhes' => $payload,
                'user_id' => $actorId,
                'ator_nome' => $atorNome,
                'ator_email' => $atorEmail,
                'ip' => $ip,
                'user_agent' => $userAgent,
            ]);
        } catch (Throwable $e) {
            error_log('app_log_registar falhou: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_log_meta_sql_expressions')) {
    /**
     * @return array{modulo:string,severidade:string,estado:string}
     */
    function app_log_meta_sql_expressions(): array
    {
        $acao = "LOWER(COALESCE(acao, ''))";

        $moduloFallback = "
            CASE
                WHEN {$acao} LIKE '%login%' OR {$acao} LIKE '%logout%' OR {$acao} LIKE '%sessao%' THEN 'autenticacao'
                WHEN {$acao} LIKE '%emprest%' OR {$acao} LIKE '%devolv%' OR {$acao} LIKE '%recebid%' THEN 'emprestimos'
                WHEN {$acao} LIKE '%notific%' THEN 'notificacoes'
                WHEN {$acao} LIKE '%invent%' OR {$acao} LIKE '%item adicionado%' OR {$acao} LIKE '%item editado%' OR {$acao} LIKE '%item removido%' OR {$acao} LIKE '%manutenc%' OR {$acao} LIKE '%abate%' THEN 'bens'
                WHEN {$acao} LIKE '%localizac%' OR {$acao} LIKE '%local%' THEN 'localizacoes'
                WHEN {$acao} LIKE '%utilizador%' OR {$acao} LIKE '%admin%' OR {$acao} LIKE '%aluno%' OR {$acao} LIKE '%formador%' OR {$acao} LIKE '%turma%' THEN 'utilizadores'
                WHEN {$acao} LIKE '%relatorio%' THEN 'relatorios'
                WHEN {$acao} LIKE '%configurac%' THEN 'configuracoes'
                WHEN {$acao} LIKE '%dashboard%' OR {$acao} LIKE '%painel%' THEN 'dashboard'
                ELSE 'sistema'
            END
        ";

        $severidadeFallback = "
            CASE
                WHEN {$acao} LIKE '%erro%' OR {$acao} LIKE '%falha%' OR {$acao} LIKE '%inval%' OR {$acao} LIKE '%negad%' OR {$acao} LIKE '%bloque%' THEN 'erro'
                WHEN {$acao} LIKE '%remov%' OR {$acao} LIKE '%apag%' OR {$acao} LIKE '%elimin%' THEN 'critico'
                WHEN {$acao} LIKE '%desativ%' OR {$acao} LIKE '%arquiv%' OR {$acao} LIKE '%abate%' OR {$acao} LIKE '%manutenc%' OR {$acao} LIKE '%atras%' OR {$acao} LIKE '%notific%' THEN 'warning'
                ELSE 'info'
            END
        ";

        $estadoFallback = "
            CASE
                WHEN {$acao} LIKE '%erro%' OR {$acao} LIKE '%falha%' OR {$acao} LIKE '%inval%' OR {$acao} LIKE '%negad%' OR {$acao} LIKE '%bloque%' THEN 'erro'
                ELSE 'sucesso'
            END
        ";

        return [
            'modulo' => "COALESCE(NULLIF(modulo, ''), {$moduloFallback})",
            'severidade' => "COALESCE(NULLIF(severidade, ''), {$severidadeFallback})",
            'estado' => "COALESCE(NULLIF(estado, ''), {$estadoFallback})",
        ];
    }
}

if (!function_exists('app_log_normalize_date_filter')) {
    function app_log_normalize_date_filter(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if (!$date || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $raw;
    }
}

if (!function_exists('app_log_listar_filtrado')) {
    /**
     * @param array<string,mixed> $filtros
     * @return array<int, array{
     *   id:int,
     *   created_at:string,
     *   acao:string,
     *   modulo:string,
     *   severidade:string,
     *   estado:string,
     *   detalhes:?string,
     *   ator:string,
     *   ip:?string,
     *   user_agent:?string
     * }>
     */
    function app_log_listar_filtrado(PDO $pdo, array $filtros = [], int $limite = 300): array
    {
        app_log_ensure_schema($pdo);

        $limite = max(1, min($limite, 10000));
        $expr = app_log_meta_sql_expressions();

        $where = [];
        $params = [];

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(
                acao LIKE :q
                OR COALESCE(detalhes, '') LIKE :q
                OR COALESCE(ator_nome, '') LIKE :q
                OR COALESCE(ator_email, '') LIKE :q
                OR COALESCE(ip, '') LIKE :q
                OR {$expr['modulo']} LIKE :q
            )";
            $params['q'] = '%' . $q . '%';
        }

        $utilizador = trim((string) ($filtros['utilizador'] ?? ''));
        if ($utilizador !== '') {
            $where[] = "(
                COALESCE(ator_nome, '') LIKE :utilizador
                OR COALESCE(ator_email, '') LIKE :utilizador
                OR CONCAT('User #', COALESCE(user_id, 0)) LIKE :utilizador
            )";
            $params['utilizador'] = '%' . $utilizador . '%';
        }

        $acao = trim((string) ($filtros['acao'] ?? ''));
        if ($acao !== '') {
            $where[] = "acao = :acao";
            $params['acao'] = $acao;
        }

        $modulo = app_log_sanitize_modulo(isset($filtros['modulo']) ? (string) $filtros['modulo'] : null);
        if ($modulo !== null) {
            $where[] = "{$expr['modulo']} = :modulo";
            $params['modulo'] = $modulo;
        }

        $severidade = app_log_sanitize_severidade(isset($filtros['severidade']) ? (string) $filtros['severidade'] : null);
        if ($severidade !== null) {
            $where[] = "{$expr['severidade']} = :severidade";
            $params['severidade'] = $severidade;
        }

        $estado = app_log_sanitize_estado(isset($filtros['estado']) ? (string) $filtros['estado'] : null);
        if ($estado !== null) {
            $where[] = "{$expr['estado']} = :estado";
            $params['estado'] = $estado;
        }

        $dataInicio = app_log_normalize_date_filter(isset($filtros['data_inicio']) ? (string) $filtros['data_inicio'] : null);
        if ($dataInicio !== null) {
            $where[] = "created_at >= :data_inicio";
            $params['data_inicio'] = $dataInicio . ' 00:00:00';
        }

        $dataFim = app_log_normalize_date_filter(isset($filtros['data_fim']) ? (string) $filtros['data_fim'] : null);
        if ($dataFim !== null) {
            $dateFim = DateTimeImmutable::createFromFormat('Y-m-d', $dataFim);
            if ($dateFim !== false) {
                $where[] = "created_at < :data_fim_exclusiva";
                $params['data_fim_exclusiva'] = $dateFim->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            }
        }

        $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT
                id,
                created_at,
                acao,
                {$expr['modulo']} AS modulo_final,
                {$expr['severidade']} AS severidade_final,
                {$expr['estado']} AS estado_final,
                detalhes,
                COALESCE(NULLIF(ator_nome, ''), NULLIF(ator_email, ''), CONCAT('User #', user_id), 'Sistema') AS ator,
                ip,
                user_agent
            FROM app_logs
            {$whereSql}
            ORDER BY id DESC
            LIMIT :limite
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = [
                'id' => (int) ($row['id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'acao' => (string) ($row['acao'] ?? ''),
                'modulo' => (string) ($row['modulo_final'] ?? 'sistema'),
                'severidade' => (string) ($row['severidade_final'] ?? 'info'),
                'estado' => (string) ($row['estado_final'] ?? 'sucesso'),
                'detalhes' => isset($row['detalhes']) ? (string) $row['detalhes'] : null,
                'ator' => (string) ($row['ator'] ?? 'Sistema'),
                'ip' => isset($row['ip']) ? (string) $row['ip'] : null,
                'user_agent' => isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            ];
        }

        return $logs;
    }
}

if (!function_exists('app_log_listar_acoes_disponiveis')) {
    /**
     * @return array<int,string>
     */
    function app_log_listar_acoes_disponiveis(PDO $pdo, int $limite = 120): array
    {
        app_log_ensure_schema($pdo);
        $limite = max(1, min($limite, 500));

        $stmt = $pdo->prepare("
            SELECT acao
            FROM app_logs
            GROUP BY acao
            ORDER BY MAX(id) DESC
            LIMIT :limite
        ");
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $acoes = [];
        foreach ((array) $stmt->fetchAll() as $row) {
            $acao = trim((string) ($row['acao'] ?? ''));
            if ($acao !== '') {
                $acoes[] = $acao;
            }
        }

        return $acoes;
    }
}

if (!function_exists('app_log_listar')) {
    /**
     * @return array<int, array{
     *   id:int,
     *   created_at:string,
     *   acao:string,
     *   modulo:string,
     *   severidade:string,
     *   estado:string,
     *   detalhes:?string,
     *   ator:string,
     *   ip:?string,
     *   user_agent:?string
     * }>
     */
    function app_log_listar(PDO $pdo, int $limite = 200): array
    {
        return app_log_listar_filtrado($pdo, [], $limite);
    }
}
