<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/trips.php';
require_once __DIR__ . '/includes/payments.php';
optional_resort($pdo);

$guest = require_guest($pdo);
$gid   = (int)$guest['guest_id'];
$error = ['profile' => '', 'password' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'profile') {
            $last  = trim((string)($_POST['last_name'] ?? ''));
            $first = trim((string)($_POST['first_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($first === '' || mb_strlen($first) > 60 || $last === '' || mb_strlen($last) > 60) {
                throw new RuntimeException('Enter your last name and first name.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
                throw new RuntimeException('Phone numbers can only have digits, spaces, + and -.');
            }
            $pdo->prepare('UPDATE guest SET first_name = ?, last_name = ?, phone = ? WHERE guest_id = ?')
                ->execute([$first, $last, $phone ?: null, $gid]);
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
    "SELECT v.reservation_id, v.booking_ref, v.booking_scope, v.check_in_date, v.check_out_date, v.status, v.source, v.total_amount, v.required_downpayment, v.num_guests,
            " . (trips_ready($pdo) ? 'v.trip_code' : 'NULL') . " AS trip_code,
            rs.name AS resort_name, rs.allow_cancellation, rs.free_cancel_days, rs.online_payment_enabled,
            (SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0) FROM payment p WHERE p.reservation_id = v.reservation_id) AS paid,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payment p WHERE p.reservation_id = v.reservation_id AND p.payment_status = 'Pending') AS pending_paid,
            CASE WHEN v.booking_scope = 'Rooms' THEN
              (SELECT GROUP_CONCAT(DISTINCT t.type_name ORDER BY t.type_name SEPARATOR ', ')
                 FROM reservation_room rr JOIN room r ON r.room_id = rr.room_id JOIN room_type t ON t.room_type_id = r.room_type_id
                WHERE rr.reservation_id = v.reservation_id)
            ELSE " . ROOMS_LABEL_SQL . " END AS rooms
       FROM reservation v JOIN resort rs ON rs.resort_id = v.resort_id WHERE v.guest_id = ? ORDER BY v.check_in_date DESC, v.reservation_id DESC"
);
$stmt->execute([$gid]);
$bookings = $stmt->fetchAll();

$today       = today();
$activeCnt   = 0;
$unpaidCnt   = 0;
foreach ($bookings as $b) {
    $_SESSION['grants'][$b['booking_ref']] = true;
    if (in_array($b['status'], ['Pending', 'Confirmed', 'Checked-in'], true) && $b['check_out_date'] >= $today) {
        $activeCnt++;
    }
    if ($b['status'] === 'Pending' && ((float)$b['total_amount'] - (float)$b['paid']) > 0.004 && (float)($b['pending_paid'] ?? 0) <= 0.004) {
        $unpaidCnt++;
    }
}
$totalBookings = count($bookings);
$hasMore       = $totalBookings > 5;
$fullName      = trim($guest['first_name'] . ' ' . $guest['last_name']);

site_start('My account', 'account');
?>
<section class="panel acct-hero">
  <div class="acct-id">
    <span class="acct-avatar" aria-hidden="true"><?= h(initials($fullName)) ?></span>
    <div class="acct-meta">
      <h1><?= h(format_name_inv((string)$guest['last_name'], (string)$guest['first_name'])) ?></h1>
      <p class="muted"><?= h($guest['email']) ?><?= !empty($guest['phone']) ? ' · ' . h($guest['phone']) : '' ?></p>
    </div>
  </div>
  <div class="head-actions">
    <?php if ($staffLink = link_to_staff($pdo, (int)$guest['guest_id'])):
        $switchLbl = $staffLink['resort_id'] === null ? 'Switch to super admin' : (($staffLink['access'] ?? '') === 'Admin' ? 'Switch to admin account' : 'Switch to staff account');
    ?>
      <form method="post" action="<?= h(ru('switch_staff.php')) ?>"><?= csrf_field() ?><button class="btn primary" type="submit"><?= h($switchLbl) ?></button></form>
    <?php endif; ?>
    <form method="post" action="<?= h(ru('logout.php')) ?>"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out" data-confirm-ok="Yes, log out">Log out</button></form>
  </div>
</section>

<div class="acct-stats" aria-label="Booking overview">
  <div class="acct-stat">
    <b><?= $totalBookings ?></b>
    <span>Total bookings</span>
  </div>
  <div class="acct-stat">
    <b><?= $activeCnt ?></b>
    <span>Upcoming &amp; active</span>
  </div>
  <div class="acct-stat">
    <b><?= $unpaidCnt ?></b>
    <span>Awaiting payment</span>
  </div>
</div>

<section class="panel flush acct-bookings" id="bookings">
  <div class="acct-sec-head">
    <div>
      <h2>My bookings</h2>
      <p class="muted" id="acct-count-note">
        <?= $hasMore ? 'Showing 5 of ' . $totalBookings . ' bookings' : h(plural($totalBookings, 'booking')) ?>
      </p>
    </div>
    <a class="btn sm primary" href="resorts.php">Book a cottage</a>
  </div>

  <?php if ($hasMore): ?>
  <div class="acct-search-bar" id="acct-search-bar" hidden>
    <input type="search" id="acct-search-input" placeholder="Search by reference, resort, cottage/room, or status…" aria-label="Search bookings" autocomplete="off">
    <button type="button" class="btn sm" id="acct-collapse-btn">Show less</button>
  </div>
  <?php endif; ?>

  <?php if (!$bookings): ?>
    <div class="acct-empty">
      <p class="muted">You haven’t booked any stay yet.</p>
      <a class="btn primary" href="resorts.php">Browse resorts &amp; cottages</a>
    </div>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Reference</th>
          <th>Resort &amp; stay</th>
          <th>Date</th>
          <th class="num">Guests</th>
          <th>Status</th>
          <th class="right">Total</th>
          <th class="right"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody id="acct-bookings-body">
      <?php foreach ($bookings as $idx => $b):
          $pol      = cancel_policy($b, (float)$b['paid']);
          $hasPend  = (float)($b['pending_paid'] ?? 0) > 0.004;
          $balance  = max(0.0, round((float)$b['total_amount'] - (float)$b['paid'], 2));
          $needsPay = $b['status'] === 'Pending' && ($b['source'] ?? '') === 'Online'
                   && $balance > 0.004
                   && !$hasPend
                   && (int)($b['online_payment_enabled'] ?? 1) === 1;
          $isCot    = ($b['booking_scope'] ?? '') === 'Cottage';
          $isExtra  = $idx >= 5;
          $statusWord = ($b['status'] === 'Pending' && (float)$b['paid'] > 0.004 && $balance > 0.004)
              ? 'Downpayment paid'
              : (string)$b['status'];
          $searchHay = strtolower($b['booking_ref'] . ' ' . $b['resort_name'] . ' ' . ($b['rooms'] ?? '') . ' ' . $statusWord . ' ' . ($b['trip_code'] ?? ''));
      ?>
        <tr data-booking-row<?= $isExtra ? ' data-extra-booking hidden' : '' ?> data-search="<?= h($searchHay) ?>">
          <td class="nowrap">
            <strong><?= h($b['booking_ref']) ?></strong>
            <?php if (!empty($b['trip_code'])): ?><br><a class="muted" style="font-size:12px" href="trip_booked.php?code=<?= urlencode($b['trip_code']) ?>">Trip <?= h($b['trip_code']) ?></a><?php endif; ?>
          </td>
          <td>
            <strong><?= h($b['resort_name']) ?></strong>
            <br><small class="muted"><?= h($b['rooms'] ?? '') ?></small>
          </td>
          <td class="nowrap">
            <?php if ($isCot): ?>
              <?= h(fdate($b['check_in_date'])) ?><br><small class="muted">Day use</small>
            <?php else: ?>
              <?= h(fdate($b['check_in_date'])) ?><br><small class="muted">to <?= h(fdate($b['check_out_date'])) ?></small>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int)$b['num_guests'] ?></td>
          <td><?= reservation_status_badge($b, (float)$b['paid']) ?></td>
          <td class="num">
            <strong><?= h(money($b['total_amount'])) ?></strong>
            <?php if ($balance > 0.004 && (float)$b['paid'] > 0.004 && $b['status'] !== 'Cancelled'): ?>
              <br><small class="muted">Bal: <?= h(money($balance)) ?></small>
            <?php endif; ?>
          </td>
          <td class="row-actions acct-actions">
            <div class="acct-btns">
              <?php if ($needsPay): ?><a class="btn sm primary" href="payment.php?ref=<?= urlencode($b['booking_ref']) ?>"><?= (float)$b['paid'] > 0.004 ? 'Pay balance' : 'Pay now' ?></a><?php endif; ?>
              <a class="btn sm" href="booking.php?ref=<?= urlencode($b['booking_ref']) ?>">View</a>
              <?php if ($b['status'] === 'Checked-out'): ?><a class="btn sm" href="booking.php?ref=<?= urlencode($b['booking_ref']) ?>#review">Review</a><?php endif; ?>
              <?php if ($pol['can']): ?>
                <form method="post" action="booking.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="ref" value="<?= h($b['booking_ref']) ?>">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="back" value="account">
                  <button class="btn sm danger" type="submit" data-confirm="Cancel booking <?= h($b['booking_ref']) ?> at <?= h($b['resort_name']) ?>? This can’t be undone." data-confirm-title="Cancel booking" data-confirm-ok="Yes, cancel booking" data-confirm-cancel="Keep booking" data-confirm-tone="danger">Cancel</button>
                </form>
              <?php elseif ($pol['active']): ?>
                <button class="btn sm danger is-locked" type="button" aria-haspopup="dialog"
                        data-notice="<?= h($pol['note']) ?>"
                        data-notice-title="Can’t cancel <?= h($b['booking_ref']) ?> online" data-notice-ok="Got it">Cancel</button>
              <?php endif; ?>
            </div>
            <?php if ($pol['active']): ?><small class="acct-note <?= $pol['can'] ? 'is-open' : 'is-closed' ?>"><?= h($pol['short']) ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="empty" id="acct-no-match" hidden>No bookings match your search.</p>
  </div>

  <?php if ($hasMore): ?>
  <div class="acct-more-bar" id="acct-more-bar">
    <button type="button" class="btn" id="acct-see-more">See more (<?= $totalBookings - 5 ?> more booking<?= $totalBookings - 5 === 1 ? '' : 's' ?>)</button>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</section>

<div class="acct-settings">
  <section class="panel stack" id="profile">
    <div class="acct-card-head">
      <h2>My details</h2>
      <p class="muted">Used to prefill your bookings faster.</p>
    </div>
    <?php if ($error['profile']): ?><div class="notice error" role="alert"><?= h($error['profile']) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="grid-form two-col">
        <label><span>Last name</span><input placeholder="Santos" name="last_name" value="<?= h($guest['last_name']) ?>" maxlength="60" required></label>
        <label><span>First name</span><input placeholder="Maria" name="first_name" value="<?= h($guest['first_name']) ?>" maxlength="60" required></label>
        <label><span>Email</span><input value="<?= h($guest['email']) ?>" disabled></label>
        <label><span>Phone</span><input placeholder="0917 123 4567" type="tel" name="phone" value="<?= h($guest['phone'] ?? '') ?>" maxlength="30"></label>
      </div>
      <div class="actions"><button class="btn primary" type="submit">Save details</button></div>
    </form>
  </section>

  <section class="panel stack" id="password">
    <div class="acct-card-head">
      <h2>Change password</h2>
      <p class="muted">Use at least 8 characters.</p>
    </div>
    <?php if ($error['password']): ?><div class="notice error" role="alert"><?= h($error['password']) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label><span>Current password</span>
        <span class="pw-wrap">
          <input placeholder="Current password" type="password" name="current" maxlength="72" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <label><span>New password</span>
        <span class="pw-wrap">
          <input placeholder="Min. 8 characters" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <label><span>Confirm new password</span>
        <span class="pw-wrap">
          <input placeholder="Repeat new password" type="password" name="password2" minlength="8" maxlength="72" autocomplete="new-password" required>
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <div class="actions"><button class="btn primary" type="submit" data-confirm="Change your password now?" data-confirm-title="Change password" data-confirm-ok="Yes, change it">Update password</button></div>
    </form>
  </section>
</div>

<?php if ($hasMore): ?>
<script>
(function () {
  var seeMore = document.getElementById('acct-see-more');
  var moreBar = document.getElementById('acct-more-bar');
  var searchBar = document.getElementById('acct-search-bar');
  var searchIn = document.getElementById('acct-search-input');
  var collapseBtn = document.getElementById('acct-collapse-btn');
  var countNote = document.getElementById('acct-count-note');
  var noMatch = document.getElementById('acct-no-match');
  var rows = [].slice.call(document.querySelectorAll('#acct-bookings-body tr[data-booking-row]'));
  var total = rows.length;
  var expanded = false;

  function applyFilter() {
    var q = (searchIn ? searchIn.value : '').trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (tr, i) {
      var visible = expanded
        ? (q === '' || (tr.dataset.search || '').indexOf(q) !== -1)
        : (i < 5);
      tr.hidden = !visible;
      if (visible) shown++;
    });
    if (noMatch) noMatch.hidden = !(expanded && shown === 0);
    if (countNote) {
      countNote.textContent = expanded
        ? (q !== '' ? 'Showing ' + shown + ' of ' + total + ' bookings' : 'Showing all ' + total + ' bookings')
        : ('Showing 5 of ' + total + ' bookings');
    }
  }

  if (seeMore) {
    seeMore.addEventListener('click', function () {
      expanded = true;
      if (moreBar) moreBar.hidden = true;
      if (searchBar) searchBar.hidden = false;
      applyFilter();
      if (searchIn) searchIn.focus();
    });
  }
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function () {
      expanded = false;
      if (searchIn) searchIn.value = '';
      if (searchBar) searchBar.hidden = true;
      if (moreBar) moreBar.hidden = false;
      applyFilter();
    });
  }
  if (searchIn) {
    searchIn.addEventListener('input', applyFilter);
  }
})();
</script>
<?php endif; ?>
<?php site_end();
