<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';

$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator'")->fetchColumn();
if ($adminCount > 0) {
    http_response_code(404);
    exit('Setup is closed because an administrator already exists.');
}
$error = '';
$email = 'rrc@bsdc.info.bd';
$username = 'administrator';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid administrator email.';
    } elseif (!preg_match('/^[\p{L}\p{N}_ -]{3,40}$/u', $username)) {
        $error = 'Choose a valid username of 3–40 characters.';
    } elseif (strlen($password) < 10) {
        $error = 'Administrator passwords must contain at least 10 characters.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? OR LOWER(username) = LOWER(?) LIMIT 1');
        $check->execute([$email, $username]);
        if ($id = $check->fetchColumn()) {
            $pdo->prepare("UPDATE users SET email = ?, username = ?, password_hash = ?, role = 'administrator', status = 'active' WHERE id = ?")->execute([$email, $username, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, 'administrator', 'active')")->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $id = $pdo->lastInsertId();
        }
        log_activity($pdo, 'administrator.bootstrapped', 'user', (int) $id);
        flash('success', 'Administrator created. Setup is now permanently locked.');
        redirect('/login');
    }
}
$page_title = 'Secure setup — ' . SITE_NAME;
$page_robots = 'noindex,nofollow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-narrow">
    <section class="panel auth-card"><span class="eyebrow">One-time setup</span><h1>Create the first administrator</h1><p>This page permanently locks after the first administrator is created. Complete it immediately after deployment.</p>
    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/setup"><?= csrf_field() ?>
        <div class="form-group"><label for="setup-name">Username</label><input id="setup-name" type="text" name="username" value="<?= e($username) ?>" required></div>
        <div class="form-group"><label for="setup-email">Email</label><input id="setup-email" type="email" name="email" value="<?= e($email) ?>" required></div>
        <div class="form-group"><label for="setup-password">Secure password</label><input id="setup-password" type="password" name="password" minlength="10" autocomplete="new-password" required data-password><div class="password-meter" data-password-meter><span></span></div><p class="form-help">Use the private password chosen for this administrator, then rotate it after launch.</p></div>
        <button class="button button-primary button-large" type="submit">Create administrator &amp; lock setup</button>
    </form></section>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
