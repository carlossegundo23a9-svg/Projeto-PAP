<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/activity_log.php';

if (!function_exists('app_notifier_format_date_br')) {
    function app_notifier_format_date_br(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '-';
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return '-';
        }

        return date('d/m/Y', $ts);
    }
}

if (!function_exists('app_notifier_tipo_emprestimo')) {
    function app_notifier_tipo_emprestimo(?string $obsInicio): string
    {
        $raw = trim((string) $obsInicio);
        if ($raw === '') {
            return '';
        }

        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return '';
        }

        $tipo = isset($meta['tipo']) ? trim((string) $meta['tipo']) : '';
        return $tipo;
    }
}

if (!function_exists('app_notifier_escape_html')) {
    function app_notifier_escape_html(?string $value): string
    {
        return htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app_notifier_compor_email_html')) {
    /**
     * @param array<string,string> $detalhes
     */
    function app_notifier_compor_email_html(
        string $badge,
        string $titulo,
        string $saudacao,
        string $mensagem,
        array $detalhes,
        string $notaFinal,
        string $accentColor = '#60a5fa'
    ): string {
        $rows = '';
        foreach ($detalhes as $label => $value) {
            $label = trim((string) $label);
            $value = trim((string) $value);
            if ($label === '' || $value === '') {
                continue;
            }

            $rows .= '<tr>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #263449;color:#9bb0ca;font-size:13px;width:34%;">'
                . app_notifier_escape_html($label)
                . '</td>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #263449;color:#e5edf8;font-size:14px;font-weight:600;">'
                . nl2br(app_notifier_escape_html($value))
                . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td style="padding:10px 12px;color:#9bb0ca;font-size:13px;">Sem detalhes disponíveis.</td></tr>';
        }

        $badgeHtml = app_notifier_escape_html($badge);
        $tituloHtml = app_notifier_escape_html($titulo);
        $saudacaoHtml = app_notifier_escape_html($saudacao);
        $mensagemHtml = app_notifier_escape_html($mensagem);
        $notaFinalHtml = app_notifier_escape_html($notaFinal);
        $accentColor = trim($accentColor) !== '' ? trim($accentColor) : '#60a5fa';

        return '<!doctype html>'
            . '<html lang="pt-PT">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $tituloHtml . '</title>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#0b1220;font-family:Segoe UI,Arial,sans-serif;color:#e5edf8;">'
            . '<div style="padding:28px 14px;background:radial-gradient(circle at 8% -10%, rgba(96,165,250,0.28), transparent 42%),radial-gradient(circle at 102% 0%, rgba(16,185,129,0.18), transparent 38%),#0b1220;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:700px;margin:0 auto;border-collapse:collapse;">'
            . '<tr>'
            . '<td style="border:1px solid #263449;border-radius:14px;background:#111b2e;padding:0;overflow:hidden;">'
            . '<div style="padding:18px 20px;background:#0f172a;border-bottom:1px solid #263449;">'
            . '<div style="display:inline-block;padding:5px 10px;border-radius:999px;background:rgba(96,165,250,0.18);color:' . app_notifier_escape_html($accentColor) . ';font-size:12px;font-weight:700;letter-spacing:0.2px;">'
            . $badgeHtml
            . '</div>'
            . '<h1 style="margin:12px 0 2px 0;font-size:22px;line-height:1.25;color:#e5edf8;">'
            . $tituloHtml
            . '</h1>'
            . '<p style="margin:0;color:#9bb0ca;font-size:13px;">ESTEL SGP</p>'
            . '</div>'
            . '<div style="padding:20px;">'
            . '<p style="margin:0 0 10px 0;font-size:15px;color:#e5edf8;">' . $saudacaoHtml . '</p>'
            . '<p style="margin:0 0 16px 0;font-size:14px;color:#c7d5e8;line-height:1.5;">' . $mensagemHtml . '</p>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border:1px solid #263449;border-radius:10px;background:#0f172a;border-collapse:separate;border-spacing:0;overflow:hidden;">'
            . $rows
            . '</table>'
            . '<div style="margin-top:16px;padding:12px 14px;border-radius:10px;border:1px solid rgba(96,165,250,0.28);background:rgba(96,165,250,0.08);color:#dbeafe;font-size:13px;line-height:1.45;">'
            . $notaFinalHtml
            . '</div>'
            . '<p style="margin:16px 0 0 0;color:#7f93af;font-size:12px;">Mensagem automática - ESTEL SGP</p>'
            . '</div>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</div>'
            . '</body>'
            . '</html>';
    }
}

if (!function_exists('app_notifier_compor_email_texto')) {
    /**
     * @param array<string,string> $detalhes
     */
    function app_notifier_compor_email_texto(
        string $saudacao,
        string $mensagem,
        array $detalhes,
        string $notaFinal
    ): string {
        $linhas = [
            trim($saudacao),
            '',
            trim($mensagem),
            '',
        ];

        foreach ($detalhes as $label => $value) {
            $label = trim((string) $label);
            $value = trim((string) $value);
            if ($label === '' || $value === '') {
                continue;
            }

            $linhas[] = $label . ': ' . $value;
        }

        $linhas[] = '';
        $linhas[] = trim($notaFinal);
        $linhas[] = '';
        $linhas[] = 'Mensagem automática - ESTEL SGP.';

        return implode("\n", $linhas);
    }
}

if (!function_exists('app_notifier_ensure_schema')) {
    function app_notifier_ensure_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $columns = [
            'email_longa_enviado_em' => "ALTER TABLE emprestimo ADD COLUMN email_longa_enviado_em DATETIME NULL AFTER obs_fim",
            'ultimo_email_atraso_em' => "ALTER TABLE emprestimo ADD COLUMN ultimo_email_atraso_em DATETIME NULL AFTER email_longa_enviado_em",
        ];

        foreach ($columns as $column => $sql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM emprestimo LIKE " . $pdo->quote($column));
            $exists = $stmt->fetch() !== false;
            if (!$exists) {
                $pdo->exec($sql);
            }
        }

        $checked = true;
    }
}

if (!function_exists('app_notifier_detalhes_emprestimo')) {
    /**
     * @return array{
     *   emprestimo_id:int,
     *   data_inicio:string,
     *   prazo_entrega:string,
     *   data_fim:?string,
     *   obs_inicio:?string,
     *   aluno_nome:string,
     *   aluno_email:string,
     *   gestor_nome:string,
     *   gestor_email:string,
     *   itens:string
     * }|null
     */
    function app_notifier_detalhes_emprestimo(PDO $pdo, int $emprestimoId): ?array
    {
        if ($emprestimoId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                e.id AS emprestimo_id,
                e.data_inicio,
                e.prazo_entrega,
                e.data_fim,
                e.obs_inicio,
                c.nome AS aluno_nome,
                c.email AS aluno_email,
                u.nome AS gestor_nome,
                u.email AS gestor_email,
                GROUP_CONCAT(
                    DISTINCT m.nome
                    ORDER BY m.id ASC
                    SEPARATOR ', '
                ) AS itens
            FROM emprestimo e
            INNER JOIN cliente c ON c.id = e.cliente_id
            INNER JOIN user u ON u.id = e.user_id
            INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
            INNER JOIN material m ON m.id = em.material_id
            WHERE e.id = :id
            GROUP BY
                e.id,
                e.data_inicio,
                e.prazo_entrega,
                e.data_fim,
                e.obs_inicio,
                c.nome,
                c.email,
                u.nome,
                u.email
            LIMIT 1
        ");
        $stmt->execute(['id' => $emprestimoId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'emprestimo_id' => (int) ($row['emprestimo_id'] ?? 0),
            'data_inicio' => (string) ($row['data_inicio'] ?? ''),
            'prazo_entrega' => (string) ($row['prazo_entrega'] ?? ''),
            'data_fim' => isset($row['data_fim']) ? (string) $row['data_fim'] : null,
            'obs_inicio' => isset($row['obs_inicio']) ? (string) $row['obs_inicio'] : null,
            'aluno_nome' => trim((string) ($row['aluno_nome'] ?? '')),
            'aluno_email' => trim((string) ($row['aluno_email'] ?? '')),
            'gestor_nome' => trim((string) ($row['gestor_nome'] ?? '')),
            'gestor_email' => trim((string) ($row['gestor_email'] ?? '')),
            'itens' => trim((string) ($row['itens'] ?? '')),
        ];
    }
}

if (!function_exists('app_notifier_marcar_email_longa_enviado')) {
    function app_notifier_marcar_email_longa_enviado(PDO $pdo, int $emprestimoId): void
    {
        $stmt = $pdo->prepare("
            UPDATE emprestimo
            SET email_longa_enviado_em = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $emprestimoId]);
    }
}

if (!function_exists('app_notifier_marcar_email_atraso_enviado')) {
    function app_notifier_marcar_email_atraso_enviado(PDO $pdo, int $emprestimoId): void
    {
        $stmt = $pdo->prepare("
            UPDATE emprestimo
            SET ultimo_email_atraso_em = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $emprestimoId]);
    }
}

if (!function_exists('app_notifier_enviar_email_longa_duracao')) {
    function app_notifier_enviar_email_longa_duracao(PDO $pdo, int $emprestimoId, ?string &$error = null): bool
    {
        app_notifier_ensure_schema($pdo);

        $dados = app_notifier_detalhes_emprestimo($pdo, $emprestimoId);
        if (!$dados) {
            $error = 'Empréstimo não encontrado para notificar.';
            return false;
        }

        $tipo = app_notifier_tipo_emprestimo($dados['obs_inicio']);
        if ($tipo !== 'longa_duracao') {
            $error = 'Notificação automática apenas para empréstimo de longa duração.';
            return false;
        }

        if (!app_mail_is_valid_email($dados['aluno_email'])) {
            $error = 'Aluno sem email válido.';
            return false;
        }

        $alunoNome = $dados['aluno_nome'] !== '' ? $dados['aluno_nome'] : 'Aluno';
        $gestorNome = $dados['gestor_nome'] !== '' ? $dados['gestor_nome'] : 'Responsável';
        $gestorInfo = $gestorNome;
        if (app_mail_is_valid_email($dados['gestor_email'])) {
            $gestorInfo .= ' (' . $dados['gestor_email'] . ')';
        }

        $itens = $dados['itens'] !== '' ? $dados['itens'] : 'Item não identificado';
        $dataInicioBr = app_notifier_format_date_br($dados['data_inicio']);
        $dataEntregaBr = app_notifier_format_date_br($dados['prazo_entrega']);
        $subject = 'ESTEL SGP - Confirmação de empréstimo longa duração (#' . (int) $dados['emprestimo_id'] . ')';
        $detalhes = [
            'Itens' => $itens,
            'Data do empréstimo' => $dataInicioBr,
            'Data prevista de entrega' => $dataEntregaBr,
            'Responsável pelo empréstimo' => $gestorInfo,
        ];
        $htmlBody = app_notifier_compor_email_html(
            'Confirmação',
            'Empréstimo de longa duração registado',
            'Olá ' . $alunoNome . ',',
            'O seu empréstimo de longa duração foi confirmado no ESTEL SGP.',
            $detalhes,
            'Guarde este email como comprovativo. Em caso de dúvida, contacte o responsável pelo empréstimo.',
            '#60a5fa'
        );
        $textoBody = app_notifier_compor_email_texto(
            'Olá ' . $alunoNome . ',',
            'O seu empréstimo de longa duração foi registado no ESTEL SGP.',
            $detalhes,
            'Guarde este email como comprovativo.'
        );

        $envioErro = null;
        $ok = app_mail_send_html($dados['aluno_email'], $subject, $htmlBody, $textoBody, $envioErro);
        if (!$ok) {
            $error = $envioErro ?: 'Falha ao enviar email de longa duração.';
            return false;
        }

        app_notifier_marcar_email_longa_enviado($pdo, (int) $dados['emprestimo_id']);
        app_log_registar($pdo, 'Notificação enviada', [
            'tipo' => 'email_confirmacao_longa_duracao',
            'emprestimo_id' => (int) $dados['emprestimo_id'],
            'destinatario' => (string) $dados['aluno_email'],
        ]);
        return true;
    }
}

if (!function_exists('app_notifier_esta_atrasado')) {
    function app_notifier_esta_atrasado(array $dados): bool
    {
        $dataFim = trim((string) ($dados['data_fim'] ?? ''));
        if ($dataFim !== '') {
            return false;
        }

        $prazo = trim((string) ($dados['prazo_entrega'] ?? ''));
        if ($prazo === '') {
            return false;
        }

        return $prazo < date('Y-m-d');
    }
}

if (!function_exists('app_notifier_enviar_email_atraso_emprestimo')) {
    function app_notifier_enviar_email_atraso_emprestimo(PDO $pdo, int $emprestimoId, ?string &$error = null): bool
    {
        app_notifier_ensure_schema($pdo);

        $dados = app_notifier_detalhes_emprestimo($pdo, $emprestimoId);
        if (!$dados) {
            $error = 'Empréstimo não encontrado.';
            return false;
        }

        if (!app_notifier_esta_atrasado($dados)) {
            $error = 'Empréstimo não está em atraso no momento.';
            return false;
        }

        if (!app_mail_is_valid_email($dados['aluno_email'])) {
            $error = 'Aluno sem email válido para atraso.';
            return false;
        }

        $alunoNome = $dados['aluno_nome'] !== '' ? $dados['aluno_nome'] : 'Aluno';
        $itens = $dados['itens'] !== '' ? $dados['itens'] : 'Item não identificado';
        $dataInicioBr = app_notifier_format_date_br($dados['data_inicio']);
        $dataEntregaBr = app_notifier_format_date_br($dados['prazo_entrega']);

        $subject = 'ESTEL SGP - Devolução em atraso ';
        $detalhes = [
            'Equipamento' => $itens,
            'Data do empréstimo' => $dataInicioBr,
            'Data prevista de entrega' => $dataEntregaBr,
        ];
        $htmlBody = app_notifier_compor_email_html(
            'Atraso',
            'Devolução em atraso',
            'Olá ' . $alunoNome . ',',
            'Foi identificado um empréstimo com devolução em atraso no ESTEL SGP.',
            $detalhes,
            'Por favor entregue o equipamento o mais rápido possível para evitar novos alertas.',
            '#f87171'
        );
        $textoBody = app_notifier_compor_email_texto(
            'Olá ' . $alunoNome . ',',
            'Tem um empréstimo com devolução em atraso no ESTEL SGP.',
            $detalhes,
            'Por favor entregue o equipamento o mais rápido possível.'
        );

        $envioErro = null;
        $ok = app_mail_send_html($dados['aluno_email'], $subject, $htmlBody, $textoBody, $envioErro);
        if (!$ok) {
            $error = $envioErro ?: 'Falha ao enviar email de atraso.';
            return false;
        }

        app_notifier_marcar_email_atraso_enviado($pdo, (int) $dados['emprestimo_id']);
        app_log_registar($pdo, 'Notificação enviada', [
            'tipo' => 'email_atraso_emprestimo',
            'emprestimo_id' => (int) $dados['emprestimo_id'],
            'destinatario' => (string) $dados['aluno_email'],
        ]);
        return true;
    }
}

if (!function_exists('app_notifier_enviar_atrasos_pendentes')) {
    /**
     * @return array{
     *   candidatos:int,
     *   enviados:int,
     *   falhas:int,
     *   detalhes:array<int, array{emprestimo_id:int,status:string,mensagem:string}>
     * }
     */
    function app_notifier_enviar_atrasos_pendentes(PDO $pdo, int $limite = 200): array
    {
        app_notifier_ensure_schema($pdo);

        $limite = max(1, min($limite, 1000));
        $stmt = $pdo->prepare("
            SELECT e.id
            FROM emprestimo e
            WHERE e.data_fim IS NULL
              AND e.prazo_entrega < CURDATE()
              AND (
                  e.ultimo_email_atraso_em IS NULL
                  OR DATE(e.ultimo_email_atraso_em) < CURDATE()
              )
            ORDER BY e.prazo_entrega ASC, e.id ASC
            LIMIT :limite
        ");
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $detalhes = [];
        $enviados = 0;
        $falhas = 0;

        foreach ($rows as $row) {
            $emprestimoId = (int) ($row['id'] ?? 0);
            if ($emprestimoId <= 0) {
                continue;
            }

            $erro = null;
            $ok = app_notifier_enviar_email_atraso_emprestimo($pdo, $emprestimoId, $erro);
            if ($ok) {
                $enviados++;
                $detalhes[] = [
                    'emprestimo_id' => $emprestimoId,
                    'status' => 'ok',
                    'mensagem' => 'Email enviado.',
                ];
                continue;
            }

            $falhas++;
            $detalhes[] = [
                'emprestimo_id' => $emprestimoId,
                'status' => 'erro',
                'mensagem' => $erro ?: 'Falha no envio.',
            ];
        }

        return [
            'candidatos' => count($rows),
            'enviados' => $enviados,
            'falhas' => $falhas,
            'detalhes' => $detalhes,
        ];
    }
}

