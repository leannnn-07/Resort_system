<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require __DIR__ . '/includes/notify.php';
require __DIR__ . '/includes/reviews.php';

optional_resort($pdo);
$ref   = strtoupper(trim((string)($_POST['ref'] ?? $_GET['ref'] ?? '')));
$ref   = preg_match('/^[A-Z0-9]{6,10}$/', $ref) ? $ref : '';
$error = '';

function load_by_ref(PDO $pdo, string $ref): ?array
{
    $stmt = $pdo->prepare(
        "SELECT v.*, g.first_name, g.last_name, g.email, g.phone,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name
           FROM reservation v JOIN guest g ON g.guest_id = v.guest_id
          WHERE v.booking_ref = ?"
    );
    $stmt->execute([$ref]);
    return $stmt->fetch() ?: null;
}

// ---------- Look up (ref + email) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lookup') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (throttle_blocked($pdo, 'lookup', 8) || (($_SESSION['lookup_fail'] ?? 0) >= 5 && time() < ($_SESSION['lookup_block'] ?? 0))) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $res = $ref !== '' ? load_by_ref($pdo, $ref) : null;
        if ($res && strtolower($res['email']) === $email) {
            $_SESSION['grants'][$ref] = true;
            unset($_SESSION['lookup_fail'], $_SESSION['lookup_block']);
            throttle_clear($pdo, 'lookup');
            redirect('booking.php?ref=' . urlencode($ref));
        }
        throttle_fail($pdo, 'lookup');
        $_SESSION['lookup_fail']  = ($_SESSION['lookup_fail'] ?? 0) + 1;
        $_SESSION['lookup_block'] = time() + 60;
        $error = 'We couldn’t find a booking with that reference and email.';
    }
}

$res = ($ref !== '' && !empty($_SESSION['grants'][$ref])) ? load_by_ref($pdo, $ref) : null;
if ($res && ($rr = resort_by_id($pdo, (int)$res['resort_id']))) {
    resort_apply($rr);   // times, contact details and cancellation rules of the resort this booking is with
}

// ---------- Review (after the stay) ----------
if ($res && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    csrf_check();
    try {
        $had = review_for($pdo, (int)$res['reservation_id']);
        save_review($pdo, $res, $_POST);
        flash($had ? 'Your review was updated. Thank you.' : 'Thank you for your review.');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('booking.php?ref=' . urlencode($ref) . '#review');
}

// ---------- Cancel ----------
if ($res && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrf_check();
    $paid = amount_paid($pdo, (int)$res['reservation_id']);
    $cutoff = add_days(today(), (int)$config['free_cancel_days']);

    if (!in_array($res['status'], ['Pending', 'Confirmed'], true)) {
        flash('This booking can’t be cancelled online.', 'error');
    } elseif ($res['check_in_date'] < $cutoff) {
        flash('It’s too close to arrival to cancel online. Please contact us.', 'error');
    } elseif ($paid > 0.004) {
        flash('A payment is on file for this booking. Please contact us to cancel and arrange a refund.', 'error');
    } else {
        $upd = $pdo->prepare("UPDATE reservation SET status = 'Cancelled' WHERE reservation_id = ? AND status IN ('Pending','Confirmed')");
        $upd->execute([$res['reservation_id']]);
        if ($upd->rowCount() === 1) {
            send_booking_email($pdo, (int)$res['reservation_id'], 'cancelled');
            flash('Your booking has been cancelled. The rooms are released.');
        } else {
            flash('This booking can’t be cancelled online any more.', 'error');
        }
    }
    redirect('booking.php?ref=' . urlencode($ref));
}

site_start($res ? 'Booking ' . $ref : 'Manage booking', 'booking');

// ---------- Lookup form ----------
if (!$res):
?>
<form method="post" class="panel stack auth">
  <h1>Manage your booking</h1>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="lookup">
  <p class="muted">Enter the booking reference from your confirmation and the email you booked with.</p>
  <label><span>Booking reference</span><input name="ref" value="<?= h($ref) ?>" maxlength="10" autocapitalize="characters" required></label>
  <label><span>Email</span><input type="email" name="email" autocomplete="email" required></label>
  <button class="btn primary" type="submit">Find booking</button>
</form>
<?php
    site_end();
    exit;
endif;

// ---------- Booking details ----------
$id = (int)$res['reservation_id'];

$stmt = $pdo->prepare(
    'SELECT t.type_name, COUNT(*) AS qty, MAX(rr.rate_applied) AS rate
       FROM reservation_room rr
       JOIN room r ON r.room_id = rr.room_id
       JOIN room_type t ON t.room_type_id = r.room_type_id
      WHERE rr.reservation_id = ? GROUP BY t.type_name'
);
$stmt->execute([$id]);
$roomLines = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT rs.*, s.service_name FROM reservation_service rs
       JOIN service s ON s.service_id = rs.service_id
      WHERE rs.reservation_id = ? ORDER BY rs.service_date, s.service_name'
);
$stmt->execute([$id]);
$svcLines = $stmt->fetchAll();

$companions = load_companions($pdo, $id);
$n       = nights($res['check_in_date'], $res['check_out_date']);
$paid    = amount_paid($pdo, $id);
$balance = (float)$res['total_amount'] - $paid;
$cutoff  = add_days(today(), (int)$config['free_cancel_days']);
$canCancel = in_array($res['status'], ['Pending', 'Confirmed'], true) && $res['check_in_date'] >= $cutoff && $paid <= 0.004;

$next = match ($res['status']) {
    'Pending'     => 'We received your request. Our team will confirm it by email or phone soon.',
    'Confirmed'   => 'Your stay is confirmed. We look forward to welcoming you.',
    'Checked-in'  => 'You are checked in. Enjoy your stay.',
    'Checked-out' => 'Thank you for staying with us.',
    default       => 'This booking was cancelled.',
};
?>
<?php if (($_SESSION['just_booked'] ?? '') === $ref): unset($_SESSION['just_booked']); ?>
  <?= booking_steps(4) ?>
  <section class="panel confirm-card">
    <h2>Request received</h2>
    <p>Your reference is <strong class="ref"><?= h($ref) ?></strong>. Keep it: you need it with your email to view or cancel this booking.</p>
  </section>
<?php endif; ?>
<div class="page-head">
  <h1>Booking <span class="ref"><?= h($ref) ?></span> <?= badge($res['status']) ?></h1>
  <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<p class="stay-line"><?= h($next) ?></p>

<div class="split">
  <section class="panel">
    <h2>Stay</h2>
    <dl class="facts">
      <dt>Guest</dt><dd><?= h($res['first_name'] . ' ' . $res['last_name']) ?><br><span class="muted"><?= h($res['email']) ?>, <?= h($res['phone']) ?></span></dd>
      <dt>Resort</dt><dd><a href="<?= h(ru('landing.php')) ?>"><?= h($config['resort']) ?></a></dd>
      <dt>Booked</dt><dd><?= h(scope_label($res)) ?></dd>
      <dt>Check-in</dt><dd><?= h(fdate($res['check_in_date'])) ?> <span class="muted">from <?= h($config['checkin_time']) ?></span></dd>
      <dt>Check-out</dt><dd><?= h(fdate($res['check_out_date'])) ?> <span class="muted">by <?= h($config['checkout_time']) ?></span></dd>
      <dt>Nights</dt><dd><?= $n ?></dd>
      <dt>Guests</dt><dd><?= h(party_text($res, $companions)) ?></dd>
      <?php if ($companions): ?><dt>Also staying</dt><dd><?php foreach ($companions as $c): ?><?= h($c['first_name'] . ' ' . $c['last_name']) ?> <span class="muted">(<?= h(strtolower($c['guest_type'])) ?>, <?= (int)$c['age'] ?>)</span><br><?php endforeach; ?></dd><?php endif; ?>
      <?php if ($res['special_requests']): ?><dt>Requests</dt><dd><?= nl2br(h($res['special_requests'])) ?></dd><?php endif; ?>
    </dl>
  </section>

  <section class="panel">
    <h2>Charges</h2>
    <dl class="facts totals">
      <?php if ($res['booking_scope'] !== 'Rooms'): ?>
        <dt><?= h(scope_label($res)) ?>, <?= h(plural($n, 'night')) ?></dt>
        <dd class="num"><?= h(money((float)$res['total_amount'] - array_sum(array_column($svcLines, 'price_charged')))) ?></dd>
      <?php else: foreach ($roomLines as $l): ?>
        <dt><?= h($l['type_name']) ?> × <?= (int)$l['qty'] ?></dt>
        <dd class="num"><?= h(money((float)$l['rate'] * (int)$l['qty'] * $n)) ?></dd>
      <?php endforeach; endif; ?>
      <?php foreach ($svcLines as $l): ?>
        <dt><?= h($l['service_name']) ?> × <?= (int)$l['quantity'] ?></dt>
        <dd class="num"><?= h(money($l['price_charged'])) ?></dd>
      <?php endforeach; ?>
      <dt>Total</dt><dd class="num strong"><?= h(money($res['total_amount'])) ?></dd>
      <dt>Paid</dt><dd class="num"><?= h(money($paid)) ?></dd>
      <dt>Due at resort</dt><dd class="num strong"><?= h(money(max(0, $balance))) ?></dd>
    </dl>
  </section>
</div>

<?php if ($res['status'] === 'Checked-out'): $myReview = review_for($pdo, $id); ?>
<section class="panel no-print" id="review">
  <h2><?= $myReview ? 'Your review' : 'How was your stay?' ?></h2>
  <p class="muted"><?= $myReview ? 'Thank you. You can change your rating or comment any time.' : 'Rate your stay at ' . h($config['resort']) . ' and tell other guests what to expect.' ?> Your first name and the initial of your last name are shown.</p>
  <?= review_form($ref, $myReview) ?>
</section>
<?php endif; ?>

<?php if (in_array($res['status'], ['Pending', 'Confirmed'], true)): ?>
<section class="panel no-print">
  <h2>Need to cancel?</h2>
  <?php if ($canCancel): ?>
    <p class="muted">Free until <?= h(fdate(add_days($res['check_in_date'], -(int)$config['free_cancel_days']))) ?>. Your rooms are released straight away.</p>
    <form method="post" onsubmit="return confirm('Cancel this booking? This can’t be undone.')">
      <?= csrf_field() ?>
      <input type="hidden" name="ref" value="<?= h($ref) ?>">
      <input type="hidden" name="action" value="cancel">
      <button class="btn danger" type="submit">Cancel booking</button>
    </form>
  <?php else: ?>
    <p class="muted">To change or cancel this booking, contact us at <?= h($config['contact_email']) ?> or <?= h($config['contact_phone']) ?>. Quote reference <?= h($ref) ?>.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php site_end();
