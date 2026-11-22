<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/notifications.php';
$staffMember = require_login($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

function load_reservation(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT v.*, CONCAT(g.last_name, ', ', g.first_name) AS guest, g.email, g.phone, g.address, g.id_type, g.id_number, s.full_name AS staff_name,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name,
                (SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1) AS cottage_name
           FROM reservation v
           JOIN guest g ON g.guest_id = v.guest_id
           LEFT JOIN staff s ON s.staff_id = v.staff_id
          WHERE v.reservation_id = ? AND v.resort_id = " . resort_id()
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$res = load_reservation($pdo, $id);
if (!$res) {
    http_response_code(404);
    admin_start('Not found', 'reservations');
    echo '<div class="page-head"><h1>Reservation not found</h1></div>'
       . '<p class="empty">That reservation doesn’t exist. <a href="reservations.php">Back to reservations</a>.</p>';
    admin_end();
    exit;
}

$self = 'reservation_view.php?id=' . $id;
staff_notification_mark_reservation_read($pdo, (int)$staffMember['staff_id'], $id);

/** How many people a whole-pool / building / resort booking can hold: the pool's limit, or what its rooms sleep. */
function whole_capacity(PDO $pdo, int $id, string $scope): int
{
    if ($scope === 'Pool') {
        $st = $pdo->prepare('SELECT COALESCE(SUM(p.max_guests), 0) FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = ?');
    } else {
        $st = $pdo->prepare("SELECT COALESCE(SUM(t.max_occupancy), 0) FROM reservation_room rr JOIN room r ON r.room_id = rr.room_id
                              JOIN room_type t ON t.room_type_id = r.room_type_id WHERE rr.reservation_id = ? AND r.status <> 'Maintenance'");
    }
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'status') {
            $to = (string)($_POST['to'] ?? '');
            if (!isset(RESERVATION_FLOW[$res['status']][$to])) {
                throw new RuntimeException('That status change isn’t allowed from ' . $res['status'] . '.');
            }
            $today = today();
            if ($to === 'Checked-in' && $res['check_in_date'] > $today) {
                throw new RuntimeException('Arrival is on ' . fdate($res['check_in_date']) . '. A guest can only be checked in from the day of arrival.');
            }
            if ($to === 'Confirmed' && $res['check_out_date'] <= $today) {
                throw new RuntimeException('This stay has already ended, so it can’t be confirmed. Cancel it instead.');
            }
            if ($to === 'Confirmed') {
                $curPaid = amount_paid($pdo, $id);
                $curBal  = round(max(0.0, (float)$res['total_amount'] - $curPaid), 2);
                if ($curBal > 0.004) {
                    throw new RuntimeException('Cannot fully approve yet — remaining balance of ' . money($curBal) . ' must be paid in full first.');
                }
            }
            // Only change it if nobody else changed it first (two people clicking at once must not undo a cancellation).
            $upd = $pdo->prepare('UPDATE reservation SET status = ? WHERE reservation_id = ? AND status = ?');
            $upd->execute([$to, $id, $res['status']]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('This reservation was just changed by someone else. Reload to see its current status.');
            }
            if ($to === 'Checked-out' && $res['check_out_date'] > $today) {
                // Left early: release the unused nights so the rooms can be booked again, and bill the nights stayed.
                $newOut = max($today, add_days($res['check_in_date'], 1));
                if ($newOut < $res['check_out_date']) {
                    $pdo->prepare('UPDATE reservation SET check_out_date = ? WHERE reservation_id = ?')->execute([$newOut, $id]);
                    $pdo->prepare('DELETE FROM reservation_service WHERE reservation_id = ? AND service_date > ?')->execute([$id, $newOut]);
                    recalc_total($pdo, $id);
                    flash('Left early: departure moved to ' . fdate($newOut) . ' and the unused nights are free to book again.');
                }
            }
            if ($to === 'Checked-out') {
                // Rooms go to housekeeping; mark them Available again on the Rooms page.
                $pdo->prepare("UPDATE room r JOIN reservation_room rr ON rr.room_id = r.room_id
                                  SET r.status = 'Cleaning'
                                WHERE rr.reservation_id = ? AND r.status = 'Available'")->execute([$id]);
            }
            flash('Reservation is now ' . strtolower($to) . '.');
            if (in_array($to, ['Confirmed', 'Cancelled'], true)) {
                send_booking_email($pdo, $id, strtolower($to));   // lets the guest know by email
            }

        } elseif ($action === 'change_dates') {
            $inHouse = $res['status'] === 'Checked-in';
            if (!$inHouse && !in_array($res['status'], ['Pending', 'Confirmed'], true)) {
                throw new RuntimeException('Dates can only be changed while a reservation is pending, confirmed or checked in.');
            }
            $newIn  = $inHouse ? $res['check_in_date'] : (string)($_POST['check_in'] ?? '');
            $newOut = (string)($_POST['check_out'] ?? '');
            if ($newIn === $res['check_in_date'] && $newOut === $res['check_out_date']) {
                throw new RuntimeException('Those are the current dates. Pick different ones.');
            }
            if ($inHouse) {
                // The guest is already here: arrival is fixed, only the departure moves, and never into the past.
                $errs = [];
                if (!valid_date($newOut) || $newOut <= $newIn) {
                    $errs[] = 'Check-out must be after the arrival date.';
                } elseif ($newOut <= today()) {
                    $errs[] = 'Check-out must be after today. To leave today, use Check out.';
                } else {
                    $nn = nights($newIn, $newOut);
                    if ($nn > (int)$config['max_nights']) {
                        $errs[] = 'The maximum stay is ' . plural((int)$config['max_nights'], 'night') . '.';
                    }
                }
            } else {
                $errs = validate_stay($newIn, $newOut, (int)$res['num_adults'], (int)$res['num_children'], $res['booking_scope'] !== 'Rooms' ? 5000 : null);
            }
            if ($errs) {
                throw new RuntimeException($errs[0]);
            }
            try {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                // Lock this reservation's rooms and pools (same order as a new booking), then make sure nobody else holds them on the new dates.
                $roomRows = $pdo->prepare('SELECT rr.room_id, rr.rate_applied, r.status, r.room_number FROM reservation_room rr JOIN room r ON r.room_id = rr.room_id WHERE rr.reservation_id = ? ORDER BY rr.room_id');
                $roomRows->execute([$id]);
                $roomRows = $roomRows->fetchAll();
                if ($roomRows) {
                    $pdo->query('SELECT room_id FROM room WHERE room_id IN (' . implode(',', array_map('intval', array_column($roomRows, 'room_id'))) . ') ORDER BY room_id FOR UPDATE')->fetchAll();
                }
                $poolIds = $pdo->prepare('SELECT pool_id FROM reservation_pool WHERE reservation_id = ? ORDER BY pool_id');
                $poolIds->execute([$id]);
                $poolIds = array_map('intval', $poolIds->fetchAll(PDO::FETCH_COLUMN));
                if ($poolIds) {
                    $pdo->query('SELECT pool_id FROM pool WHERE pool_id IN (' . implode(',', $poolIds) . ') ORDER BY pool_id FOR UPDATE')->fetchAll();
                }
                foreach ($roomRows as $rr) {
                    if (!$inHouse && $rr['status'] === 'Maintenance' && (float)$rr['rate_applied'] > 0) {
                        throw new RuntimeException('Room ' . $rr['room_number'] . ' is under maintenance now, so these dates can’t be moved. Cancel and rebook instead.');
                    }
                }
                $clash = $pdo->prepare(
                    "SELECT v.booking_ref FROM reservation v
                      WHERE v.reservation_id <> :id AND v.status <> 'Cancelled' AND v.check_in_date < :o AND v.check_out_date > :i
                        AND (EXISTS (SELECT 1 FROM reservation_room x JOIN reservation_room me ON me.room_id = x.room_id AND me.reservation_id = :id2 WHERE x.reservation_id = v.reservation_id)
                          OR EXISTS (SELECT 1 FROM reservation_pool x JOIN reservation_pool me ON me.pool_id = x.pool_id AND me.reservation_id = :id3 WHERE x.reservation_id = v.reservation_id))
                      LIMIT 1"
                );
                $clash->execute(['id' => $id, 'id2' => $id, 'id3' => $id, 'o' => $newOut, 'i' => $newIn]);
                if ($other = $clash->fetchColumn()) {
                    throw new RuntimeException('Not free on those dates: the same room or pool is booked by ' . $other . '. Choose other dates, or cancel and rebook with different rooms.');
                }
                // Services keep their place in the stay: shift by the change of arrival, then keep them inside the new stay.
                $delta = (int)round((strtotime($newIn) - strtotime($res['check_in_date'])) / 86400);
                $svc = $pdo->prepare('SELECT service_id, service_date, quantity, price_charged FROM reservation_service WHERE reservation_id = ?');
                $svc->execute([$id]);
                $merged = [];
                foreach ($svc->fetchAll() as $l) {
                    $dt = add_days($l['service_date'], $delta);
                    $dt = min(max($dt, $newIn), $newOut);
                    $k = $l['service_id'] . '|' . $dt;
                    $merged[$k] = [$l['service_id'], $dt, ($merged[$k][2] ?? 0) + (int)$l['quantity'], ($merged[$k][3] ?? 0) + (float)$l['price_charged']];
                }
                $pdo->prepare('DELETE FROM reservation_service WHERE reservation_id = ?')->execute([$id]);
                $insS = $pdo->prepare('INSERT INTO reservation_service (reservation_id, service_id, service_date, quantity, price_charged) VALUES (?, ?, ?, ?, ?)');
                foreach ($merged as [$sid, $dt, $qty, $chg]) {
                    $insS->execute([$id, $sid, $dt, $qty, $chg]);
                }
                $upd = $pdo->prepare("UPDATE reservation SET check_in_date = ?, check_out_date = ? WHERE reservation_id = ? AND status = ?");
                $upd->execute([$newIn, $newOut, $id, $res['status']]);
                if ($upd->rowCount() !== 1) {
                    throw new RuntimeException('This reservation was just changed by someone else. Reload and try again.');
                }
                recalc_total($pdo, $id);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            send_booking_email($pdo, $id, 'changed');
            flash(($inHouse ? 'Check-out moved to ' . fdate($newOut) : 'Dates changed to ' . fdate($newIn) . ' – ' . fdate($newOut)) . '. The total was recalculated and the guest was emailed.');
        } elseif ($action === 'edit_guests') {
            if ($closed_now = in_array($res['status'], ['Cancelled', 'Checked-out'], true)) {
                throw new RuntimeException('You can’t change the guests of a ' . strtolower($res['status']) . ' reservation.');
            }
            if ($res['booking_scope'] !== 'Rooms') {
                throw new RuntimeException('Guest names aren’t kept for whole-place bookings.');
            }
            $total = (int)$res['num_guests'];
            $bt    = clean_booker_type($_POST['booker_type'] ?? 'Adult');
            $list  = parse_companions($_POST, $total, 0, false);
            [$na, $nc] = party_counts($bt, $list, (int)$res['num_adults'], (int)$res['num_children']);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM reservation_companion WHERE reservation_id = ?')->execute([$id]);
                save_companions($pdo, $id, $list);
                $pdo->prepare('UPDATE reservation SET booker_type = ?, num_adults = ?, num_children = ? WHERE reservation_id = ?')->execute([$bt, $na, $nc, $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            flash('Guests saved.');
        } elseif ($action === 'headcount') {
            if (in_array($res['status'], ['Cancelled', 'Checked-out'], true)) {
                throw new RuntimeException('You can’t change the headcount of a ' . strtolower($res['status']) . ' reservation.');
            }
            if ($res['booking_scope'] === 'Rooms') {
                throw new RuntimeException('Use Edit guests for room bookings.');
            }
            $na = (string)($_POST['num_adults'] ?? '');
            $nc = (string)($_POST['num_children'] ?? '');
            if (!ctype_digit($na) || !ctype_digit($nc) || (int)$na < 1) {
                throw new RuntimeException('Enter at least 1 adult and 0 or more children.');
            }
            $cap = whole_capacity($pdo, $id, $res['booking_scope']);
            if ((int)$na + (int)$nc > $cap) {
                throw new RuntimeException('This booking holds up to ' . plural($cap, 'guest') . '. Lower the headcount.');
            }
            $pdo->prepare('UPDATE reservation SET num_adults = ?, num_children = ? WHERE reservation_id = ?')->execute([(int)$na, (int)$nc, $id]);
            flash('Headcount saved: ' . plural((int)$na + (int)$nc, 'guest') . '.');
        } elseif ($action === 'requests') {
            $text = trim((string)($_POST['special_requests'] ?? ''));
            if (mb_strlen($text) > 500) {
                throw new RuntimeException('Keep the requests under 500 characters.');
            }
            $pdo->prepare('UPDATE reservation SET special_requests = ? WHERE reservation_id = ?')->execute([$text === '' ? null : $text, $id]);
            flash('Requests saved.');
        } elseif ($action === 'add_service') {
            if (in_array($res['status'], ['Cancelled', 'Checked-out'], true)) {
                throw new RuntimeException('You can’t add services to a ' . strtolower($res['status']) . ' reservation.');
            }
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $qty  = (int)($_POST['quantity'] ?? 0);
            $date = (string)($_POST['service_date'] ?? '');
            if ($serviceId <= 0 || $qty <= 0) {
                throw new RuntimeException('Select a service and enter a quantity of at least 1.');
            }
            if (!valid_date($date) || $date < $res['check_in_date'] || $date > $res['check_out_date']) {
                throw new RuntimeException('Choose a service date within the stay.');
            }
            $price = $pdo->prepare('SELECT unit_price FROM service WHERE service_id = ? AND resort_id = ' . resort_id());
            $price->execute([$serviceId]);
            $unit = $price->fetchColumn();
            if ($unit === false) {
                throw new RuntimeException('That service no longer exists.');
            }
            $pdo->prepare(
                'INSERT INTO reservation_service (reservation_id, service_id, service_date, quantity, price_charged)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity),
                                         price_charged = price_charged + VALUES(price_charged)'
            )->execute([$id, $serviceId, $date, $qty, (float)$unit * $qty]);
            recalc_total($pdo, $id);
            flash('Service added.');

        } elseif ($action === 'remove_service') {
            if (in_array($res['status'], ['Cancelled', 'Checked-out'], true)) {
                throw new RuntimeException('You can’t change the charges of a ' . strtolower($res['status']) . ' reservation.');
            }
            $pdo->prepare('DELETE FROM reservation_service WHERE reservation_id = ? AND service_id = ? AND service_date = ?')
                ->execute([$id, (int)($_POST['service_id'] ?? 0), (string)($_POST['service_date'] ?? '')]);
            recalc_total($pdo, $id);
            flash('Service removed.');

        } elseif ($action === 'record_balance_payment') {
            $mode = (string)($_POST['pay_mode'] ?? 'cash');
            $note = trim((string)($_POST['note'] ?? ''));
            $legs = [];
            if ($mode === 'split_gcash_cash' || $mode === 'split_maya_cash') {
                $ewLabel = $mode === 'split_maya_cash' ? 'Maya' : 'GCash';
                $ewAmt   = round((float)($_POST['ewallet_amount'] ?? 0), 2);
                $cashAmt = round((float)($_POST['cash_amount'] ?? 0), 2);
                $ewRef   = trim((string)($_POST['ewallet_ref'] ?? ''));
                if ($ewAmt <= 0 || $cashAmt <= 0) {
                    throw new RuntimeException('Enter amounts greater than zero for both ' . $ewLabel . ' and Cash.');
                }
                $legs[] = ['method' => $ewLabel, 'amount' => $ewAmt, 'reference_no' => $ewRef];
                $legs[] = ['method' => 'Cash', 'amount' => $cashAmt, 'reference_no' => ''];
            } else {
                $mMap   = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya'];
                $method = $mMap[$mode] ?? '';
                $amt    = round((float)($_POST['amount'] ?? 0), 2);
                $refNo  = trim((string)($_POST['reference_no'] ?? ''));
                $legs[] = ['method' => $method, 'amount' => $amt, 'reference_no' => $refNo];
            }
            $out = record_staff_payment($pdo, $id, (int)$staffMember['staff_id'], $legs, $note);
            flash(
                'Recorded ' . money($out['total']) . ' (' . implode(' + ', $out['methods']) . ').'
                . ($out['auto_confirmed'] ? ' Paid in full — reservation is now confirmed.' : ' Downpayment paid only — full payment is required for approval.')
            );

        } elseif ($action === 'verify_payment') {
            $pid  = (int)($_POST['payment_id'] ?? 0);
            $note = trim((string)($_POST['verification_note'] ?? ''));
            $out  = verify_guest_payment($pdo, $pid, $id, (int)$staffMember['staff_id'], $note);
            flash(
                $out['method'] . ' payment of ' . money($out['amount']) . ' verified.'
                . ($out['auto_confirmed'] ? ' Paid in full — reservation is now confirmed and the guest was emailed.' : ' Downpayment paid only — full payment is required before approval.')
            );

        } elseif ($action === 'reject_payment') {
            $pid    = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            reject_guest_payment($pdo, $pid, $id, (int)$staffMember['staff_id'], $reason);
            flash('Payment rejected and voided. The guest can submit a corrected reference number on their booking page.');

        } elseif ($action === 'void_payment') {
            // Payments are never deleted: a voided one stays on the record but no longer counts.
            $pdo->prepare("UPDATE payment SET payment_status = 'Voided' WHERE payment_id = ? AND reservation_id = ? AND payment_status <> 'Voided'")
                ->execute([(int)($_POST['payment_id'] ?? 0), $id]);
            flash('Payment voided. It stays on the record but no longer counts.');
        }
    } catch (PDOException $e) {
        flash('The database rejected that change. Check the values and try again.', 'error');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($self);
}

// ---------- Load details ----------
$stmt = $pdo->prepare(
    'SELECT r.room_number, r.floor, t.type_name, rr.rate_applied
       FROM reservation_room rr
       JOIN room r ON r.room_id = rr.room_id
       JOIN room_type t ON t.room_type_id = r.room_type_id
      WHERE rr.reservation_id = ? ORDER BY r.room_number'
);
$stmt->execute([$id]);
$rooms = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT rs.*, s.service_name FROM reservation_service rs
       JOIN service s ON s.service_id = rs.service_id
      WHERE rs.reservation_id = ? ORDER BY rs.service_date, s.service_name'
);
$stmt->execute([$id]);
$lines = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT p.*,
            COALESCE(p.approved_by_name, sv.full_name, sr.full_name) AS approver_name,
            COALESCE(p.approved_by_email, sv.email, sr.email) AS approver_email
       FROM payment p
       LEFT JOIN staff sr ON sr.staff_id = p.recorded_by_staff_id
       LEFT JOIN staff sv ON sv.staff_id = p.verified_by_staff_id
      WHERE p.reservation_id = ? ORDER BY p.payment_date, p.payment_id'
);
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT p.name, rp.rate_applied FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = ? ORDER BY p.name');
$stmt->execute([$id]);
$pools = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM reservation_cottage WHERE reservation_id = ?');
$stmt->execute([$id]);
$cottages = $stmt->fetchAll();

$allServices = $pdo->query("SELECT service_id, CONCAT(service_name, ' (', unit_price, ')') FROM service WHERE resort_id = " . resort_id() . " AND archived_at IS NULL ORDER BY category, service_name")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);

$companions = load_companions($pdo, $id);
$n       = nights($res['check_in_date'], $res['check_out_date']);
$cotSum  = array_sum(array_map(fn($c) => (float)$c['cottage_rate'] * (int)$c['quantity'] + (float)$c['entrance_total'], $cottages));
$roomSum = (array_sum(array_column($rooms, 'rate_applied')) + array_sum(array_column($pools, 'rate_applied'))) * $n + $cotSum;
$svcSum  = array_sum(array_column($lines, 'price_charged'));
$paySum  = reservation_payment_summary($pdo, $res);
$paid    = $paySum['paid'];
$balance = $paySum['balance'];
$closed  = in_array($res['status'], ['Cancelled', 'Checked-out'], true);

admin_start('Reservation ' . $res['booking_ref'], 'reservations');
?>
<header class="page-head has-back">
  <div class="ph-text ph-left">
    <a class="btn sm page-back" href="reservations.php">&larr; Back to reservations</a>
    <h1>Reservation <?= h($res['booking_ref']) ?> <?= reservation_status_badge($res, $paid) ?></h1>
    <p class="page-sub"><?= h($res['guest']) ?> · <?= h(scope_label($res)) ?> · <?= $res['booking_scope'] === 'Cottage' ? h(fdate($res['check_in_date'])) . ' (Day use)' : h(fdate($res['check_in_date'])) . ' to ' . h(fdate($res['check_out_date'])) ?></p>
  </div>
  <div class="actions">
    <?php foreach (RESERVATION_FLOW[$res['status']] as $to => $label):
        $blockedUnpaid = $to === 'Confirmed' && $balance > 0.004;
    ?>
      <?php if ($blockedUnpaid): ?>
        <button class="btn primary" type="button" disabled title="Full payment required before approving (<?= h(money($balance)) ?> remaining)"><?= h($label) ?> (needs full payment)</button>
      <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="to" value="<?= h($to) ?>">
        <?php $ask = ['Confirmed' => 'Confirm this reservation?', 'Checked-in' => 'Check this guest in?', 'Checked-out' => 'Check this guest out?',
                      'Cancelled' => 'Are you sure you want to cancel this reservation? Its rooms become available again.'][$to] ?? ('Change this reservation to ' . $to . '?'); ?>
        <button class="btn<?= $to === 'Cancelled' ? ' danger' : ' primary' ?>" type="submit" data-confirm="<?= h($ask) ?>" data-confirm-title="<?= h($label) ?>" data-confirm-ok="Yes, <?= h(strtolower($label)) ?>"<?= $to === 'Cancelled' ? ' data-confirm-tone="danger" data-confirm-cancel="Keep it"' : '' ?>><?= h($label) ?></button>
      </form>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</header>

<div class="split">
  <section class="panel">
    <h2>Details</h2>
    <dl class="facts">
      <dt>Guest</dt><dd><?= h($res['guest']) ?><br><span class="muted"><?= h($res['email']) ?><?= $res['phone'] ? ', ' . h($res['phone']) : '' ?><?= $res['address'] ? '<br>' . h($res['address']) : '' ?><?php $idShown = id_display($res['id_number'], $staffMember); ?><?= ($res['id_type'] || $idShown) ? '<br>' . h(trim(($res['id_type'] ?: 'ID') . ($idShown ? ': ' . $idShown : ''))) : '' ?></span></dd>
      <dt>Booked</dt><dd><strong><?= h(scope_label($res)) ?></strong></dd>
      <?php if ($res['booking_scope'] === 'Cottage'): ?>
        <dt>Visit date</dt><dd><?= h(fdate($res['check_in_date'])) ?> <span class="muted">(Day use)</span></dd>
      <?php else: ?>
        <dt>Dates</dt><dd><?= h(fdate($res['check_in_date'])) ?> to <?= h(fdate($res['check_out_date'])) ?> <span class="muted">(<?= h(plural($n, 'night')) ?>)</span></dd>
      <?php endif; ?>
      <dt>Guests</dt><dd><?= h(plural((int)$res['num_guests'], 'guest')) ?></dd>
      <dt>Source</dt><dd><?= h(fdate($res['booking_date'])) ?> · <?= $res['source'] === 'Online' ? 'Online' : h($res['staff_name'] ?: 'Staff') ?></dd>
      <?php if (!empty($res['special_requests'])): ?><dt>Requests</dt><dd><?= nl2br(h((string)$res['special_requests'])) ?></dd><?php endif; ?>
    </dl>

    <?php
      $stayBtnLabel = ($res['booking_scope'] === 'Cottage' && $cottages)
          ? 'View cottage'
          : ('View rooms (' . count($rooms) . ')');
    ?>
    <div class="detail-triggers no-print">
      <button type="button" class="btn sm" data-form-open="guests-modal">View guests (<?= (int)$res['num_guests'] ?>)</button>
      <button type="button" class="btn sm" data-form-open="stay-items-modal"><?= h($stayBtnLabel) ?></button>
      <button type="button" class="btn sm" data-form-open="addons-modal">View add-ons (<?= count($lines) ?>)</button>
      <?php if (in_array($res['status'], ['Pending', 'Confirmed', 'Checked-in'], true)): ?>
        <button type="button" class="btn sm" data-form-open="dates-modal">Change dates</button>
      <?php endif; ?>
      <button type="button" class="btn sm" data-form-open="notes-modal"><?= !empty($res['special_requests']) ? 'Edit notes' : 'Add notes' ?></button>
    </div>
  </section>

  <section class="panel">
    <h2>Payment summary</h2>
    <dl class="facts">
      <dt><?= $res['booking_scope'] === 'Cottage' ? 'Cottage & entrance' : ($res['booking_scope'] === 'Rooms' ? 'Rooms' : 'Accommodation') ?></dt><dd class="num"><?= h(money($roomSum)) ?></dd>
      <dt>Add-ons</dt><dd class="num"><?= h(money($svcSum)) ?></dd>
      <dt>Total</dt><dd class="num strong"><?= h(money($res['total_amount'])) ?></dd>
      <?php if ($paySum['required_downpayment'] > 0.004): ?>
        <dt>Required downpayment</dt>
        <dd class="num"><?= h(money($paySum['required_downpayment'])) ?> <?= $paySum['downpayment_met'] ? '<span class="badge confirmed">Met</span>' : ($paySum['awaiting_verification'] ? '<span class="badge pending">Verify ref</span>' : '<span class="badge pending">Unpaid</span>') ?></dd>
      <?php endif; ?>
      <dt>Paid (verified)</dt><dd class="num"><?= h(money($paid)) ?></dd>
      <?php if ($paySum['pending'] > 0.004): ?>
        <dt>Pending verification</dt><dd class="num"><?= h(money($paySum['pending'])) ?></dd>
      <?php endif; ?>
      <dt>Balance</dt><dd class="num strong<?= $balance > 0.004 && $res['status'] !== 'Cancelled' ? ' due' : '' ?>"><?= h(money($balance)) ?></dd>
    </dl>
    <?php if ($paySum['awaiting_verification']): ?>
      <p class="notice warn" style="margin-top:12px">
        Pending verification: <strong><?= h(money($paySum['pending'])) ?></strong> · <a href="#payments">Verify screenshot &amp; ref</a>
      </p>
    <?php elseif ($paySum['downpayment_only']): ?>
      <p class="notice warn" style="margin-top:12px">
        Downpayment paid only — collect <strong><?= h(money($balance)) ?></strong> for full approval.
      </p>
    <?php elseif ($paySum['needs_downpayment']): ?>
      <p class="notice warn" style="margin-top:12px">
        Unpaid downpayment: <strong><?= h(money($paySum['downpayment_remaining'])) ?></strong>
      </p>
    <?php endif; ?>
  </section>
</div>

<!-- Pop-up modal: Guests -->
<div class="modal" id="guests-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="guests-modal-title">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="guests-modal-title">Guests (<?= (int)$res['num_guests'] ?>)</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="table-scroll">
      <table>
        <thead><tr><th>#</th><th>Name</th><th>Type</th><th>Contact</th></tr></thead>
        <tbody>
          <tr>
            <td>1</td>
            <td><?= h($res['guest']) ?> <span class="muted">(booked by)</span></td>
            <td><?= h($res['booker_type'] ?? 'Adult') ?></td>
            <td><?= h($res['email']) ?><?= $res['phone'] ? '<br><span class="muted">' . h($res['phone']) . '</span>' : '' ?></td>
          </tr>
        <?php foreach ($companions as $i => $c): ?>
          <tr>
            <td><?= $i + 2 ?></td>
            <td><?= h($c['first_name'] . ' ' . $c['last_name']) ?></td>
            <td><?= h($c['guest_type']) ?></td>
            <td class="muted">&ndash;</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($res['booking_scope'] === 'Rooms' && count($companions) + 1 < (int)$res['num_guests']): ?>
      <p class="muted" style="margin:10px 0 0">Names for <?= (int)$res['num_guests'] - count($companions) - 1 ?> guest(s) were not entered.</p>
    <?php endif; ?>
    <?php if ($res['booking_scope'] !== 'Rooms' && !$closed): $cap = whole_capacity($pdo, $id, $res['booking_scope']); ?>
    <details style="margin-top:14px"><summary class="muted" style="cursor:pointer;font-weight:600">Edit guest count</summary>
      <form method="post" class="grid-form" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="headcount">
        <label><span>Guests</span><input type="number" name="num_adults" value="<?= (int)$res['num_adults'] + (int)$res['num_children'] ?>" min="1" max="<?= $cap ?>" step="1" required></label>
        <input type="hidden" name="num_children" value="0">
        <div class="actions wide"><button class="btn sm primary" type="submit">Save headcount</button> <span class="muted">Up to <?= h(plural($cap, 'guest')) ?>.</span></div>
      </form>
    </details>
    <?php endif; ?>
    <?php if ($res['booking_scope'] === 'Rooms' && !$closed): ?>
    <details style="margin-top:14px"><summary class="muted" style="cursor:pointer;font-weight:600">Edit guest names</summary>
      <form method="post" class="stack" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="edit_guests">
        <?= booker_type_field((string)($res['booker_type'] ?? 'Adult')) ?>
        <?php
          $pv = [];
          foreach ($companions as $c) { $pv[] = ['type' => $c['guest_type'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name']]; }
          echo companion_fields((int)$res['num_guests'], 0, $pv, false);
        ?>
        <div class="actions"><button class="btn sm primary" type="submit">Save guests</button></div>
      </form>
    </details>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<!-- Pop-up modal: Cottage / Rooms -->
<div class="modal" id="stay-items-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="stay-items-title">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="stay-items-title"><?= ($res['booking_scope'] === 'Cottage' && $cottages) ? 'Cottage details' : 'Rooms & rates' ?></h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <?php if ($res['booking_scope'] === 'Cottage' && $cottages): ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Cottage</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Entrance</th><th class="right">Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($cottages as $c): ?>
          <tr>
            <td><?= h($c['cottage_name']) ?></td>
            <td class="num"><?= (int)$c['quantity'] ?></td>
            <td class="num"><?= h(money($c['cottage_rate'])) ?></td>
            <td class="num"><?= h(money($c['entrance_total'])) ?></td>
            <td class="num"><?= h(money((float)$c['cottage_rate'] * (int)$c['quantity'] + (float)$c['entrance_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php elseif ($rooms): ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Room</th><th>Type</th><th>Floor</th><th class="right">Per night</th><th class="right">× <?= $n ?> night<?= $n === 1 ? '' : 's' ?></th></tr></thead>
        <tbody>
        <?php foreach ($rooms as $r): ?>
          <tr>
            <td><?= h($r['room_number']) ?></td><td><?= h($r['type_name']) ?></td><td><?= (int)$r['floor'] ?></td>
            <td class="num"><?= h(money($r['rate_applied'])) ?></td>
            <td class="num"><?= h(money((float)$r['rate_applied'] * $n)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php elseif ($pools): ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Pool</th><th class="right">Per day</th><th class="right">× <?= $n ?> day<?= $n === 1 ? '' : 's' ?></th></tr></thead>
        <tbody>
        <?php foreach ($pools as $pl): ?>
          <tr>
            <td><?= h($pl['name']) ?></td>
            <td class="num"><?= h(money($pl['rate_applied'])) ?></td>
            <td class="num"><?= h(money((float)$pl['rate_applied'] * $n)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="muted">No rooms or cottages linked.</p>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<!-- Pop-up modal: Add-ons -->
<div class="modal" id="addons-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="addons-modal-title">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="addons-modal-title">Add-ons (<?= count($lines) ?>)</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <?php if (!$lines): ?>
      <p class="muted">No add-ons yet.</p>
    <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Date</th><th>Add-on</th><th class="num">Qty</th><th class="num">Charged</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr>
            <td class="nowrap"><?= h(fdate($l['service_date'])) ?></td>
            <td><?= h($l['service_name']) ?></td>
            <td class="num"><?= (int)$l['quantity'] ?></td>
            <td class="num"><?= h(money($l['price_charged'])) ?></td>
            <td class="row-actions">
              <?php if (!$closed): ?>
              <div class="act-group">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="action" value="remove_service">
                  <input type="hidden" name="service_id" value="<?= (int)$l['service_id'] ?>">
                  <input type="hidden" name="service_date" value="<?= h($l['service_date']) ?>">
                  <button class="btn sm danger" type="submit" data-confirm="Remove this add-on from the booking?" data-confirm-title="Remove add-on" data-confirm-ok="Yes, remove" data-confirm-tone="danger">Remove</button>
                </form>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if (!$closed): ?>
    <form method="post" class="grid-form" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="add_service">
      <label class="wide"><span>Add an add-on</span>
        <select name="service_id" required>
          <option value="">Select an add-on</option>
          <?php foreach ($allServices as $sid => $slabel): ?><option value="<?= (int)$sid ?>"><?= h($slabel) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span>Date</span><input type="date" name="service_date" value="<?= h($res['check_in_date']) ?>" min="<?= h($res['check_in_date']) ?>" max="<?= h($res['check_out_date']) ?>" required></label>
      <label><span>Quantity</span><input type="number" name="quantity" value="1" min="1" step="1" required></label>
      <div class="modal-actions wide"><button class="btn" type="button" data-modal-close>Close</button><button class="btn primary" type="submit">Add add-on</button></div>
    </form>
    <?php else: ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
    <?php endif; ?>
  </div>
</div>

<!-- Pop-up modal: Change dates -->
<?php if (in_array($res['status'], ['Pending', 'Confirmed', 'Checked-in'], true)): $inHouseUi = $res['status'] === 'Checked-in'; ?>
<div class="modal" id="dates-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="dates-modal-title">
  <div class="modal-card form-card narrow-card">
    <div class="modal-head">
      <h2 id="dates-modal-title"><?= $inHouseUi ? 'Extend or shorten stay' : 'Change dates' ?></h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="change_dates">
      <?php if (!$inHouseUi): ?><label><span>Check-in</span><input type="date" name="check_in" value="<?= h($res['check_in_date']) ?>" min="<?= h(today()) ?>" required></label><?php endif; ?>
      <label><span>Check-out</span><input type="date" name="check_out" value="<?= h($res['check_out_date']) ?>" min="<?= h($inHouseUi ? add_days(today(), 1) : today()) ?>" required></label>
      <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit"><?= $inHouseUi ? 'Move check-out' : 'Save dates' ?></button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Pop-up modal: Requests & notes -->
<div class="modal" id="notes-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="notes-modal-title">
  <div class="modal-card form-card narrow-card">
    <div class="modal-head">
      <h2 id="notes-modal-title">Guest requests &amp; notes</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="requests">
      <label><span>Notes</span><textarea name="special_requests" rows="4" maxlength="500" placeholder="Late arrival, celebration, dietary needs…"><?= h((string)$res['special_requests']) ?></textarea></label>
      <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save notes</button></div>
    </form>
  </div>
</div>

<script>
(function () {
  var map = { '#guests': 'guests-modal', '#rooms': 'stay-items-modal', '#addons': 'addons-modal' };
  var id = map[location.hash];
  if (id) {
    window.addEventListener('DOMContentLoaded', function () {
      var btn = document.querySelector('[data-form-open="' + id + '"]');
      if (btn) btn.click();
    });
  }
})();
</script>

<section class="panel flush" id="payments">
  <h2 class="panel-title">Payments</h2>
  <?php if (!$payments): ?>
    <p class="empty">No payments recorded yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Date</th><th>Method</th><th>Reference &amp; screenshot</th><th>Status</th><th class="num">Amount</th><th class="right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): $isPend = $p['payment_status'] === 'Pending'; $proofUrl = payment_proof_url($p['proof_image'] ?? null, '../'); ?>
        <tr>
          <td class="nowrap"><?= h(fdate($p['payment_date'])) ?></td>
          <td class="nowrap"><?= h($p['method']) ?></td>
          <td>
            <?php if (!empty($p['reference_no'])): ?>
              Ref: <code class="ref-code"><?= h($p['reference_no']) ?></code>
              <?php if (!empty($p['sender_name'])): ?><br><small class="muted">Sender: <?= h($p['sender_name']) ?></small><?php endif; ?>
            <?php else: ?>
              <span class="muted">–</span>
            <?php endif; ?>
            <?php if ($proofUrl !== ''): ?>
              <br><a class="proof-link" href="<?= h($proofUrl) ?>" data-proof-title="<?= h($p['method'] . (!empty($p['reference_no']) ? ' · Ref ' . $p['reference_no'] : '') . ' · ' . money($p['amount'])) ?>"><img class="proof-thumb" src="<?= h($proofUrl) ?>" alt="Payment screenshot"><span>View screenshot</span></a>
            <?php endif; ?>
            <?php
              $appName  = trim((string)($p['approver_name'] ?? ''));
              $appEmail = trim((string)($p['approver_email'] ?? ''));
              $appWhen  = !empty($p['verified_at']) ? date('M j, Y · g:i A', strtotime($p['verified_at'])) : '';
              $appMeta  = $appName . ($appEmail !== '' ? ' (' . $appEmail . ')' : '') . ($appWhen !== '' ? ' · ' . $appWhen : '');
            ?>
            <?php if ($p['payment_status'] === 'Paid' && $appName !== ''): ?>
              <br><small class="muted"><?= ($p['source'] ?? '') === 'Staff' ? 'Recorded by ' : 'Approved by ' ?><?= h($appMeta) ?><?= !empty($p['verification_note']) ? ' · ' . h($p['verification_note']) : '' ?></small>
            <?php elseif ($p['payment_status'] === 'Voided' && !empty($p['verification_note'])): ?>
              <br><small class="muted">Rejected: <?= h($p['verification_note']) ?><?= $appMeta !== '' ? ' · ' . h($appMeta) : '' ?></small>
            <?php elseif ($appName !== ''): ?>
              <br><small class="muted">Recorded by <?= h($appMeta) ?></small>
            <?php endif; ?>
          </td>
          <td class="nowrap"><?= badge($p['payment_status']) ?></td>
          <td class="num<?= $p['payment_status'] === 'Voided' ? ' voided-amt' : '' ?>"><?= $p['payment_status'] === 'Refunded' ? '−' : '' ?><?= h(money($p['amount'])) ?></td>
          <td class="row-actions">
            <div class="act-group">
              <?php if ($isPend && $res['status'] !== 'Cancelled'): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="action" value="verify_payment">
                  <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
                  <button class="btn sm primary" type="submit"
                          data-confirm="Confirm you received <?= h(money($p['amount'])) ?> via <?= h($p['method']) ?><?= !empty($p['reference_no']) ? ' (Ref: ' . h($p['reference_no']) . ')' : '' ?>? Approving marks it Paid. Full payment is required to confirm the booking."
                          data-confirm-title="Verify payment" data-confirm-ok="Yes, verify &amp; approve">Verify &amp; approve</button>
                </form>
                <button type="button" class="btn sm danger" data-form-open="reject-modal-<?= (int)$p['payment_id'] ?>">Reject</button>
              <?php elseif ($p['payment_status'] !== 'Voided'): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="void_payment">
                <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
                <button class="btn sm danger" type="submit" data-confirm="Void this payment? It stays on the record but no longer counts." data-confirm-title="Void payment" data-confirm-ok="Yes, void it" data-confirm-tone="danger">Void</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php foreach ($payments as $p): if ($p['payment_status'] !== 'Pending') continue; ?>
  <div class="modal" id="reject-modal-<?= (int)$p['payment_id'] ?>" data-form-modal role="dialog" aria-modal="true" aria-labelledby="rej-title-<?= (int)$p['payment_id'] ?>">
    <div class="modal-card form-card narrow-card">
      <div class="modal-head"><h2 id="rej-title-<?= (int)$p['payment_id'] ?>">Reject payment</h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="reject_payment">
        <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
        <p class="muted" style="margin:0">Reject <?= h($p['method']) ?> · <strong><?= h(money($p['amount'])) ?></strong><?= !empty($p['reference_no']) ? ' (Ref: ' . h($p['reference_no']) . ')' : '' ?></p>
        <label><span>Reason</span><input name="reason" maxlength="255" placeholder="e.g. Unclear screenshot / ref not found" required></label>
        <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn danger" type="submit">Reject payment</button></div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($res['status'] !== 'Cancelled' && $balance > 0.004): ?>
  <div class="row-actions no-print" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--line)">
    <span class="muted">Remaining balance: <strong style="color:var(--ink)"><?= h(money($balance)) ?></strong></span>
    <button type="button" class="btn sm primary" data-form-open="collect-pay-modal">Collect balance (GCash / Cash)</button>
  </div>
  <div class="modal" id="collect-pay-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="cp-title">
    <div class="modal-card form-card narrow-card">
      <div class="modal-head"><h2 id="cp-title">Collect balance · <?= h(money($balance)) ?></h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
      <form method="post" class="stack" id="collect-pay-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="record_balance_payment">
        <label><span>Payment mode</span>
          <select name="pay_mode" id="cp-mode">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="maya">Maya</option>
            <option value="split_gcash_cash">Split: GCash + Cash</option>
            <option value="split_maya_cash">Split: Maya + Cash</option>
          </select>
        </label>

        <div id="cp-single" class="stack">
          <label><span>Amount (<?= h($config['currency']) ?>)</span>
            <input type="number" name="amount" id="cp-amount" value="<?= h(number_format($balance, 2, '.', '')) ?>" min="0.01" max="<?= h(number_format($balance, 2, '.', '')) ?>" step="0.01" required>
          </label>
          <label id="cp-ref-wrap" hidden><span>Reference number</span>
            <input name="reference_no" id="cp-ref" maxlength="80" placeholder="e.g. 1029 3847 56123" disabled>
          </label>
        </div>

        <div id="cp-split" class="stack" hidden>
          <label><span id="cp-ew-label">GCash amount (<?= h($config['currency']) ?>)</span>
            <input type="number" name="ewallet_amount" id="cp-ew-amt" value="<?= h(number_format(round($balance / 2, 2), 2, '.', '')) ?>" min="0.01" max="<?= h(number_format(max(0.01, $balance - 0.01), 2, '.', '')) ?>" step="0.01" disabled>
          </label>
          <label><span id="cp-ew-ref-label">GCash reference number</span>
            <input name="ewallet_ref" id="cp-ew-ref" maxlength="80" placeholder="e.g. 1029 3847 56123" disabled>
          </label>
          <label><span>Cash amount (<?= h($config['currency']) ?>)</span>
            <input type="number" name="cash_amount" id="cp-cash-amt" value="<?= h(number_format(round($balance - round($balance / 2, 2), 2), 2, '.', '')) ?>" min="0.01" max="<?= h(number_format(max(0.01, $balance - 0.01), 2, '.', '')) ?>" step="0.01" disabled>
          </label>
        </div>

        <label><span>Note</span>
          <input name="note" maxlength="255" placeholder="Optional: e.g. Paid upon arrival">
        </label>
        <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save payment</button></div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    var bal = <?= json_encode(round((float)$balance, 2)) ?>;
    var mode = document.getElementById('cp-mode');
    var singleBox = document.getElementById('cp-single');
    var splitBox = document.getElementById('cp-split');
    var singleAmt = document.getElementById('cp-amount');
    var refWrap = document.getElementById('cp-ref-wrap');
    var refIn = document.getElementById('cp-ref');
    var ewAmt = document.getElementById('cp-ew-amt');
    var ewRef = document.getElementById('cp-ew-ref');
    var cashAmt = document.getElementById('cp-cash-amt');
    var ewLbl = document.getElementById('cp-ew-label');
    var ewRefLbl = document.getElementById('cp-ew-ref-label');
    if (!mode) return;
    function syncMode() {
      var v = mode.value;
      var isSplit = v === 'split_gcash_cash' || v === 'split_maya_cash';
      var needsRef = v === 'gcash' || v === 'maya';
      singleBox.hidden = isSplit;
      splitBox.hidden = !isSplit;
      singleAmt.disabled = isSplit;
      singleAmt.required = !isSplit;
      refWrap.hidden = !needsRef;
      refIn.disabled = !needsRef;
      refIn.required = needsRef;
      ewAmt.disabled = !isSplit;
      ewAmt.required = isSplit;
      ewRef.disabled = !isSplit;
      ewRef.required = isSplit;
      cashAmt.disabled = !isSplit;
      cashAmt.required = isSplit;
      if (isSplit) {
        var nm = v === 'split_maya_cash' ? 'Maya' : 'GCash';
        ewLbl.textContent = nm + ' amount (<?= h($config['currency']) ?>)';
        ewRefLbl.textContent = nm + ' reference number';
      }
    }
    ewAmt.addEventListener('input', function () {
      var v = parseFloat(ewAmt.value) || 0;
      var rem = Math.max(0.01, Math.round((bal - v) * 100) / 100);
      if (v > 0 && v < bal) cashAmt.value = rem.toFixed(2);
    });
    mode.addEventListener('change', syncMode);
    syncMode();
  })();
  </script>
  <?php endif; ?>
</section>
<?php admin_end();
