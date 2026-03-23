<?php
require_once "../includes/session_security.php";
app_session_start();

if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_login'];
$cssVUtil = @filemtime(__DIR__ . '/../css/utilizadores.css') ?: time();
$cssVLogin = @filemtime(__DIR__ . '/../css/login.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | ESTEL SGP</title>
  <link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
  <link rel="stylesheet" href="../css/utilizadores.css?v=<?php echo (int) $cssVUtil; ?>">
  <link rel="stylesheet" href="../css/login.css?v=<?php echo (int) $cssVLogin; ?>">
</head>
<body>
<script>
(function applyStoredTheme() {
  try {
    var savedTheme = localStorage.getItem('estel_sgp_theme');
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
      document.body.classList.add('theme-dark');
    }
  } catch (e) {
    // Ignore theme preference errors.
  }
})();
</script>

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
          onerror="handleLoginLogoError(this)"
        >
      </div>
      <div class="auth-brand-mark">ESTEL SGP</div>
      <div class="auth-brand-subtitle">Sistema de Gestao Patrimonial</div>
    </div>

    <h1 class="auth-title">Entrar</h1>

    <?php if (isset($_SESSION['erro_login'])): ?>
      <div class="msg msg-error"><?php echo htmlspecialchars((string) $_SESSION['erro_login'], ENT_QUOTES, 'UTF-8'); ?></div>
      <?php unset($_SESSION['erro_login']); ?>
    <?php endif; ?>

    <form action="../login/validar_login.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

      <label class="field">
        <span class="field-label">Utilizador</span>
        <input type="text" name="nome" placeholder="Nome de utilizador" required autocomplete="username">
      </label>

      <label class="field">
        <span class="field-label">Palavra-passe</span>
        <div class="field-row">
          <input id="password" type="password" name="password" placeholder="A tua palavra-passe" required autocomplete="current-password">
          <button
            id="togglePass"
            class="icon-btn"
            type="button"
            onclick="togglePassword()"
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

      <button class="btn btn-primary" type="submit">Entrar</button>
    </form>

    <div class="links">
      <a href="recuperar.php">Recuperar palavra-passe</a>
    </div>
  </section>
</main>

<script>
function handleLoginLogoError(img) {
  if (!img) return;
  img.style.display = 'none';

  var brand = document.getElementById('authBrand');
  if (brand) {
    brand.classList.add('is-fallback');
  }
}

function togglePassword() {
  const input = document.getElementById('password');
  const btn = document.getElementById('togglePass');
  if (!input || !btn) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';
  btn.classList.toggle('is-revealed', isPassword);
  btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
  btn.setAttribute('aria-label', isPassword ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe');
}
</script>

</body>
</html>


