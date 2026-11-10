<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

$guest = require_guest($pdo);
$gid   = (int)$guest['guest_id'];
$error = ['profile' => '', 'password' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'profile') {
            $first = trim((string)($_POST['first_name'] ?? ''));
            $last  = trim((string)($_POST['last_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $addr  = trim((string)($_POST['address'] ?? ''));
            if ($first === '' || mb_strlen($first) > 60 || $last === '' || mb_strlen($last) > 60) {
                throw new RuntimeException('Enter your first and last name.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
                throw new RuntimeException('Phone numbers can only have digits, spaces, + and -.');
            }
            if (mb_strlen($addr) > 200) {
                throw new RuntimeException('Keep the address under 200 characters.');
            }
            $pdo->prepare('UPDATE guest SET first_name = ?, last_name = ?, phone = ?, address = ? WHERE guest_id = ?')
                ->execute([$first, $last, $phone ?: null, $addr ?: null, $gid]);
            $_SESSION['guest_name'] = $first;
            flash('Your details are saved.');
            redirect('account.php');
        } elseif ($action === 'password') {
            if (throttle_blocked($pdo, 'account_pw', 6)) {
                throw new RuntimeException('Too many attempts. Please wait a few minutes and try again.');
            }
            $cur   = (string)($_POST['current'] ?? '');
            $pass  = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');
            if (!password_verify($cur, $guest['password_hash'])) {
                throttle_fail($pdo, 'account_pw');
                throw new RuntimeException('Your current password isn’t right.');
            }
            if ($msg = password_problem($pass, $guest['email'])) {
                throw new RuntimeException($msg);
            }
            if (!hash_equals($pass, $pass2)) {
                throw new RuntimeException('The two new passwords don’t match.');
            }
            $pdo->prepare('UPDATE guest SET password_hash = ? WHERE guest_id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $gid]);
            throttle_clear($pdo, 'account_pw');
            session_regenerate_id(true);
            flash('Your password was changed.');
            redirect('account.php');
        }
    } catch (RuntimeException $e) {
        $error[$action === 'password' ? 'password' : 'profile'] = $e->getMessage();
        $guest = array_merge($guest, $action === 'profile' ? [
            'first_name' => $first ?? $guest['first_name'], 'last_name' => $last ?? $guest['last_name'],
            'phone' => $phone ?? $guest['phone'], 'address' => $addr ?? $guest['address'],
        ] : []);
    }
}

$stmt = $pdo->prepare(
    "SELECT v.reservation_id, v.booking_ref, v.check_in_date, v.check_out_date, v.status, v.total_amount, v.num_guests,
            rs.name AS resort_name,
            CASE WHEN v.booking_scope = 'Rooms' THEN
              (SELECT GROUP_CONCAT(DISTINCT t.type_name ORDER BY t.type_name SEPARATOR ', ')
                 FROM reservation_room rr JOIN room r ON r.room_id = rr.room_id JOIN room_type t ON t.room_type_id = r.room_type_id
                WHERE rr.reservation_id = v.reservation_id)
            ELSE " . ROOMS_LABEL_SQL . " END AS rooms
       FROM reservation v JOIN resort rs ON rs.resort_id = v.resort_id WHERE v.guest_id = ? ORDER BY v.check_in_date DESC, v.reservation_id DESC"
);
$stmt->execute([$gid]);
$bookings = $stmt->fetchAll();
foreach ($bookings as $b) {
    $_SESSION['grants'][$b['booking_ref']] = true;
}

site_start('My account', 'account');
?>
<div class="account-head">
  <div>
    <h1>Hello, <?= h($guest['first_name']) ?></h1>
    <p class="muted"><?= h($guest['email']) ?></p>
  </div>
  <form method="post" action="<?= h(ru('logout.php')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Log out</button></form>
</div>

<section class="panel flush" id="bookings">
  <h2 class="panel-title">My bookings</h2>
  <?php if (!$bookings): ?>
    <p class="muted" style="padding:0 16px 16px">You have no bookings yet. <a href="index.php#resorts">Find a resort</a>.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Reference</th><th>Resort</th><th>Booked</th><th>Check-in</th><th>Check-out</th><th>Guests</th><th>Status</th><th class="right">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bookings as $b): ?>
        <tr>
          <td><strong><?= h($b['booking_ref']) ?></strong></td>
          <td><?= h($b['resort_name']) ?></td>
          <td><?= h($b['rooms'] ?? '') ?></td>
          <td><?= h(fdate($b['check_in_date'])) ?></td>
          <td><?= h(fdate($b['check_out_date'])) ?></td>
          <td><?= (int)$b['num_guests'] ?></td>
          <td><?= badge($b['status']) ?></td>
          <td class="num"><?= h(money($b['total_amount'])) ?></td>
          <td><a class="btn" href="booking.php?ref=<?= urlencode($b['booking_ref']) ?>">View</a><?php if ($b['status'] === 'Checked-out'): ?> <a class="btn" href="booking.php?ref=<?= urlencode($b['booking_ref']) ?>#review">Review</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="rate-cols">
  <section class="panel stack" id="profile">
    <h2>My details</h2>
    <?php if ($error['profile']): ?><div class="notice error" role="alert"><?= h($error['profile']) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="grid-form">
        <label><span>First name</span><input name="first_name" value="<?= h($guest['first_name']) ?>" maxlength="60" required></label>
        <label><span>Last name</span><input name="last_name" value="<?= h($guest['last_name']) ?>" maxlength="60" required></label>
        <label class="wide"><span>Email (can’t be changed)</span><input value="<?= h($guest['email']) ?>" disabled></label>
        <label><span>Phone</span><input type="tel" name="phone" value="<?= h($guest['phone'] ?? '') ?>" maxlength="30"></label>
        <label><span>City or country</span><input name="address" value="<?= h($guest['address'] ?? '') ?>" maxlength="200"></label>
      </div>
      <div class="actions"><button class="btn primary" type="submit">Save details</button></div>
    </form>
  </section>

  <section class="panel stack" id="password">
    <h2>Change password</h2>
    <?php if ($error['password']): ?><div class="notice error" role="alert"><?= h($error['password']) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label><span>Current password</span><input type="password" name="current" maxlength="72" autocomplete="current-password" required></label>
      <label><span>New password</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <label><span>Repeat new password</span><input type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <div class="actions"><button class="btn primary" type="submit">Change password</button></div>
    </form>
  </section>
</div>
<?php site_end();
