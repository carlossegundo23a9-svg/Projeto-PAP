<?php
declare(strict_types=1);

if (!function_exists('app_password_reset_ensure_schema')) {
    function app_password_reset_ensure_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                requested_ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_password_reset_tokens_hash (token_hash),
                KEY idx_password_reset_tokens_user (user_id, created_at),
                KEY idx_password_reset_tokens_exp_used (expires_at, used_at),
                CONSTRAINT fk_password_reset_tokens_user
                    FOREIGN KEY (user_id) REFERENCES user(id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }
}

if (!function_exists('app_password_reset_cleanup')) {
    function app_password_reset_cleanup(PDO $pdo): void
    {
        app_password_reset_ensure_schema($pdo);

        $pdo->exec("
            DELETE FROM password_reset_tokens
            WHERE (used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY))
               OR (expires_at < (NOW() - INTERVAL 2 DAY))
        ");
    }
}

if (!function_exists('app_password_reset_find_user_by_email')) {
    /**
     * @return array{id:int,nome:string,email:string}|null
     */
    function app_password_reset_find_user_by_email(PDO $pdo, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT id, nome, email, ativo
            FROM user
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (isset($row['ativo']) && (int) $row['ativo'] !== 1) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nome' => trim((string) ($row['nome'] ?? '')),
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
        ];
    }
}

if (!function_exists('app_password_reset_create_token')) {
    function app_password_reset_create_token(PDO $pdo, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        app_password_reset_cleanup($pdo);

        $stmtInvalidate = $pdo->prepare("
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE user_id = :user_id
              AND used_at IS NULL
        ");
        $stmtInvalidate->execute(['user_id' => $userId]);

        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');

        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $ip = $ip !== '' ? substr($ip, 0, 45) : null;
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $userAgent = $userAgent !== '' ? substr($userAgent, 0, 255) : null;

        $stmtInsert = $pdo->prepare("
            INSERT INTO password_reset_tokens (
                user_id,
                token_hash,
                expires_at,
                requested_ip,
                user_agent
            ) VALUES (
                :user_id,
                :token_hash,
                :expires_at,
                :requested_ip,
                :user_agent
            )
        ");
        $stmtInsert->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        return $token;
    }
}

if (!function_exists('app_password_reset_build_url')) {
    function app_password_reset_build_url(int $userId, string $token): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($host === '') {
            $host = 'localhost';
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/login/recuperar.php'));
        $basePath = rtrim((string) dirname($script), '/');
        if ($basePath === '' || $basePath === '.') {
            $basePath = '/login';
        }

        return sprintf(
            '%s://%s%s/recuperar.php?uid=%d&token=%s',
            $scheme,
            $host,
            $basePath,
            $userId,
            rawurlencode($token)
        );
    }
}

if (!function_exists('app_password_reset_is_valid')) {
    function app_password_reset_is_valid(PDO $pdo, int $userId, string $token): bool
    {
        if ($userId <= 0 || trim($token) === '') {
            return false;
        }

        app_password_reset_cleanup($pdo);

        $stmt = $pdo->prepare("
            SELECT prt.id
            FROM password_reset_tokens prt
            INNER JOIN user u ON u.id = prt.user_id
            WHERE prt.user_id = :user_id
              AND prt.token_hash = :token_hash
              AND prt.used_at IS NULL
              AND prt.expires_at >= NOW()
              AND u.ativo = 1
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', trim($token)),
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('app_password_reset_consume')) {
    function app_password_reset_consume(
        PDO $pdo,
        int $userId,
        string $token,
        string $newPassword,
        ?string &$error = null
    ): bool {
        $token = trim($token);
        $newPassword = (string) $newPassword;

        if ($userId <= 0 || $token === '') {
            $error = 'Link de recuperação inválido.';
            return false;
        }
        if (strlen($newPassword) < 8) {
            $error = 'A palavra-passe deve ter, no mínimo, 8 caracteres.';
            return false;
        }

        app_password_reset_cleanup($pdo);

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            $stmtToken = $pdo->prepare("
                SELECT id
                FROM password_reset_tokens
                WHERE user_id = :user_id
                  AND token_hash = :token_hash
                  AND used_at IS NULL
                  AND expires_at >= NOW()
                LIMIT 1
                FOR UPDATE
            ");
            $stmtToken->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
            ]);
            $tokenId = (int) ($stmtToken->fetchColumn() ?: 0);

            if ($tokenId <= 0) {
                $pdo->rollBack();
                $error = 'O link é inválido ou já expirou.';
                return false;
            }

            $stmtUser = $pdo->prepare("
                SELECT id
                FROM user
                WHERE id = :id
                  AND ativo = 1
                LIMIT 1
                FOR UPDATE
            ");
            $stmtUser->execute(['id' => $userId]);
            $validUserId = (int) ($stmtUser->fetchColumn() ?: 0);
            if ($validUserId <= 0) {
                $pdo->rollBack();
                $error = 'A conta associada não está disponível.';
                return false;
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmtUpdatePassword = $pdo->prepare("
                UPDATE user
                SET password = :password
                WHERE id = :id
            ");
            $stmtUpdatePassword->execute([
                'password' => $passwordHash,
                'id' => $userId,
            ]);

            $stmtConsumeTokens = $pdo->prepare("
                UPDATE password_reset_tokens
                SET used_at = NOW()
                WHERE user_id = :user_id
                  AND used_at IS NULL
            ");
            $stmtConsumeTokens->execute(['user_id' => $userId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Não foi possível redefinir a palavra-passe neste momento.';
            error_log('app_password_reset_consume falhou: ' . $e->getMessage());
            return false;
        }
    }
}
