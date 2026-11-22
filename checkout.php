<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/accounts.php';
require_resort($pdo);
require_guest_to_book($pdo);   // reserving needs an account

$payRules       = resort_payment_rules();
$dpPct          = $payRules['downpayment_percent'];
$onlinePayOn    = $payRules['enabled'];
$needsOnlinePay = $onlinePayOn && $dpPct > 0;

$acct = current_guest($pdo);
$editRef = strtoupper(trim((string)($_POST['edit'] ?? $_GET['edit'] ?? '')));
$editRef = preg_match('/^[A-Z0-9]{6,10}$/', $editRef) ? $editRef : '';
$editRes = null;
$isEdit  = false;
$editId  = 0;

$services = $pdo->query('SELECT * FROM service WHERE resort_id = ' . resort_id() . ' AND archived_at IS NULL ORDER BY category, service_name')->fetchAll();
$form = [
    'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'requests' => '',
];
$svcOn  = [];
$svcQty = [];
$error  = '';
$compValues = [];
$bookerType = 'Adult';
$type = null;
$target = null;
$cottage = null;
$cottageEnt = ['adult_rate' => 0.0, 'child_rate' => 0.0, 'total' => 0.0];

if ($editRef !== '') {
    $eStmt = $pdo->prepare(
        "SELECT v.*, g.first_name, g.last_name, g.email, g.phone, g.address,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name
           FROM reservation v JOIN guest g ON g.guest_id = v.guest_id
          WHERE v.booking_ref = ? AND v.resort_id = ?"
    );
    $eStmt->execute([$editRef, resort_id()]);
    $editRes = $eStmt->fetch() ?: null;
    $canOwn  = $editRes && (!empty($_SESSION['grants'][$editRef]) || ($acct && (int)$acct['guest_id'] === (int)$editRes['guest_id']));
    if (!$canOwn) {
        flash('That booking could not be opened for editing.', 'error');
        redirect('booking.php?ref=' . urlencode($editRef));
    }
    $editId = (int)$editRes['reservation_id'];
    if ($editRes['status'] !== 'Pending' || amount_paid($pdo, $editId) > 0.004 || pending_payment_amount($pdo, $editId) > 0.004) {
        redirect('payment.php?ref=' . urlencode($editRef));
    }
    $_SESSION['grants'][$editRef] = true;
    $isEdit    = true;
    $scope     = (string)$editRes['booking_scope'];
    $isCottage = $scope === 'Cottage';
    $isEx      = in_array($scope, ['Building', 'Pool', 'Resort'], true);
    $in        = (string)$editRes['check_in_date'];
    $out       = (string)$editRes['check_out_date'];
    $adults    = (int)$editRes['num_adults'];
    $children  = (int)$editRes['num_children'];
    $guests    = $adults + $children;
    $targetId  = 0;
    $typeId    = 0;
    $roomsReq  = 1;

    if ($isCottage) {
        $rcStmt = $pdo->prepare(
            'SELECT rc.*, COALESCE(p.max_guests, 15) AS max_guests
               FROM reservation_cottage rc
               LEFT JOIN price_item p ON p.price_item_id = rc.price_item_id
              WHERE rc.reservation_id = ? LIMIT 1'
        );
        $rcStmt->execute([$editId]);
        $rc = $rcStmt->fetch();
        $targetId = (int)($rc['price_item_id'] ?? 0);
        $cottage = [
            'id'       => $targetId,
            'name'     => (string)($rc['cottage_name'] ?? 'Cottage'),
            'rate'     => (float)($rc['cottage_rate'] ?? 0),
            'capacity' => (int)($rc['max_guests'] ?? max(15, $guests)),
            'free'     => true,
        ];
        $cottageEnt = ['adult_rate' => 0.0, 'child_rate' => 0.0, 'total' => (float)($rc['entrance_total'] ?? 0)];
        $n          = 1;
        $roomTotal  = round($cottage['rate'] + $cottageEnt['total'], 2);
    } elseif ($isEx) {
        $n = max(1, nights($in, $out));
        $svcSumStmt = $pdo->prepare('SELECT COALESCE(SUM(price_charged), 0) FROM reservation_service WHERE reservation_id = ?');
        $svcSumStmt->execute([$editId]);
        $roomTotal = max(0.0, round((float)$editRes['total_amount'] - (float)$svcSumStmt->fetchColumn(), 2));
        $target = [
            'name'     => (string)($editRes['building_name'] ?: $editRes['pool_name'] ?: $config['resort']),
            'capacity' => max(1, $guests),
            'rate'     => round($roomTotal / $n, 2),
            'free'     => true,
        ];
    } else {
        $rrStmt = $pdo->prepare(
            'SELECT t.*, COUNT(*) AS qty, SUM(rr.rate_applied) AS nightly_sum
               FROM reservation_room rr
               JOIN room r ON r.room_id = rr.room_id
               JOIN room_type t ON t.room_type_id = r.room_type_id
              WHERE rr.reservation_id = ?
              GROUP BY t.room_type_id LIMIT 1'
        );
        $rrStmt->execute([$editId]);
        $type = $rrStmt->fetch() ?: ['room_type_id' => 0, 'type_name' => 'Room', 'base_rate' => 0, 'max_occupancy' => max(2, $guests)];
        $typeId    = (int)$type['room_type_id'];
        $roomsReq  = max(1, (int)($type['qty'] ?? 1));
        $n         = max(1, nights($in, $out));
        $roomTotal = round((float)($type['nightly_sum'] ?? 0) * $n, 2);
    }

    $form = [
        'first_name' => (string)$editRes['first_name'],
        'last_name'  => (string)$editRes['last_name'],
        'email'      => (string)$editRes['email'],
        'phone'      => (string)($editRes['phone'] ?? ''),
        'address'    => (string)($editRes['address'] ?? ''),
        'requests'   => (string)($editRes['special_requests'] ?? ''),
    ];
    $bookerType = clean_booker_type((string)($editRes['booker_type'] ?? 'Adult'));
    $existingSvcs = $pdo->prepare('SELECT service_id, quantity FROM reservation_service WHERE reservation_id = ?');
    $existingSvcs->execute([$editId]);
    foreach ($existingSvcs->fetchAll() as $es) {
        $svcOn[(int)$es['service_id']]  = 1;
        $svcQty[(int)$es['service_id']] = (int)$es['quantity'];
    }
    if (!$isCottage && !$isEx) {
        $compValues = companion_values(reservation_companions($pdo, $editId));
    }
    $hid = ['edit' => $editRef];
} else {
    $scope    = (string)($_POST['scope'] ?? $_GET['scope'] ?? 'Rooms');
    $scope    = isset(BOOKING_SCOPES[$scope]) ? $scope : 'Rooms';
    $isCottage = $scope === 'Cottage';
    $isEx     = in_array($scope, ['Building', 'Pool', 'Resort'], true);   // a whole resort, building or pool
    $targetId = (int)($_POST['target'] ?? $_GET['target'] ?? 0);
    $typeId   = (int)($_POST['type'] ?? $_GET['type'] ?? 0);
    $roomsReq = (int)($_POST['rooms'] ?? $_GET['rooms'] ?? 1);
    $in       = (string)($_POST['check_in'] ?? $_GET['check_in'] ?? '');
    $out      = (string)($_POST['check_out'] ?? $_GET['check_out'] ?? '');
    if ($isCottage && valid_date($in)) {
        $out = add_days($in, 1);
    }
    $adults   = (int)($_POST['adults'] ?? $_GET['adults'] ?? 0);
    $children = (int)($_POST['children'] ?? $_GET['children'] ?? 0);
    $guests   = $adults + $children;

    // If the guest hit browser Back from Step 2 (payment.php), seamlessly reopen their just-created unpaid booking in edit mode.
    $tryRecentEdit = function () use ($pdo, $scope, $in, $resort): void {
        $last = $_SESSION['last_booking'] ?? null;
        if (!$last || time() - (int)($last['at'] ?? 0) > 600 || empty($last['ref'])) {
            return;
        }
        $chk = $pdo->prepare("SELECT reservation_id, booking_ref FROM reservation WHERE booking_ref = ? AND resort_id = ? AND booking_scope = ? AND check_in_date = ? AND status = 'Pending'");
        $chk->execute([$last['ref'], resort_id(), $scope, $in]);
        $row = $chk->fetch();
        if ($row && amount_paid($pdo, (int)$row['reservation_id']) <= 0.004 && pending_payment_amount($pdo, (int)$row['reservation_id']) <= 0.004) {
            redirect('checkout.php?r=' . rawurlencode((string)$resort['slug']) . '&edit=' . rawurlencode($row['booking_ref']));
        }
    };

    function back_to_search(string $message, string $in, string $out, int $adults, int $children): never
    {
        global $isEx, $isCottage;
        flash($message, 'error');
        $params = ['check_in' => $in, 'adults' => max(1, $adults), 'children' => max(0, $children)];
        if ($isCottage) {
            $params['mode'] = 'cottage';
        } else {
            $params['mode'] = 'rooms';
            $params['check_out'] = $out;
        }
        redirect(ru($isEx ? 'exclusive.php' : 'search.php') . '&' . http_build_query($params));
    }

    // ---------- Validate the selection ----------
    $problems = validate_stay($in, $out, $adults, $children, ($isEx || $isCottage) ? 5000 : null);
    if ($problems) {
        back_to_search($problems[0], $in, $out, $adults, $children);
    }

    if ($isCottage) {
        $cottage = cottage_target($pdo, $targetId, $in);
        if (!$cottage || !$cottage['free']) {
            $tryRecentEdit();
            back_to_search($cottage ? ($cottage['why'] ?: 'That cottage is no longer available.') : 'Choose a cottage to book.', $in, $out, $adults, $children);
        }
        $problems = validate_stay($in, $out, $adults, $children, (int)$cottage['capacity']);
        if ($problems) {
            back_to_search($problems[0], $in, $out, $adults, $children);
        }
        $roomsReq   = 1;
        $n          = 1;
        $cottageEnt = cottage_entrance_total($pdo, resort_id(), $adults, $children);
        $roomTotal  = round($cottage['rate'] + $cottageEnt['total'], 2);
    } elseif ($isEx) {
        $target = exclusive_target($pdo, $scope, $targetId, $in, $out);
        if (!$target || !$target['free']) {
            $tryRecentEdit();
            back_to_search($target ? ($target['why'] ?: 'That is no longer available for your dates.') : 'Choose what you want to book.', $in, $out, $adults, $children);
        }
        $problems = validate_stay($in, $out, $adults, $children, (int)$target['capacity']);
        if ($problems) {
            back_to_search($problems[0], $in, $out, $adults, $children);
        }
        $roomsReq  = 1;
        $n         = nights($in, $out);
        $roomTotal = $target['rate'] * $n;
    } else {
        $stmt = $pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ? AND resort_id = ?');
        $stmt->execute([$typeId, resort_id()]);
        $type = $stmt->fetch();
        if (!$type) {
            back_to_search('Choose a room to reserve.', $in, $out, $adults, $children);
        }

        $need = rooms_needed($guests, (int)$type['max_occupancy']);
        $free = count(available_rooms($pdo, $in, $out, $typeId));
        $maxPick = min($free, (int)$config['max_rooms_per_booking']);
        if ($free === 0 || $need > $maxPick) {
            $tryRecentEdit();
            back_to_search('That room type is no longer available for your dates.', $in, $out, $adults, $children);
        }
        $roomsReq = min(max($roomsReq, $need), $maxPick);

        $n         = nights($in, $out);
        $roomTotal = (float)$type['base_rate'] * $n * $roomsReq;
    }
    $hid = ['type' => $typeId, 'rooms' => $roomsReq, 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
            'scope' => $scope, 'target' => $targetId];

    // Signed-in guests: fill in their details, and their email is already verified.
    if ($acct) {
        $form['first_name'] = $acct['first_name'];
        $form['last_name']  = $acct['last_name'];
        $form['email']      = $acct['email'];
        $form['phone']      = (string)($acct['phone'] ?? '');
        $form['address']    = (string)($acct['address'] ?? '');
    }
}

// ---------- Email verification state ----------
$verifyOn = !empty($config['verify_email']);
$sig      = implode('|', [resort_id(), $scope, $targetId, $typeId, $roomsReq, $in, $out, $adults, $children]);
$pending  = $isEdit ? null : ($_SESSION['pending_booking'] ?? null);
if ($pending && ($pending['sig'] ?? '') !== $sig) {
    unset($_SESSION['pending_booking']);   // the guest changed their selection: start over
    $pending = null;
}
$notice = '';

/** Create the booking, email the guest, and go to Step 2 (Payment) or Step 3 (Confirmed). */
function finish_booking(PDO $pdo, array $data): never
{
    global $onlinePayOn;
    // A double click or a re-sent form must not create the same booking twice: reuse the one just made in this session.
    $fp = hash('sha256', json_encode([resort_id(), $data['scope'], $data['target_id'], $data['type_id'], $data['rooms'] ?? 1, $data['check_in'],
                                      $data['check_out'], $data['adults'], $data['children'], $data['email'], $data['first_name'], $data['last_name']]));
    $last = $_SESSION['last_booking'] ?? null;
    if ($last && $last['fp'] === $fp && time() - $last['at'] < 300) {
        $same = $pdo->prepare("SELECT reservation_id, required_downpayment FROM reservation WHERE booking_ref = ? AND status <> 'Cancelled'");
        $same->execute([$last['ref']]);
        if ($existing = $same->fetch()) {
            $_SESSION['grants'][$last['ref']] = true;
            unset($_SESSION['pending_booking']);
            $goPay = $onlinePayOn && (float)$existing['required_downpayment'] > 0.004 && amount_paid($pdo, (int)$existing['reservation_id']) <= 0.004 && pending_payment_amount($pdo, (int)$existing['reservation_id']) <= 0.004;
            redirect(($goPay ? 'payment.php?ref=' : 'booking.php?ref=') . urlencode($last['ref']));
        }
    }
    $result = create_online_booking($pdo, $data);
    $_SESSION['last_booking'] = ['fp' => $fp, 'ref' => $result['ref'], 'at' => time()];
    unset($_SESSION['pending_booking']);
    $_SESSION['grants'][$result['ref']] = true;
    $_SESSION['just_booked'] = $result['ref'];
    send_booking_email($pdo, $result['id'], 'received');

    if ($onlinePayOn && ($result['required_downpayment'] ?? 0) > 0.004) {
        flash('Booking ' . $result['ref'] . ' created! Complete Step 2 below by sending your GCash or Maya payment and uploading your screenshot.');
        redirect('payment.php?ref=' . urlencode($result['ref']));
    }

    flash('Thank you. Your booking request is in.');
    redirect('booking.php?ref=' . urlencode($result['ref']));
}

/** Email a fresh code and store its hash in the session. Throws RuntimeException on failure. */
function start_verification(PDO $pdo, array $data, string $sig): void
{
    if (throttle_blocked($pdo, 'verify_send', 5)) {
        throw new RuntimeException('Too many codes requested. Please wait a few minutes and try again.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(8));
    if (!send_verification_email($data['email'], $data['first_name'], $code)) {
        throw new RuntimeException('We couldn’t send an email to that address. Check it and try again, or contact us.');
    }
    throttle_fail($pdo, 'verify_send');   // counts every send
    $_SESSION['pending_booking'] = [
        'sig' => $sig, 'data' => $data, 'salt' => $salt, 'hash' => hash('sha256', $salt . $code),
        'expires' => time() + 600, 'sent_at' => time(), 'tries' => 0,
    ];
}

// ---------- Submit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'details');

    try {
        if ($action === 'details') {
            foreach ($form as $k => $_) {
                $form[$k] = trim((string)($_POST[$k] ?? ''));
            }
            $form['address'] = (string)($acct['address'] ?? '');
            if ($acct) {
                $form['email'] = $acct['email'];   // an account's email is fixed and already verified
            }
            $svcOn  = (array)($_POST['svc_on'] ?? []);
            $svcQty = (array)($_POST['svc_qty'] ?? []);
            $cf = (array)($_POST['comp_first'] ?? []);
            $cl = (array)($_POST['comp_last'] ?? []);
            foreach (($isEx || $isCottage) ? [] : companion_slots($adults, $children) as $i => $_) {
                $compValues[$i] = ['type' => (string)(($_POST['comp_type'] ?? [])[$i] ?? ''), 'first_name' => trim((string)($cf[$i] ?? '')), 'last_name' => trim((string)($cl[$i] ?? ''))];
            }

            if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 60 || $form['last_name'] === '' || mb_strlen($form['last_name']) > 60) {
                throw new RuntimeException('Enter your first and last name.');
            }
            if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 120) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $form['phone'])) {
                throw new RuntimeException('Enter a phone number we can reach you on (digits, spaces, + and - only).');
            }
            if (mb_strlen($form['address']) > 200) {
                throw new RuntimeException('Keep the address under 200 characters.');
            }
            if (mb_strlen($form['requests']) > 500) {
                throw new RuntimeException('Special requests can be up to 500 characters.');
            }
            if (empty($_POST['terms'])) {
                throw new RuntimeException('Please accept the booking terms to continue.');
            }

            $chosen = [];
            foreach ($services as $s) {
                $sid = (int)$s['service_id'];
                if (!empty($svcOn[$sid])) {
                    $q = (int)($svcQty[$sid] ?? 1);
                    if ($q < 1 || $q > 20) {
                        throw new RuntimeException('Service quantities must be between 1 and 20.');
                    }
                    $chosen[$sid] = $q;
                }
            }

            $bookerType = clean_booker_type($_POST['booker_type'] ?? 'Adult');
            $companions = ($isEx || $isCottage) ? [] : parse_companions($_POST, $adults, $children, true);

            if ($isEdit && $editRes) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE guest SET first_name = ?, last_name = ?, phone = ? WHERE guest_id = ?')
                        ->execute([$form['first_name'], $form['last_name'], $form['phone'] ?: null, (int)$editRes['guest_id']]);
                    [$numAdults, $numChildren] = party_counts($bookerType, $companions, $adults, $children);
                    $pdo->prepare('UPDATE reservation SET num_adults = ?, num_children = ?, booker_type = ?, special_requests = ? WHERE reservation_id = ?')
                        ->execute([$numAdults, $numChildren, $bookerType, $form['requests'] !== '' ? $form['requests'] : null, $editId]);

                    $pdo->prepare('DELETE FROM reservation_service WHERE reservation_id = ?')->execute([$editId]);
                    if ($chosen) {
                        $priceStmt = $pdo->prepare('SELECT unit_price FROM service WHERE service_id = ? AND resort_id = ?');
                        $insSvc = $pdo->prepare('INSERT INTO reservation_service (reservation_id, service_id, service_date, quantity, price_charged) VALUES (?, ?, ?, ?, ?)');
                        foreach ($chosen as $sid => $qty) {
                            $priceStmt->execute([(int)$sid, resort_id()]);
                            $unit = $priceStmt->fetchColumn();
                            if ($unit !== false) {
                                $insSvc->execute([$editId, (int)$sid, $in, (int)$qty, (float)$unit * (int)$qty]);
                            }
                        }
                    }

                    if (!$isEx && !$isCottage) {
                        save_companions($pdo, $editId, $companions);
                    }
                    recalc_total($pdo, $editId);
                    $totStmt = $pdo->prepare('SELECT total_amount FROM reservation WHERE reservation_id = ?');
                    $totStmt->execute([$editId]);
                    $newTotal = (float)$totStmt->fetchColumn();
                    $newReqDp = $onlinePayOn ? calc_downpayment($newTotal, $dpPct) : 0.0;
                    $pdo->prepare('UPDATE reservation SET required_downpayment = ? WHERE reservation_id = ?')
                        ->execute([$newReqDp, $editId]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw new RuntimeException('Could not save your updated details. Please try again.');
                }
                if ($onlinePayOn && $newReqDp > 0.004) {
                    flash('Details updated for ' . $editRef . '. Continue with Step 2 below.');
                    redirect('payment.php?ref=' . urlencode($editRef));
                }
                flash('Details updated for ' . $editRef . '.');
                redirect('booking.php?ref=' . urlencode($editRef));
            }

            $data = [
                'scope' => $scope, 'target_id' => $targetId, 'type_id' => $typeId, 'rooms' => $roomsReq,
                'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
                'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                'email' => strtolower($form['email']), 'phone' => $form['phone'], 'address' => $form['address'],
                'requests' => $form['requests'], 'services' => $chosen, 'companions' => $companions, 'booker_type' => $bookerType,
            ];

            if ($verifyOn && empty($_SESSION['verified_emails'][$data['email']])) {
                start_verification($pdo, $data, $sig);
                $pending = $_SESSION['pending_booking'];
            } else {
                finish_booking($pdo, $data);
            }
        } elseif (!$pending && in_array($action, ['verify', 'resend', 'edit'], true)) {
            $last = $_SESSION['last_booking'] ?? null;
            if ($last && time() - $last['at'] < 300) {
                $_SESSION['grants'][$last['ref']] = true;
                redirect('booking.php?ref=' . urlencode($last['ref']));   // the code was already used and the booking exists
            }
            throw new RuntimeException('That verification has expired. Please enter your details again.');
        } elseif ($pending && $action === 'verify') {
            $entered = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
            if (time() > $pending['expires']) {
                throw new RuntimeException('That code has expired. Send a new one.');
            }
            if ($pending['tries'] >= 5 || throttle_blocked($pdo, 'verify_fail', 10)) {
                throw new RuntimeException('Too many wrong codes. Send a new code and try again.');
            }
            if (!hash_equals($pending['hash'], hash('sha256', $pending['salt'] . $entered))) {
                $_SESSION['pending_booking']['tries']++;
                throttle_fail($pdo, 'verify_fail');
                $left = 5 - $_SESSION['pending_booking']['tries'];
                $pending = $_SESSION['pending_booking'];
                throw new RuntimeException('That code isn’t right.' . ($left > 0 ? ' ' . plural($left, 'try', 'tries') . ' left.' : ' Send a new code.'));
            }
            throttle_clear($pdo, 'verify_fail');
            $_SESSION['verified_emails'][$pending['data']['email']] = true;
            try {
                finish_booking($pdo, $pending['data']);
            } catch (RuntimeException $e) {
                // Email is verified but the booking failed (for example the room was just taken): back to the form.
                $d = $pending['data'];
                $form = ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'email' => $d['email'],
                         'phone' => $d['phone'], 'address' => $d['address'], 'requests' => $d['requests']];
                $compValues = companion_values($d['companions'] ?? []);
                $bookerType = $d['booker_type'] ?? 'Adult';
                foreach ($d['services'] ?? [] as $sid => $q) {
                    $svcOn[$sid] = 1;
                    $svcQty[$sid] = $q;
                }
                unset($_SESSION['pending_booking']);
                $pending = null;
                throw $e;
            }
        } elseif ($pending && $action === 'resend') {
            if (time() - $pending['sent_at'] < 60) {
                throw new RuntimeException('Please wait a minute before asking for another code.');
            }
            start_verification($pdo, $pending['data'], $sig);
            $pending = $_SESSION['pending_booking'];
            $notice  = 'We sent you a new code.';
        } elseif ($pending && $action === 'edit') {
            $d = $pending['data'];
            $form = ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'email' => $d['email'],
                     'phone' => $d['phone'], 'address' => $d['address'], 'requests' => $d['requests']];
            $compValues = companion_values($d['companions'] ?? []);
            $bookerType = $d['booker_type'] ?? 'Adult';
            foreach ($d['services'] as $sid => $q) {
                $svcOn[$sid] = 1;
                $svcQty[$sid] = $q;
            }
            unset($_SESSION['pending_booking']);
            $pending = null;
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$step = $pending ? 'verify' : 'details';
$addonSum = 0.0;
if ($pending) {
    foreach ($services as $s) {
        $q = (int)($pending['data']['services'][(int)$s['service_id']] ?? 0);
        $addonSum += (float)$s['unit_price'] * $q;
    }
} elseif ($svcOn) {
    foreach ($services as $s) {
        $sid = (int)$s['service_id'];
        if (!empty($svcOn[$sid])) {
            $addonSum += (float)$s['unit_price'] * max(1, (int)($svcQty[$sid] ?? 1));
        }
    }
}

site_start('Your details', 'rooms', 'no-nav');
?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back" href="<?= h(ru($isEx ? 'exclusive.php' : 'search.php') . '&' . http_build_query($isCottage ? ['mode' => 'cottage', 'check_in' => $in, 'adults' => $adults, 'children' => $children] : ['mode' => 'rooms', 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children])) ?>">&larr; <?= $isCottage ? 'Back to cottages' : ($isEx ? 'Back' : 'Back to rooms') ?></a>
    <h1>Your details</h1>
  </div>
</div>
<?= booking_steps(1, ($isEdit && $needsOnlinePay) ? [2 => 'payment.php?ref=' . rawurlencode($editRef)] : []) ?>

<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

<div class="checkout">
  <?php if ($step === 'verify'): $pe = $pending['data']['email']; ?>
  <section class="panel stack" id="verify-panel">
    <h2>Check your email</h2>
    <p>We sent a 6-digit code to <strong><?= h($pe) ?></strong>. Enter it to finish your booking request. It works for 10 minutes.</p>
    <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: no email was actually sent. Open <code>storage/mail.log</code> to read the code. Set up SMTP in <code>config.php</code> to send real emails.</div>
    <?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?><?= rhidden() ?>
      <?php foreach ($hid as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="action" value="verify">
      <label><span>Verification code</span>
        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required autofocus>
      </label>
      <div class="actions"><button class="btn primary" type="submit"<?= $needsOnlinePay ? '' : ' data-confirm="Send your booking request to the resort now?" data-confirm-title="Request booking" data-confirm-ok="Yes, send request"' ?>><?= $needsOnlinePay ? 'Verify and continue to payment →' : 'Verify and request booking' ?></button></div>
    </form>
    <div class="actions">
      <form method="post">
        <?= csrf_field() ?><?= rhidden() ?>
        <?php foreach ($hid as $k => $v): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="action" value="resend">
        <button class="btn" type="submit">Send a new code</button>
      </form>
      <form method="post">
        <?= csrf_field() ?><?= rhidden() ?>
        <?php foreach ($hid as $k => $v): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="action" value="edit">
        <button class="btn" type="submit">Use a different email</button>
      </form>
    </div>
  </section>
  <?php else: ?>
  <?php if (!$acct): $back = urlencode('checkout.php?' . ($_SERVER['QUERY_STRING'] ?? '')); ?>
  <p class="notice" role="note" style="margin-bottom:12px">Have an account? <a href="<?= h(ru('login.php?next=' . $back)) ?>">Log in</a> to fill in your details, or <a href="<?= h(ru('register.php?next=' . $back)) ?>">create one</a>. You can also continue as a guest.</p>
  <?php endif; ?>
  <form method="post" class="panel pay-form ck-form" id="checkout-form">
    <?= csrf_field() ?><?= rhidden() ?>
    <input type="hidden" name="action" value="details">
    <?php foreach ($hid as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>

    <div class="pay-section">
      <div class="pay-sec-head">
        <span class="pay-sec-num">1</span>
        <div>
          <h2 class="pay-sec-title">Guest details</h2>
          <p class="pay-sec-sub">Format: Last name, First name. Used on your booking confirmation.</p>
        </div>
      </div>
      <div class="grid-form two-col">
        <label><span>Last name</span><input placeholder="e.g. Santos" name="last_name" value="<?= h($form['last_name']) ?>" maxlength="60" autocomplete="family-name" required></label>
        <label><span>First name</span><input placeholder="e.g. Maria" name="first_name" value="<?= h($form['first_name']) ?>" maxlength="60" autocomplete="given-name" required></label>
      </div>
      <div class="grid-form three-col-mix" style="margin-top:12px">
        <label><span>Email</span><input placeholder="e.g. maria@example.com" type="email" name="email" value="<?= h($form['email']) ?>" maxlength="120" autocomplete="email" required<?= $acct ? ' readonly' : '' ?>></label>
        <label><span>Phone</span><input placeholder="e.g. 0917 123 4567" type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="30" autocomplete="tel" required></label>
        <?= booker_type_field($bookerType) ?>
      </div>
      <?php if ($isCottage): ?>
        <p class="ck-stay-note">1× <?= h($cottage['name']) ?> · <?= h(plural($guests, 'guest')) ?> arriving (up to <?= (int)$cottage['capacity'] ?> guests).</p>
      <?php elseif ($isEx): ?>
        <p class="ck-stay-note"><?= h(BOOKING_SCOPES[$scope]) ?> · up to <?= (int)$target['capacity'] ?> guests.</p>
      <?php else: ?>
        <?= companion_fields($adults, $children, $compValues, true) ?>
      <?php endif; ?>
    </div>

    <div class="pay-section">
      <div class="pay-sec-head">
        <span class="pay-sec-num">2</span>
        <div>
          <h2 class="pay-sec-title">Add-ons &amp; requests <small class="muted">(optional)</small></h2>
          <p class="pay-sec-sub">Customize your visit or let the resort know about special needs.</p>
        </div>
      </div>
      <?php if ($services): ?>
      <div class="addon-pick">
        <?php foreach ($services as $s): $sid = (int)$s['service_id']; ?>
          <div class="addon-row">
            <label class="check">
              <input type="checkbox" name="svc_on[<?= $sid ?>]" value="1" data-price="<?= h($s['unit_price']) ?>" <?= !empty($svcOn[$sid]) ? 'checked' : '' ?>>
              <span><?= h($s['service_name']) ?> <small class="muted"><?= h(money($s['unit_price'])) ?></small></span>
            </label>
            <input type="number" name="svc_qty[<?= $sid ?>]" value="<?= (int)($svcQty[$sid] ?? 1) ?>" min="1" max="20" aria-label="Quantity for <?= h($s['service_name']) ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <label style="margin-top:<?= $services ? '14px' : '0' ?>"><span>Special requests</span>
        <textarea name="requests" rows="2" maxlength="500" placeholder="Optional: late arrival, celebration, dietary needs…"><?= h($form['requests']) ?></textarea>
      </label>
    </div>

    <div class="ck-terms-box">
      <label class="check terms" style="margin:0">
        <input type="checkbox" name="terms" value="1" <?= ($isEdit || !empty($_POST['terms'])) ? 'checked' : '' ?> required>
        <span><?= $needsOnlinePay
          ? 'I agree to the ' . $dpPct . '% GCash/Maya downpayment on the next step.'
          : 'I accept the booking terms.' ?> <?= $config['allow_cancellation'] ? 'Free cancellation up to ' . h(plural((int)$config['free_cancel_days'], 'day')) . ' before check-in.' : 'Contact the resort to cancel.' ?></span>
      </label>
    </div>

    <?php
      $needCodeStep = !$isEdit && $verifyOn && !($acct && !empty($_SESSION['verified_emails'][strtolower($acct['email'])]));
      $btnText = $isEdit
          ? ($needsOnlinePay ? 'Save & continue to payment →' : 'Save details')
          : ($needCodeStep
              ? 'Continue'
              : ($needsOnlinePay ? 'Continue to payment →' : 'Request booking'));
    ?>
    <div class="actions pay-submit-row">
      <button class="btn primary" type="submit" id="checkout-submit"<?= ($needCodeStep || $needsOnlinePay) ? '' : ' data-confirm="Send your booking request to the resort now?" data-confirm-title="Request booking" data-confirm-ok="Yes, send request"' ?>><?= h($btnText) ?></button>
      <span class="ck-trust-note"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg> Direct resort verification</span>
    </div>
  </form>
  <?php endif; ?>

  <aside class="panel summary-card is-fintech" aria-label="Booking summary">
    <div class="sum-head">
      <span class="sum-eyebrow"><?= $isCottage ? 'Day-use cottage' : 'Overnight stay' ?></span>
      <h2><?= h($config['resort']) ?></h2>
      <?php if (!empty($resort['location'])): ?><p class="sum-addr"><?= h($resort['location']) ?></p><?php endif; ?>
    </div>
    <?php $sp = $type ? room_photos($type['type_name']) : []; ?>
    <?php if ($sp): ?><div class="sc-photo" style="background-image:url('<?= h($sp[0]['url']) ?>')"></div><?php endif; ?>
    <dl class="facts">
      <?php if ($isCottage): ?><dt>Cottage</dt><dd><?= h($cottage['name']) ?> × 1</dd>
      <?php elseif ($isEx): ?><dt>Booking</dt><dd><?= h(scope_label(['booking_scope' => $scope, 'building_name' => $target['name'], 'pool_name' => $target['name']])) ?></dd>
      <?php else: ?><dt>Room</dt><dd><?= h($type['type_name']) ?> × <?= $roomsReq ?></dd><?php endif; ?>
      <?php if ($isCottage): ?>
        <dt>Visit date</dt><dd><?= h(fdate($in)) ?> <span class="muted">(Day use)</span></dd>
      <?php else: ?>
        <dt>Check-in</dt><dd><?= h(fdate($in)) ?><br><span class="muted">from <?= h($config['checkin_time']) ?></span></dd>
        <dt>Check-out</dt><dd><?= h(fdate($out)) ?><br><span class="muted">by <?= h($config['checkout_time']) ?></span></dd>
        <dt>Nights</dt><dd><?= $n ?></dd>
      <?php endif; ?>
      <dt>Guests</dt><dd><?= h(plural($guests, 'guest')) ?></dd>
    </dl>
    <?php
      $initTotal     = $roomTotal + $addonSum;
      $initDp        = $needsOnlinePay ? calc_downpayment($initTotal, $dpPct) : 0.0;
      $initDueResort = max(0.0, round($initTotal - $initDp, 2));
    ?>
    <dl class="facts totals">
      <?php if ($isCottage): ?>
        <dt>Cottage (× 1)</dt><dd class="num"><?= h(money($cottage['rate'])) ?></dd>
        <?php if ($cottageEnt['total'] > 0.004): ?>
          <dt>Entrance (<?= h(plural($guests, 'guest')) ?>)</dt><dd class="num"><?= h(money($cottageEnt['total'])) ?></dd>
        <?php endif; ?>
      <?php else: ?>
        <dt><?= $isEx ? 'Price' : 'Rooms' ?></dt><dd class="num"><?= h(money($roomTotal)) ?></dd>
      <?php endif; ?>
      <dt>Add-ons</dt><dd class="num" id="addon-total"><?= h(money($addonSum)) ?></dd>
      <dt>Total</dt><dd class="num strong" id="grand-total"><?= h(money($initTotal)) ?></dd>
    </dl>
    <?php if ($needsOnlinePay): ?>
    <div class="sum-highlight">
      <div class="sum-hi-row">
        <span>Next step (<?= $dpPct ?>% downpayment)</span>
        <strong id="due-now"><?= h(money($initDp)) ?></strong>
      </div>
      <div class="sum-hi-sub">
        <span>Balance at resort</span>
        <span id="due-resort"><?= h(money($initDueResort)) ?></span>
      </div>
    </div>
    <?php endif; ?>
    <p class="muted sum-foot-note" id="summary-pay-note">
      <?= $needsOnlinePay ? 'Next step: Send via GCash or Maya and upload screenshot.' : ($onlinePayOn ? 'Pay at the resort or via GCash / Maya.' : 'Pay at the resort.') ?>
    </p>
  </aside>
</div>

<script>
(function () {
  var cur = <?= json_encode($config['currency'], JSON_UNESCAPED_UNICODE) ?>;
  var roomTotal = <?= json_encode($roomTotal) ?>;
  var needsDp = <?= json_encode($needsOnlinePay) ?>;
  var dpPct = <?= json_encode($dpPct) ?>;
  var rows = document.querySelectorAll('.addon-row');
  var addonEl = document.getElementById('addon-total');
  var grandEl = document.getElementById('grand-total');
  var dueNowEl = document.getElementById('due-now');
  var dueResEl = document.getElementById('due-resort');
  if (!grandEl || !rows.length) return;

  function fmt(v) { return cur + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function update() {
    var sum = 0;
    rows.forEach(function (row) {
      var box = row.querySelector('input[type="checkbox"]');
      var qty = row.querySelector('input[type="number"]');
      if (box && box.checked) sum += (+box.dataset.price) * Math.max(1, Math.min(20, +(qty ? qty.value : 1) || 1));
    });
    var total = Math.round((roomTotal + sum) * 100) / 100;
    if (addonEl) addonEl.textContent = fmt(sum);
    grandEl.textContent = fmt(total);
    if (needsDp && dueNowEl && dueResEl) {
      var dpAmt = dpPct >= 100 ? total : Math.round(total * dpPct) / 100;
      dueNowEl.textContent = fmt(dpAmt);
      dueResEl.textContent = fmt(Math.max(0, Math.round((total - dpAmt) * 100) / 100));
    }
  }
  rows.forEach(function (row) {
    row.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', update); i.addEventListener('change', update); });
  });
  update();
})();
</script>
<?php site_end();
