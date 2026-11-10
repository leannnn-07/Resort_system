<?php
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/booking.php';
$staffMember = require_login($pdo);

$RID = resort_id();
$guests = $pdo->query("SELECT guest_id, CONCAT(first_name, ' ', last_name, ' (', email, ')') FROM guest
                        WHERE created_resort_id = $RID OR EXISTS (SELECT 1 FROM reservation v WHERE v.guest_id = guest.guest_id AND v.resort_id = $RID)
                        ORDER BY last_name, first_name")
              ->fetchAll(PDO::FETCH_KEY_PAIR);

$in       = (string)($_POST['check_in']  ?? $_GET['check_in']  ?? '');
$out      = (string)($_POST['check_out'] ?? $_GET['check_out'] ?? '');
$adults   = max(1, (int)($_POST['adults']   ?? $_GET['adults']   ?? 2));
$children = max(0, (int)($_POST['children'] ?? $_GET['children'] ?? 0));
$searched = isset($_GET['check_in']) || $_SERVER['REQUEST_METHOD'] === 'POST';
$wholePick = (string)($_POST['whole'] ?? '');
$problems = $searched ? validate_stay($in, $out, $adults, $children, $wholePick !== '' ? 5000 : null) : [];
$datesOk  = $searched && !$problems;
$selected = array_map('intval', (array)($_POST['rooms'] ?? []));
$error    = '';

// ---------- Create the reservation ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $guestId  = (int)($_POST['guest_id'] ?? 0);
    $roomIds  = array_values(array_unique($selected));
    $requests = trim((string)($_POST['special_requests'] ?? ''));

    try {
        if ($problems) {
            throw new RuntimeException($problems[0]);
        }
        if ($guestId <= 0 || !isset($guests[$guestId])) {
            throw new RuntimeException('Select a guest. You can add new guests on the Guests page.');
        }
        if ($wholePick !== '') {
            // Whole resort / building / pool: same checks and locks as an online booking, then marked as taken by this staff member.
            [$wScope, $wId] = array_pad(explode(':', $wholePick, 2), 2, '0');
            $g = $pdo->prepare('SELECT * FROM guest WHERE guest_id = ?');
            $g->execute([$guestId]);
            $g = $g->fetch();
            if (mb_strlen($requests) > 500) {
                throw new RuntimeException('Special requests can be up to 500 characters.');
            }
            $made = create_online_booking($pdo, [
                'scope' => $wScope, 'target_id' => (int)$wId, 'check_in' => $in, 'check_out' => $out,
                'adults' => $adults, 'children' => $children, 'first_name' => $g['first_name'], 'last_name' => $g['last_name'],
                'email' => strtolower($g['email']), 'phone' => (string)$g['phone'], 'address' => (string)$g['address'],
                'requests' => $requests, 'services' => [], 'companions' => [], 'booker_type' => clean_booker_type($_POST['booker_type'] ?? 'Adult'),
            ]);
            $pdo->prepare("UPDATE reservation SET source = 'Staff', staff_id = ? WHERE reservation_id = ?")->execute([$staffMember['staff_id'], $made['id']]);
            flash('Reservation ' . $made['ref'] . ' created. Confirm it once the guest agrees.');
            redirect('reservation_view.php?id=' . $made['id']);
        }
        if (!$roomIds) {
            throw new RuntimeException('Select at least one room, or choose a whole place.');
        }
        if (mb_strlen($requests) > 500) {
            throw new RuntimeException('Special requests can be up to 500 characters.');
        }
        $bookerType = clean_booker_type($_POST['booker_type'] ?? 'Adult');
        $companions = parse_companions($_POST, $adults, $children, false);

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $marks = implode(',', array_fill(0, count($roomIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT r.room_id, r.status, t.base_rate, t.max_occupancy
               FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id
              WHERE r.room_id IN ($marks) AND r.resort_id = $RID ORDER BY r.room_id FOR UPDATE"
        );
        $stmt->execute($roomIds);
        $found = $stmt->fetchAll();
        if (count($found) !== count($roomIds)) {
            throw new RuntimeException('One of the selected rooms no longer exists. Search again.');
        }

        $capacity = 0;
        foreach ($found as $r) {
            if ($r['status'] === 'Maintenance') {
                throw new RuntimeException('One of the selected rooms is under maintenance. Search again.');
            }
            $capacity += (int)$r['max_occupancy'];
        }
        if ($adults + $children > $capacity) {
            throw new RuntimeException('The selected rooms sleep ' . $capacity . ' guests. Add a room or lower the number of guests.');
        }

        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM reservation_room rr
               JOIN reservation v ON v.reservation_id = rr.reservation_id
              WHERE rr.room_id IN ($marks) AND v.status <> 'Cancelled'
                AND v.check_in_date < ? AND v.check_out_date > ?"
        );
        $chk->execute([...$roomIds, $out, $in]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new RuntimeException('A selected room was just booked for these dates. Search again.');
        }

        $ref = new_booking_ref($pdo);
        [$numAdults, $numChildren] = party_counts($bookerType, $companions, $adults, $children);
        $pdo->prepare(
            "INSERT INTO reservation (resort_id, booking_ref, guest_id, staff_id, booking_date, check_in_date, check_out_date,
                                      num_adults, num_children, booker_type, special_requests, source, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Staff', 'Pending')"
        )->execute([$RID, $ref, $guestId, $staffMember['staff_id'], today(), $in, $out, $numAdults, $numChildren, $bookerType, $requests ?: null]);
        $reservationId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO reservation_room (reservation_id, room_id, rate_applied) VALUES (?, ?, ?)');
        foreach ($found as $r) {
            $ins->execute([$reservationId, $r['room_id'], $r['base_rate']]);
        }
        save_companions($pdo, $reservationId, $companions);
        recalc_total($pdo, $reservationId);
        $pdo->commit();

        flash('Reservation ' . $ref . ' created. Confirm it once the guest agrees.');
        redirect('reservation_view.php?id=' . $reservationId);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = 'The reservation could not be saved. Check the details and try again.';
    }
}

$available = $datesOk ? available_rooms($pdo, $in, $out) : [];
$wholeOpts = [];
if ($datesOk) {
    $eo = exclusive_options($pdo, $in, $out);
    foreach (array_merge($eo['Resort'] ? [$eo['Resort']] : [], $eo['Building'], $eo['Pool']) as $t) {
        $wholeOpts[] = $t;
    }
}
$stayNights = $datesOk ? nights($in, $out) : 0;

admin_start('New reservation', 'reservations');
?>
<?php admin_head('New reservation', 'Book a stay for a guest at ' . $config['resort'], '<a class="btn" href="reservations.php">Back to reservations</a>'); ?>

<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($searched && $problems && $_SERVER['REQUEST_METHOD'] === 'GET'): ?>
  <div class="notice error" role="alert"><?= h($problems[0]) ?></div>
<?php endif; ?>

<section class="panel">
  <h2>1. Dates and guests</h2>
  <form method="get" class="grid-form" data-stay>
    <label><span>Check-in</span><input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" required></label>
    <label><span>Check-out <em class="nights" data-nights></em></span><input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(today()) ?>" required></label>
    <label><span>Adults</span><input type="number" name="adults" value="<?= $adults ?>" min="1" max="20" step="1" required></label>
    <label><span>Children</span><input type="number" name="children" value="<?= $children ?>" min="0" max="20" step="1"></label>
    <div class="actions"><button class="btn primary" type="submit">Find rooms</button></div>
  </form>
</section>

<?php if ($datesOk): ?>
<form method="post" class="panel" id="booking">
  <?= csrf_field() ?>
  <input type="hidden" name="check_in" value="<?= h($in) ?>">
  <input type="hidden" name="check_out" value="<?= h($out) ?>">
  <input type="hidden" name="adults" value="<?= $adults ?>">
  <input type="hidden" name="children" value="<?= $children ?>">

  <h2>2. Pick rooms: <?= h(fdate($in)) ?> to <?= h(fdate($out)) ?>, <?= h(plural($stayNights, 'night')) ?>, <?= h(plural($adults + $children, 'guest')) ?></h2>

  <?php if (!$available && !$wholeOpts): ?>
    <p class="empty">No rooms are free on these dates. Try different dates.</p>
  <?php else: ?>
    <?php if (!$available): ?><p class="empty">No single rooms are free on these dates.</p><?php endif; ?>
    <div class="room-list">
      <?php foreach ($available as $r): ?>
        <label class="room-option">
          <input type="checkbox" name="rooms[]" value="<?= (int)$r['room_id'] ?>"
                 data-cap="<?= (int)$r['max_occupancy'] ?>" data-rate="<?= h($r['base_rate']) ?>"
                 <?= in_array((int)$r['room_id'], $selected, true) ? 'checked' : '' ?>>
          <span class="room-no"><?= h($r['room_number']) ?></span>
          <span class="room-meta"><?= h($r['type_name']) ?>, floor <?= (int)$r['floor'] ?>, sleeps <?= (int)$r['max_occupancy'] ?></span>
          <span class="room-rate"><?= h(money($r['base_rate'])) ?> / night</span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="summary" id="summary" aria-live="polite">Select one or more rooms.</p>

    <?php if ($wholeOpts): ?>
    <h2>Or book a whole place</h2>
    <p class="muted">Exclusive use: nobody else can book it for these dates. Choose one instead of rooms.</p>
    <div class="room-list">
      <label class="room-option"><input type="radio" name="whole" value="" <?= $wholePick === '' ? 'checked' : '' ?>><span class="room-no">Rooms</span><span class="room-meta">Use the rooms ticked above</span><span class="room-rate"></span></label>
      <?php foreach ($wholeOpts as $t): $val = $t['scope'] . ':' . $t['id']; ?>
        <label class="room-option<?= $t['free'] ? '' : ' disabled' ?>">
          <input type="radio" name="whole" value="<?= h($val) ?>" <?= $t['free'] ? '' : 'disabled' ?> <?= $wholePick === $val ? 'checked' : '' ?>>
          <span class="room-no"><?= h(BOOKING_SCOPES[$t['scope']] ?? $t['scope']) ?></span>
          <span class="room-meta"><?= h($t['name']) ?><?= $t['free'] ? ', sleeps ' . (int)$t['capacity'] : ': ' . h($t['why']) ?></span>
          <span class="room-rate"><?= $t['free'] ? h(money($t['rate'])) . ' / ' . ($t['scope'] === 'Pool' ? 'day' : 'night') : '' ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>3. Guest details</h2>
    <div class="grid-form">
      <label><span>Guest</span>
        <select name="guest_id" required>
          <option value="">Select a guest</option>
          <?php foreach ($guests as $gid => $glabel): ?>
            <option value="<?= (int)$gid ?>"<?= (int)($_POST['guest_id'] ?? 0) === (int)$gid ? ' selected' : '' ?>><?= h($glabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="wide"><span>Special requests (optional)</span>
        <textarea name="special_requests" rows="2" maxlength="500"><?= h($_POST['special_requests'] ?? '') ?></textarea>
      </label>
      <?php
        $pv = [];
        foreach (companion_slots($adults, $children) as $i => $_) {
            $pv[$i] = ['type' => $_POST['comp_type'][$i] ?? '', 'first_name' => $_POST['comp_first'][$i] ?? '', 'last_name' => $_POST['comp_last'][$i] ?? '', 'age' => $_POST['comp_age'][$i] ?? ''];
        }
      ?>
      <?= booker_type_field((string)($_POST['booker_type'] ?? 'Adult')) ?>
      <div class="wide"><?= companion_fields($adults, $children, $pv, false) ?></div>
      <div class="actions wide">
        <button class="btn primary" type="submit">Create reservation</button>
        <a class="btn" href="guests.php">Add a new guest</a>
      </div>
    </div>
  <?php endif; ?>
</form>

<script>
(function () {
  var boxes = document.querySelectorAll('input[name="rooms[]"]');
  var out = document.getElementById('summary');
  if (!out) return;
  var nights = <?= (int)$stayNights ?>, guests = <?= (int)($adults + $children) ?>, cur = <?= json_encode($config['currency'], JSON_UNESCAPED_UNICODE) ?>;
  function update() {
    var rooms = 0, cap = 0, rate = 0;
    boxes.forEach(function (b) {
      if (b.checked) { rooms++; cap += +b.dataset.cap; rate += +b.dataset.rate; }
    });
    if (!rooms) { out.textContent = 'Select one or more rooms.'; out.className = 'summary'; return; }
    var total = (rate * nights).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var text = rooms + (rooms > 1 ? ' rooms' : ' room') + ' selected, sleeps ' + cap + ', rooms total ' + cur + total + '.';
    if (cap < guests) text += ' Not enough space for ' + guests + ' guests.';
    out.textContent = text;
    out.className = cap < guests ? 'summary warn' : 'summary';
  }
  boxes.forEach(function (b) { b.addEventListener('change', update); });
  update();
})();
</script>
<?php endif; ?>
<?php admin_end();
