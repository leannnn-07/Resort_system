<?php
/**
 * A booked trip: stays grouped by resort so stays at the same resort share ONE payment.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/trips.php';
require_once __DIR__ . '/includes/payments.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$code = preg_match('/^[A-Z0-9]{6,10}$/', $code) ? $code : '';
$refs = $code === '' ? [] : array_values(array_filter(trip_refs($pdo, $code), fn($ref) => !empty($_SESSION['grants'][$ref])));
if (!$refs) {
    redirect('booking.php' . ($code !== '' ? '?ref=' . urlencode($code) : ''));
}

$stmt = $pdo->prepare(
    'SELECT v.*, rs.name AS resort_name, rs.slug AS resort_slug, rs.location AS resort_location,
            rs.checkin_time, rs.checkout_time, rs.online_payment_enabled, rs.downpayment_percent,
            (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
            (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name,
            (SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1) AS cottage_name
       FROM reservation v JOIN resort rs ON rs.resort_id = v.resort_id
      WHERE v.booking_ref IN (' . implode(',', array_fill(0, count($refs), '?')) . ') ORDER BY v.resort_id, v.reservation_id'
);
$stmt->execute($refs);
$stays = $stmt->fetchAll();

$lineStmt = $pdo->prepare(
    'SELECT t.type_name, COUNT(*) AS qty FROM reservation_room rr
       JOIN room r ON r.room_id = rr.room_id JOIN room_type t ON t.room_type_id = r.room_type_id
      WHERE rr.reservation_id = ? GROUP BY t.type_name'
);
$total  = 0.0;
$due    = 0.0;
$dpDue  = 0.0;
$groups = []; // resort_id => ['resort_id', 'name', 'slug', 'online_on', 'dp_due', 'pending', 'total', 'stays' => []]

foreach ($stays as &$s) {
    $id = (int)$s['reservation_id'];
    if ($s['booking_scope'] === 'Rooms') {
        $lineStmt->execute([$id]);
        $s['what'] = implode(', ', array_map(fn($l) => $l['type_name'] . ' × ' . (int)$l['qty'], $lineStmt->fetchAll()));
    } else {
        $s['what'] = scope_label($s);
    }
    $s['guests_text'] = plural((int)$s['num_guests'], 'guest');
    $s['pay'] = reservation_payment_summary($pdo, $s);

    $rid = (int)$s['resort_id'];
    if (!isset($groups[$rid])) {
        $groups[$rid] = [
            'resort_id' => $rid,
            'name'      => (string)$s['resort_name'],
            'slug'      => (string)$s['resort_slug'],
            'online_on' => (int)($s['online_payment_enabled'] ?? 1) === 1,
            'dp_due'    => 0.0,
            'pending'   => 0.0,
            'total'     => 0.0,
            'stays'     => [],
        ];
    }
    if ($s['status'] !== 'Cancelled') {
        $total += $s['pay']['total'];
        $due   += $s['pay']['balance'];
        $groups[$rid]['total']   = round($groups[$rid]['total'] + $s['pay']['total'], 2);
        $groups[$rid]['pending'] = round($groups[$rid]['pending'] + $s['pay']['pending'], 2);
        if ($s['pay']['needs_downpayment']) {
            $dpDue += $s['pay']['downpayment_remaining'];
            $groups[$rid]['dp_due'] = round($groups[$rid]['dp_due'] + $s['pay']['downpayment_remaining'], 2);
        }
    }
    $groups[$rid]['stays'][] = $s;
}
unset($s);

$justPaidTrip = ($_SESSION['just_paid_trip'] ?? '') === $code;
if ($justPaidTrip) {
    unset($_SESSION['just_paid_trip']);
}
$justBooked = ($_SESSION['just_booked_trip'] ?? '') === $code;
if ($justBooked) {
    unset($_SESSION['just_booked_trip']);
}

site_start('Trip ' . $code, 'trip', 'no-nav');
?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back no-print" href="<?= !empty($_SESSION['guest_id']) ? 'account.php#bookings' : 'resorts.php' ?>">&larr; <?= !empty($_SESSION['guest_id']) ? 'Back to my account' : 'Back to resorts' ?></a>
    <h1>Trip <span class="ref"><?= h($code) ?></span></h1>
  </div>
  <button class="btn no-print" type="button" onclick="window.print()">Print</button>
</div>
<?php if ($justPaidTrip): ?>
  <?= booking_steps($dpDue > 0.004 ? 2 : 3) ?>
  <section class="panel confirm-card">
    <h2>Payment submitted</h2>
    <p>Trip <strong class="ref"><?= h($code) ?></strong> · Staff will verify your screenshot and confirm by email.</p>
  </section>
<?php elseif ($justBooked): ?>
  <?= booking_steps($dpDue > 0.004 ? 2 : 3) ?>
  <section class="panel confirm-card">
    <h2><?= $dpDue > 0.004 ? 'Complete your payment' : 'Trip received' ?></h2>
    <p>Trip code: <strong class="ref"><?= h($code) ?></strong></p>
  </section>
<?php endif; ?>

<div class="checkout">
  <div class="trip-list">
    <?php foreach ($groups as $g): ?>
      <section class="panel trip-group">
        <header class="tg-head">
          <h2><a href="<?= h('landing.php?r=' . rawurlencode($g['slug'])) ?>"><?= h($g['name']) ?></a></h2>
          <span class="muted"><?= h(plural(count($g['stays']), 'stay')) ?> · <strong><?= h(money($g['total'])) ?></strong></span>
        </header>

        <?php if ($g['dp_due'] > 0.004 && $g['online_on']): ?>
          <div class="tg-pay-bar no-print">
            <div>
              <strong><?= count($g['stays']) > 1 ? 'One payment for all stays at ' . h($g['name']) : 'Downpayment due' ?></strong>
              <span class="muted"> · <?= h(money($g['dp_due'])) ?> via GCash or Maya</span>
            </div>
            <a class="btn primary" href="payment.php?trip=<?= urlencode($code) ?>&amp;resort=<?= (int)$g['resort_id'] ?>">Pay <?= h(money($g['dp_due'])) ?> once →</a>
          </div>
        <?php elseif ($g['pending'] > 0.004): ?>
          <div class="tg-pay-bar">
            <span><span class="badge pending">Pending staff verification</span> <span class="muted">(<?= h(money($g['pending'])) ?> submitted)</span></span>
          </div>
        <?php endif; ?>

        <?php foreach ($g['stays'] as $s): $sModalId = 'stay-modal-' . (int)$s['reservation_id']; ?>
          <article class="trip-booked-item">
            <div class="tbi-head">
              <div>
                <strong><?= h($s['what']) ?></strong>
                <div class="muted" style="font-size:13px">
                  Ref <strong class="ref"><?= h($s['booking_ref']) ?></strong> ·
                  <?= $s['booking_scope'] === 'Cottage'
                      ? h(fdate($s['check_in_date'])) . ' (Day use)'
                      : h(fdate($s['check_in_date'])) . ' to ' . h(fdate($s['check_out_date'])) ?> ·
                  <strong><?= h(money($s['total_amount'])) ?></strong>
                </div>
              </div>
              <?= reservation_status_badge($s, $s['pay']['paid']) ?>
            </div>
            <div class="actions no-print">
              <button type="button" class="btn sm" data-form-open="<?= h($sModalId) ?>">View details</button>
              <a class="btn sm" href="booking.php?ref=<?= urlencode($s['booking_ref']) ?>">Open booking</a>
            </div>
          </article>

          <div class="modal" id="<?= h($sModalId) ?>" data-form-modal role="dialog" aria-modal="true" aria-labelledby="<?= h($sModalId) ?>-t">
            <div class="modal-card form-card narrow-card">
              <div class="modal-head">
                <h2 id="<?= h($sModalId) ?>-t"><?= h($s['what']) ?></h2>
                <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
              </div>
              <dl class="facts">
                <dt>Reference</dt><dd><strong class="ref"><?= h($s['booking_ref']) ?></strong></dd>
                <dt>Resort</dt><dd><?= h($g['name']) ?></dd>
                <?php if ($s['booking_scope'] === 'Cottage'): ?>
                  <dt>Visit date</dt><dd><?= h(fdate($s['check_in_date'])) ?> <span class="muted">(Day use)</span></dd>
                <?php else: ?>
                  <dt>Check-in</dt><dd><?= h(fdate($s['check_in_date'])) ?> <span class="muted">from <?= h($s['checkin_time']) ?></span></dd>
                  <dt>Check-out</dt><dd><?= h(fdate($s['check_out_date'])) ?> <span class="muted">by <?= h($s['checkout_time']) ?></span></dd>
                <?php endif; ?>
                <dt>Guests</dt><dd><?= h($s['guests_text']) ?></dd>
                <dt>Total</dt><dd><strong><?= h(money($s['total_amount'])) ?></strong></dd>
                <dt>Paid</dt><dd><?= h(money($s['pay']['paid'])) ?></dd>
                <dt>Balance</dt><dd><strong><?= h(money($s['pay']['balance'])) ?></strong></dd>
              </dl>
              <div class="modal-actions" style="margin-top:16px">
                <a class="btn" href="booking.php?ref=<?= urlencode($s['booking_ref']) ?>">Manage booking</a>
                <button class="btn primary" type="button" data-modal-close>Close</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <aside class="panel summary-card after-list" aria-label="Trip total">
    <h2>Your trip</h2>
    <dl class="facts totals trip-sum">
      <dt>Stays</dt><dd class="num"><?= count($stays) ?></dd>
      <dt>Resorts</dt><dd class="num"><?= count($groups) ?></dd>
      <dt>Total</dt><dd class="num strong"><?= h(money($total)) ?></dd>
      <?php if ($dpDue > 0.004): ?>
        <dt>Downpayment due now</dt><dd class="num strong"><?= h(money($dpDue)) ?></dd>
      <?php endif; ?>
      <dt>Remaining balance</dt><dd class="num"><?= h(money($due)) ?></dd>
    </dl>
    <p class="muted"><?= $dpDue > 0.004 ? 'Stays at the same resort share one GCash/Maya payment.' : 'Each resort confirms its own stays.' ?></p>
  </aside>
</div>
<?php site_end();
