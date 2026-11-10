<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Validate a stay request. Returns a list of error messages (empty = valid).
 */
function validate_stay(string $in, string $out, int $adults, int $children, ?int $maxGuests = null): array
{
    global $config;
    $errors = [];

    if (!valid_date($in) || !valid_date($out)) {
        return ['Choose both a check-in and a check-out date.'];
    }
    if ($in < today()) {
        $errors[] = 'Check-in can’t be in the past.';
    }
    if ($out <= $in) {
        $errors[] = 'Check-out must be after check-in.';
    } else {
        $n = nights($in, $out);
        if ($n < $config['min_nights']) {
            $errors[] = 'The minimum stay is ' . plural((int)$config['min_nights'], 'night') . '.';
        }
        if ($n > $config['max_nights']) {
            $errors[] = 'The maximum stay is ' . plural((int)$config['max_nights'], 'night') . '. For longer stays, contact us.';
        }
    }
    if ($in > add_days(today(), (int)$config['max_advance_days'])) {
        $errors[] = 'We take bookings up to ' . plural((int)$config['max_advance_days'], 'day') . ' ahead.';
    }
    if ($adults < 1) {
        $errors[] = 'At least one adult is required.';
    }
    if ($children < 0) {
        $errors[] = 'Children can’t be negative.';
    }
    $limit = $maxGuests ?? (int)$config['max_guests_per_booking'];
    if ($adults + $children > $limit) {
        $errors[] = $maxGuests !== null
            ? 'This booking holds up to ' . plural($limit, 'guest') . '. Reduce the group size.'
            : 'For groups larger than ' . $limit . ' guests, please contact us.';
    }
    return $errors;
}

/** Sub-query fragment: rooms that overlap the stay (params :d_in, :d_out). */
const OVERLAP_SQL = "SELECT rr.room_id FROM reservation_room rr
                       JOIN reservation v ON v.reservation_id = rr.reservation_id
                      WHERE v.status <> 'Cancelled'
                        AND v.check_in_date < :d_out AND v.check_out_date > :d_in";

/** Room types with how many rooms of each are free for the stay. */
function room_type_availability(PDO $pdo, string $in, string $out): array
{
    $stmt = $pdo->prepare(
        "SELECT t.room_type_id, t.type_name, t.description, t.max_occupancy, t.base_rate,
                COUNT(r.room_id) AS free_rooms
           FROM room_type t
           LEFT JOIN room r ON r.room_type_id = t.room_type_id
                           AND r.status <> 'Maintenance'
                           AND r.room_id NOT IN (" . OVERLAP_SQL . ")
          WHERE t.resort_id = :rid
          GROUP BY t.room_type_id, t.type_name, t.description, t.max_occupancy, t.base_rate
          ORDER BY t.base_rate"
    );
    $stmt->execute(['d_in' => $in, 'd_out' => $out, 'rid' => resort_id()]);
    return $stmt->fetchAll();
}

/** Free rooms for the stay, optionally limited to one room type. */
function available_rooms(PDO $pdo, string $in, string $out, ?int $typeId = null): array
{
    $sql = "SELECT r.room_id, r.room_number, r.floor, t.type_name, t.max_occupancy, t.base_rate
              FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id
             WHERE r.status <> 'Maintenance' AND r.resort_id = :rid
               AND r.room_id NOT IN (" . OVERLAP_SQL . ")";
    $params = ['d_in' => $in, 'd_out' => $out, 'rid' => resort_id()];
    if ($typeId !== null) {
        $sql .= ' AND r.room_type_id = :type';
        $params['type'] = $typeId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY t.base_rate, r.room_number');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rooms_needed(int $guests, int $maxOccupancy): int
{
    return max(1, (int)ceil($guests / max(1, $maxOccupancy)));
}

function new_booking_ref(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $check = $pdo->prepare('SELECT COUNT(*) FROM reservation WHERE booking_ref = ?');
    do {
        $ref = '';
        for ($i = 0; $i < 8; $i++) {
            $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $check->execute([$ref]);
    } while ((int)$check->fetchColumn() > 0);
    return $ref;
}

// ---------- Whole resort / whole building / whole pool ----------
const BOOKING_SCOPES = ['Rooms' => 'Rooms', 'Building' => 'Entire building', 'Pool' => 'Entire pool', 'Resort' => 'Entire resort'];

/** Room ids and pool ids that are already taken for the stay (any resort: ids are unique). */
function busy_ids(PDO $pdo, string $in, string $out): array
{
    $p = ['d_in' => $in, 'd_out' => $out];
    $stmt = $pdo->prepare(OVERLAP_SQL);
    $stmt->execute($p);
    $rooms = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = $pdo->prepare("SELECT rp.pool_id FROM reservation_pool rp JOIN reservation v ON v.reservation_id = rp.reservation_id
                            WHERE v.status <> 'Cancelled' AND v.check_in_date < :d_out AND v.check_out_date > :d_in");
    $stmt->execute($p);
    return ['rooms' => $rooms, 'pools' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))];
}

/**
 * What a whole-resort, whole-building or whole-pool booking would take, and whether it is free.
 * Returns ['scope','id','name','rooms'=>[room rows],'pools'=>[pool rows],'rate'(per night),'capacity','free'(bool),'why'(string)]
 * or null when the target does not exist in this resort.
 */
function exclusive_target(PDO $pdo, string $scope, int $id, string $in, string $out): ?array
{
    $rid = resort_id();
    $busy = busy_ids($pdo, $in, $out);
    $roomStmt = fn(string $extra, array $args) => (function () use ($pdo, $rid, $extra, $args) {
        $st = $pdo->prepare("SELECT r.room_id, r.room_number, t.max_occupancy, t.base_rate FROM room r
                               JOIN room_type t ON t.room_type_id = r.room_type_id
                              WHERE r.resort_id = ? AND r.status <> 'Maintenance' $extra ORDER BY r.room_id");
        $st->execute(array_merge([$rid], $args));
        return $st->fetchAll();
    })();
    $poolStmt = function (string $extra, array $args) use ($pdo, $rid) {
        $st = $pdo->prepare("SELECT * FROM pool WHERE resort_id = ? $extra ORDER BY pool_id");
        $st->execute(array_merge([$rid], $args));
        return $st->fetchAll();
    };
    // Rooms under maintenance are not sold or counted, but a whole-building/resort booking still holds them so nobody else can book them mid-stay.
    $holdStmt = function (string $extra, array $args) use ($pdo, $rid) {
        $st = $pdo->prepare("SELECT room_id FROM room WHERE resort_id = ? AND status = 'Maintenance' $extra ORDER BY room_id");
        $st->execute(array_merge([$rid], $args));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    $t = ['scope' => $scope, 'id' => $id, 'name' => '', 'rooms' => [], 'hold' => [], 'pools' => [], 'rate' => 0.0, 'capacity' => 0, 'free' => false, 'why' => '', 'info' => ''];

    if ($scope === 'Building') {
        $st = $pdo->prepare('SELECT * FROM building WHERE building_id = ? AND resort_id = ?');
        $st->execute([$id, $rid]);
        $b = $st->fetch();
        if (!$b) { return null; }
        $t['name']  = $b['name'];
        $t['info']  = (string)$b['description'];
        $t['rooms'] = $roomStmt('AND r.building_id = ?', [$id]);
        $t['hold']  = $holdStmt('AND building_id = ?', [$id]);
        $t['rate']  = (float)($b['exclusive_rate'] ?? 0);
        if ($t['rate'] <= 0) { $t['rate'] = (float)array_sum(array_column($t['rooms'], 'base_rate')); }
    } elseif ($scope === 'Pool') {
        $rows = $poolStmt('AND pool_id = ?', [$id]);
        if (!$rows || (float)($rows[0]['rate_per_day'] ?? 0) <= 0) { return null; }   // no price = the pool isn't offered whole
        $t['name']     = $rows[0]['name'];
        $t['info']     = (string)$rows[0]['description'];
        $t['pools']    = $rows;
        $t['rate']     = (float)$rows[0]['rate_per_day'];
        $t['capacity'] = (int)$rows[0]['max_guests'];
    } elseif ($scope === 'Resort') {
        $st = $pdo->prepare('SELECT * FROM resort WHERE resort_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch();
        if (!$r) { return null; }
        $t['name']  = $r['name'];
        $t['rooms'] = $roomStmt('', []);
        $t['hold']  = $holdStmt('', []);
        $t['pools'] = $poolStmt('', []);
        $t['rate']  = (float)($r['exclusive_rate'] ?? 0);
        if ($t['rate'] <= 0) { $t['rate'] = (float)array_sum(array_column($t['rooms'], 'base_rate')); }
        $t['info']  = 'Every room, cottage and pool, for your group only.';
    } else {
        return null;
    }
    if ($scope !== 'Pool') {
        $t['capacity'] = (int)array_sum(array_column($t['rooms'], 'max_occupancy'));
    }
    if ($scope !== 'Pool' && !$t['rooms']) {
        $t['why'] = 'Nothing here can be booked right now.';
        return $t;
    }
    $roomBusy = array_intersect(array_column($t['rooms'], 'room_id'), $busy['rooms']);
    $poolBusy = array_intersect(array_column($t['pools'], 'pool_id'), $busy['pools']);
    if ($scope === 'Pool' && $poolBusy) {
        $t['why'] = 'Already booked for those dates.';
    } elseif ($roomBusy || $poolBusy) {
        $t['why'] = $scope === 'Resort'
            ? 'Some rooms or pools are already booked for those dates.'
            : 'Some rooms in it are already booked for those dates.';
    } else {
        $t['free'] = true;
    }
    return $t;
}

/** Every whole-resort / whole-building / whole-pool option for the stay, for the booking pages. */
function exclusive_options(PDO $pdo, string $in, string $out): array
{
    $rid = resort_id();
    $opts = ['Resort' => null, 'Building' => [], 'Pool' => []];
    $opts['Resort'] = exclusive_target($pdo, 'Resort', $rid, $in, $out);
    $st = $pdo->prepare('SELECT building_id FROM building WHERE resort_id = ? ORDER BY name');
    $st->execute([$rid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $t = exclusive_target($pdo, 'Building', (int)$bid, $in, $out);
        if ($t && $t['rooms']) { $opts['Building'][] = $t; }
    }
    $st = $pdo->prepare('SELECT pool_id FROM pool WHERE resort_id = ? ORDER BY name');
    $st->execute([$rid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $t = exclusive_target($pdo, 'Pool', (int)$pid, $in, $out);
        if ($t) { $opts['Pool'][] = $t; }
    }
    return $opts;
}

/** Write the room and pool lines of an exclusive booking. A flat nightly price is split across the rooms (cents balanced). */
function save_exclusive_lines(PDO $pdo, int $reservationId, array $t): void
{
    if ($t['rooms']) {
        $n = count($t['rooms']);
        $each = floor($t['rate'] / $n * 100) / 100;
        $last = round($t['rate'] - $each * ($n - 1), 2);
        $ins = $pdo->prepare('INSERT INTO reservation_room (reservation_id, room_id, rate_applied) VALUES (?, ?, ?)');
        foreach ($t['rooms'] as $i => $room) {
            $ins->execute([$reservationId, $room['room_id'], $i === $n - 1 ? $last : $each]);
        }
    }
    foreach ($t['hold'] ?? [] as $roomId) {
        $pdo->prepare('INSERT INTO reservation_room (reservation_id, room_id, rate_applied) VALUES (?, ?, 0)')->execute([$reservationId, $roomId]);
    }
    if ($t['pools']) {
        $ins = $pdo->prepare('INSERT INTO reservation_pool (reservation_id, pool_id, rate_applied) VALUES (?, ?, ?)');
        foreach ($t['pools'] as $pool) {
            // A pool booking is priced by the pool; inside a resort booking the pool is included.
            $ins->execute([$reservationId, $pool['pool_id'], $t['scope'] === 'Pool' ? $t['rate'] : 0]);
        }
    }
}

/** Short text for what a reservation covers: "Entire resort", "Entire building: Main Building", "Entire pool: Main Pool", or the room lines. */
function scope_label(array $res): string
{
    $s = (string)($res['booking_scope'] ?? 'Rooms');
    return match ($s) {
        'Resort'   => 'Entire resort',
        'Building' => 'Entire building' . (!empty($res['building_name']) ? ': ' . $res['building_name'] : ''),
        'Pool'     => 'Entire pool' . (!empty($res['pool_name']) ? ': ' . $res['pool_name'] : ''),
        default    => 'Rooms',
    };
}

// ---------- Other guests staying on a reservation ----------
/** Guest types and the age range allowed for each (Philippine senior citizens are 60+). */
const GUEST_TYPES = ['Adult' => [18, 59], 'Senior' => [60, 120], 'Child' => [0, 17]];

/** The booker is an adult or a senior, never a child. Anything else becomes 'Adult'. */
function clean_booker_type(mixed $value): string
{
    return in_array($value, ['Adult', 'Senior'], true) ? $value : 'Adult';
}

/** Default type of each person besides the booker: adults-1 adults, then the children. */
function companion_slots(int $adults, int $children): array
{
    $slots = [];
    for ($i = 2; $i <= $adults; $i++) {
        $slots[] = 'Adult';
    }
    for ($i = 1; $i <= $children; $i++) {
        $slots[] = 'Child';
    }
    return $slots;
}

/**
 * Read and validate the other guests from posted form data (comp_type[], comp_first[], comp_last[], comp_age[]).
 * The guest chooses each person's type (Adult, Senior or Child); the age must fit the type.
 * With $required every slot must be filled in; otherwise empty rows are skipped.
 * Returns [['type', 'first_name', 'last_name', 'age'], ...]. Throws RuntimeException.
 */
function parse_companions(array $post, int $adults, int $children, bool $required = true): array
{
    $slots = companion_slots($adults, $children);
    $types = (array)($post['comp_type'] ?? []);
    $first = (array)($post['comp_first'] ?? []);
    $last  = (array)($post['comp_last'] ?? []);
    $age   = (array)($post['comp_age'] ?? []);
    $out   = [];
    foreach ($slots as $i => $defaultType) {
        $label = 'Guest ' . ($i + 2);
        $type  = (string)($types[$i] ?? $defaultType);
        if (!isset(GUEST_TYPES[$type])) {
            throw new RuntimeException("Choose adult, senior or child for $label.");
        }
        $f = trim((string)($first[$i] ?? ''));
        $l = trim((string)($last[$i] ?? ''));
        $a = trim((string)($age[$i] ?? ''));
        if (!$required && $f === '' && $l === '' && $a === '') {
            continue;
        }
        if ($f === '' || $l === '' || mb_strlen($f) > 60 || mb_strlen($l) > 60) {
            throw new RuntimeException("Enter the first and last name of $label.");
        }
        if (!ctype_digit($a)) {
            throw new RuntimeException("Enter the age of $label.");
        }
        $n = (int)$a;
        [$min, $max] = GUEST_TYPES[$type];
        if ($n < $min || $n > $max) {
            $range = $type === 'Senior' ? '60 or older' : ($type === 'Child' ? '17 or younger' : 'between 18 and 59');
            throw new RuntimeException("$label is marked as " . strtolower($type) . ", so the age must be $range. Change the type or the age.");
        }
        $out[] = ['type' => $type, 'first_name' => $f, 'last_name' => $l, 'age' => $n];
    }
    return $out;
}

/** How many of each type are in the party: ['Adult' => n, 'Senior' => n, 'Child' => n]. */
function type_counts(string $bookerType, array $companions): array
{
    $c = ['Adult' => 0, 'Senior' => 0, 'Child' => 0];
    $c[clean_booker_type($bookerType)]++;
    foreach ($companions as $p) {
        if (isset($c[$p['type'] ?? $p['guest_type'] ?? ''])) {
            $c[$p['type'] ?? $p['guest_type']]++;
        }
    }
    return $c;
}

/**
 * num_adults / num_children for the reservation. Seniors count as adults. When every person was
 * entered the counts come from the chosen types (the total never changes); otherwise keep the
 * counts that were asked for.
 */
function party_counts(string $bookerType, array $companions, int $adults, int $children): array
{
    if (count($companions) !== $adults + $children - 1) {
        return [$adults, $children];
    }
    $c = type_counts($bookerType, $companions);
    return [$c['Adult'] + $c['Senior'], $c['Child']];
}

/** Party text for a reservation row ($res needs num_adults, num_children, num_guests, booker_type) and its companions. */
function party_text(array $res, array $companions): string
{
    if (count($companions) + 1 === (int)$res['num_guests']) {
        return party_summary(type_counts((string)($res['booker_type'] ?? 'Adult'), $companions));
    }
    return party_summary(['Adult' => (int)$res['num_adults'], 'Senior' => 0, 'Child' => (int)$res['num_children']]);
}

/** "2 adults, 1 senior, 1 child" */
function party_summary(array $counts): string
{
    $parts = [];
    foreach (['Adult' => ['adult', 'adults'], 'Senior' => ['senior', 'seniors'], 'Child' => ['child', 'children']] as $t => [$one, $many]) {
        if ($counts[$t] > 0) {
            $parts[] = plural($counts[$t], $one, $many);
        }
    }
    return implode(', ', $parts);
}

function save_companions(PDO $pdo, int $reservationId, array $list): void
{
    $ins = $pdo->prepare(
        'INSERT INTO reservation_companion (reservation_id, guest_type, first_name, last_name, age) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($list as $c) {
        $ins->execute([$reservationId, $c['type'], $c['first_name'], $c['last_name'], $c['age']]);
    }
}

function load_companions(PDO $pdo, int $reservationId): array
{
    try {
        $stmt = $pdo->prepare('SELECT guest_type, first_name, last_name, age FROM reservation_companion
                                WHERE reservation_id = ? ORDER BY companion_id');
        $stmt->execute([$reservationId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];   // table not created yet (run database/upgrade_companions.sql)
    }
}

/** Turn parsed companions back into form values (used to refill the form). */
function companion_values(array $companions): array
{
    return array_map(fn($c) => ['type' => $c['type'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'age' => $c['age']], $companions);
}

/** "I am an: Adult / Senior citizen" choice for the person making the booking. */
function booker_type_field(string $value = 'Adult'): string
{
    $value = clean_booker_type($value);
    return '<label><span>You are</span><select name="booker_type">'
         . '<option value="Adult"' . ($value === 'Adult' ? ' selected' : '') . '>Adult (18 to 59)</option>'
         . '<option value="Senior"' . ($value === 'Senior' ? ' selected' : '') . '>Senior citizen (60+)</option>'
         . '</select></label>';
}

/**
 * Form rows for the other guests: for each person a type (adult, senior or child), first name, last name and age.
 * $values = previously entered rows (type, first_name, last_name, age), in slot order.
 */
function companion_fields(int $adults, int $children, array $values = [], bool $required = true): string
{
    $slots = companion_slots($adults, $children);
    if (!$slots) {
        return '';
    }
    $html = '<h2>Other guests <small class="muted">(' . count($slots) . ' more)</small></h2>'
          . '<p class="muted">' . ($required
                ? 'Choose adult, senior or child for each person, then give their name and age.'
                : 'Optional: type, name and age of everyone staying.') . '</p>'
          . '<div class="companions">';
    $req = $required ? ' required' : '';
    foreach ($slots as $i => $defaultType) {
        $v    = $values[$i] ?? [];
        $type = isset(GUEST_TYPES[$v['type'] ?? '']) ? $v['type'] : $defaultType;
        [$min, $max] = GUEST_TYPES[$type];
        $opts = '';
        foreach (GUEST_TYPES as $t => [$lo, $hi]) {
            $opts .= '<option value="' . $t . '" data-min="' . $lo . '" data-max="' . $hi . '"' . ($t === $type ? ' selected' : '') . '>'
                   . ($t === 'Senior' ? 'Senior citizen (60+)' : ($t === 'Child' ? 'Child (17 or younger)' : 'Adult (18 to 59)')) . '</option>';
        }
        $html .= '<fieldset class="companion"><legend>Guest ' . ($i + 2) . '</legend><div class="grid-form">'
              . '<label><span>Type</span><select name="comp_type[' . $i . ']" class="comp-type">' . $opts . '</select></label>'
              . '<label><span>First name</span><input name="comp_first[' . $i . ']" value="' . h($v['first_name'] ?? '') . '" maxlength="60"' . $req . '></label>'
              . '<label><span>Last name</span><input name="comp_last[' . $i . ']" value="' . h($v['last_name'] ?? '') . '" maxlength="60"' . $req . '></label>'
              . '<label><span>Age</span><input type="number" class="comp-age" name="comp_age[' . $i . ']" value="' . h($v['age'] ?? '') . '" min="' . $min . '" max="' . $max . '" step="1"' . $req . '></label>'
              . '</div></fieldset>';
    }
    // Keep the age limits in step with the chosen type.
    $html .= '</div><script>(function(){document.querySelectorAll(".companion").forEach(function(f){'
           . 'var t=f.querySelector(".comp-type"),a=f.querySelector(".comp-age");'
           . 'function sync(){var o=t.options[t.selectedIndex];a.min=o.dataset.min;a.max=o.dataset.max;}'
           . 't.addEventListener("change",sync);sync();});})();</script>';
    return $html;
}

/**
 * Create an online booking: picks free rooms of the chosen type automatically.
 * $d keys: type_id, rooms, check_in, check_out, adults, children, first_name, last_name,
 *          email, phone, address, requests, services (service_id => qty)
 * Returns ['id' => int, 'ref' => string]. Throws RuntimeException with a guest-friendly message.
 */
function create_online_booking(PDO $pdo, array $d): array
{
    global $config;

    $scope = (string)($d['scope'] ?? 'Rooms');
    if (!isset(BOOKING_SCOPES[$scope])) {
        throw new RuntimeException('Choose what you want to book.');
    }
    $exclusive = $scope !== 'Rooms';
    $rooms  = (int)($d['rooms'] ?? 1);
    $guests = $d['adults'] + $d['children'];
    $target = null;
    $buildingId = null;
    if (!$exclusive && ($rooms < 1 || $rooms > $config['max_rooms_per_booking'])) {
        throw new RuntimeException('You can book 1 to ' . (int)$config['max_rooms_per_booking'] . ' rooms at a time.');
    }
    $errors = validate_stay($d['check_in'], $d['check_out'], $d['adults'], $d['children'], $exclusive ? 5000 : null);
    if ($errors) {
        throw new RuntimeException($errors[0]);
    }

    try {
        // READ COMMITTED: after waiting for a lock we must see what the other booking just saved.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();

        if ($exclusive) {
            // Lock every room (and pool) this booking touches, in a fixed order, then check again.
            $rid = resort_id();
            $targetId = (int)($d['target_id'] ?? 0);
            $pre = exclusive_target($pdo, $scope, $targetId, $d['check_in'], $d['check_out']);
            if (!$pre) {
                throw new RuntimeException('That option isn’t available any more. Please search again.');
            }
            if ($pre['rooms']) {
                $ids = array_merge(array_column($pre['rooms'], 'room_id'), $pre['hold'] ?? []);
                $pdo->query('SELECT room_id FROM room WHERE room_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY room_id FOR UPDATE')->fetchAll();
            }
            if ($pre['pools']) {
                $ids = array_column($pre['pools'], 'pool_id');
                $pdo->query('SELECT pool_id FROM pool WHERE pool_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY pool_id FOR UPDATE')->fetchAll();
            }
            $target = exclusive_target($pdo, $scope, $targetId, $d['check_in'], $d['check_out']);
            if (!$target || !$target['free']) {
                throw new RuntimeException('Sorry, that was just taken for your dates. Please search again.');
            }
            $errors = validate_stay($d['check_in'], $d['check_out'], $d['adults'], $d['children'], $target['capacity']);
            if ($errors) {
                throw new RuntimeException($errors[0]);
            }
            $buildingId = $scope === 'Building' ? $targetId : null;
        } else {
            $stmt = $pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ? AND resort_id = ?');
            $stmt->execute([$d['type_id'], resort_id()]);
            $type = $stmt->fetch();
            if (!$type) {
                throw new RuntimeException('That room type isn’t available any more. Please search again.');
            }
            if ($rooms * (int)$type['max_occupancy'] < $guests) {
                throw new RuntimeException('Your group of ' . $guests . ' needs more space. Add rooms or choose a bigger room type.');
            }

            // Lock this type's rooms so two guests can't take the same room at once.
            $lock = $pdo->prepare("SELECT room_id FROM room WHERE room_type_id = ? AND status <> 'Maintenance' ORDER BY room_id FOR UPDATE");
            $lock->execute([$d['type_id']]);
            $lock->fetchAll();
            $free = available_rooms($pdo, $d['check_in'], $d['check_out'], (int)$d['type_id']);
            if (count($free) < $rooms) {
                throw new RuntimeException(count($free) === 0
                    ? 'Sorry, that room type was just taken for your dates. Please search again.'
                    : 'Only ' . plural(count($free), 'room') . ' of that type left for your dates. Lower the number of rooms or search again.');
            }
        }

        // Guest record: reuse by email, fill in blanks.
        $requests = trim((string)($d['requests'] ?? ''));
        $stmt = $pdo->prepare('SELECT guest_id, first_name, last_name, phone, address FROM guest WHERE email = ?');
        $stmt->execute([$d['email']]);
        $guest = $stmt->fetch();
        if ($guest) {
            $guestId = (int)$guest['guest_id'];
            // Requests nobody has confirmed yet hold rooms: stop one email from tying up the whole resort.
            $open = $pdo->prepare("SELECT COUNT(*) FROM reservation WHERE guest_id = ? AND resort_id = ? AND source = 'Online' AND status = 'Pending' AND check_out_date >= ?");
            $open->execute([$guestId, resort_id(), today()]);
            if ((int)$open->fetchColumn() >= 5) {
                throw new RuntimeException('You already have 5 booking requests waiting for confirmation. Please wait for the resort to confirm them, or cancel one first.');
            }
            // Same email, different name (for example a family sharing one address): keep the name on the booking.
            if (mb_strtolower($guest['first_name'] . ' ' . $guest['last_name']) !== mb_strtolower($d['first_name'] . ' ' . $d['last_name'])) {
                $requests = mb_substr('Booked under the name ' . $d['first_name'] . ' ' . $d['last_name'] . '. ' . $requests, 0, 500);
            }
            if (empty($guest['phone']) || empty($guest['address'])) {
                $pdo->prepare('UPDATE guest SET phone = COALESCE(phone, ?), address = COALESCE(address, ?) WHERE guest_id = ?')
                    ->execute([$d['phone'] ?: null, $d['address'] ?: null, $guestId]);
            }
        } else {
            $pdo->prepare('INSERT INTO guest (first_name, last_name, email, phone, address) VALUES (?, ?, ?, ?, ?)')
                ->execute([$d['first_name'], $d['last_name'], $d['email'], $d['phone'] ?: null, $d['address'] ?: null]);
            $guestId = (int)$pdo->lastInsertId();
        }

        $ref = new_booking_ref($pdo);
        $bookerType = clean_booker_type($d['booker_type'] ?? 'Adult');
        [$numAdults, $numChildren] = party_counts($bookerType, $d['companions'] ?? [], $d['adults'], $d['children']);
        $pdo->prepare(
            "INSERT INTO reservation (resort_id, booking_ref, guest_id, staff_id, booking_date, check_in_date, check_out_date,
                                      num_adults, num_children, booker_type, special_requests, source, status, booking_scope, building_id)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Online', 'Pending', ?, ?)"
        )->execute([resort_id(), $ref, $guestId, today(), $d['check_in'], $d['check_out'], $numAdults, $numChildren, $bookerType, $requests ?: null, $scope, $buildingId]);
        $reservationId = (int)$pdo->lastInsertId();

        if ($exclusive) {
            save_exclusive_lines($pdo, $reservationId, $target);
        } else {
            $ins = $pdo->prepare('INSERT INTO reservation_room (reservation_id, room_id, rate_applied) VALUES (?, ?, ?)');
            foreach (array_slice($free, 0, $rooms) as $room) {
                $ins->execute([$reservationId, $room['room_id'], $type['base_rate']]);
            }
        }

        $price = $pdo->prepare('SELECT unit_price FROM service WHERE service_id = ? AND resort_id = ' . resort_id());
        $insSvc = $pdo->prepare(
            'INSERT INTO reservation_service (reservation_id, service_id, service_date, quantity, price_charged) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($d['services'] as $serviceId => $qty) {
            $qty = (int)$qty;
            if ($qty < 1) {
                continue;
            }
            $price->execute([(int)$serviceId]);
            $unit = $price->fetchColumn();
            if ($unit === false) {
                continue;
            }
            $insSvc->execute([$reservationId, (int)$serviceId, $d['check_in'], $qty, (float)$unit * $qty]);
        }

        save_companions($pdo, $reservationId, $exclusive ? [] : ($d['companions'] ?? []));
        recalc_total($pdo, $reservationId);
        $pdo->commit();
        return ['id' => $reservationId, 'ref' => $ref];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException('Something went wrong while saving your booking. Please try again.');
    }
}
