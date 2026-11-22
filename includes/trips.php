<?php
declare(strict_types=1);

require_once __DIR__ . '/trip_basics.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/notify.php';

/**
 * A TRIP: stays a guest has picked, possibly at several resorts, to be booked in ONE checkout.
 *
 * How it works
 *  - "Add to trip" saves only the choice (resort, room type or whole place, dates, group) in the session.
 *    Prices and availability are never stored: they are looked up again every time the trip is shown or booked.
 *  - One checkout asks for the guest's details once and (for guests without an account) one email code.
 *  - Each stay is still its own booking at its own resort, with its own reference, so every resort's staff only
 *    ever see their own bookings. The stays of one checkout share a trip code.
 *  - All stays are saved in ONE database transaction: either the whole trip is booked or nothing is.
 */

const TRIP_MAX_STAYS = 8;

// ---------- Database support ----------
/** True once database/upgrade_trips.sql has been run (the reservation.trip_code column exists). */
function trips_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$pdo->query("SHOW COLUMNS FROM reservation LIKE 'trip_code'")->fetch();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Turn untrusted form input (the same fields checkout.php takes) into a trip item. */
function trip_item_from_input(array $in): array
{
    $v     = fn(string $k): string => is_scalar($in[$k] ?? null) ? (string)$in[$k] : '';   // a form field sent as an array is ignored
    $scope = $v('scope') !== '' ? $v('scope') : 'Rooms';
    $scope = isset(BOOKING_SCOPES[$scope]) ? $scope : 'Rooms';
    $isEx  = $scope !== 'Rooms';
    $ci    = $v('check_in');
    $co    = $v('check_out');
    if ($scope === 'Cottage' && valid_date($ci) && $co === '') {
        $co = add_days($ci, 1);
    }
    return [
        'id'        => bin2hex(random_bytes(4)),
        'slug'      => substr($v('r'), 0, 40),
        'scope'     => $scope,
        'target'    => $isEx ? (int)$v('target') : 0,
        'type'      => $isEx ? 0 : (int)$v('type'),
        'rooms'     => $isEx ? 1 : max(1, (int)$v('rooms')),
        'check_in'  => $ci,
        'check_out' => $co,
        'adults'    => (int)$v('adults'),
        'children'  => (int)$v('children'),
    ];
}

// ---------- Working inside one resort at a time ----------
/**
 * Every booking function reads "the current resort" from globals. Run $fn as if this resort's page were open,
 * then put things back, so one request can work with several resorts.
 */
function with_resort(array $resortRow, callable $fn): mixed
{
    global $config, $resort;
    $savedConfig = $config;
    $savedResort = $resort;
    try {
        resort_apply($resortRow);
        return $fn();
    } finally {
        $config = $savedConfig;
        $resort = $savedResort;
    }
}

// ---------- Live check of one stay ----------
/**
 * Look a trip item up again: is it still bookable, what does it cost now?
 * Returns the item plus: ok, why (reason when not ok), resort (row), label, nights, rooms_n, total, photo, capacity, free.
 */
function trip_resolve(PDO $pdo, array $item): array
{
    $base = $item + [
        'ok' => false, 'why' => '', 'resort' => null, 'label' => '', 'nights' => 0, 'rooms_n' => 1,
        'total' => 0.0, 'photo' => '', 'capacity' => 0, 'free' => 0,
    ];
    $r = $item['slug'] !== '' ? resort_find($pdo, $item['slug']) : null;
    if (!$r) {
        $base['why'] = 'This resort isn’t available any more.';
        return $base;
    }
    $base['resort'] = $r;

    return with_resort($r, function () use ($pdo, $item, $base): array {
        global $config;
        $out      = $base;
        $in       = $item['check_in'];
        $to       = $item['check_out'];
        $adults   = $item['adults'];
        $children = $item['children'];
        $guests    = $adults + $children;
        $isCottage = $item['scope'] === 'Cottage';
        $isEx      = in_array($item['scope'], ['Building', 'Pool', 'Resort'], true);
        if ($isCottage && valid_date($in)) {
            $to = add_days($in, 1);
            $out['check_out'] = $to;
        }

        $problems = validate_stay($in, $to, $adults, $children, ($isEx || $isCottage) ? 5000 : null);
        if ($problems) {
            $out['why'] = $problems[0];
            return $out;
        }
        $n = nights($in, $to);
        $out['nights'] = $n;

        if ($isCottage) {
            $t = cottage_target($pdo, $item['target'], $in);
            if (!$t) {
                $out['why'] = 'That cottage isn’t available any more.';
                return $out;
            }
            $ent             = cottage_entrance_total($pdo, resort_id(), $adults, $children);
            $out['label']    = $t['name'] . ' × 1';
            $out['capacity'] = (int)$t['capacity'];
            $out['free']     = (int)$t['free_qty'];
            $out['total']    = round((float)$t['rate'] + $ent['total'], 2);
            $photos          = room_photos($t['name']);
            $out['photo']    = $photos ? $photos[0]['url'] : resort_hero();
            if (!$t['free']) {
                $out['why'] = $t['why'] ?: 'Fully booked on this date.';
                return $out;
            }
            $problems = validate_stay($in, $to, $adults, $children, (int)$t['capacity']);
            if ($problems) {
                $out['why'] = $problems[0];
                return $out;
            }
        } elseif ($isEx) {
            $t = exclusive_target($pdo, $item['scope'], $item['target'], $in, $to);
            if (!$t) {
                $out['why'] = 'That option isn’t available any more.';
                return $out;
            }
            $out['label']    = scope_label(['booking_scope' => $item['scope'], 'building_name' => $t['name'], 'pool_name' => $t['name']]);
            $out['capacity'] = (int)$t['capacity'];
            $out['total']    = (float)$t['rate'] * $n;
            $out['photo']    = resort_hero();
            if (!$t['free']) {
                $out['why'] = $t['why'] ?: 'Not available for those dates.';
                return $out;
            }
            $problems = validate_stay($in, $to, $adults, $children, (int)$t['capacity']);
            if ($problems) {
                $out['why'] = $problems[0];
                return $out;
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ? AND resort_id = ?');
            $stmt->execute([$item['type'], resort_id()]);
            $type = $stmt->fetch();
            if (!$type) {
                $out['why'] = 'That room type isn’t available any more.';
                return $out;
            }
            $need    = rooms_needed($guests, (int)$type['max_occupancy']);
            $free    = count(available_rooms($pdo, $in, $to, $item['type']));
            $maxPick = min($free, (int)$config['max_rooms_per_booking']);
            $out['free']     = $free;
            $out['capacity'] = (int)$type['max_occupancy'];
            $out['rooms_n']  = min(max($item['rooms'], $need), max($maxPick, 1));
            $out['label']    = $type['type_name'] . ' × ' . $out['rooms_n'];
            $out['total']    = (float)$type['base_rate'] * $n * $out['rooms_n'];
            $photos          = room_photos($type['type_name']);
            $out['photo']    = $photos ? $photos[0]['url'] : resort_hero();
            if ($free === 0 || $need > $maxPick) {
                $out['why'] = 'That room type is no longer available for your dates.';
                return $out;
            }
        }
        $out['ok'] = true;
        return $out;
    });
}

/**
 * Two stays in the same trip can each be free on their own and still clash together
 * (the last room of a type, or a whole building plus a room in it). Mark the later one.
 */
function trip_apply_conflicts(array $rows): array
{
    for ($j = 1, $n = count($rows); $j < $n; $j++) {
        if (!$rows[$j]['ok']) {
            continue;
        }
        for ($i = 0; $i < $j; $i++) {
            $a = $rows[$i];
            $b = $rows[$j];
            if (!$a['ok'] || $a['slug'] !== $b['slug'] || !($a['check_in'] < $b['check_out'] && $b['check_in'] < $a['check_out'])) {
                continue;
            }
            $clash = $a['scope'] === 'Resort' || $b['scope'] === 'Resort'
                || ($a['scope'] === $b['scope'] && in_array($a['scope'], ['Building', 'Pool'], true) && $a['target'] === $b['target']);
            if ($clash) {
                $rows[$j]['ok']  = false;
                $rows[$j]['why'] = 'This overlaps another stay at the same resort in your trip.';
                break;
            }
            if ($a['scope'] === 'Cottage' && $b['scope'] === 'Cottage' && $a['target'] === $b['target']) {
                $used = 1;
                for ($k = 0; $k < $j; $k++) {
                    if ($rows[$k]['ok'] && $rows[$k]['slug'] === $b['slug'] && $rows[$k]['scope'] === 'Cottage'
                        && $rows[$k]['target'] === $b['target']
                        && $rows[$k]['check_in'] < $b['check_out'] && $b['check_in'] < $rows[$k]['check_out']) {
                        $used++;
                    }
                }
                if ($used > (int)$b['free']) {
                    $rows[$j]['ok']  = false;
                    $rows[$j]['why'] = 'Not enough of this cottage left on that date for all stays in your trip.';
                    break;
                }
            }
            if ($a['scope'] === 'Rooms' && $b['scope'] === 'Rooms' && $a['type'] === $b['type']
                && $a['rooms_n'] + $b['rooms_n'] > min($a['free'], $b['free'])) {
                $rows[$j]['ok']  = false;
                $rows[$j]['why'] = 'There aren’t enough of this room type left for both stays in your trip.';
                break;
            }
        }
    }
    return $rows;
}

/** Every stay in the trip, checked live. */
function trip_resolve_all(PDO $pdo): array
{
    return trip_apply_conflicts(array_map(fn($i) => trip_resolve($pdo, $i), trip_items()));
}

/** Add a stay. Returns [true|false, message]. */
function trip_add(PDO $pdo, array $item): array
{
    $items = trip_items();
    if (count($items) >= TRIP_MAX_STAYS) {
        return [false, 'A trip holds up to ' . TRIP_MAX_STAYS . ' stays. Book these first, or remove one.'];
    }
    $key = fn(array $i): string => implode('|', [$i['slug'], $i['scope'], $i['target'], $i['type'], $i['rooms'], $i['check_in'], $i['check_out'], $i['adults'], $i['children']]);
    foreach ($items as $it) {
        if ($key($it) === $key($item)) {
            return [false, 'That stay is already in your trip.'];
        }
    }
    $rows = trip_apply_conflicts(array_map(fn($i) => trip_resolve($pdo, $i), array_merge($items, [$item])));
    $new  = end($rows);
    if (!$new['ok']) {
        return [false, $new['why']];
    }
    $item['rooms'] = $new['rooms_n'];   // keep the number of rooms the group really needs
    $items[] = $item;
    trip_save($items);
    return [true, 'Added to your trip.'];
}

// ---------- Booking the whole trip ----------
/** A code that no booking reference and no other trip is using. */
function new_trip_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $byRef  = $pdo->prepare('SELECT COUNT(*) FROM reservation WHERE booking_ref = ?');
    $byTrip = trips_ready($pdo) ? $pdo->prepare('SELECT COUNT(*) FROM reservation WHERE trip_code = ?') : null;
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $byRef->execute([$code]);
        $taken = (int)$byRef->fetchColumn() > 0;
        if (!$taken && $byTrip) {
            $byTrip->execute([$code]);
            $taken = (int)$byTrip->fetchColumn() > 0;
        }
    } while ($taken);
    return $code;
}

/**
 * Book every stay of the trip in ONE transaction: all of it, or none of it.
 *   $rows   resolved stays from trip_resolve_all(), all ok
 *   $guest  first_name, last_name, email, phone, address, booker_type
 *   $extras per stay id: companions, services (service_id => qty), requests
 * Returns ['code' => trip code, 'bookings' => [['id', 'ref'], ...]] in the same order as $rows.
 * Throws RuntimeException with a guest-friendly message (and nothing is saved).
 */
function create_trip_booking(PDO $pdo, array $rows, array $guest, array $extras): array
{
    // Always lock in the same order (resort, then what is booked) so two trips can't wait on each other forever.
    $order = array_keys($rows);
    usort($order, fn($x, $y) => [(int)$rows[$x]['resort']['resort_id'], $rows[$x]['scope'], $rows[$x]['type'], $rows[$x]['target'], $rows[$x]['check_in']]
                              <=> [(int)$rows[$y]['resort']['resort_id'], $rows[$y]['scope'], $rows[$y]['type'], $rows[$y]['target'], $rows[$y]['check_in']]);

    $made = [];
    try {
        // READ COMMITTED: after waiting for a lock we must see what the other booking just saved.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $code = new_trip_code($pdo);
        $tag  = trips_ready($pdo) ? $pdo->prepare('UPDATE reservation SET trip_code = ? WHERE reservation_id = ?') : null;

        foreach ($order as $idx) {
            $row = $rows[$idx];
            $x   = $extras[$row['id']] ?? [];
            $d = [
                'scope' => $row['scope'], 'target_id' => $row['target'], 'type_id' => $row['type'], 'rooms' => $row['rooms_n'],
                'check_in' => $row['check_in'], 'check_out' => $row['check_out'], 'adults' => $row['adults'], 'children' => $row['children'],
                'first_name' => $guest['first_name'], 'last_name' => $guest['last_name'], 'email' => $guest['email'],
                'phone' => $guest['phone'], 'address' => $guest['address'], 'booker_type' => $guest['booker_type'],
                'requests' => (string)($x['requests'] ?? ''), 'services' => (array)($x['services'] ?? []), 'companions' => (array)($x['companions'] ?? []),
            ];
            try {
                $made[$idx] = with_resort($row['resort'], fn() => create_booking_rows($pdo, $d));
            } catch (RuntimeException $e) {
                throw new RuntimeException('Nothing was booked. ' . $row['resort']['name'] . ', ' . $row['label'] . ': ' . $e->getMessage(), 0, $e);
            }
            if ($tag) {
                $tag->execute([$code, $made[$idx]['id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        error_log('[resort] trip booking failed: ' . $e);
        throw new RuntimeException('Something went wrong while saving your trip. Nothing was booked. Please try again.');
    }
    ksort($made);
    return ['code' => $code, 'bookings' => array_values($made)];
}

// ---------- Finding a booked trip again ----------
/** Booking references of every stay in a trip. */
function trip_refs(PDO $pdo, string $code): array
{
    if (trips_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT booking_ref FROM reservation WHERE trip_code = ? ORDER BY reservation_id');
        $stmt->execute([$code]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_values((array)($_SESSION['trip_done'][$code] ?? []));   // before the upgrade: only this session knows
}

/** The same, but only if the stays were booked with this email (used by "Manage booking"). */
function trip_refs_for_email(PDO $pdo, string $code, string $email): array
{
    if (!trips_ready($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT v.booking_ref FROM reservation v JOIN guest g ON g.guest_id = v.guest_id WHERE v.trip_code = ? AND g.email = ? ORDER BY v.reservation_id');
    $stmt->execute([$code, $email]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
