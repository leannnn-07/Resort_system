<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

if (current_guest($pdo)) {
    redirect(ru('account.php'));
}

$error   = '';
$notice  = '';
$email   = '';
$pending = code_pending('reset');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'request');

    try {
        if ($action === 'request') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter the email you signed up with.');
            }
            $stmt = $pdo->prepare('SELECT guest_id, first_name FROM guest WHERE email = ? AND password_hash IS NOT NULL');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            // Same screen whether or not the email has an account, so this can't be used to find accounts.
            code_issue($pdo, 'reset', $email, $row['first_name'] ?? 'there', ['guest_id' => (int)($row['guest_id'] ?? 0)], (bool)$row);
            $pending = code_pending('reset');
        } elseif ($pending && $action === 'verify') {
            $pass  = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');
            if ($msg = password_problem($pass, $pending['email'])) {
                throw new RuntimeException($msg);
            }
            if (!hash_equals($pass, $pass2)) {
                throw new RuntimeException('The two passwords don’t match.');
            }
            $d = code_verify($pdo, 'reset', (string)($_POST['code'] ?? ''));
            $pdo->prepare('UPDATE guest SET password_hash = ? WHERE guest_id = ? AND password_hash IS NOT NULL')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $d['guest_id']]);
            session_regenerate_id(true);
            unset($_SESSION['glogin_fail'], $_SESSION['glogin_block']);
            flash('Password changed. Log in with your new password.');
            redirect(ru('login.php'));
        } elseif ($pending && $action === 'resend') {
            if (code_wait('reset') > 0) {
                throw new RuntimeException('Please wait a minute before asking for another code.');
            }
            code_issue($pdo, 'reset', $pending['email'], $pending['first'], $pending['payload'], (bool)$pending['real']);
            $pending = code_pending('reset');
            $notice  = 'We sent you a new code.';
        } elseif ($pending && $action === 'edit') {
            $email = $pending['email'];
            code_cancel('reset');
            $pending = null;
        }
    } catch (RuntimeException $e) {
        $error   = $e->getMessage();
        $pending = code_pending('reset');
    }
}

site_start('Forgot password', 'account');
?>
<section class="panel stack auth">
  <h1>Forgot your password?</h1>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

  <?php if ($pending): ?>
    <p>If <strong><?= h($pending['email']) ?></strong> has an account, we sent it a 6-digit code. Enter it with a new password. The code works for 10 minutes.</p>
    <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: no email was actually sent. Open <code>storage/mail.log</code> to read the code.</div>
    <?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <label><span>Verification code</span>
        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required autofocus>
      </label>
      <label><span>New password</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <label><span>Repeat new password</span><input type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <div class="actions"><button class="btn primary" type="submit">Change password</button></div>
    </form>
    <div class="actions">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn" type="submit">Send a new code</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><button class="btn" type="submit">Use a different email</button></form>
    </div>
  <?php else: ?>
    <p class="muted">Enter your email and we’ll send you a code to choose a new password.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request">
      <label><span>Email</span><input type="email" name="email" value="<?= h($email) ?>" maxlength="120" autocomplete="username" required autofocus></label>
      <div class="actions"><button class="btn primary" type="submit">Send me a code</button></div>
    </form>
  <?php endif; ?>
  <p><a href="<?= h(ru('login.php')) ?>">Back to log in</a></p>
</section>
<?php site_end();
