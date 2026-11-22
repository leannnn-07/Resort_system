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
            $stStaff = $pdo->prepare('SELECT staff_id, full_name FROM staff WHERE LOWER(email) = ? AND ' . STAFF_CAN_LOGIN);
            $stStaff->execute([$email]);
            $sRow = $stStaff->fetch();
            $stmt = $pdo->prepare('SELECT guest_id, first_name FROM guest WHERE LOWER(email) = ? AND ' . GUEST_CAN_LOGIN);
            $stmt->execute([$email]);
            $gRow = $stmt->fetch();
            $first = $sRow ? (explode(' ', trim((string)$sRow['full_name']))[0] ?: 'there') : ($gRow['first_name'] ?? 'there');
            // Same screen whether or not the email has an account, so this can't be used to find accounts.
            code_issue($pdo, 'reset', $email, $first, ['staff_id' => (int)($sRow['staff_id'] ?? 0), 'guest_id' => (int)($gRow['guest_id'] ?? 0)], (bool)($sRow || $gRow));
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
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            if (!empty($d['staff_id'])) {
                $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ? AND password_hash IS NOT NULL')
                    ->execute([$newHash, (int)$d['staff_id']]);
            }
            if (!empty($d['guest_id'])) {
                $pdo->prepare('UPDATE guest SET password_hash = ? WHERE guest_id = ? AND password_hash IS NOT NULL')
                    ->execute([$newHash, (int)$d['guest_id']]);
            }
            session_regenerate_id(true);
            unset($_SESSION['glogin_fail'], $_SESSION['glogin_block'], $_SESSION['login_fail'], $_SESSION['login_block']);
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

site_start('Forgot password', 'account', 'no-nav');
?>
<section class="panel auth-single">
  <div class="auth-head">
    <h1><?= $pending ? 'Reset your password' : 'Forgot password?' ?></h1>
    <p class="muted"><?= $pending
      ? 'Enter the 6-digit code sent to ' . h($pending['email']) . ' and choose a new password.'
      : 'Enter your email and we’ll send you a 6-digit reset code.' ?></p>
  </div>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

  <?php if ($pending): ?>
    <?php if ($notice): ?><div class="notice ok" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: read the code in <code>storage/mail.log</code>.</div>
    <?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <label><span>6-digit code</span>
        <input class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required autofocus>
      </label>
      <label><span>New password</span>
        <span class="pw-wrap">
          <input placeholder="Min. 8 characters" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <label><span>Confirm new password</span>
        <span class="pw-wrap">
          <input placeholder="Repeat password" type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required>
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <button class="btn primary auth-submit" type="submit">Update password</button>
    </form>
    <div class="auth-sub-actions">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="link" type="submit">Resend code</button></form>
      <span class="muted">·</span>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><button class="link" type="submit">Use another email</button></form>
    </div>
  <?php else: ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request">
      <label><span>Email</span><input placeholder="you@example.com" type="email" name="email" value="<?= h($email) ?>" maxlength="120" autocomplete="username" required autofocus></label>
      <button class="btn primary auth-submit" type="submit">Send reset code</button>
    </form>
  <?php endif; ?>
  <p class="auth-foot"><a href="<?= h(ru('login.php')) ?>">&larr; Back to log in</a></p>
</section>
<?php site_end();
