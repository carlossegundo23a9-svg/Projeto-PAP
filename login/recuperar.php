<?php
declare(strict_types=1);

require_once "../includes/session_security.php";
app_session_start();

require_once "../includes/pdo.php";
require_once "../includes/mailer.php";
require_once "../includes/password_reset.php";
require_once "../includes/activity_log.php";

app_password_reset_ensure_schema($pdo);

if (empty($_SESSION['csrf_recuperar'])) {
    $_SESSION['csrf_recuperar'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_recuperar'];

$cssVUtil = @filemtime(__DIR__ . '/../css/utilizadores.css') ?: time();
$cssVLogin = @filemtime(__DIR__ . '/../css/login.css') ?: time();

$mensagem = '';
$sucesso = false;
$modo = 'pedido';

$uidInput = filter_var($_GET['uid'] ?? $_POST['uid'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$uidInput = $uidInput === false ? 0 : (int) $uidInput;
$tokenInput = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($uidInput > 0 && $tokenInput !== '') {
    $modo = 'reset';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = isset($_POST['csrf_token']) && hash_equals($csrf, (string) $_POST['csrf_token']);
    if (!$tokenOk) {
        $mensagem = 'Pedido inválido. Atualize a página e tente novamente.';
    } else {
        $acao = trim((string) ($_POST['acao'] ?? ''));

        if ($acao === 'pedir_link') {
            $modo = 'pedido';
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $mensagem = 'Indique um e-mail válido.';
            } else {
                try {
                    $user = app_password_reset_find_user_by_email($pdo, $email);
                    if ($user !== null) {
                        $token = app_password_reset_create_token($pdo, (int) $user['id']);
                        if ($token !== null && $token !== '') {
                            $link = app_password_reset_build_url((int) $user['id'], $token);
                            $assunto = 'Recuperação de palavra-passe - ESTEL SGP';
                            $corpo = implode("\n", [
                                'Olá,',
                                '',
                                'Recebemos um pedido para redefinir a palavra-passe da sua conta no ESTEL SGP.',
                                'Para continuar, abra o link abaixo (válido por 30 minutos):',
                                $link,
                                '',
                                'Se não pediu esta alteração, ignore este e-mail.',
                            ]);

                            $erroEmail = null;
                            if (!app_mail_send_plain((string) $user['email'], $assunto, $corpo, $erroEmail)) {
                                error_log('Falha no envio de recuperação de palavra-passe: ' . (string) $erroEmail);
                            }

                            app_log_registar($pdo, 'Pedido de recuperação de palavra-passe', [
                                'user_id' => (int) $user['id'],
                                'email' => (string) $user['email'],
                            ], (int) $user['id']);
                        }
                    }

                    $mensagem = 'Se o e-mail existir no sistema, será enviado um link de recuperação válido por 30 minutos.';
                    $sucesso = true;
                } catch (Throwable $e) {
                    error_log('Pedido de recuperação falhou: ' . $e->getMessage());
                    $mensagem = 'Não foi possível processar o pedido neste momento. Tente novamente em instantes.';
                }
            }
        } elseif ($acao === 'redefinir') {
            $modo = 'reset';
            $uidInput = filter_var($_POST['uid'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $uidInput = $uidInput === false ? 0 : (int) $uidInput;
            $tokenInput = trim((string) ($_POST['token'] ?? ''));

            $pass1 = (string) ($_POST['password'] ?? '');
            $pass2 = (string) ($_POST['password2'] ?? '');

            if ($uidInput <= 0 || $tokenInput === '') {
                $mensagem = 'Link de recuperação inválido.';
                $modo = 'pedido';
            } elseif ($pass1 !== $pass2) {
                $mensagem = 'As palavras-passe não coincidem.';
            } elseif (strlen($pass1) < 8) {
                $mensagem = 'A palavra-passe deve ter, no mínimo, 8 caracteres.';
            } elseif (!app_password_reset_is_valid($pdo, $uidInput, $tokenInput)) {
                $mensagem = 'Este link é inválido ou expirou. Peça um novo link de recuperação.';
                $modo = 'pedido';
            } else {
                $erroConsumo = null;
                $ok = app_password_reset_consume($pdo, $uidInput, $tokenInput, $pass1, $erroConsumo);
                if ($ok) {
                    app_log_registar($pdo, 'Palavra-passe redefinida', [
                        'user_id' => $uidInput,
                    ], $uidInput);
                    $mensagem = 'Palavra-passe redefinida com sucesso. Já pode iniciar sessão.';
                    $sucesso = true;
                    $modo = 'pedido';
                    $uidInput = 0;
                    $tokenInput = '';
                } else {
                    $mensagem = $erroConsumo !== null && trim($erroConsumo) !== ''
                        ? $erroConsumo
                        : 'Não foi possível redefinir a palavra-passe.';
                    if (str_contains($mensagem, 'inválido') || str_contains($mensagem, 'expirou')) {
                        $modo = 'pedido';
                    }
                }
            }
        } else {
            $mensagem = 'Ação inválida.';
        }
    }
}

if ($modo === 'reset' && !$sucesso && !app_password_reset_is_valid($pdo, $uidInput, $tokenInput)) {
    $mensagem = $mensagem !== '' ? $mensagem : 'Este link é inválido ou expirou. Peça um novo link de recuperação.';
    $modo = 'pedido';
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar palavra-passe | ESTEL SGP</title>
  <link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
  <link rel="stylesheet" href="../css/utilizadores.css?v=<?php echo (int) $cssVUtil; ?>">
  <link rel="stylesheet" href="../css/login.css?v=<?php echo (int) $cssVLogin; ?>">
</head>
<body>
<main class="auth-page">
  <section class="auth-card">
    <div class="auth-brand-inline" id="authBrand">
      <div class="auth-brand-logo-wrap">
        <img
          class="auth-brand-logo"
          src="../assets/logo_estel.png"
          alt="Logo da escola"
          decoding="async"
          loading="eager"
        >
      </div>
      <div class="auth-brand-subtitle">Recuperação de conta</div>
    </div>

    <h1 class="auth-title">
      <?php echo $modo === 'reset' ? 'Redefinir palavra-passe' : 'Recuperar palavra-passe'; ?>
    </h1>

    <?php if ($mensagem !== ''): ?>
      <div class="msg <?php echo $sucesso ? 'msg-success' : 'msg-error'; ?>"><?php echo h($mensagem); ?></div>
    <?php endif; ?>

    <?php if ($modo === 'reset' && !$sucesso): ?>
      <form method="POST" action="recuperar.php">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="acao" value="redefinir">
        <input type="hidden" name="uid" value="<?php echo (int) $uidInput; ?>">
        <input type="hidden" name="token" value="<?php echo h($tokenInput); ?>">

        <label class="field">
          <span class="field-label">Nova palavra-passe</span>
          <div class="field-row">
            <input
              id="reset_password"
              type="password"
              name="password"
              placeholder="Mínimo de 8 caracteres"
              required
              autocomplete="new-password"
            >
            <button
              class="icon-btn"
              type="button"
              onclick="togglePasswordField('reset_password', this)"
              aria-label="Mostrar palavra-passe"
              aria-pressed="false"
            >
              <svg class="icon icon-eye-open" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 5c5.25 0 9.62 3.4 11 7-1.38 3.6-5.75 7-11 7S2.38 15.6 1 12c1.38-3.6 5.75-7 11-7Zm0 2C7.83 7 4.33 9.55 3.12 12 4.33 14.45 7.83 17 12 17s7.67-2.55 8.88-5C19.67 9.55 16.17 7 12 7Zm0 2.5A2.5 2.5 0 1 1 12 14.5a2.5 2.5 0 0 1 0-5Z"></path>
              </svg>
              <svg class="icon icon-eye-closed" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M3.3 4.7 4.7 3.3l16 16-1.4 1.4-2.25-2.25A11.9 11.9 0 0 1 12 19c-5.25 0-9.62-3.4-11-7a11.6 11.6 0 0 1 4.55-5.5L3.3 4.7ZM12 7c-.84 0-1.64.1-2.4.3l1.78 1.78c.2-.05.41-.08.62-.08a2.5 2.5 0 0 1 2.5 2.5c0 .21-.03.42-.08.62l1.78 1.78c.2-.76.3-1.56.3-2.4 0-1.46-.42-2.83-1.14-4L14.8 6.8c-.9-.53-1.9-.8-2.8-.8Zm0 10c.84 0 1.64-.1 2.4-.3l-1.78-1.78c-.2.05-.41.08-.62.08a2.5 2.5 0 0 1-2.5-2.5c0-.21.03-.42.08-.62L7.8 9.18c-.2.76-.3 1.56-.3 2.4 0 1.46.42 2.83 1.14 4l1.56-1.56c.9.53 1.9.8 2.8.8Zm0-12c5.25 0 9.62 3.4 11 7a11.8 11.8 0 0 1-4.05 5.15l-1.45-1.45c1.4-1.07 2.45-2.38 3.03-3.7-1.21-2.45-4.71-5-8.53-5-1.12 0-2.2.22-3.2.62L7.3 6.12A12.8 12.8 0 0 1 12 5Z"></path>
              </svg>
            </button>
          </div>
        </label>

        <label class="field">
          <span class="field-label">Confirmar palavra-passe</span>
          <div class="field-row">
            <input
              id="reset_password_confirm"
              type="password"
              name="password2"
              placeholder="Repetir palavra-passe"
              required
              autocomplete="new-password"
            >
            <button
              class="icon-btn"
              type="button"
              onclick="togglePasswordField('reset_password_confirm', this)"
              aria-label="Mostrar palavra-passe"
              aria-pressed="false"
            >
              <svg class="icon icon-eye-open" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 5c5.25 0 9.62 3.4 11 7-1.38 3.6-5.75 7-11 7S2.38 15.6 1 12c1.38-3.6 5.75-7 11-7Zm0 2C7.83 7 4.33 9.55 3.12 12 4.33 14.45 7.83 17 12 17s7.67-2.55 8.88-5C19.67 9.55 16.17 7 12 7Zm0 2.5A2.5 2.5 0 1 1 12 14.5a2.5 2.5 0 0 1 0-5Z"></path>
              </svg>
              <svg class="icon icon-eye-closed" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M3.3 4.7 4.7 3.3l16 16-1.4 1.4-2.25-2.25A11.9 11.9 0 0 1 12 19c-5.25 0-9.62-3.4-11-7a11.6 11.6 0 0 1 4.55-5.5L3.3 4.7ZM12 7c-.84 0-1.64.1-2.4.3l1.78 1.78c.2-.05.41-.08.62-.08a2.5 2.5 0 0 1 2.5 2.5c0 .21-.03.42-.08.62l1.78 1.78c.2-.76.3-1.56.3-2.4 0-1.46-.42-2.83-1.14-4L14.8 6.8c-.9-.53-1.9-.8-2.8-.8Zm0 10c.84 0 1.64-.1 2.4-.3l-1.78-1.78c-.2.05-.41.08-.62.08a2.5 2.5 0 0 1-2.5-2.5c0-.21.03-.42.08-.62L7.8 9.18c-.2.76-.3 1.56-.3 2.4 0 1.46.42 2.83 1.14 4l1.56-1.56c.9.53 1.9.8 2.8.8Zm0-12c5.25 0 9.62 3.4 11 7a11.8 11.8 0 0 1-4.05 5.15l-1.45-1.45c1.4-1.07 2.45-2.38 3.03-3.7-1.21-2.45-4.71-5-8.53-5-1.12 0-2.2.22-3.2.62L7.3 6.12A12.8 12.8 0 0 1 12 5Z"></path>
              </svg>
            </button>
          </div>
        </label>

        <button class="btn btn-primary" type="submit">Redefinir palavra-passe</button>
      </form>
    <?php elseif (!$sucesso): ?>
      <form method="POST" action="recuperar.php">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="acao" value="pedir_link">

        <label class="field">
          <span class="field-label">E-mail</span>
          <input type="email" name="email" placeholder="E-mail registado" required autocomplete="email">
        </label>

        <button class="btn btn-primary" type="submit">Enviar link de recuperação</button>
      </form>
    <?php endif; ?>

    <div class="links">
      <a href="index.php">Voltar ao login</a>
      <?php if ($modo === 'reset' && !$sucesso): ?>
        <a href="recuperar.php">Pedir novo link</a>
      <?php endif; ?>
    </div>
  </section>
</main>
<script>
function togglePasswordField(inputId, buttonEl) {
  const input = document.getElementById(inputId);
  if (!input || !buttonEl) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';
  buttonEl.classList.toggle('is-revealed', isPassword);
  buttonEl.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
  buttonEl.setAttribute('aria-label', isPassword ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe');
}
</script>
</body>
</html>
