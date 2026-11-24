<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

if (current_guest($pdo)) {
    redirect(ru('resorts.php'));
}

$error  = '';
$notice = '';
$next   = safe_next($_POST['next'] ?? $_GET['next'] ?? '', 'resorts.php');   // after sign-up: the resorts page (unless a booking was in progress)
$form   = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
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

            if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 60 || $form['last_name'] === '' || mb_strlen($form['last_name']) > 60 || mb_strlen($form['middle_name']) > 60) {
                throw new RuntimeException('Enter your first and last name (up to 60 characters each).');
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
                $pdo->prepare('UPDATE guest SET first_name = ?, middle_name = COALESCE(?, middle_name), last_name = ?, password_hash = ?, email_verified_at = ?, phone = COALESCE(phone, ?) WHERE guest_id = ?')
                    ->execute([$d['first_name'], ($d['middle_name'] ?? '') !== '' ? $d['middle_name'] : null, $d['last_name'], $d['hash'], date('Y-m-d H:i:s'), $d['phone'] ?: null, $row['guest_id']]);
                $guestId = (int)$row['guest_id'];
            } else {
                $pdo->prepare('INSERT INTO guest (first_name, middle_name, last_name, email, phone, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$d['first_name'], ($d['middle_name'] ?? '') !== '' ? $d['middle_name'] : null, $d['last_name'], $d['email'], $d['phone'] ?: null, $d['hash'], date('Y-m-d H:i:s')]);
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

site_start('Create account', 'account', 'no-nav');
$q = $next !== 'resorts.php' ? '?next=' . urlencode($next) : '';
$authBg = is_file(__DIR__ . '/assets/resorts/vista-del-mar.jpg') ? 'assets/resorts/vista-del-mar.jpg' : 'assets/landing.jpg';
$backUrl = auth_back_url();
?>
<div class="auth-outer-bar">
  <a class="btn sm page-back auth-back-btn" href="<?= h($backUrl) ?>" data-auth-back>&larr; Back</a>
</div>
<div class="auth-shell">
  <aside class="auth-visual" style="background-image:url('<?= h($authBg) ?>')" aria-hidden="true">
    <div class="auth-v-top">
      <span class="auth-v-badge"><?= h($config['platform'] ?? 'Vista') ?></span>
    </div>
    <div class="auth-v-body">
      <h2>Plan your resort day in minutes.</h2>
      <ul class="auth-perks">
        <li>Book cottages &amp; rooms across Zamboanga</li>
        <li>Combine stays into a single trip</li>
        <li>Track payment status &amp; confirmations</li>
      </ul>
    </div>
  </aside>

  <section class="auth-pane">
    <div class="auth-tabs" role="tablist" aria-label="Account">
      <a href="<?= h(ru('login.php' . $q)) ?>">Log in</a>
      <a href="<?= h(ru('register.php' . $q)) ?>" aria-current="page">Create account</a>
    </div>

    <div class="auth-head">
      <h1><?= $pending ? 'Verify your email' : 'Create your account' ?></h1>
      <p class="muted"><?= $pending
        ? 'Enter the 6-digit code sent to ' . h($pending['email']) . '.'
        : 'Book faster and keep all your stays in one place.' ?></p>
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
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <label><span>6-digit code</span>
          <input class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required autofocus>
        </label>
        <button class="btn primary auth-submit" type="submit">Verify &amp; create account</button>
      </form>
      <div class="auth-sub-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="next" value="<?= h($next) ?>"><button class="link" type="submit">Resend code</button></form>
        <span class="muted">·</span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="next" value="<?= h($next) ?>"><button class="link" type="submit">Change email</button></form>
      </div>
    <?php else: ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <div class="auth-row">
          <label><span>Last name</span><input placeholder="Santos" name="last_name" value="<?= h($form['last_name']) ?>" maxlength="60" autocomplete="family-name" required autofocus></label>
          <label><span>First name</span><input placeholder="Maria" name="first_name" value="<?= h($form['first_name']) ?>" maxlength="60" autocomplete="given-name" required></label>
        </div>
        <label><span>Middle name <small class="muted">(optional)</small></span><input placeholder="Cruz" name="middle_name" value="<?= h($form['middle_name']) ?>" maxlength="60" autocomplete="additional-name"></label>
        <label><span>Email</span><input placeholder="you@example.com" type="email" name="email" value="<?= h($form['email']) ?>" maxlength="120" autocomplete="email" required></label>
        <label><span>Phone <small class="muted">(optional)</small></span><input placeholder="0917 123 4567" type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="30" autocomplete="tel"></label>
        <div class="auth-row">
          <label><span>Password</span>
            <span class="pw-wrap">
              <input placeholder="Min. 8 chars" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
              <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </span>
          </label>
          <label><span>Confirm password</span>
            <span class="pw-wrap">
              <input placeholder="Repeat password" type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required>
              <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </span>
          </label>
        </div>
        <button class="btn primary auth-submit" type="submit">Continue &amp; send code</button>
      </form>
      <p class="auth-foot">Already have an account? <a href="<?= h(ru('login.php' . $q)) ?>">Log in</a> · <a href="index.php">&larr; Home</a></p>
    <?php endif; ?>
  </section>
</div>
<?php site_end();
