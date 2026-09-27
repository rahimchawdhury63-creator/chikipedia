<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
if (is_logged_in()) redirect('/');

$error = '';
$loginId = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $loginId = trim((string) ($_POST['login_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $error = 'Too many attempts. Please wait a few minutes before trying again.';
    } elseif ($loginId === '' || $password === '') {
        $error = 'Enter your username or email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, email, password_hash, role, status, created_at FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$loginId, mb_strtolower($loginId)]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash']) && $user['status'] === 'active') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user'] = array_intersect_key($user, array_flip(['id', 'username', 'email', 'role', 'status', 'created_at']));
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
            log_activity($pdo, 'user.logged_in', 'user', (int) $user['id']);
            redirect(safe_redirect_target($_POST['redirect'] ?? $_GET['redirect'] ?? '/', '/'));
        }
        $_SESSION['login_attempts'] = ++$attempts;
        if ($attempts >= 6) {
            $_SESSION['login_locked_until'] = time() + 300;
            $_SESSION['login_attempts'] = 0;
        }
        usleep(250000);
        $error = $user && $user['status'] !== 'active' ? 'This account is not active. Contact an administrator.' : 'The username/email or password is incorrect.';
    }
}
$page_title = 'Log in — ' . SITE_NAME;
$page_description = 'Log in to edit and contribute to ' . SITE_NAME . '.';
$page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container auth-layout">
    <section class="panel auth-card">
        <h1>Welcome back</h1><p>Log in to continue building free knowledge.</p>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="/login">
            <?= csrf_field() ?><input type="hidden" name="redirect" value="<?= e(safe_redirect_target($_GET['redirect'] ?? '/', '/')) ?>">
            <div class="form-group"><label for="login-id">Username or email</label><input id="login-id" type="text" name="login_id" value="<?= e($loginId) ?>" autocomplete="username" required autofocus></div>
            <div class="form-group"><label for="login-password">Password</label><input id="login-password" type="password" name="password" autocomplete="current-password" required></div>
            <button class="button button-primary button-large" type="submit" style="width:100%">Log in</button>
        </form>
        <p class="auth-footer">New here? <a href="/register">Create a free account</a></p>
    </section>
    <aside class="auth-side"><span class="eyebrow">Community powered</span><h2>Your edits can help someone understand the world.</h2><div class="auth-benefit"><b>1</b><span><strong>Write with confidence</strong><br>Source editing, live previews, and private drafts.</span></div><div class="auth-benefit"><b>2</b><span><strong>Build your record</strong><br>Every revision is attributed and preserved.</span></div><div class="auth-benefit"><b>3</b><span><strong>Keep watch</strong><br>Follow the articles that matter to you.</span></div></aside>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
