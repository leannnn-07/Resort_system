<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

$next = safe_next($_POST['next'] ?? $_GET['next'] ?? '', 'resorts.php');
if (!empty($_SESSION['staff_id'])) {
    $stCur = $pdo->prepare('SELECT staff_id, resort_id FROM staff WHERE staff_id = ? AND ' . STAFF_CAN_LOGIN);
    $stCur->execute([(int)$_SESSION['staff_id']]);
    if ($curStaff = $stCur->fetch()) {
        redirect($curStaff['resort_id'] === null ? 'admin/platform.php' : 'admin/index.php');
    }
    unset($_SESSION['staff_id'], $_SESSION['default_password']);
}
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
        // 1. Check staff / admin / super admin accounts first (so staff who also have a linked guest email land in their desk).
        $stStaff = $pdo->prepare('SELECT staff_id, resort_id, password_hash, login_enabled FROM staff WHERE LOWER(email) = ? AND password_hash IS NOT NULL AND archived_at IS NULL');
        $stStaff->execute([$email]);
        $staffRow = $stStaff->fetch();

        $stGuest = $pdo->prepare('SELECT guest_id, password_hash, login_enabled FROM guest WHERE LOWER(email) = ? AND password_hash IS NOT NULL AND archived_at IS NULL');
        $stGuest->execute([$email]);
        $guestRow = $stGuest->fetch();

        if ($staffRow && password_verify($pass, $staffRow['password_hash'])) {
            if (!(int)$staffRow['login_enabled']) {
                $error = 'Login is turned off for this account. Ask your administrator to turn it back on.';
            } else {
                if (password_needs_rehash($staffRow['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')
                        ->execute([password_hash($pass, PASSWORD_DEFAULT), $staffRow['staff_id']]);
                }
                session_regenerate_id(true);
                unset($_SESSION['guest_id'], $_SESSION['guest_name'], $_SESSION['glogin_fail'], $_SESSION['glogin_block'], $_SESSION['login_fail'], $_SESSION['login_block']);
                $_SESSION['staff_id'] = (int)$staffRow['staff_id'];
                throttle_clear($pdo, 'guest_login');
                throttle_clear($pdo, 'login');
                $dest = str_starts_with($next, 'admin/')
                    ? $next
                    : ($staffRow['resort_id'] === null ? 'admin/platform.php' : 'admin/index.php');
                redirect($dest);
            }
        } elseif ($guestRow && password_verify($pass, $guestRow['password_hash'])) {
            if (!(int)$guestRow['login_enabled']) {
                $error = 'Login is turned off for this account. Please contact the resort.';
            } else {
                if (password_needs_rehash($guestRow['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE guest SET password_hash = ? WHERE guest_id = ?')
                        ->execute([password_hash($pass, PASSWORD_DEFAULT), $guestRow['guest_id']]);
                }
                unset($_SESSION['staff_id'], $_SESSION['default_password'], $_SESSION['glogin_fail'], $_SESSION['glogin_block']);
                throttle_clear($pdo, 'guest_login');
                log_in_guest($pdo, (int)$guestRow['guest_id']);
                redirect($next);
            }
        }

        if (!$staffRow && !$guestRow) {
            dummy_password_check($pass);
        }
        if ($error === '') {
            throttle_fail($pdo, 'guest_login');
            $_SESSION['glogin_fail']  = ($_SESSION['glogin_fail'] ?? 0) + 1;
            $_SESSION['glogin_block'] = time() + 60;
            $error = 'Email or password is incorrect.';
        }
    }
}

site_start('Log in', 'account', 'no-nav');
echo auth_gate_popup($next);   // "Create an account first to book" when sent here from a booking page
$q = $next !== 'resorts.php' ? '?next=' . urlencode($next) : '';
$authBg = is_file(__DIR__ . '/assets/resorts/vista-del-mar.jpg') ? 'assets/resorts/vista-del-mar.jpg' : 'assets/landing.jpg';
?>
<div class="auth-shell">
  <aside class="auth-visual" style="background-image:url('<?= h($authBg) ?>')" aria-hidden="true">
    <div class="auth-v-top">
      <span class="auth-v-badge"><?= h($config['platform'] ?? 'Vista') ?></span>
    </div>
    <div class="auth-v-body">
      <h2>Your next beach getaway starts here.</h2>
      <ul class="auth-perks">
        <li>Book day-use cottages &amp; overnight rooms</li>
        <li>Pay easily via GCash or Maya</li>
        <li>Manage all your bookings in one place</li>
      </ul>
    </div>
  </aside>

  <section class="auth-pane">
    <div class="auth-tabs" role="tablist" aria-label="Account">
      <a href="<?= h(ru('login.php' . $q)) ?>" aria-current="page">Log in</a>
      <a href="<?= h(ru('register.php' . $q)) ?>">Create account</a>
    </div>

    <div class="auth-head">
      <h1>Welcome back</h1>
      <p class="muted">Sign in to your account.</p>
    </div>

    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label>
        <span>Email</span>
        <input placeholder="you@example.com" type="email" name="email" value="<?= h($email) ?>" maxlength="120" autocomplete="username" required autofocus>
      </label>
      <label>
        <span class="auth-label-row">
          <span>Password</span>
          <a class="auth-forgot" href="<?= h(ru('forgot.php')) ?>">Forgot password?</a>
        </span>
        <span class="pw-wrap">
          <input placeholder="Enter your password" type="password" name="password" maxlength="72" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <button class="btn primary auth-submit" type="submit">Log in</button>
    </form>

    <p class="auth-foot">New here? <a href="<?= h(ru('register.php' . $q)) ?>">Create an account</a> · <a href="index.php">&larr; Home</a></p>
  </section>
</div>
<?php site_end();
