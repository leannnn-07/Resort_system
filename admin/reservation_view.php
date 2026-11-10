<?php
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/booking.php';
$staffMember = require_login($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

function load_reservation(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT v.*, CONCAT(g.first_name, ' ', g.last_name) AS guest, g.email, g.phone, g.address, g.id_number, s.full_name AS staff_name,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name
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

        } elseif ($action === 'add_payment') {
            $amount = (string)($_POST['amount'] ?? '');
            $method = trim((string)($_POST['method'] ?? ''));
            $pstat  = (string)($_POST['payment_status'] ?? 'Paid');
            $pdate  = (string)($_POST['payment_date'] ?? '');
            if (!is_numeric($amount) || (float)$amount <= 0) {
                throw new RuntimeException('Enter a payment amount greater than zero.');
            }
            if ($method === '' || !in_array($pstat, ['Paid', 'Pending', 'Refunded'], true) || !valid_date($pdate)) {
                throw new RuntimeException('Choose a payment method, status and date.');
            }
            if ($pstat === 'Paid' && $res['status'] === 'Cancelled') {
                throw new RuntimeException('This reservation is cancelled. You can only record a refund on it.');
            }
            if ($pstat === 'Paid' && $pdate > today()) {
                throw new RuntimeException('A payment that has been received can’t be dated in the future.');
            }
            $amt     = round((float)$amount, 2);
            $paidNow = amount_paid($pdo, $id);
            $owing   = round((float)$res['total_amount'] - $paidNow, 2);
            if ($pstat === 'Paid' && $amt > max(0.0, $owing) + 0.004) {
                throw new RuntimeException('That is more than the balance due (' . money(max(0.0, $owing)) . '). Enter the amount actually owed.');
            }
            if ($pstat === 'Refunded' && $amt > $paidNow + 0.004) {
                throw new RuntimeException('You can’t refund more than was paid (' . money($paidNow) . ').');
            }
            $pdo->prepare('INSERT INTO payment (reservation_id, payment_date, amount, method, payment_status) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $pdate, $amt, $method, $pstat]);
            flash('Payment recorded.');

        } elseif ($action === 'void_payment') {
            // Payments are never deleted: a voided one stays on the record but no longer counts.
            $pdo->prepare("UPDATE payment SET payment_status = 'Voided' WHERE payment_id = ? AND reservation_id = ? AND payment_status <> 'Voided'")
                ->execute([(int)($_POST['payment_id'] ?? 0), $id]);
            flash('Payment voided. It stays on the record but no longer counts.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        flash('The database rejected that change. Check the values and try again.', 'error');
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

$stmt = $pdo->prepare('SELECT * FROM payment WHERE reservation_id = ? ORDER BY payment_date, payment_id');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT p.name, rp.rate_applied FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = ? ORDER BY p.name');
$stmt->execute([$id]);
$pools = $stmt->fetchAll();

$allServices = $pdo->query("SELECT service_id, CONCAT(service_name, ' (', unit_price, ')') FROM service WHERE resort_id = " . resort_id() . " ORDER BY category, service_name")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);

$companions = load_companions($pdo, $id);
$n       = nights($res['check_in_date'], $res['check_out_date']);
$roomSum = (array_sum(array_column($rooms, 'rate_applied')) + array_sum(array_column($pools, 'rate_applied'))) * $n;
$svcSum  = array_sum(array_column($lines, 'price_charged'));
$paid    = amount_paid($pdo, $id);
$balance = (float)$res['total_amount'] - $paid;
$closed  = in_array($res['status'], ['Cancelled', 'Checked-out'], true);

admin_start('Reservation ' . $res['booking_ref'], 'reservations');
?>
<header class="page-head">
  <div class="ph-text">
    <h1>Reservation <?= h($res['booking_ref']) ?> <?= badge($res['status']) ?></h1>
    <p class="page-sub"><?= h($res['guest']) ?> · <?= h(scope_label($res)) ?> · <?= h(fdate($res['check_in_date'])) ?> to <?= h(fdate($res['check_out_date'])) ?></p>
  </div>
  <div class="actions">
    <a class="btn" href="reservations.php">Back</a>
    <?php foreach (RESERVATION_FLOW[$res['status']] as $to => $label): ?>
      <form method="post" <?= $to === 'Cancelled' ? 'onsubmit="return confirm(\'Cancel this reservation? Its rooms become available again.\')"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="to" value="<?= h($to) ?>">
        <button class="btn<?= $to === 'Cancelled' ? ' danger' : ' primary' ?>" type="submit"><?= h($label) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
</header>

<div class="split">
  <section class="panel">
    <h2>Stay</h2>
    <dl class="facts">
      <dt>Guest</dt><dd><?= h($res['guest']) ?><br><span class="muted"><?= h($res['email']) ?><?= $res['phone'] ? ', ' . h($res['phone']) : '' ?><?= $res['address'] ? '<br>' . h($res['address']) : '' ?><?= $res['id_number'] ? '<br>ID ' . h($res['id_number']) : '' ?></span></dd>
      <dt>Booking</dt><dd><strong><?= h(scope_label($res)) ?></strong><?= $res['booking_scope'] !== 'Rooms' ? '<br><span class="muted">Whole-property booking: no one else can book it for these dates.</span>' : '' ?></dd>
      <dt>Dates</dt><dd><?= h(fdate($res['check_in_date'])) ?> to <?= h(fdate($res['check_out_date'])) ?>, <?= $n ?> night<?= $n === 1 ? '' : 's' ?><?php if (in_array($res['status'], ['Pending', 'Confirmed', 'Checked-in'], true)): $inHouseUi = $res['status'] === 'Checked-in'; ?>
        <details class="no-print" style="margin-top:6px"><summary class="muted" style="cursor:pointer"><?= $inHouseUi ? 'Extend or shorten the stay' : 'Change dates' ?></summary>
          <form method="post" class="grid-form" style="margin-top:8px">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="change_dates">
            <?php if (!$inHouseUi): ?><label><span>Check-in</span><input type="date" name="check_in" value="<?= h($res['check_in_date']) ?>" min="<?= h(today()) ?>" required></label><?php endif; ?>
            <label><span>Check-out</span><input type="date" name="check_out" value="<?= h($res['check_out_date']) ?>" min="<?= h($inHouseUi ? add_days(today(), 1) : today()) ?>" required></label>
            <div class="actions wide"><button class="btn sm primary" type="submit"><?= $inHouseUi ? 'Move check-out' : 'Move dates' ?></button> <span class="muted">Same rooms; refused if they are taken. The total is recalculated and the guest is emailed.</span></div>
          </form>
        </details>
      <?php endif; ?></dd>
      <dt>Guests</dt><dd><?= h(party_text($res, $companions)) ?></dd>
      <dt>Booked</dt><dd><?= h(fdate($res['booking_date'])) ?>, <?= $res['source'] === 'Online' ? 'online by the guest' : 'by ' . h($res['staff_name'] ?: 'staff') ?></dd>
    </dl>
    <form method="post" class="stack" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="requests">
      <label><span>Guest requests and notes</span><textarea name="special_requests" rows="3" maxlength="500" placeholder="Late arrival, celebration, dietary needs…"><?= h((string)$res['special_requests']) ?></textarea></label>
      <div class="actions"><button class="btn sm" type="submit">Save requests</button></div>
    </form>
  </section>

  <section class="panel">
    <h2>Account</h2>
    <dl class="facts">
      <dt><?= $res['booking_scope'] === 'Rooms' ? 'Rooms' : 'Accommodation' ?></dt><dd class="num"><?= h(money($roomSum)) ?></dd>
      <dt>Services</dt><dd class="num"><?= h(money($svcSum)) ?></dd>
      <dt>Total</dt><dd class="num strong"><?= h(money($res['total_amount'])) ?></dd>
      <dt>Paid</dt><dd class="num"><?= h(money($paid)) ?></dd>
      <dt>Balance</dt><dd class="num strong<?= $balance > 0.004 && $res['status'] !== 'Cancelled' ? ' due' : '' ?>"><?= h(money($balance)) ?></dd>
    </dl>
  </section>
</div>

<section class="panel flush" id="guests">
  <h2 class="panel-title">Guests <small class="muted">(<?= (int)$res['num_guests'] ?> in total)</small></h2>
  <div class="table-scroll">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Type</th><th class="right">Age</th><th>Contact</th></tr></thead>
      <tbody>
        <tr>
          <td>1</td>
          <td><?= h($res['guest']) ?> <span class="muted">(booked by)</span></td>
          <td><?= h($res['booker_type'] ?? 'Adult') ?></td>
          <td class="num">&ndash;</td>
          <td><?= h($res['email']) ?><?= $res['phone'] ? '<br><span class="muted">' . h($res['phone']) . '</span>' : '' ?></td>
        </tr>
      <?php foreach ($companions as $i => $c): ?>
        <tr>
          <td><?= $i + 2 ?></td>
          <td><?= h($c['first_name'] . ' ' . $c['last_name']) ?></td>
          <td><?= h($c['guest_type']) ?></td>
          <td class="num"><?= (int)$c['age'] ?></td>
          <td class="muted">&ndash;</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($res['booking_scope'] !== 'Rooms' && !$closed): $cap = whole_capacity($pdo, $id, $res['booking_scope']); ?>
  <details class="no-print" style="padding:0 16px 14px"><summary class="muted" style="cursor:pointer">Edit headcount</summary>
    <form method="post" class="grid-form" style="margin-top:8px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="headcount">
      <label><span>Adults</span><input type="number" name="num_adults" value="<?= (int)$res['num_adults'] ?>" min="1" max="<?= $cap ?>" step="1" required></label>
      <label><span>Children</span><input type="number" name="num_children" value="<?= (int)$res['num_children'] ?>" min="0" max="<?= $cap ?>" step="1" required></label>
      <div class="actions wide"><button class="btn sm primary" type="submit">Save headcount</button> <span class="muted">Up to <?= h(plural($cap, 'guest')) ?> for this booking. Names aren’t kept for whole-place bookings.</span></div>
    </form>
  </details>
  <?php endif; ?>
  <?php if ($res['booking_scope'] === 'Rooms' && !$closed): ?>
  <details class="no-print" style="padding:0 16px 14px"><summary class="muted" style="cursor:pointer">Edit guests</summary>
    <form method="post" class="stack" style="margin-top:8px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="edit_guests">
      <?= booker_type_field((string)($res['booker_type'] ?? 'Adult')) ?>
      <?php
        $pv = [];
        foreach ($companions as $c) { $pv[] = ['type' => $c['guest_type'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'age' => $c['age']]; }
        echo companion_fields((int)$res['num_guests'], 0, $pv, false);
      ?>
      <div class="actions"><button class="btn sm primary" type="submit">Save guests</button></div>
    </form>
  </details>
  <?php endif; ?>
  <?php if ($res['booking_scope'] === 'Rooms' && count($companions) + 1 < (int)$res['num_guests']): ?>
    <p class="muted" style="padding:0 16px 14px">Names for <?= (int)$res['num_guests'] - count($companions) - 1 ?> guest(s) were not entered for this reservation.</p>
  <?php endif; ?>
</section>

<section class="panel flush">
  <h2 class="panel-title">Rooms</h2>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Room</th><th>Type</th><th>Floor</th><th class="right">Rate per night</th><th class="right">× <?= $n ?> night<?= $n === 1 ? '' : 's' ?></th></tr></thead>
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
</section>

<section class="panel flush">
  <h2 class="panel-title">Services</h2>
  <?php if (!$lines): ?>
    <p class="empty">No services added yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Date</th><th>Service</th><th class="right">Qty</th><th class="right">Charged</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr>
          <td><?= h(fdate($l['service_date'])) ?></td>
          <td><?= h($l['service_name']) ?></td>
          <td class="num"><?= (int)$l['quantity'] ?></td>
          <td class="num"><?= h(money($l['price_charged'])) ?></td>
          <td class="right row-actions">
            <?php if (!$closed): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="action" value="remove_service">
              <input type="hidden" name="service_id" value="<?= (int)$l['service_id'] ?>">
              <input type="hidden" name="service_date" value="<?= h($l['service_date']) ?>">
              <button class="btn sm danger" type="submit">Remove</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!$closed): ?>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="add_service">
    <label><span>Service</span>
      <select name="service_id" required>
        <option value="">Select a service</option>
        <?php foreach ($allServices as $sid => $slabel): ?><option value="<?= (int)$sid ?>"><?= h($slabel) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><span>Date</span><input type="date" name="service_date" value="<?= h($res['check_in_date']) ?>" min="<?= h($res['check_in_date']) ?>" max="<?= h($res['check_out_date']) ?>" required></label>
    <label><span>Qty</span><input type="number" name="quantity" value="1" min="1" step="1" required></label>
    <button class="btn" type="submit">Add service</button>
  </form>
  <?php endif; ?>
</section>

<section class="panel flush">
  <h2 class="panel-title">Payments</h2>
  <?php if (!$payments): ?>
    <p class="empty">No payments recorded yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Date</th><th>Method</th><th>Status</th><th class="right">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= h(fdate($p['payment_date'])) ?></td>
          <td><?= h($p['method']) ?></td>
          <td><?= badge($p['payment_status']) ?></td>
          <td class="num<?= $p['payment_status'] === 'Voided' ? ' voided-amt' : '' ?>"><?= $p['payment_status'] === 'Refunded' ? '−' : '' ?><?= h(money($p['amount'])) ?></td>
          <td class="right row-actions">
            <?php if ($p['payment_status'] !== 'Voided'): ?>
            <form method="post" onsubmit="return confirm('Void this payment? It stays on the record but no longer counts.')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="action" value="void_payment">
              <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
              <button class="btn sm danger" type="submit">Void</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="add_payment">
    <label><span>Amount</span><input type="number" name="amount" step="0.01" min="0.01" value="<?= $balance > 0.004 ? h(number_format($balance, 2, '.', '')) : '' ?>" required></label>
    <label><span>Method</span>
      <select name="method" required>
        <?php foreach (['Cash', 'Card', 'GCash', 'Bank transfer'] as $m): ?><option><?= h($m) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><span>Status</span>
      <select name="payment_status"><?php foreach (['Paid', 'Pending', 'Refunded'] as $s): ?><option><?= h($s) ?></option><?php endforeach; ?></select>
    </label>
    <label><span>Date</span><input type="date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" required></label>
    <button class="btn" type="submit">Record payment</button>
  </form>
</section>
<?php admin_end();
