<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

$next = safe_next($_POST['next'] ?? $_GET['next'] ?? '');
if (current_guest($pdo)) {
    redirect($next);
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');

    if (throttle_blocked($pdo, 'guest_login', 8) || (($_SESSION['glogin_fail'] ?? 0) >= 5 && time() < ($_SESSION['glogin_block'] ?? 0))) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT guest_id, password_hash FROM guest WHERE email = ? AND password_hash IS NOT NULL');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if ($row && password_verify($pass, $row['password_hash'])) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE guest SET password_hash = ? WHERE guest_id = ?')
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['guest_id']]);
            }
            unset($_SESSION['glogin_fail'], $_SESSION['glogin_block']);
            throttle_clear($pdo, 'guest_login');
            log_in_guest($pdo, (int)$row['guest_id']);
            redirect($next);
        }
        if (!$row) {
            dummy_password_check($pass);
        }
        throttle_fail($pdo, 'guest_login');
        $_SESSION['glogin_fail']  = ($_SESSION['glogin_fail'] ?? 0) + 1;
        $_SESSION['glogin_block'] = time() + 60;
        $error = 'Email or password is incorrect.';
    }
}

site_start('Log in', 'account');
$q = $next !== 'account.php' ? '?next=' . urlencode($next) : '';
?>
<section class="panel stack auth">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label><span>Email</span><input type="email" name="email" value="<?= h($email) ?>" maxlength="120" autocomplete="username" required autofocus></label>
    <label><span>Password</span><input type="password" name="password" maxlength="72" autocomplete="current-password" required></label>
    <div class="actions"><button class="btn primary" type="submit">Log in</button></div>
  </form>
  <p><a href="<?= h(ru('forgot.php')) ?>">Forgot your password?</a></p>
  <p>New here? <a href="<?= h(ru('register.php' . $q)) ?>">Create an account</a></p>
</section>
<?php site_end();
