<?php
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/notify.php';

if (!empty($_SESSION['staff_id'])) {
    redirect('index.php');
}

$sent  = false;
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (throttle_blocked($pdo, 'reset', 5)) {
        $error = 'Too many requests. Please wait a few minutes and try again.';
    } else {
        throttle_fail($pdo, 'reset');   // every request counts, so this can't be used to spam inboxes

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare('SELECT staff_id, full_name, email FROM staff WHERE email = ? AND password_hash IS NOT NULL');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if ($row) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('DELETE FROM password_reset WHERE staff_id = ? OR expires_at < ?')
                    ->execute([$row['staff_id'], date('Y-m-d H:i:s')]);
                $pdo->prepare('INSERT INTO password_reset (staff_id, token_hash, expires_at) VALUES (?, ?, ?)')
                    ->execute([$row['staff_id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
                send_staff_reset_email($row['email'], $row['full_name'], site_base_url() . '/admin/reset.php?token=' . $token);
            }
        }
        // Same answer whether or not the address exists, so this can't be used to find staff emails.
        $sent = true;
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Forgot password · <?= h($config['resort']) ?></title>
<meta name="color-scheme" content="dark">
<?= font_links() ?>
<link rel="stylesheet" href="<?= h(asset_url('style.css', '../')) ?>">
</head>
<body class="admin">
<div class="bg-layer" aria-hidden="true"></div>
<main class="login">
  <h1>Forgot password</h1>
  <p class="muted"><?= h($config['resort']) ?></p>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if ($sent): ?>
    <div class="notice" role="status">If that email belongs to a staff member, a reset link is on its way. It works once, for 1 hour.</div>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: no email was sent. The link is in <code>storage/mail.log</code>.</div>
    <?php endif; ?>
  <?php else: ?>
  <form method="post" class="panel stack">
    <?= csrf_field() ?>
    <p class="muted">Enter your staff email and we’ll send you a link to choose a new password.</p>
    <label><span>Email</span><input type="email" name="email" value="<?= h($email) ?>" autocomplete="username" required autofocus></label>
    <button class="btn primary" type="submit">Send reset link</button>
  </form>
  <?php endif; ?>
  <p><a href="login.php">Back to sign in</a></p>
</main>
</body>
</html>
