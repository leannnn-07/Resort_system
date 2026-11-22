<?php
require __DIR__ . '/../includes/layout.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$error = '';
$row   = null;

if ($token !== '') {
    $stmt = $pdo->prepare(
        'SELECT r.reset_id, r.staff_id FROM password_reset r
           JOIN staff s ON s.staff_id = r.staff_id
          WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND s.login_enabled = 1 AND s.archived_at IS NULL'
    );
    $stmt->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
    $row = $stmt->fetch() ?: null;
}

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pass  = (string)($_POST['password'] ?? '');
    $again = (string)($_POST['password2'] ?? '');

    if (strlen($pass) < 8) {
        $error = 'Use at least 8 characters.';
    } elseif (strlen($pass) > 72) {
        $error = 'Use 72 characters or fewer.';
    } elseif ($pass === 'admin123') {
        $error = 'Choose something other than the sample password.';
    } elseif (!hash_equals($pass, $again)) {
        $error = 'The two passwords don’t match.';
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['staff_id']]);
        $pdo->prepare('DELETE FROM password_reset WHERE staff_id = ?')->execute([$row['staff_id']]);
        $pdo->commit();
        session_regenerate_id(true);
        unset($_SESSION['staff_id'], $_SESSION['default_password'], $_SESSION['login_fail'], $_SESSION['login_block']);
        flash('Password changed. Log in with your new password.');
        redirect('../login.php');
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>Choose a new password · <?= h($config['resort']) ?></title>
<meta name="color-scheme" content="dark">
<?= font_links() ?>
<link rel="stylesheet" href="<?= h(asset_url('style.css', '../')) ?>">
</head>
<body class="admin">
<div class="bg-layer" aria-hidden="true"></div>
<main class="login">
  <h1>New password</h1>
  <p class="muted"><?= h($config['resort']) ?></p>
  <?php if (!$row): ?>
    <div class="notice error" role="alert">This reset link is invalid or has expired.</div>
    <p><a href="forgot.php">Request a new link</a></p>
  <?php else: ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="panel stack">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label><span>New password</span><input placeholder="Min. 8 characters" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required autofocus></label>
      <label><span>Repeat password</span><input placeholder="Repeat password" type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <button class="btn primary" type="submit">Save password</button>
    </form>
  <?php endif; ?>
  <p><a href="../login.php">Back to sign in</a></p>
</main>
</body>
</html>
