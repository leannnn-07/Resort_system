<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/payments.php';
$staffMember = require_login($pdo);

$RID = resort_id();
$guests = $pdo->query("SELECT guest_id, CONCAT(last_name, ', ', first_name, IF(middle_name IS NOT NULL AND middle_name <> '', CONCAT(' ', middle_name), ''), ' (', email, ')') FROM guest
                        WHERE archived_at IS NULL AND (created_resort_id = $RID OR EXISTS (SELECT 1 FROM reservation v WHERE v.guest_id = guest.guest_id AND v.resort_id = $RID))
                        ORDER BY last_name, first_name")
              ->fetchAll(PDO::FETCH_KEY_PAIR);

$mode      = (string)($_POST['mode'] ?? $_GET['mode'] ?? 'cottage');
$mode      = in_array($mode, ['cottage', 'rooms'], true) ? $mode : 'cottage';
$isCottage = $mode === 'cottage';

$in        = (string)($_POST['check_in']  ?? $_GET['check_in']  ?? today());
$out       = (string)($_POST['check_out'] ?? $_GET['check_out'] ?? add_days(today(), 1));
if ($isCottage && valid_date($in)) {
    $out = add_days($in, 1);
}
$adults    = max(1, (int)($_POST['adults']   ?? $_GET['adults']   ?? 2));
$children  = max(0, (int)($_POST['children'] ?? $_GET['children'] ?? 0));
$guestsCnt = $adults + $children;
$q         = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));

$searched  = isset($_GET['check_in']) || isset($_GET['mode']) || $_SERVER['REQUEST_METHOD'] === 'POST';
$wholePick = (string)($_POST['whole'] ?? '');
$cotPick   = (int)($_POST['cottage_id'] ?? 0);
$problems  = $searched ? validate_stay($in, $out, $adults, $children, ($isCottage || $wholePick !== '') ? 5000 : null) : [];
$datesOk   = $searched && !$problems;
$selected  = array_map('intval', (array)($_POST['rooms'] ?? []));
$error     = '';

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
        if (mb_strlen($requests) > 500) {
            throw new RuntimeException('Special requests can be up to 500 characters.');
        }

        // Day-use cottage booking
        if ($isCottage) {
            if ($cotPick <= 0) {
                throw new RuntimeException('Select a day-use cottage to book.');
            }
            $gStmt = $pdo->prepare('SELECT * FROM guest WHERE guest_id = ?');
            $gStmt->execute([$guestId]);
            $g = $gStmt->fetch();
            $bt = clean_booker_type($_POST['booker_type'] ?? 'Adult');
            $companions = parse_companions($_POST, $adults, $children, false, 50);
            [$numAdults, $numChildren] = party_counts($bt, $companions, $adults, $children, !empty($companions) || isset($_POST['comp_present']));
            $made = create_online_booking($pdo, [
                'scope' => 'Cottage', 'target_id' => $cotPick, 'check_in' => $in, 'check_out' => add_days($in, 1),
                'adults' => $numAdults, 'children' => $numChildren, 'first_name' => $g['first_name'], 'middle_name' => (string)($g['middle_name'] ?? ''), 'last_name' => $g['last_name'],
                'email' => strtolower($g['email']), 'phone' => (string)($g['phone'] ?? ''), 'address' => (string)($g['address'] ?? ''),
                'requests' => $requests, 'services' => [], 'companions' => $companions, 'booker_type' => $bt,
                'skip_notif' => true,
            ]);
            $pdo->prepare("UPDATE reservation SET source = 'Staff', staff_id = ?, required_downpayment = 0 WHERE reservation_id = ?")
                ->execute([$staffMember['staff_id'], $made['id']]);
            flash('Cottage reservation ' . $made['ref'] . ' created. Confirm it once paid.');
            redirect('reservation_view.php?id=' . $made['id']);
        }

        // Whole resort / building / pool booking
        if ($wholePick !== '') {
            [$wScope, $wId] = array_pad(explode(':', $wholePick, 2), 2, '0');
            $gStmt = $pdo->prepare('SELECT * FROM guest WHERE guest_id = ?');
            $gStmt->execute([$guestId]);
            $g = $gStmt->fetch();
            $bt = clean_booker_type($_POST['booker_type'] ?? 'Adult');
            $companions = parse_companions($_POST, $adults, $children, false, 500);
            [$numAdults, $numChildren] = party_counts($bt, $companions, $adults, $children, !empty($companions) || isset($_POST['comp_present']));
            $made = create_online_booking($pdo, [
                'scope' => $wScope, 'target_id' => (int)$wId, 'check_in' => $in, 'check_out' => $out,
                'adults' => $numAdults, 'children' => $numChildren, 'first_name' => $g['first_name'], 'middle_name' => (string)($g['middle_name'] ?? ''), 'last_name' => $g['last_name'],
                'email' => strtolower($g['email']), 'phone' => (string)($g['phone'] ?? ''), 'address' => (string)($g['address'] ?? ''),
                'requests' => $requests, 'services' => [], 'companions' => $companions, 'booker_type' => $bt,
                'skip_notif' => true,
            ]);
            $pdo->prepare("UPDATE reservation SET source = 'Staff', staff_id = ?, required_downpayment = 0 WHERE reservation_id = ?")
                ->execute([$staffMember['staff_id'], $made['id']]);
            flash('Reservation ' . $made['ref'] . ' created. Confirm it once the guest agrees.');
            redirect('reservation_view.php?id=' . $made['id']);
        }

        // Overnight room(s) booking
        if (!$roomIds) {
            throw new RuntimeException('Select at least one room, or choose a whole place.');
        }
        $bookerType = clean_booker_type($_POST['booker_type'] ?? 'Adult');
        $companions = parse_companions($_POST, $adults, $children, false, 50);

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
        [$numAdults, $numChildren] = party_counts($bookerType, $companions, $adults, $children, !empty($companions) || isset($_POST['comp_present']));
        $effectiveGuests = $numAdults + $numChildren;
        if ($effectiveGuests > $capacity) {
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
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = 'The reservation could not be saved. Check the details and try again.';
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}

$cottages  = [];
$cottageEnt = ['adult_rate' => 0.0, 'child_rate' => 0.0, 'total' => 0.0];
$available = [];
$wholeOpts = [];
if ($datesOk) {
    if ($isCottage) {
        $cottages   = cottage_availability($pdo, $in);
        $cottageEnt = cottage_entrance_total($pdo, $RID, $adults, $children);
    } else {
        $available = available_rooms($pdo, $in, $out);
        $eo = exclusive_options($pdo, $in, $out);
        foreach (array_merge($eo['Resort'] ? [$eo['Resort']] : [], $eo['Building'], $eo['Pool']) as $t) {
            $wholeOpts[] = $t;
        }
    }
}
$stayNights = $datesOk ? nights($in, $out) : 0;

admin_start('New reservation', 'reservations');
?>
<div class="ph-back-row"><a class="btn sm page-back" href="reservations.php">&larr; Back to reservations</a></div>
<?php admin_head('New reservation'); ?>

<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($searched && $problems && $_SERVER['REQUEST_METHOD'] === 'GET'): ?>
  <div class="notice error" role="alert"><?= h($problems[0]) ?></div>
<?php endif; ?>

<section class="panel">
  <h2>1. Search cottages or rooms</h2>
  <form method="get" class="grid-form" id="new-res-search" data-stay>
    <label><span>Booking type</span>
      <select name="mode" id="nr-mode">
        <option value="cottage"<?= $isCottage ? ' selected' : '' ?>>Day-use cottage</option>
        <option value="rooms"<?= !$isCottage ? ' selected' : '' ?>>Overnight rooms / Whole place</option>
      </select>
    </label>
    <label><span>Search name or room #</span>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Optional: Beach Cottage, Deluxe, 101…" maxlength="80" autocomplete="off">
    </label>
    <label><span id="nr-in-lbl"><?= $isCottage ? 'Visit date' : 'Check-in' ?></span>
      <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" required>
    </label>
    <label id="nr-out-wrap"<?= $isCottage ? ' hidden' : '' ?>><span>Check-out <em class="nights" data-nights></em></span>
      <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>"<?= $isCottage ? '' : ' required' ?>>
    </label>
    <label><span>Guests</span>
      <input type="number" name="adults" value="<?= $guestsCnt ?>" min="1" max="50" step="1" required>
    </label>
    <input type="hidden" name="children" value="0">
    <div class="actions"><button class="btn primary" type="submit" id="nr-search-btn"><?= $isCottage ? 'Find cottages' : 'Find rooms' ?></button></div>
  </form>
</section>

<script>
(function () {
  var mode = document.getElementById('nr-mode');
  var outWrap = document.getElementById('nr-out-wrap');
  var outIn = outWrap ? outWrap.querySelector('input') : null;
  var inLbl = document.getElementById('nr-in-lbl');
  var btn = document.getElementById('nr-search-btn');
  if (!mode) return;
  function syncMode() {
    var isCot = mode.value === 'cottage';
    if (outWrap) outWrap.hidden = isCot;
    if (outIn) outIn.required = !isCot;
    if (inLbl) inLbl.textContent = isCot ? 'Visit date' : 'Check-in';
    if (btn) btn.textContent = isCot ? 'Find cottages' : 'Find rooms';
  }
  mode.addEventListener('change', syncMode);
  syncMode();
})();
</script>

<?php if ($datesOk): ?>
<form method="post" class="panel" id="booking">
  <?= csrf_field() ?>
  <input type="hidden" name="mode" value="<?= h($mode) ?>">
  <input type="hidden" name="q" value="<?= h($q) ?>">
  <input type="hidden" name="check_in" value="<?= h($in) ?>">
  <input type="hidden" name="check_out" value="<?= h($out) ?>">
  <input type="hidden" name="adults" value="<?= $adults ?>">
  <input type="hidden" name="children" value="<?= $children ?>">

  <?php if ($isCottage): ?>
    <div class="nr-pick-head">
      <h2>2. Pick a day-use cottage · <?= h(fdate($in)) ?>, <?= h(plural($guestsCnt, 'guest')) ?></h2>
      <input type="search" class="nr-live-filter" id="nr-live-filter" value="<?= h($q) ?>" placeholder="Filter cottages by name…" aria-label="Filter cottages" autocomplete="off">
    </div>

    <?php if (!$cottages): ?>
      <p class="empty">No day-use cottages are listed for this resort yet. Add cottages under <strong>Pricing &amp; Add-ons → Cottages &amp; Entrance</strong>.</p>
    <?php else: ?>
      <div class="room-list" id="nr-opt-list">
        <?php foreach ($cottages as $c):
            $cid   = (int)$c['price_item_id'];
            $cap   = (int)$c['max_guests'];
            $free  = (int)$c['free'];
            $can   = $free > 0 && $guestsCnt <= $cap;
            $rate  = (float)$c['price'];
            $total = round($rate + $cottageEnt['total'], 2);
            $why   = $free <= 0 ? 'Fully booked on this date' : ($guestsCnt > $cap ? 'Fits up to ' . $cap . ' guests' : plural($free, 'unit') . ' left');
            $searchTxt = mb_strtolower($c['item_name'] . ' ' . ($c['note'] ?? ''));
        ?>
          <label class="room-option<?= $can ? '' : ' disabled' ?>" data-filter-item="<?= h($searchTxt) ?>">
            <input type="radio" name="cottage_id" value="<?= $cid ?>"
                   data-name="<?= h($c['item_name']) ?>" data-rate="<?= h((string)$rate) ?>" data-ent="<?= h((string)$cottageEnt['total']) ?>" data-total="<?= h((string)$total) ?>"
                   <?= $can ? '' : 'disabled' ?> <?= $cotPick === $cid ? 'checked' : '' ?>>
            <span class="room-no"><?= h($c['item_name']) ?></span>
            <span class="room-meta">Up to <?= $cap ?> guests · <?= h($why) ?><?= !empty($c['note']) ? ' · ' . h($c['note']) : '' ?></span>
            <span class="room-rate"><?= h(money($rate)) ?> <small class="muted"><?= h($c['unit'] ?: 'per cottage') ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="empty" id="nr-no-match" hidden>No cottages match your filter.</p>
      <p class="summary" id="cot-summary" aria-live="polite">Select a cottage above.<?= $cottageEnt['total'] > 0.004 ? ' Entrance fee (' . h(money($cottageEnt['total'])) . ' for ' . h(plural($guestsCnt, 'guest')) . ') is added automatically.' : '' ?></p>
    <?php endif; ?>
  <?php else: ?>
    <div class="nr-pick-head">
      <h2>2. Pick rooms: <?= h(fdate($in)) ?> to <?= h(fdate($out)) ?>, <?= h(plural($stayNights, 'night')) ?>, <?= h(plural($guestsCnt, 'guest')) ?></h2>
      <input type="search" class="nr-live-filter" id="nr-live-filter" value="<?= h($q) ?>" placeholder="Filter by room #, type, or floor…" aria-label="Filter rooms" autocomplete="off">
    </div>

    <?php if (!$available && !$wholeOpts): ?>
      <p class="empty">No rooms are free on these dates. Try different dates.</p>
    <?php else: ?>
      <?php if (!$available): ?><p class="empty">No single rooms are free on these dates.</p><?php endif; ?>
      <div class="room-list" id="nr-opt-list">
        <?php foreach ($available as $r):
            $searchTxt = mb_strtolower($r['room_number'] . ' ' . $r['type_name'] . ' floor ' . $r['floor']);
        ?>
          <label class="room-option" data-filter-item="<?= h($searchTxt) ?>">
            <input type="checkbox" name="rooms[]" value="<?= (int)$r['room_id'] ?>"
                   data-cap="<?= (int)$r['max_occupancy'] ?>" data-rate="<?= h($r['base_rate']) ?>"
                   <?= in_array((int)$r['room_id'], $selected, true) ? 'checked' : '' ?>>
            <span class="room-no"><?= h($r['room_number']) ?></span>
            <span class="room-meta"><?= h($r['type_name']) ?>, floor <?= (int)$r['floor'] ?>, sleeps <?= (int)$r['max_occupancy'] ?></span>
            <span class="room-rate"><?= h(money($r['base_rate'])) ?> / night</span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="empty" id="nr-no-match" hidden>No rooms match your filter.</p>
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
    <?php endif; ?>
  <?php endif; ?>

  <?php if (($isCottage && $cottages) || (!$isCottage && ($available || $wholeOpts))): ?>
    <h2>3. Guest details</h2>
    <div class="grid-form two-col">
      <label><span>Guest <small class="muted">(Lastname, Firstname)</small></span>
        <select name="guest_id" required>
          <option value="">Select a guest</option>
          <?php foreach ($guests as $gid => $glabel): ?>
            <option value="<?= (int)$gid ?>"<?= (int)($_POST['guest_id'] ?? 0) === (int)$gid ? ' selected' : '' ?>><?= h($glabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?= booker_type_field((string)($_POST['booker_type'] ?? 'Adult')) ?>
      <label class="wide"><span>Special requests</span>
        <textarea name="special_requests" rows="2" maxlength="500" placeholder="Optional, e.g. Late arrival, celebration, dietary needs"><?= h($_POST['special_requests'] ?? '') ?></textarea>
      </label>
      <?php
        $pv = [];
        $ct = array_values((array)($_POST['comp_type'] ?? []));
        $cf = array_values((array)($_POST['comp_first'] ?? []));
        $cm = array_values((array)($_POST['comp_middle'] ?? []));
        $cl = array_values((array)($_POST['comp_last'] ?? []));
        $postedComps = isset($_POST['comp_present']) ? max(count($ct), count($cf), count($cl)) : count(companion_slots($adults, $children));
        for ($i = 0; $i < $postedComps; $i++) {
            $pv[$i] = [
                'type'        => $ct[$i] ?? 'Adult',
                'first_name'  => $cf[$i] ?? '',
                'middle_name' => $cm[$i] ?? '',
                'last_name'   => $cl[$i] ?? '',
            ];
        }
        $maxNewCap = $isCottage
            ? ($cottages ? max(array_map(fn($c) => (int)$c['max_guests'], $cottages)) : 25)
            : max(25, array_sum(array_map(fn($r) => (int)$r['max_occupancy'], $available)));
      ?>
      <div class="wide"><?= companion_fields($adults, $children, $pv, false, '', $maxNewCap) ?></div>
      <div class="actions wide">
        <button class="btn primary" type="submit" data-confirm="Create this reservation now?" data-confirm-title="Create reservation" data-confirm-ok="Yes, create it">Create reservation</button>
        <a class="btn" href="guests.php">Add a new guest</a>
      </div>
    </div>
  <?php endif; ?>
</form>

<script>
(function () {
  var cur = <?= json_encode($config['currency'], JSON_UNESCAPED_UNICODE) ?>;
  var fmt = function (v) { return cur + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  // Live search filter for rooms & cottages list
  var filterIn = document.getElementById('nr-live-filter');
  var items = Array.prototype.slice.call(document.querySelectorAll('[data-filter-item]'));
  var noMatch = document.getElementById('nr-no-match');
  function applyFilter() {
    if (!filterIn || !items.length) return;
    var q = filterIn.value.trim().toLowerCase();
    var shown = 0;
    items.forEach(function (el) {
      var hit = !q || (el.getAttribute('data-filter-item') || '').indexOf(q) !== -1;
      el.hidden = !hit;
      if (hit) shown++;
    });
    if (noMatch) noMatch.hidden = shown > 0;
  }
  if (filterIn) {
    filterIn.addEventListener('input', applyFilter);
    applyFilter();
  }

  // Day-use cottage live total
  var cotRadios = document.querySelectorAll('input[name="cottage_id"]');
  var cotOut = document.getElementById('cot-summary');
  if (cotRadios.length && cotOut) {
    function syncCot() {
      var sel = document.querySelector('input[name="cottage_id"]:checked');
      if (!sel) return;
      var ent = +sel.dataset.ent || 0;
      cotOut.textContent = sel.dataset.name + ' selected · Cottage ' + fmt(sel.dataset.rate)
        + (ent > 0 ? ' + Entrance ' + fmt(ent) : '') + ' = Total ' + fmt(sel.dataset.total) + '.';
    }
    cotRadios.forEach(function (r) { r.addEventListener('change', syncCot); });
    syncCot();
  }

  // Overnight rooms live total
  var boxes = document.querySelectorAll('input[name="rooms[]"]');
  var out = document.getElementById('summary');
  if (boxes.length && out) {
    var nights = <?= (int)$stayNights ?>, guests = <?= (int)$guestsCnt ?>;
    function update() {
      var rooms = 0, cap = 0, rate = 0;
      boxes.forEach(function (b) {
        if (b.checked) { rooms++; cap += +b.dataset.cap; rate += +b.dataset.rate; }
      });
      if (!rooms) { out.textContent = 'Select one or more rooms.'; out.className = 'summary'; return; }
      var text = rooms + (rooms > 1 ? ' rooms' : ' room') + ' selected, sleeps ' + cap + ', rooms total ' + fmt(rate * nights) + '.';
      if (cap < guests) text += ' Not enough space for ' + guests + ' guests.';
      out.textContent = text;
      out.className = cap < guests ? 'summary warn' : 'summary';
    }
    boxes.forEach(function (b) { b.addEventListener('change', update); });
    update();
  }
})();
</script>
<?php endif; ?>
<?php admin_end();
