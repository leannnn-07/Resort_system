<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/reviews.php';
require_once __DIR__ . '/includes/trips.php';

optional_resort($pdo);
$ref   = strtoupper(trim((string)($_POST['ref'] ?? $_GET['ref'] ?? '')));
$ref   = preg_match('/^[A-Z0-9]{6,10}$/', $ref) ? $ref : '';
$error = '';

function load_by_ref(PDO $pdo, string $ref): ?array
{
    $stmt = $pdo->prepare(
        "SELECT v.*, g.first_name, g.last_name, g.email, g.phone,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name,
                (SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1) AS cottage_name
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
        // A trip code (with the email used to book) opens every stay booked in that checkout.
        if (!$res && $ref !== '' && ($tripRefs = trip_refs_for_email($pdo, $ref, $email))) {
            foreach ($tripRefs as $tr) {
                $_SESSION['grants'][$tr] = true;
            }
            unset($_SESSION['lookup_fail'], $_SESSION['lookup_block']);
            throttle_clear($pdo, 'lookup');
            redirect('trip_booked.php?code=' . urlencode($ref));
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

// A form bigger than the server's post_max_size arrives completely empty (no fields, no files): say what happened instead of failing silently.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('Those files are too big to send at once (the limit is ' . review_size_label(review_media_limits()['post_bytes']) . ' for everything together). Choose fewer or smaller photos and videos and try again.', 'error');
    redirect('booking.php' . ($ref !== '' ? '?ref=' . urlencode($ref) . '#review' : ''));
}

// ---------- Review (after the stay) ----------
if ($res && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    csrf_check();
    try {
        $had = review_for($pdo, (int)$res['reservation_id']);
        save_review($pdo, $res, $_POST, $_FILES);
        flash($had ? 'Your review was updated. Thank you.' : 'Thank you for your review.');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('booking.php?ref=' . urlencode($ref) . '#review');
}

// ---------- Cancel ----------
// The resort's own terms decide: online cancellation on or off, and how many days before check-in it stays open.
function booking_cancel_policy(array $res, float $paid): array
{
    global $config;
    return cancel_policy($res + ['allow_cancellation' => $config['allow_cancellation'] ? 1 : 0, 'free_cancel_days' => $config['free_cancel_days']], $paid);
}
if ($res && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    csrf_check();
    $pol = booking_cancel_policy($res, amount_paid($pdo, (int)$res['reservation_id']));

    if (!$pol['active']) {
        flash('This booking can’t be cancelled online.', 'error');
    } elseif (!$pol['can']) {
        flash($pol['note'], 'error');
    } else {
        $upd = $pdo->prepare("UPDATE reservation SET status = 'Cancelled' WHERE reservation_id = ? AND status IN ('Pending','Confirmed')");
        $upd->execute([$res['reservation_id']]);
        if ($upd->rowCount() === 1) {
            require_once __DIR__ . '/includes/notifications.php';
            add_staff_notification(
                $pdo,
                (int)$res['resort_id'],
                (int)$res['reservation_id'],
                'cancelled',
                'Booking cancelled · ' . $ref,
                trim($res['first_name'] . ' ' . $res['last_name']) . ' cancelled online',
                'reservation_view.php?id=' . (int)$res['reservation_id']
            );
            send_booking_email($pdo, (int)$res['reservation_id'], 'cancelled');
            flash('Your booking ' . $ref . ' has been cancelled. The rooms are released.');
        } else {
            flash('This booking can’t be cancelled online any more.', 'error');
        }
    }
    // coming from My account: go back to the list
    redirect(($_POST['back'] ?? '') === 'account' && !empty($_SESSION['guest_id']) ? 'account.php#bookings' : 'booking.php?ref=' . urlencode($ref));
}

site_start($res ? 'Booking ' . $ref : 'Manage booking', 'booking', $res ? 'no-nav' : '');

// ---------- Lookup form ----------
if (!$res):
?>
<form method="post" class="panel stack auth">
  <h1>Manage booking</h1>
  <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="lookup">
  <p class="muted">Enter the booking reference from your confirmation and the email you booked with.</p>
  <label><span>Booking reference or trip code</span><input placeholder="e.g. BK12345" name="ref" value="<?= h($ref) ?>" maxlength="10" autocapitalize="characters" required></label>
  <label><span>Email</span><input placeholder="e.g. maria@example.com" type="email" name="email" autocomplete="email" required></label>
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

$stmt = $pdo->prepare('SELECT * FROM reservation_cottage WHERE reservation_id = ?');
$stmt->execute([$id]);
$cottageLines = $stmt->fetchAll();

$companions = load_companions($pdo, $id);
$n       = nights($res['check_in_date'], $res['check_out_date']);
$paySum  = reservation_payment_summary($pdo, $res);
$paid    = $paySum['paid'];
$balance = $paySum['balance'];
$pol     = booking_cancel_policy($res, $paid);

$pStmt = $pdo->prepare("SELECT * FROM payment WHERE reservation_id = ? ORDER BY payment_date, payment_id");
$pStmt->execute([$id]);
$payments = $pStmt->fetchAll();

// Find the most recent rejected guest payment (if any) so the guest sees why it was rejected.
$lastRejected = null;
foreach (array_reverse($payments) as $pp) {
    if ($pp['payment_status'] === 'Voided' && ($pp['source'] ?? '') === 'Guest' && !empty($pp['verification_note'])) {
        $lastRejected = $pp;
        break;
    }
}

$next = match ($res['status']) {
    'Pending'     => $paySum['awaiting_verification']
        ? 'Payment submitted (' . money($paySum['pending']) . ') — waiting for staff verification.'
        : ($paySum['downpayment_only']
            ? 'Downpayment paid (' . money($paid) . '). Remaining balance of ' . money($balance) . ' must be paid in full before approval.'
            : ($paySum['needs_downpayment']
                ? 'Send ' . money($paySum['downpayment_remaining']) . ' via GCash or Maya to confirm your booking.'
                : 'Request received — full payment is required for approval.')),
    'Confirmed'   => 'Your stay is confirmed.',
    'Checked-in'  => 'You are checked in. Enjoy your stay.',
    'Checked-out' => 'Thank you for staying with us.',
    default       => 'This booking was cancelled.',
};
$justPaidSubmitted = ($_SESSION['just_paid_submitted'] ?? '') === $ref;
if ($justPaidSubmitted) {
    unset($_SESSION['just_paid_submitted']);
}
$justBooked = ($_SESSION['just_booked'] ?? '') === $ref;
if ($justBooked) {
    unset($_SESSION['just_booked']);
}
?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back no-print" href="<?= !empty($_SESSION['guest_id']) ? 'account.php#bookings' : 'resorts.php' ?>">&larr; <?= !empty($_SESSION['guest_id']) ? 'Back to my account' : 'Back to resorts' ?></a>
    <h1>Booking <span class="ref"><?= h($ref) ?></span> <?= reservation_status_badge($res, $paid) ?></h1>
  </div>
  <button class="btn no-print" type="button" onclick="window.print()">Print</button>
</div>
<?php if ($justPaidSubmitted): ?>
  <?= booking_steps(3) ?>
  <section class="panel confirm-card">
    <h2>Payment submitted</h2>
    <p>Ref <strong class="ref"><?= h($ref) ?></strong> · Staff will verify your screenshot and confirm by email.</p>
  </section>
<?php elseif ($justBooked): ?>
  <?= booking_steps($paySum['needs_downpayment'] ? 2 : 3) ?>
  <section class="panel confirm-card">
    <h2><?= $paySum['needs_downpayment'] ? 'Booking held — pay to confirm' : 'Request received' ?></h2>
    <p>Booking reference: <strong class="ref"><?= h($ref) ?></strong></p>
  </section>
<?php endif; ?>

<p class="stay-line"><?= h($next) ?></p>

<?php if (($paySum['needs_downpayment'] || ($paySum['downpayment_only'] && !$paySum['awaiting_verification'])) && !empty($config['online_payment_enabled'])):
    $isBalOnly = $paySum['downpayment_only'];
    $payNowTarget = $isBalOnly ? $balance : $paySum['downpayment_remaining'];
?>
<section class="panel stack pay-banner no-print" id="pay">
  <div class="panel-head">
    <h2><?= $isBalOnly ? 'Pay remaining ' . h(money($payNowTarget)) . ' for full approval' : 'Pay ' . h(money($payNowTarget)) . ' to reserve' ?></h2>
    <span class="badge <?= $isBalOnly ? 'dp-paid' : 'pending' ?>"><?= $isBalOnly ? 'Downpayment paid' : 'Downpayment required' ?></span>
  </div>
  <?php if ($lastRejected): ?>
    <div class="notice error" role="alert">
      Rejected: <strong><?= h($lastRejected['verification_note']) ?></strong>
    </div>
  <?php endif; ?>
  <?= payment_accounts_box() ?>
  <div class="actions">
    <a class="btn primary" href="payment.php?ref=<?= urlencode($ref) ?>"><?= $isBalOnly ? 'Pay remaining balance (GCash / Maya) →' : 'Upload screenshot &amp; reference no. →' ?></a>
  </div>
</section>
<?php endif; ?>

<div class="split">
  <section class="panel">
    <h2>Details</h2>
    <dl class="facts">
      <dt>Guest</dt><dd><?= h(format_name_inv((string)$res['last_name'], (string)$res['first_name'])) ?><br><span class="muted"><?= h($res['email']) ?><?= !empty($res['phone']) ? ', ' . h($res['phone']) : '' ?></span></dd>
      <dt>Resort</dt><dd><a href="<?= h(ru('landing.php')) ?>"><?= h($config['resort']) ?></a><?php if (!empty($resort['location'])): ?><br><span class="muted"><?= h($resort['location']) ?></span><?php endif; ?></dd>
      <dt>Booked</dt><dd><strong><?= h(scope_label($res)) ?></strong></dd>
      <?php if (!empty($res['trip_code'])): ?><dt>Trip</dt><dd><a href="trip_booked.php?code=<?= urlencode($res['trip_code']) ?>"><?= h($res['trip_code']) ?></a></dd><?php endif; ?>
      <?php if ($res['booking_scope'] === 'Cottage'): ?>
        <dt>Visit date</dt><dd><?= h(fdate($res['check_in_date'])) ?> <span class="muted">(Day use)</span></dd>
      <?php else: ?>
        <dt>Check-in</dt><dd><?= h(fdate($res['check_in_date'])) ?> <span class="muted">from <?= h($config['checkin_time']) ?></span></dd>
        <dt>Check-out</dt><dd><?= h(fdate($res['check_out_date'])) ?> <span class="muted">by <?= h($config['checkout_time']) ?></span></dd>
        <dt>Nights</dt><dd><?= $n ?></dd>
      <?php endif; ?>
      <dt>Guests</dt><dd><?= h(plural((int)$res['num_guests'], 'guest')) ?></dd>
    </dl>
    <div class="detail-triggers no-print">
      <button type="button" class="btn sm" data-form-open="booking-guests-modal">View guests (<?= (int)$res['num_guests'] ?>)</button>
      <button type="button" class="btn sm" data-form-open="booking-items-modal"><?= $res['booking_scope'] === 'Cottage' ? 'View cottage & breakdown' : 'View rooms & breakdown' ?></button>
    </div>
  </section>

  <section class="panel">
    <h2>Payment</h2>
    <dl class="facts totals">
      <dt>Total</dt><dd class="num strong"><?= h(money($res['total_amount'])) ?></dd>
      <?php if ($paySum['required_downpayment'] > 0.004): ?>
        <dt>Required downpayment</dt><dd class="num"><?= h(money($paySum['required_downpayment'])) ?><?= $paySum['downpayment_met'] ? ' <span class="badge confirmed">Paid</span>' : '' ?></dd>
      <?php endif; ?>
      <dt>Paid (verified)</dt><dd class="num"><?= h(money($paid)) ?></dd>
      <?php if ($paySum['pending'] > 0.004): ?>
        <dt>Pending verification</dt><dd class="num"><?= h(money($paySum['pending'])) ?></dd>
      <?php endif; ?>
      <dt><?= $balance > 0.004 ? 'Balance due' : 'Balance' ?></dt><dd class="num strong"><?= h(money(max(0, $balance))) ?></dd>
    </dl>
    <?php if ($payments): ?>
      <h3 style="margin:16px 0 8px;font-size:14px">Payment history</h3>
      <ul class="pay-history">
        <?php foreach ($payments as $p):
            $stLbl = match ($p['payment_status']) {
                'Paid'     => 'Verified',
                'Pending'  => 'Pending verification',
                'Voided'   => ($p['source'] ?? '') === 'Guest' ? 'Not verified' : 'Voided',
                default    => $p['payment_status'],
            };
            $stCls = match ($p['payment_status']) {
                'Paid'    => 'verified',
                'Pending' => 'pending',
                default   => 'voided',
            };
        ?>
          <li>
            <span>
              <strong><?= h($p['method']) ?></strong> · <?= h(fdate($p['payment_date'])) ?>
              <span class="badge <?= h($stCls) ?>"><?= h($stLbl) ?></span>
              <?php if (!empty($p['reference_no'])): ?><br><small class="muted">Ref: <code><?= h($p['reference_no']) ?></code></small><?php endif; ?>
              <?php if ($proofUrl = payment_proof_url($p['proof_image'] ?? null)): ?>
                <br><a class="proof-link" href="<?= h($proofUrl) ?>" data-proof-title="<?= h($p['method'] . (!empty($p['reference_no']) ? ' · Ref ' . $p['reference_no'] : '') . ' · ' . money($p['amount'])) ?>"><img class="proof-thumb" src="<?= h($proofUrl) ?>" alt="Payment screenshot"><span>View screenshot</span></a>
              <?php endif; ?>
              <?php if ($p['payment_status'] === 'Voided' && !empty($p['verification_note'])): ?><br><small class="muted"><?= h($p['verification_note']) ?></small><?php endif; ?>
            </span>
            <strong class="num<?= $p['payment_status'] === 'Voided' ? ' voided-amt' : '' ?>"><?= $p['payment_status'] === 'Refunded' ? '−' : '' ?><?= h(money($p['amount'])) ?></strong>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<!-- Pop-up modal: Guests & requests -->
<div class="modal" id="booking-guests-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="bg-title">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="bg-title">Guests (<?= (int)$res['num_guests'] ?>)</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="table-scroll">
      <table>
        <thead><tr><th>#</th><th>Name</th><th>Type</th></tr></thead>
        <tbody>
          <tr>
            <td>1</td>
            <td><?= h(format_name_inv((string)$res['last_name'], (string)$res['first_name'])) ?> <span class="muted">(primary guest)</span></td>
            <td><?= h($res['booker_type'] ?? 'Adult') ?></td>
          </tr>
        <?php foreach ($companions as $i => $c): ?>
          <tr>
            <td><?= $i + 2 ?></td>
            <td><?= h(format_name_inv((string)$c['last_name'], (string)$c['first_name'])) ?></td>
            <td><?= h($c['guest_type']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($res['special_requests'])): ?>
      <p style="margin:14px 0 0"><strong>Special requests:</strong><br><span class="muted"><?= nl2br(h($res['special_requests'])) ?></span></p>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<!-- Pop-up modal: Cottage / Rooms & Add-ons breakdown -->
<div class="modal" id="booking-items-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="bi-title">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="bi-title"><?= $res['booking_scope'] === 'Cottage' ? 'Cottage & charges breakdown' : 'Rooms & charges breakdown' ?></h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <dl class="facts totals">
      <?php if ($res['booking_scope'] === 'Cottage' && $cottageLines): foreach ($cottageLines as $cl): ?>
        <dt><?= h($cl['cottage_name']) ?> × <?= (int)$cl['quantity'] ?></dt>
        <dd class="num"><?= h(money((float)$cl['cottage_rate'] * (int)$cl['quantity'])) ?></dd>
        <?php if ((float)$cl['entrance_total'] > 0.004): ?>
          <dt>Entrance fees (<?= h(plural((int)$res['num_guests'], 'guest')) ?>)</dt>
          <dd class="num"><?= h(money($cl['entrance_total'])) ?></dd>
        <?php endif; ?>
      <?php endforeach; elseif ($res['booking_scope'] !== 'Rooms'): ?>
        <dt><?= h(scope_label($res)) ?>, <?= h(plural($n, 'night')) ?></dt>
        <dd class="num"><?= h(money((float)$res['total_amount'] - array_sum(array_column($svcLines, 'price_charged')))) ?></dd>
      <?php else: foreach ($roomLines as $l): ?>
        <dt><?= h($l['type_name']) ?> × <?= (int)$l['qty'] ?> (<?= h(plural($n, 'night')) ?>)</dt>
        <dd class="num"><?= h(money((float)$l['rate'] * (int)$l['qty'] * $n)) ?></dd>
      <?php endforeach; endif; ?>
      <?php foreach ($svcLines as $l): ?>
        <dt><?= h($l['service_name']) ?> × <?= (int)$l['quantity'] ?></dt>
        <dd class="num"><?= h(money($l['price_charged'])) ?></dd>
      <?php endforeach; ?>
      <dt>Total</dt><dd class="num strong"><?= h(money($res['total_amount'])) ?></dd>
    </dl>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<?php
  $unclaimedBal = round(max(0.0, $balance - $paySum['pending']), 2);
  if (!$paySum['needs_downpayment'] && in_array($res['status'], ['Pending', 'Confirmed', 'Checked-in'], true) && $unclaimedBal > 0.004 && !empty($config['online_payment_enabled'])):
?>
<section class="panel stack no-print" id="pay">
  <h2>Pay remaining balance (<?= h(money($unclaimedBal)) ?>)</h2>
  <?= payment_accounts_box() ?>
  <div class="actions"><a class="btn primary" href="payment.php?ref=<?= urlencode($ref) ?>">Upload screenshot &amp; reference no. →</a></div>
</section>
<?php endif; ?>

<?php if ($res['status'] === 'Checked-out'): $myReview = review_for($pdo, $id); $myMedia = $myReview ? review_media_for($pdo, (int)$myReview['review_id']) : []; ?>
<section class="panel no-print" id="review">
  <h2><?= $myReview ? 'Your review' : 'How was your stay?' ?></h2>
  <p class="muted"><?= $myReview ? 'Thank you. You can change your rating, comment, photos and videos any time.' : 'Rate your stay at ' . h($config['resort']) . ' and tell other guests what to expect.' ?> Your first name and the initial of your last name are shown.</p>
  <?= review_form($ref, $myReview, $myMedia, review_media_available($pdo)) ?>
</section>
<?php endif; ?>

<?php if ($pol['active']): ?>
<section class="panel no-print" id="cancel">
  <h2>Need to cancel?</h2>
  <?php if ($pol['can']): ?>
    <p class="muted"><?= h($pol['note']) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="ref" value="<?= h($ref) ?>">
      <input type="hidden" name="action" value="cancel">
      <button class="btn danger" type="submit" data-confirm="Are you sure you want to cancel this booking? This can’t be undone." data-confirm-title="Cancel booking" data-confirm-ok="Yes, cancel booking" data-confirm-cancel="Keep booking" data-confirm-tone="danger">Cancel booking</button>
    </form>
  <?php else: ?>
    <p class="muted"><?= h($pol['note']) ?></p>
    <p class="muted">Contact the resort at <?= h($config['contact_email']) ?> or <?= h($config['contact_phone']) ?> and quote reference <?= h($ref) ?>.</p>
    <button class="btn danger is-locked" type="button" aria-haspopup="dialog"
            data-notice="<?= h($pol['note']) ?>"
            data-notice-extra="<?= h('Contact the resort at ' . $config['contact_email'] . ' or ' . $config['contact_phone'] . ' and quote reference ' . $ref . '.') ?>"
            data-notice-title="Can’t cancel this booking online" data-notice-ok="Got it">Cancel booking</button>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php site_end();
