<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'profile') {
        $bio = mb_substr(trim((string) ($_POST['bio'] ?? '')), 0, 500);
        $pdo->prepare('UPDATE users SET bio = ? WHERE id = ?')->execute([$bio, current_user()['id']]);
        log_activity($pdo, 'user.profile_updated', 'user', (int) current_user()['id']); flash('success', 'Profile updated.'); redirect('/account');
    }
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? ''); $new = (string) ($_POST['new_password'] ?? ''); $confirm = (string) ($_POST['new_password_confirmation'] ?? '');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?'); $stmt->execute([current_user()['id']]); $hash = (string) $stmt->fetchColumn();
        if (!password_verify($current, $hash)) $error = 'The current password is incorrect.';
        elseif (strlen($new) < 12) $error = 'The new password must contain at least 12 characters.';
        elseif (!hash_equals($new, $confirm)) $error = 'The new passwords do not match.';
        else { $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), current_user()['id']]); session_regenerate_id(true); log_activity($pdo, 'user.password_changed', 'user', (int) current_user()['id']); flash('success', 'Password changed successfully.'); redirect('/account'); }
    }
}
$userStmt = $pdo->prepare('SELECT username, email, bio, role, created_at, last_login_at FROM users WHERE id = ?'); $userStmt->execute([current_user()['id']]); $user = $userStmt->fetch();
$page_title = 'Account settings — ' . SITE_NAME; $page_robots = 'noindex,nofollow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-narrow">
<header class="page-heading"><span class="eyebrow">Private account</span><h1>Account settings</h1><p>Manage your public editor profile and security.</p></header>
<?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>
<section class="panel"><div class="panel-header"><h2>Profile</h2><a href="/user/<?= rawurlencode($user['username']) ?>">View public profile</a></div><div class="panel-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile"><div class="form-row"><div class="form-group"><label>Username</label><input type="text" value="<?= e($user['username']) ?>" disabled></div><div class="form-group"><label>Email</label><input type="email" value="<?= e($user['email']) ?>" disabled></div></div><div class="form-group"><label for="bio">Public bio</label><textarea id="bio" name="bio" rows="4" maxlength="500" placeholder="Share your editing interests."><?= e($user['bio'] ?? '') ?></textarea></div><button class="button button-primary" type="submit">Save profile</button></form></div></section>
<section class="panel" style="margin-top:22px"><div class="panel-header"><h2>Change password</h2></div><div class="panel-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password"><div class="form-group"><label for="current-password">Current password</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></div><div class="form-row"><div class="form-group"><label for="new-password">New password</label><input id="new-password" type="password" name="new_password" minlength="12" autocomplete="new-password" required data-password><div class="password-meter" data-password-meter><span></span></div></div><div class="form-group"><label for="confirm-password">Confirm new password</label><input id="confirm-password" type="password" name="new_password_confirmation" minlength="12" autocomplete="new-password" required></div></div><button class="button button-primary" type="submit">Change password</button></form></div></section>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
