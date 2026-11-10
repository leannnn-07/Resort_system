<?php
require __DIR__ . '/../includes/layout.php';

if (!empty($_SESSION['staff_id'])) {
    redirect('index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if (throttle_blocked($pdo, 'login', 8) || (($_SESSION['login_fail'] ?? 0) >= 5 && time() < ($_SESSION['login_block'] ?? 0))) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT staff_id, password_hash FROM staff WHERE email = ? AND password_hash IS NOT NULL');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row) {
            // Same work as a real check, so an unknown email can't be told apart by how fast the reply is.
            password_verify($pass, '$2y$10$usesomesillystringforsalt.OqWAfGBzcPkx3ugG3KN7hVKdtDpb2');
        }
        if ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['staff_id'] = (int)$row['staff_id'];
            // Remind staff to change the password that ships with the sample data.
            $_SESSION['default_password'] = hash_equals('admin123', $pass);
            unset($_SESSION['login_fail'], $_SESSION['login_block']);
            throttle_clear($pdo, 'login');
            $own = $pdo->prepare('SELECT resort_id FROM staff WHERE staff_id = ?');
            $own->execute([(int)$row['staff_id']]);
            redirect($own->fetchColumn() === null ? 'platform.php' : 'index.php');   // the super admin starts on the platform overview
        }
        throttle_fail($pdo, 'login');
        $_SESSION['login_fail']  = ($_SESSION['login_fail'] ?? 0) + 1;
        $_SESSION['login_block'] = time() + 60;
        $error = 'Email or password is incorrect.';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Staff sign in · <?= h($config['platform'] ?? 'Resort Booking') ?></title>
<meta name="color-scheme" content="dark">
<?= font_links() ?>
<link rel="stylesheet" href="<?= h(asset_url('style.css', '../')) ?>">
</head>
<body class="admin">
<div class="bg-layer" aria-hidden="true"></div>
<main class="login">
  <h1>Staff sign in</h1>
  <p class="muted"><?= h($config['platform'] ?? 'Resort Booking') ?> · sign in to your resort’s desk</p>
  <?php flash_render(); ?>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="panel stack">
    <?= csrf_field() ?>
    <label><span>Email</span><input type="email" name="email" value="<?= h($email) ?>" autocomplete="username" required autofocus></label>
    <label><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
  <p><a href="forgot.php">Forgot your password?</a></p>
  <p class="muted">Sample login: admin@resort.test / admin123. Change it under Staff after signing in.</p>
  <p><a href="../index.php">Back to the website</a></p>
</main>
</body>
</html>
