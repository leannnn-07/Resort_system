<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';

/**
 * On-site e-wallet payment helpers (GCash & Maya with staff reference-number verification).
 *
 * Flow:
 *  1. Resort admin configures required downpayment % (e.g. 50%) and GCash / Maya account details in Admin > Settings.
 *  2. Guest sends the downpayment (or full amount) via GCash or Maya and submits the Reference Number on checkout or booking.php.
 *  3. Payment is recorded as Pending (source = 'Guest') with duplicate-reference protection.
 *  4. Resort staff verify the reference number against their GCash/Maya app and click Verify & Approve (or Reject with reason).
 *  5. Approving a payment that meets the required downpayment automatically confirms the Pending reservation and emails the guest.
 */

const PAYMENT_METHODS = [
    'gcash'   => ['label' => 'GCash', 'hint' => 'Send via GCash'],
    'paymaya' => ['label' => 'Maya',  'hint' => 'Send via Maya'],
];

const PAYMENT_PROOF_MAX_BYTES = 8 * 1024 * 1024; // 8 MB max screenshot size

/** Validate and save a required payment screenshot into uploads/payments/<reservationId>/. Returns relative path. */
function payment_save_proof(array $files, int $reservationId, string $field = 'proof_image'): string
{
    $f = $files[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please upload a screenshot of your GCash or Maya payment receipt.');
    }
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)($f['size'] ?? 0) > PAYMENT_PROOF_MAX_BYTES) {
        throw new RuntimeException('That screenshot is too big. Upload a JPG, PNG or WebP image under 8 MB.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        throw new RuntimeException('The payment screenshot could not be uploaded. Please try again.');
    }
    $info = @getimagesize((string)$f['tmp_name']);
    $ext  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('Upload a valid JPG, PNG or WebP screenshot of your payment.');
    }
    $dir = dirname(__DIR__) . '/uploads/payments/' . $reservationId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not save your screenshot on the server. Please contact the resort.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save your screenshot on the server. Please try again.');
    }
    return 'payments/' . $reservationId . '/' . $name;
}

/** Public/admin URL for a stored payment screenshot, or '' when missing/invalid. */
function payment_proof_url(?string $proofPath, string $prefix = ''): string
{
    $p = (string)($proofPath ?? '');
    if ($p === '' || !preg_match('#^payments/\d+/[a-f0-9]+\.(jpg|png|webp)$#i', $p)) {
        return '';
    }
    if (!is_file(dirname(__DIR__) . '/uploads/' . $p)) {
        return '';
    }
    return $prefix . 'uploads/' . $p;
}

function payment_enabled_methods(): array
{
    global $config;
    $raw = $config['payment']['methods'] ?? ['gcash', 'paymaya'];
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $m) {
            $m = strtolower(trim((string)$m));
            if (isset(PAYMENT_METHODS[$m]) && !in_array($m, $out, true)) {
                $out[] = $m;
            }
        }
    }
    return $out ?: ['gcash', 'paymaya'];
}

function payment_method_label(string $code): string
{
    $code = strtolower(trim($code));
    if (isset(PAYMENT_METHODS[$code])) {
        return PAYMENT_METHODS[$code]['label'];
    }
    return match ($code) {
        'maya', 'paymaya' => 'Maya',
        'gcash'           => 'GCash',
        default           => $code !== '' ? ucfirst($code) : 'GCash',
    };
}

/** Payment rules & receiving accounts for a resort (defaults to the active resort). */
function resort_payment_rules(?array $r = null): array
{
    global $resort;
    $src     = $r ?? $resort ?? [];
    $enabled = (int)($src['online_payment_enabled'] ?? 1) === 1;
    $pct     = max(0, min(100, (int)($src['downpayment_percent'] ?? 50)));
    return [
        'enabled'             => $enabled,
        'downpayment_percent' => $enabled ? $pct : 0,
        'raw_percent'         => $pct,
        'gcash_name'          => trim((string)($src['gcash_name'] ?? '')),
        'gcash_number'        => trim((string)($src['gcash_number'] ?? '')),
        'maya_name'           => trim((string)($src['maya_name'] ?? '')),
        'maya_number'         => trim((string)($src['maya_number'] ?? '')),
    ];
}

/** Calculate required downpayment amount for a booking total and percentage (0..100). */
function calc_downpayment(float $total, int $percent): float
{
    $total = round(max(0.0, $total), 2);
    if ($total <= 0.0 || $percent <= 0) {
        return 0.0;
    }
    if ($percent >= 100) {
        return $total;
    }
    return round($total * $percent / 100, 2);
}

/** Validate and normalize a GCash / Maya reference number. Throws RuntimeException if invalid. */
function clean_payment_reference(string $ref): string
{
    $ref = trim((string)preg_replace('/\s+/', ' ', $ref));
    $compact = preg_replace('/[\s\-]/', '', $ref);
    if (strlen($compact) < 6 || strlen($ref) > 60 || !preg_match('/^[A-Za-z0-9\-\s]+$/', $ref)) {
        throw new RuntimeException('Enter a valid GCash or Maya reference number (6 to 60 letters/digits).');
    }
    return strtoupper($ref);
}

/** Total amount of guest-submitted payments currently Pending staff verification. */
function pending_payment_amount(PDO $pdo, int $reservationId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment WHERE reservation_id = ? AND payment_status = 'Pending'");
    $stmt->execute([$reservationId]);
    return (float)$stmt->fetchColumn();
}

/** Display badge for a reservation: shows "Downpayment paid" when Pending with partial payment (not yet paid in full). */
function reservation_status_badge(array $res, ?float $paid = null): string
{
    $status  = (string)($res['status'] ?? 'Pending');
    $total   = round((float)($res['total_amount'] ?? 0), 2);
    $paidAmt = round($paid ?? (float)($res['paid'] ?? 0), 2);
    if ($status === 'Pending' && $paidAmt > 0.004 && $paidAmt + 0.004 < $total) {
        return '<span class="badge dp-paid">Downpayment paid</span>';
    }
    return badge($status);
}

/** Summary of payment state for a reservation row. */
function reservation_payment_summary(PDO $pdo, array $res): array
{
    $id        = (int)$res['reservation_id'];
    $total     = round((float)$res['total_amount'], 2);
    $paid      = round(amount_paid($pdo, $id), 2);
    $pending   = round(pending_payment_amount($pdo, $id), 2);
    $balance   = round(max(0.0, $total - $paid), 2);
    $reqDp     = round(min($total, max(0.0, (float)($res['required_downpayment'] ?? 0))), 2);
    $dpRem     = round(max(0.0, $reqDp - $paid), 2);
    $dpMet     = $reqDp <= 0.004 || $dpRem <= 0.004;
    $fullyPaid = $total <= 0.004 || ($paid + 0.004 >= $total);
    $hasPend   = $pending > 0.004;

    return [
        'total'                 => $total,
        'paid'                  => $paid,
        'pending'               => $pending,
        'balance'               => $balance,
        'required_downpayment'  => $reqDp,
        'downpayment_remaining' => $dpRem,
        'downpayment_met'       => $dpMet,
        'fully_paid'            => $fullyPaid,
        'downpayment_only'      => ($res['status'] ?? '') === 'Pending' && $paid > 0.004 && !$fullyPaid,
        'has_pending'           => $hasPend,
        'needs_downpayment'     => ($res['status'] ?? '') === 'Pending' && ($res['source'] ?? '') === 'Online' && !$dpMet && !$hasPend,
        'awaiting_verification' => $hasPend,
    ];
}

/**
 * Record a guest-submitted GCash / Maya payment with reference number and required screenshot
 * (status = 'Pending' until staff verify). Rejects duplicate reference numbers already Pending or Paid.
 */
function submit_guest_payment(
    PDO $pdo,
    int $reservationId,
    string $methodCode,
    float $amount,
    string $referenceNo,
    string $senderName = '',
    string $proofImage = ''
): int {
    $enabled = payment_enabled_methods();
    $code    = strtolower(trim($methodCode));
    if (!in_array($code, $enabled, true)) {
        throw new RuntimeException('Choose GCash or Maya.');
    }
    $method = payment_method_label($code);
    $refNo  = clean_payment_reference($referenceNo);
    $sender = trim($senderName);
    if (mb_strlen($sender) > 100) {
        throw new RuntimeException('Keep the sender name or mobile number under 100 characters.');
    }
    $proofImage = trim($proofImage);
    if ($proofImage === '' || !preg_match('#^payments/\d+/[a-f0-9]+\.(jpg|png|webp)$#i', $proofImage)) {
        throw new RuntimeException('Please upload a screenshot of your GCash or Maya payment receipt.');
    }

    $stmt = $pdo->prepare('SELECT * FROM reservation WHERE reservation_id = ?');
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch();
    if (!$res) {
        throw new RuntimeException('Booking not found.');
    }
    if (in_array($res['status'], ['Cancelled', 'Checked-out'], true)) {
        throw new RuntimeException('This booking is ' . strtolower($res['status']) . ' and cannot accept payments.');
    }

    $sum = reservation_payment_summary($pdo, $res);
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter a payment amount greater than zero.');
    }
    $remainingUnclaimed = round(max(0.0, $sum['balance'] - $sum['pending']), 2);
    if ($remainingUnclaimed <= 0.004) {
        throw new RuntimeException($sum['has_pending']
            ? 'You already have a submitted payment awaiting staff verification.'
            : 'This booking is already paid in full.');
    }
    if ($amount > $remainingUnclaimed + 0.004) {
        throw new RuntimeException('That amount is more than the remaining balance (' . money($remainingUnclaimed) . ').');
    }

    // Prevent reusing the same GCash/Maya reference number across bookings.
    $compactRef = str_replace([' ', '-'], '', $refNo);
    $dup = $pdo->prepare(
        "SELECT payment_id FROM payment
          WHERE method = ?
            AND REPLACE(REPLACE(UPPER(COALESCE(reference_no, '')), ' ', ''), '-', '') = ?
            AND payment_status IN ('Pending', 'Paid')
          LIMIT 1"
    );
    $dup->execute([$method, $compactRef]);
    if ($dup->fetchColumn()) {
        throw new RuntimeException('That ' . $method . ' reference number has already been submitted. Double-check your receipt.');
    }

    $pdo->prepare(
        "INSERT INTO payment (reservation_id, payment_date, amount, method, payment_status, reference_no, sender_name, proof_image, source)
         VALUES (?, ?, ?, ?, 'Pending', ?, ?, ?, 'Guest')"
    )->execute([$reservationId, today(), $amount, $method, $refNo, $sender !== '' ? $sender : null, $proofImage]);

    $paymentId = (int)$pdo->lastInsertId();
    require_once __DIR__ . '/notifications.php';
    add_staff_notification(
        $pdo,
        (int)$res['resort_id'],
        $reservationId,
        'payment',
        $method . ' payment · ' . $res['booking_ref'],
        money($amount) . ' · Ref ' . $refNo . ' — verify screenshot',
        'reservation_view.php?id=' . $reservationId . '#payments'
    );
    return $paymentId;
}

/**
 * Record ONE combined guest GCash / Maya payment covering multiple reservations at the SAME resort in one trip.
 * Each reservation gets its exact share recorded with the shared reference number and screenshot, and staff get ONE notification.
 *
 * @param array<int, array{reservation: array, amount: float}> $targets
 * @return int[] Inserted payment IDs
 */
function submit_trip_resort_payment(
    PDO $pdo,
    array $targets,
    string $methodCode,
    string $referenceNo,
    string $senderName = '',
    string $proofImage = '',
    string $tripCode = ''
): array {
    if (!$targets) {
        throw new RuntimeException('No bookings selected for payment.');
    }
    $enabled = payment_enabled_methods();
    $code    = strtolower(trim($methodCode));
    if (!in_array($code, $enabled, true)) {
        throw new RuntimeException('Choose GCash or Maya.');
    }
    $method = payment_method_label($code);
    $refNo  = clean_payment_reference($referenceNo);
    $sender = trim($senderName);
    if (mb_strlen($sender) > 100) {
        throw new RuntimeException('Keep the sender name or mobile number under 100 characters.');
    }
    $proofImage = trim($proofImage);
    if ($proofImage === '' || !preg_match('#^payments/\d+/[a-f0-9]+\.(jpg|png|webp)$#i', $proofImage)) {
        throw new RuntimeException('Please upload a screenshot of your GCash or Maya payment receipt.');
    }

    $compactRef = str_replace([' ', '-'], '', $refNo);
    $dup = $pdo->prepare(
        "SELECT payment_id FROM payment
          WHERE method = ?
            AND REPLACE(REPLACE(UPPER(COALESCE(reference_no, '')), ' ', ''), '-', '') = ?
            AND payment_status IN ('Pending', 'Paid')
          LIMIT 1"
    );
    $dup->execute([$method, $compactRef]);
    if ($dup->fetchColumn()) {
        throw new RuntimeException('That ' . $method . ' reference number has already been submitted. Double-check your receipt.');
    }

    $resortId    = (int)$targets[0]['reservation']['resort_id'];
    $firstResId  = (int)$targets[0]['reservation']['reservation_id'];
    $firstRef    = (string)$targets[0]['reservation']['booking_ref'];
    $totalAmount = 0.0;
    $paymentIds  = [];

    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO payment (reservation_id, payment_date, amount, method, payment_status, reference_no, sender_name, proof_image, source)
             VALUES (?, ?, ?, ?, 'Pending', ?, ?, ?, 'Guest')"
        );
        foreach ($targets as $t) {
            $resId = (int)$t['reservation']['reservation_id'];
            $amt   = round((float)$t['amount'], 2);
            if ($amt <= 0.004) {
                continue;
            }
            $st = $pdo->prepare('SELECT * FROM reservation WHERE reservation_id = ? FOR UPDATE');
            $st->execute([$resId]);
            $res = $st->fetch();
            if (!$res || (int)$res['resort_id'] !== $resortId) {
                throw new RuntimeException('All bookings in a combined payment must belong to the same resort.');
            }
            if (in_array($res['status'], ['Cancelled', 'Checked-out'], true)) {
                throw new RuntimeException('Booking ' . $res['booking_ref'] . ' is ' . strtolower($res['status']) . ' and cannot accept payments.');
            }
            $sum = reservation_payment_summary($pdo, $res);
            $unclaimed = round(max(0.0, $sum['balance'] - $sum['pending']), 2);
            if ($amt > $unclaimed + 0.004) {
                throw new RuntimeException('Payment amount exceeds remaining balance for booking ' . $res['booking_ref'] . '.');
            }
            $ins->execute([$resId, today(), $amt, $method, $refNo, $sender !== '' ? $sender : null, $proofImage]);
            $paymentIds[] = (int)$pdo->lastInsertId();
            $totalAmount  = round($totalAmount + $amt, 2);
        }
        if (!$paymentIds) {
            throw new RuntimeException('No remaining balance to pay.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    require_once __DIR__ . '/notifications.php';
    $cnt   = count($paymentIds);
    $title = $cnt > 1
        ? $method . ' payment · Trip ' . ($tripCode !== '' ? $tripCode : $firstRef) . ' (' . $cnt . ' stays)'
        : $method . ' payment · ' . $firstRef;
    add_staff_notification(
        $pdo,
        $resortId,
        $firstResId,
        'payment',
        $title,
        money($totalAmount) . ' · Ref ' . $refNo . ' — verify screenshot',
        'reservation_view.php?id=' . $firstResId . '#payments'
    );
    return $paymentIds;
}

/** Snapshot of the approving staff member's name and email for permanent payment audit logs. */
function staff_approval_snapshot(PDO $pdo, int $staffId): array
{
    $st = $pdo->prepare('SELECT full_name, email FROM staff WHERE staff_id = ?');
    $st->execute([$staffId]);
    $row = $st->fetch();
    return [
        'name'  => $row ? trim((string)$row['full_name']) : '',
        'email' => $row ? trim((string)$row['email']) : '',
    ];
}

/**
 * Staff verifies a Pending payment's reference number and marks it Paid.
 * Automatically confirms a Pending reservation once paid in full, and emails the guest.
 */
function verify_guest_payment(PDO $pdo, int $paymentId, int $reservationId, int $staffId, string $note = ''): array
{
    $note = trim($note);
    if (mb_strlen($note) > 255) {
        throw new RuntimeException('Keep the verification note under 255 characters.');
    }
    $approver = staff_approval_snapshot($pdo, $staffId);

    $autoConfirmed = false;
    $paidAmt       = 0.0;
    $paidMethod    = '';
    $refNo         = '';
    $emailQueue    = [];

    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $pStmt = $pdo->prepare('SELECT * FROM payment WHERE payment_id = ? AND reservation_id = ? FOR UPDATE');
        $pStmt->execute([$paymentId, $reservationId]);
        $p = $pStmt->fetch();
        if (!$p) {
            throw new RuntimeException('Payment not found.');
        }
        if ($p['payment_status'] !== 'Pending') {
            throw new RuntimeException('Only pending payments can be verified.');
        }

        $rStmt = $pdo->prepare('SELECT * FROM reservation WHERE reservation_id = ? FOR UPDATE');
        $rStmt->execute([$reservationId]);
        $res = $rStmt->fetch();
        if (!$res) {
            throw new RuntimeException('Reservation not found.');
        }
        if ($res['status'] === 'Cancelled') {
            throw new RuntimeException('This reservation is cancelled and cannot have payments approved.');
        }

        // Gather this payment plus any sibling Pending payments from the same combined trip transfer at this resort.
        $batch = [['p' => $p, 'res' => $res]];
        if (!empty($res['trip_code']) && !empty($p['reference_no'])) {
            $sibStmt = $pdo->prepare(
                "SELECT p.*, v.reservation_id AS v_id
                   FROM payment p
                   JOIN reservation v ON v.reservation_id = p.reservation_id
                  WHERE v.resort_id = ? AND v.trip_code = ?
                    AND p.payment_id <> ?
                    AND p.payment_status = 'Pending'
                    AND p.method = ?
                    AND p.reference_no = ?
                    AND COALESCE(p.proof_image, '') = COALESCE(?, '')
                    AND v.status <> 'Cancelled'
                    FOR UPDATE"
            );
            $sibStmt->execute([
                (int)$res['resort_id'],
                $res['trip_code'],
                $paymentId,
                $p['method'],
                $p['reference_no'],
                $p['proof_image'],
            ]);
            foreach ($sibStmt->fetchAll() as $sp) {
                $rStmt->execute([(int)$sp['reservation_id']]);
                $sRes = $rStmt->fetch();
                if ($sRes && $sRes['status'] !== 'Cancelled') {
                    $batch[] = ['p' => $sp, 'res' => $sRes];
                }
            }
        }

        $updPay = $pdo->prepare(
            "UPDATE payment
                SET payment_status = 'Paid',
                    verified_at = NOW(),
                    verified_by_staff_id = ?,
                    approved_by_name = ?,
                    approved_by_email = ?,
                    verification_note = ?
              WHERE payment_id = ? AND payment_status = 'Pending'"
        );
        $updRes = $pdo->prepare("UPDATE reservation SET status = 'Confirmed' WHERE reservation_id = ? AND status = 'Pending'");

        $paidMethod = (string)$p['method'];
        $refNo      = (string)($p['reference_no'] ?? '');

        foreach ($batch as $entry) {
            $ep    = $entry['p'];
            $eRes  = $entry['res'];
            $eId   = (int)$eRes['reservation_id'];
            $eAmt  = (float)$ep['amount'];
            $updPay->execute([
                $staffId,
                $approver['name'] !== '' ? $approver['name'] : null,
                $approver['email'] !== '' ? $approver['email'] : null,
                $note !== '' ? $note : null,
                (int)$ep['payment_id'],
            ]);
            $paidAmt = round($paidAmt + $eAmt, 2);

            $totalPaid = amount_paid($pdo, $eId);
            $eTotal    = (float)$eRes['total_amount'];
            $eConf     = false;
            // Full payment is required before a reservation can be fully approved (Confirmed).
            if ($eRes['status'] === 'Pending' && ($totalPaid + 0.004 >= $eTotal)) {
                $updRes->execute([$eId]);
                $eConf = $updRes->rowCount() === 1;
                if ($eConf) {
                    $autoConfirmed = true;
                }
            }
            $emailQueue[] = ['id' => $eId, 'amt' => $eAmt, 'confirmed' => $eConf];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    require_once __DIR__ . '/notify.php';
    foreach ($emailQueue as $eq) {
        send_booking_email($pdo, $eq['id'], 'paid', [
            'paid_amount'  => $eq['amt'],
            'paid_method'  => $paidMethod,
            'reference_no' => $refNo,
            'confirmed'    => $eq['confirmed'],
        ]);
    }

    return ['auto_confirmed' => $autoConfirmed, 'amount' => $paidAmt, 'method' => $paidMethod, 'count' => count($emailQueue)];
}

/**
 * Staff rejects a Pending payment (e.g., invalid or missing reference number).
 * Marks it (and any sibling combined trip payments sharing the same receipt) Voided with a note.
 */
function reject_guest_payment(PDO $pdo, int $paymentId, int $reservationId, int $staffId, string $reason = ''): void
{
    $reason = trim($reason);
    if (mb_strlen($reason) > 255) {
        throw new RuntimeException('Keep the rejection reason under 255 characters.');
    }
    $note     = $reason !== '' ? $reason : 'Reference number could not be verified.';
    $approver = staff_approval_snapshot($pdo, $staffId);

    $pStmt = $pdo->prepare(
        'SELECT p.payment_id, p.method, p.reference_no, p.proof_image, v.resort_id, v.trip_code
           FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id
          WHERE p.payment_id = ? AND p.reservation_id = ? AND p.payment_status = \'Pending\''
    );
    $pStmt->execute([$paymentId, $reservationId]);
    $p = $pStmt->fetch();
    if (!$p) {
        throw new RuntimeException('That payment is no longer pending.');
    }

    $upd = $pdo->prepare(
        "UPDATE payment
            SET payment_status = 'Voided',
                verified_at = NOW(),
                verified_by_staff_id = ?,
                approved_by_name = ?,
                approved_by_email = ?,
                verification_note = ?
          WHERE payment_id = ? AND payment_status = 'Pending'"
    );
    $upd->execute([
        $staffId,
        $approver['name'] !== '' ? $approver['name'] : null,
        $approver['email'] !== '' ? $approver['email'] : null,
        $note,
        $paymentId,
    ]);
    if ($upd->rowCount() !== 1) {
        throw new RuntimeException('That payment is no longer pending.');
    }

    if (!empty($p['trip_code']) && !empty($p['reference_no'])) {
        $pdo->prepare(
            "UPDATE payment p
               JOIN reservation v ON v.reservation_id = p.reservation_id
                SET p.payment_status = 'Voided',
                    p.verified_at = NOW(),
                    p.verified_by_staff_id = ?,
                    p.approved_by_name = ?,
                    p.approved_by_email = ?,
                    p.verification_note = ?
              WHERE v.resort_id = ? AND v.trip_code = ?
                AND p.payment_status = 'Pending'
                AND p.method = ?
                AND p.reference_no = ?
                AND COALESCE(p.proof_image, '') = COALESCE(?, '')"
        )->execute([
            $staffId,
            $approver['name'] !== '' ? $approver['name'] : null,
            $approver['email'] !== '' ? $approver['email'] : null,
            $note,
            (int)$p['resort_id'],
            $p['trip_code'],
            $p['method'],
            $p['reference_no'],
            $p['proof_image'],
        ]);
    }
}

/**
 * Staff records one or more front-desk payments (Cash, GCash, Maya, or split GCash/Maya + Cash) as immediately Paid.
 * Automatically confirms a Pending reservation once required downpayment is met.
 *
 * @param array<int, array{method: string, amount: float, reference_no?: string}> $legs
 * @return array{total: float, count: int, methods: string[], auto_confirmed: bool}
 */
function record_staff_payment(PDO $pdo, int $reservationId, int $staffId, array $legs, string $note = ''): array
{
    $allowed = ['Cash', 'GCash', 'Maya'];
    $note    = trim($note);
    if (mb_strlen($note) > 255) {
        throw new RuntimeException('Keep the note under 255 characters.');
    }

    $cleanLegs = [];
    $sum       = 0.0;
    foreach ($legs as $leg) {
        $m = trim((string)($leg['method'] ?? ''));
        if (!in_array($m, $allowed, true)) {
            throw new RuntimeException('Choose Cash, GCash, or Maya.');
        }
        $amt = round((float)($leg['amount'] ?? 0), 2);
        if ($amt <= 0) {
            throw new RuntimeException('Each payment amount must be greater than zero.');
        }
        $ref = trim((string)($leg['reference_no'] ?? ''));
        if ($m === 'GCash' || $m === 'Maya') {
            $ref = clean_payment_reference($ref);
        } elseif (mb_strlen($ref) > 80) {
            throw new RuntimeException('Keep the receipt number under 80 characters.');
        }
        $cleanLegs[] = ['method' => $m, 'amount' => $amt, 'reference_no' => $ref];
        $sum = round($sum + $amt, 2);
    }
    if (!$cleanLegs) {
        throw new RuntimeException('Enter at least one payment amount.');
    }

    // Reject duplicate GCash/Maya reference numbers already Pending or Paid.
    $dup = $pdo->prepare(
        "SELECT 1 FROM payment
          WHERE method = ?
            AND REPLACE(REPLACE(UPPER(COALESCE(reference_no, '')), ' ', ''), '-', '') = ?
            AND payment_status IN ('Pending', 'Paid')
          LIMIT 1"
    );
    foreach ($cleanLegs as $leg) {
        if ($leg['method'] === 'GCash' || $leg['method'] === 'Maya') {
            $compact = str_replace([' ', '-'], '', $leg['reference_no']);
            $dup->execute([$leg['method'], $compact]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('That ' . $leg['method'] . ' reference number has already been recorded.');
            }
        }
    }

    $autoConfirmed = false;
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $rStmt = $pdo->prepare('SELECT * FROM reservation WHERE reservation_id = ? FOR UPDATE');
        $rStmt->execute([$reservationId]);
        $res = $rStmt->fetch();
        if (!$res) {
            throw new RuntimeException('Reservation not found.');
        }
        if ($res['status'] === 'Cancelled') {
            throw new RuntimeException('Cancelled reservations cannot accept payments.');
        }

        $balance = round(max(0.0, (float)$res['total_amount'] - amount_paid($pdo, $reservationId)), 2);
        if ($balance <= 0.004) {
            throw new RuntimeException('This reservation is already paid in full.');
        }
        if ($sum > $balance + 0.004) {
            throw new RuntimeException('Total payment (' . money($sum) . ') exceeds the remaining balance (' . money($balance) . ').');
        }

        $approver = staff_approval_snapshot($pdo, $staffId);
        $ins = $pdo->prepare(
            "INSERT INTO payment (reservation_id, payment_date, amount, method, payment_status, reference_no, source, verified_at, verified_by_staff_id, approved_by_name, approved_by_email, verification_note, recorded_by_staff_id)
             VALUES (?, ?, ?, ?, 'Paid', ?, 'Staff', NOW(), ?, ?, ?, ?, ?)"
        );
        foreach ($cleanLegs as $leg) {
            $ins->execute([
                $reservationId,
                today(),
                $leg['amount'],
                $leg['method'],
                $leg['reference_no'] !== '' ? $leg['reference_no'] : null,
                $staffId,
                $approver['name'] !== '' ? $approver['name'] : null,
                $approver['email'] !== '' ? $approver['email'] : null,
                $note !== '' ? $note : null,
                $staffId,
            ]);
        }

        $totalPaid = amount_paid($pdo, $reservationId);
        $resTotal  = (float)$res['total_amount'];
        // Full payment is required before a reservation can be fully approved (Confirmed).
        if ($res['status'] === 'Pending' && ($totalPaid + 0.004 >= $resTotal)) {
            $upd = $pdo->prepare("UPDATE reservation SET status = 'Confirmed' WHERE reservation_id = ? AND status = 'Pending'");
            $upd->execute([$reservationId]);
            $autoConfirmed = $upd->rowCount() === 1;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($autoConfirmed) {
        require_once __DIR__ . '/notify.php';
        send_booking_email($pdo, $reservationId, 'confirmed');
    }

    return [
        'total'          => $sum,
        'count'          => count($cleanLegs),
        'methods'        => array_values(array_unique(array_column($cleanLegs, 'method'))),
        'auto_confirmed' => $autoConfirmed,
    ];
}

/** Reusable HTML segmented selector for GCash and Maya. */
function payment_method_picker(string $selected = 'gcash', string $inputName = 'pay_method'): string
{
    $methods = payment_enabled_methods();
    if (!in_array($selected, $methods, true)) {
        $selected = $methods[0] ?? 'gcash';
    }
    $html = '<div class="pay-methods" role="radiogroup" aria-label="Choose payment method">';
    foreach ($methods as $code) {
        $m = PAYMENT_METHODS[$code];
        $checked = $code === $selected ? ' checked' : '';
        $cls = $code === 'gcash' ? 'is-gcash' : 'is-maya';
        $html .= '<label class="pay-tab ' . $cls . '">'
               . '<input type="radio" name="' . h($inputName) . '" value="' . h($code) . '"' . $checked . '>'
               . '<span class="pay-tab-dot" aria-hidden="true"></span>'
               . '<span class="pay-tab-label">' . h($m['label']) . '</span>'
               . '</label>';
    }
    $html .= '</div>';
    return $html;
}

/** Render the resort's clean GCash & Maya receiving account cards with one-click copy.
 *  When $singleToggle is true, each card carries data-acct="gcash"|"paymaya" so Step 2 shows the selected method's account. */
function payment_accounts_box(?array $r = null, bool $singleToggle = false, string $activeMethod = 'gcash'): string
{
    global $config;
    $rules    = resort_payment_rules($r);
    $resortNm = (string)($r['name'] ?? $config['resort'] ?? '');
    $gcashNum = $rules['gcash_number'];
    $gcashNm  = $rules['gcash_name'] !== '' ? $rules['gcash_name'] : $resortNm;
    $mayaNum  = $rules['maya_number'];
    $mayaNm   = $rules['maya_name'] !== '' ? $rules['maya_name'] : $resortNm;
    $fallbackPhone = trim((string)($r['contact_phone'] ?? $config['contact_phone'] ?? ''));

    if (!$singleToggle) {
        $hasGcash = $gcashNum !== '';
        $hasMaya  = $mayaNum !== '';
        if (!$hasGcash && !$hasMaya && $fallbackPhone !== '') {
            $gcashNum = $fallbackPhone;
            $hasGcash = true;
        }
        $html = '<div class="pay-accounts">';
        if ($hasGcash) {
            $cleanCopy = preg_replace('/\s+/', '', $gcashNum);
            $html .= '<div class="pay-acct is-gcash" data-acct="gcash">'
                   . '<div class="pay-acct-body">'
                   . '<div class="pay-acct-main">'
                   . '<span class="pay-acct-tag">GCash</span>'
                   . '<strong class="pay-acct-num">' . h($gcashNum) . '</strong>'
                   . '<small class="pay-acct-name">' . h($gcashNm) . '</small>'
                   . '</div>'
                   . '<button type="button" class="btn sm copy-btn" data-copy="' . h($cleanCopy) . '">Copy</button>'
                   . '</div></div>';
        }
        if ($hasMaya) {
            $cleanCopy = preg_replace('/\s+/', '', $mayaNum);
            $html .= '<div class="pay-acct is-maya" data-acct="paymaya">'
                   . '<div class="pay-acct-body">'
                   . '<div class="pay-acct-main">'
                   . '<span class="pay-acct-tag">Maya</span>'
                   . '<strong class="pay-acct-num">' . h($mayaNum) . '</strong>'
                   . '<small class="pay-acct-name">' . h($mayaNm) . '</small>'
                   . '</div>'
                   . '<button type="button" class="btn sm copy-btn" data-copy="' . h($cleanCopy) . '">Copy</button>'
                   . '</div></div>';
        }
        if (!$hasGcash && !$hasMaya) {
            $html .= '<div class="pay-acct is-gcash"><div class="pay-acct-body"><div class="pay-acct-main">'
                   . '<span class="pay-acct-tag">GCash / Maya</span>'
                   . '<small class="pay-acct-name">Contact the resort for e-wallet details.</small>'
                   . '</div></div></div>';
        }
        return $html . '</div>';
    }

    // Single-method interactive transfer card for Step 2 (Payment).
    $cards = [
        'gcash'   => ['label' => 'GCash', 'cls' => 'is-gcash', 'num' => $gcashNum ?: $fallbackPhone, 'name' => $gcashNm],
        'paymaya' => ['label' => 'Maya',  'cls' => 'is-maya',  'num' => $mayaNum  ?: $fallbackPhone, 'name' => $mayaNm],
    ];
    $html = '<div class="pay-accounts is-single">';
    foreach (payment_enabled_methods() as $code) {
        if (!isset($cards[$code])) continue;
        $c   = $cards[$code];
        $hid = $code === $activeMethod ? '' : ' hidden';
        $html .= '<div class="pay-acct ' . $c['cls'] . '" data-acct="' . h($code) . '"' . $hid . '>';
        $html .= '<div class="pay-acct-top">'
               . '<span class="pay-acct-tag">' . h($c['label']) . '</span>'
               . '<span class="pay-acct-due">Amount to send: <strong data-transfer-amt></strong></span>'
               . '</div>';
        $html .= '<div class="pay-acct-body">';
        if ($c['num'] !== '') {
            $html .= '<div class="pay-acct-main">'
                   . '<span class="pay-acct-k">Account number</span>'
                   . '<strong class="pay-acct-num">' . h($c['num']) . '</strong>'
                   . '<small class="pay-acct-name">' . h($c['name']) . '</small>'
                   . '</div>'
                   . '<button type="button" class="btn sm copy-btn" data-copy="' . h(preg_replace('/\s+/', '', $c['num'])) . '">Copy number</button>';
        } else {
            $html .= '<small class="pay-acct-name">No ' . h($c['label']) . ' number configured yet. Contact the resort.</small>';
        }
        $html .= '</div></div>';
    }
    return $html . '</div>';
}
