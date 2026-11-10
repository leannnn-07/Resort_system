<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

if (current_guest($pdo)) {
    redirect(ru('account.php'));
}

$error  = '';
$notice = '';
$next   = safe_next($_POST['next'] ?? $_GET['next'] ?? '');
$form   = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
$pending = code_pending('register');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'register');

    try {
        if ($action === 'register') {
            foreach ($form as $k => $_) {
                $form[$k] = trim((string)($_POST[$k] ?? ''));
            }
            $form['email'] = strtolower($form['email']);
            $pass  = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');

            if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 60 || $form['last_name'] === '' || mb_strlen($form['last_name']) > 60) {
                throw new RuntimeException('Enter your first and last name.');
            }
            if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 120) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if ($form['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $form['phone'])) {
                throw new RuntimeException('Phone numbers can only have digits, spaces, + and -.');
            }
            if ($msg = password_problem($pass, $form['email'])) {
                throw new RuntimeException($msg);
            }
            if (!hash_equals($pass, $pass2)) {
                throw new RuntimeException('The two passwords don’t match.');
            }

            $stmt = $pdo->prepare('SELECT guest_id, first_name FROM guest WHERE email = ? AND password_hash IS NOT NULL');
            $stmt->execute([$form['email']]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Same screen as a normal sign-up, so this can't be used to find out who has an account.
                if (!throttle_blocked($pdo, 'code_send', 6)) {
                    send_existing_account_email($form['email'], $existing['first_name']);
                    throttle_fail($pdo, 'code_send');
                }
                code_issue($pdo, 'register', $form['email'], $form['first_name'], [], false);
            } else {
                code_issue($pdo, 'register', $form['email'], $form['first_name'], $form + ['hash' => password_hash($pass, PASSWORD_DEFAULT)], true);
            }
            $pending = code_pending('register');
        } elseif ($pending && $action === 'verify') {
            $d = code_verify($pdo, 'register', (string)($_POST['code'] ?? ''));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT guest_id, password_hash FROM guest WHERE email = ? FOR UPDATE');
            $stmt->execute([$d['email']]);
            $row = $stmt->fetch();
            if ($row && $row['password_hash'] !== null) {
                $pdo->rollBack();
                throw new RuntimeException('That email already has an account. Log in instead.');
            }
            if ($row) {
                // They have booked before with this email: attach the account to that guest record, so old bookings show up.
                $pdo->prepare('UPDATE guest SET password_hash = ?, email_verified_at = ?, phone = COALESCE(phone, ?) WHERE guest_id = ?')
                    ->execute([$d['hash'], date('Y-m-d H:i:s'), $d['phone'] ?: null, $row['guest_id']]);
                $guestId = (int)$row['guest_id'];
            } else {
                $pdo->prepare('INSERT INTO guest (first_name, last_name, email, phone, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$d['first_name'], $d['last_name'], $d['email'], $d['phone'] ?: null, $d['hash'], date('Y-m-d H:i:s')]);
                $guestId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            log_in_guest($pdo, $guestId);
            flash('Your account is ready. Welcome, ' . $d['first_name'] . '!');
            redirect($next);
        } elseif ($pending && $action === 'resend') {
            if (code_wait('register') > 0) {
                throw new RuntimeException('Please wait a minute before asking for another code.');
            }
            $p = $pending;
            if ($p['real']) {
                code_issue($pdo, 'register', $p['email'], $p['first'], $p['payload'], true);
            } else {
                send_existing_account_email($p['email'], $p['first']);
                code_issue($pdo, 'register', $p['email'], $p['first'], [], false);
            }
            $pending = code_pending('register');
            $notice  = 'We sent you a new code.';
        } elseif ($pending && $action === 'edit') {
            $d = $pending['payload'];
            foreach ($form as $k => $_) {
                $form[$k] = $d[$k] ?? ($k === 'email' ? $pending['email'] : '');
            }
            code_cancel('register');
            $pending = null;
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        $pending = code_pending('register');
    }
}

site_start('Create account', 'account');
?>
<section class="panel stack auth">
  <h1>Create your account</h1>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

  <?php if ($pending): ?>
    <p>We sent a 6-digit code to <strong><?= h($pending['email']) ?></strong>. Enter it to finish creating your account. It works for 10 minutes.</p>
    <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: no email was actually sent. Open <code>storage/mail.log</code> to read the code.</div>
    <?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label><span>Verification code</span>
        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required autofocus>
      </label>
      <div class="actions"><button class="btn primary" type="submit">Verify and create account</button></div>
    </form>
    <div class="actions">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="next" value="<?= h($next) ?>"><button class="btn" type="submit">Send a new code</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="next" value="<?= h($next) ?>"><button class="btn" type="submit">Use a different email</button></form>
    </div>
  <?php else: ?>
    <p class="muted">Keep your bookings in one place and skip retyping your details. We’ll email you a code to check your address.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="register">
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <div class="grid-form">
        <label><span>First name</span><input name="first_name" value="<?= h($form['first_name']) ?>" maxlength="60" autocomplete="given-name" required></label>
        <label><span>Last name</span><input name="last_name" value="<?= h($form['last_name']) ?>" maxlength="60" autocomplete="family-name" required></label>
        <label class="wide"><span>Email</span><input type="email" name="email" value="<?= h($form['email']) ?>" maxlength="120" autocomplete="email" required></label>
        <label class="wide"><span>Phone (optional)</span><input type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="30" autocomplete="tel"></label>
        <label><span>Password</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
        <label><span>Repeat password</span><input type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      </div>
      <p class="muted">At least 8 characters.</p>
      <div class="actions"><button class="btn primary" type="submit">Send me a code</button></div>
    </form>
    <p>Already have an account? <a href="<?= h(ru('login.php' . ($next !== 'account.php' ? '?next=' . urlencode($next) : ''))) ?>">Log in</a></p>
  <?php endif; ?>
</section>
<?php site_end();
