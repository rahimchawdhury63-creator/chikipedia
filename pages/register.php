<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
if (is_logged_in()) redirect('/');

$error = '';
$username = '';
$email = '';
$registrationOpen = setting($pdo, 'allow_registration', '1') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!$registrationOpen) {
        $error = 'Account registration is temporarily closed.';
    } elseif (!empty($_POST['website'])) {
        $error = 'Registration could not be completed.';
    } elseif (!preg_match('/^[\p{L}\p{N}_ -]{3,40}$/u', $username)) {
        $error = 'Username must be 3–40 letters, numbers, spaces, underscores, or hyphens.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Use at least 10 characters for your password.';
    } elseif (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? ''))) {
        $error = 'The passwords do not match.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) OR email = ? LIMIT 1');
        $check->execute([$username, $email]);
        if ($check->fetchColumn()) {
            $error = 'That username or email is already registered.';
        }
    }
    if ($error === '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, 'editor', 'active')");
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            $_SESSION['user'] = ['id' => $userId, 'username' => $username, 'email' => $email, 'role' => 'editor', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s')];
            log_activity($pdo, 'user.registered', 'user', $userId);
            flash('success', 'Welcome to BanglaVerseWiki. Your editor account is ready.');
            redirect('/');
        } catch (Throwable $exception) {
            error_log('Registration failed: ' . $exception->getMessage());
            $error = 'We could not create the account. Please try again.';
        }
    }
}
$page_title = 'Create an account — ' . SITE_NAME;
$page_description = 'Join the BanglaVerseWiki editor community for free.';
$page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container auth-layout">
    <section class="panel auth-card">
        <h1>Join the community</h1><p>A free account lets you write, save drafts, and follow articles.</p>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$registrationOpen): ?><div class="form-error">Registration is temporarily closed by an administrator.</div><?php else: ?>
        <form method="post" action="/register">
            <?= csrf_field() ?><div class="sr-only" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="form-group"><label for="username">Username</label><input id="username" type="text" name="username" value="<?= e($username) ?>" minlength="3" maxlength="40" autocomplete="username" required autofocus><p class="form-help">This public name is shown beside your contributions.</p></div>
            <div class="form-group"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="email" required></div>
            <div class="form-group"><label for="password">Password</label><input id="password" type="password" name="password" minlength="10" autocomplete="new-password" required data-password><div class="password-meter" data-password-meter><span></span></div><p class="form-help">At least 10 characters; a longer unique passphrase is best.</p></div>
            <div class="form-group"><label for="password-confirmation">Confirm password</label><input id="password-confirmation" type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required></div>
            <button class="button button-primary button-large" type="submit" style="width:100%">Create my account</button>
        </form>
        <?php endif; ?>
        <p class="auth-footer">Already an editor? <a href="/login">Log in</a></p>
    </section>
    <aside class="auth-side"><span class="eyebrow">Free and open</span><h2>One account. A universe of knowledge.</h2><div class="auth-benefit"><b>✓</b><span><strong>No subscription</strong><br>Reading and contributing are always free.</span></div><div class="auth-benefit"><b>✓</b><span><strong>Your work is preserved</strong><br>Transparent revision history keeps every article accountable.</span></div><div class="auth-benefit"><b>✓</b><span><strong>Privacy minded</strong><br>Your email is never shown on your public profile.</span></div></aside>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
