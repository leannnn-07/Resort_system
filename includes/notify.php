<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/booking.php';

/**
 * Email a 6-digit code so a guest can prove the address is theirs.
 * $purpose: 'booking' (finish a booking), 'register' (create an account) or 'reset' (reset a password).
 */
function send_verification_email(string $email, string $firstName, string $code, string $purpose = 'booking'): bool
{
    global $config;
    $minutes = 10;
    [$does, $ignore] = match ($purpose) {
        'register' => ['create your account', 'try to create an account'],
        'reset'    => ['reset your password', 'ask to reset your password'],
        default    => ['finish your booking request', 'try to book a stay'],
    };
    $text = "Hi $firstName,\n\nYour {$config['resort']} verification code is: $code\n\n"
          . "Enter it to $does. It works for $minutes minutes. If you didn't $ignore, you can ignore this email.\n";
    $html = mail_html('Confirm your email',
        '<p>Hi ' . h($firstName) . ',</p><p>Enter this code to ' . h($does) . ':</p>'
        . '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;margin:18px 0">' . h($code) . '</p>'
        . '<p style="color:#5b6b75">It works for ' . $minutes . ' minutes. If you didn’t ' . h($ignore) . ', you can ignore this email.</p>');
    return send_mail($email, $config['resort'] . ' verification code: ' . $code, $text, $html);
}

/** Sent instead of a code when someone tries to register an email that already has an account. */
function send_existing_account_email(string $email, string $firstName): bool
{
    global $config;
    $login = site_base_url() . '/login.php';
    $reset = site_base_url() . '/forgot.php';
    $text = "Hi $firstName,\n\nSomeone tried to create a {$config['resort']} account with this email, but you already have one.\n\n"
          . "Log in: $login\nForgot your password? $reset\n\nIf this wasn't you, ignore this email.\n";
    $html = mail_html('You already have an account',
        '<p>Hi ' . h($firstName) . ',</p><p>Someone tried to create an account with this email, but you already have one.</p>'
        . '<p><a href="' . h($login) . '" style="display:inline-block;background:#0e7490;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px">Log in</a></p>'
        . '<p><a href="' . h($reset) . '">Forgot your password?</a></p>'
        . '<p style="color:#5b6b75">If this wasn’t you, ignore this email.</p>');
    return send_mail($email, 'You already have an account at ' . $config['resort'], $text, $html);
}

/** Load a booking with guest, room and service lines for emails. */
function load_booking_for_email(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT v.*, g.first_name, g.last_name, g.email,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name,
                (SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1) AS cottage_name
           FROM reservation v
           JOIN guest g ON g.guest_id = v.guest_id WHERE v.reservation_id = ?'
    );
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch();
    if (!$res) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT t.type_name, COUNT(*) AS qty FROM reservation_room rr
           JOIN room r ON r.room_id = rr.room_id JOIN room_type t ON t.room_type_id = r.room_type_id
          WHERE rr.reservation_id = ? GROUP BY t.type_name'
    );
    $stmt->execute([$reservationId]);
    $res['rooms'] = $stmt->fetchAll();
    $stmt = $pdo->prepare(
        'SELECT s.service_name, rs.quantity FROM reservation_service rs
           JOIN service s ON s.service_id = rs.service_id WHERE rs.reservation_id = ?'
    );
    $stmt->execute([$reservationId]);
    $res['services'] = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = ? ORDER BY p.name');
    $stmt->execute([$reservationId]);
    $res['pools'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $res['building_name'] = '';
    if (!empty($res['building_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM building WHERE building_id = ?');
        $stmt->execute([$res['building_id']]);
        $res['building_name'] = (string)$stmt->fetchColumn();
    }
    if (($res['booking_scope'] ?? 'Rooms') === 'Pool') {
        $res['pool_name'] = $res['pools'][0] ?? '';
    }
    return $res;
}

/**
 * Email the guest about a booking. $kind: 'received' (just requested), 'paid' (verified online payment), 'confirmed', 'changed' or 'cancelled'.
 * Safe to call anywhere: failures are logged, never thrown.
 */
function send_booking_email(PDO $pdo, int $reservationId, string $kind = 'received', array $extra = []): bool
{
    global $config;
    try {
        $r = load_booking_for_email($pdo, $reservationId);
        if (!$r) {
            return false;
        }
        global $resort;
        if ((int)($resort['resort_id'] ?? 0) !== (int)$r['resort_id'] && ($rr = resort_by_id($pdo, (int)$r['resort_id']))) {
            resort_apply($rr);   // the email speaks for the resort the booking belongs to
        }
        $n    = nights($r['check_in_date'], $r['check_out_date']);
        $link = site_base_url() . '/booking.php?ref=' . urlencode($r['booking_ref']);
        $paid = amount_paid($pdo, $reservationId);
        $bal  = max(0.0, round((float)$r['total_amount'] - $paid, 2));

        [$subject, $heading, $intro] = match ($kind) {
            'paid'      => [
                (!empty($extra['confirmed']) ? 'Paid in full & stay confirmed' : 'Downpayment verified') . ' (' . $r['booking_ref'] . ')',
                !empty($extra['confirmed']) ? 'Paid in full — stay confirmed' : 'Downpayment verified',
                !empty($extra['confirmed'])
                    ? 'Our team verified your payment. Your booking is paid in full and confirmed!'
                    : 'Our team verified your downpayment. Full payment of the remaining balance (' . money($bal) . ') is required for full approval.',
            ],
            'confirmed' => ['Your stay is confirmed (' . $r['booking_ref'] . ')', 'Your stay is confirmed',
                            'Good news: we have confirmed your booking. We look forward to welcoming you.'],
            'changed'   => ['Your booking dates were changed (' . $r['booking_ref'] . ')', 'Dates changed',
                            'We moved your stay to the dates below. If this isn’t what you agreed with us, please contact us.'],
            'cancelled' => ['Your booking was cancelled (' . $r['booking_ref'] . ')', 'Booking cancelled',
                            'This booking has been cancelled and the rooms were released. If this is a surprise, please contact us.'],
            default     => ['We received your booking request (' . $r['booking_ref'] . ')', 'Request received',
                            'Thank you for choosing us. Keep your reference: you need it with your email to view, pay or cancel the booking.'],
        };

        $roomLines = array_map(fn($x) => $x['type_name'] . ' × ' . (int)$x['qty'], $r['rooms']);
        $svcLines  = array_map(fn($x) => $x['service_name'] . ' × ' . (int)$x['quantity'], $r['services']);
        $companions = load_companions($pdo, $reservationId);
        $guests    = party_text($r, $companions);
        $others = array_map(fn($c) => format_full_name((string)$c['first_name'], (string)($c['middle_name'] ?? ''), (string)$c['last_name']) . ' (' . strtolower($c['guest_type']) . ')', $companions);
        $rows = [
            'Resort'     => $config['resort'],
            'Reference'  => $r['booking_ref'],
            'Check-in'   => fdate($r['check_in_date']) . ' (from ' . $config['checkin_time'] . ')',
            'Check-out'  => fdate($r['check_out_date']) . ' (by ' . $config['checkout_time'] . ')',
            'Nights'     => (string)$n,
            'Guests'     => $guests,
            'Booked'     => $r['booking_scope'] === 'Rooms' ? implode(', ', $roomLines) : scope_label($r),
        ];
        if ($others) {
            $rows['Also staying'] = implode(', ', $others);
        }
        if ($svcLines) {
            $rows['Add-ons'] = implode(', ', $svcLines);
        }
        $rows['Total'] = money($r['total_amount']);
        if ($kind === 'paid' && !empty($extra['paid_amount'])) {
            $refStr = (string)($extra['reference_no'] ?? $extra['gateway_ref'] ?? '');
            $rows['Paid now'] = money($extra['paid_amount'])
                . (!empty($extra['paid_method']) ? ' via ' . (string)$extra['paid_method'] : '')
                . ($refStr !== '' ? ' (Ref: ' . $refStr . ')' : '');
        }
        if ($paid > 0.004) {
            $rows['Total paid'] = money($paid);
            $rows['Balance']    = $bal > 0.004 ? money($bal) . ' (due at the resort or online)' : money(0) . ' (paid in full)';
        } elseif ((float)($r['required_downpayment'] ?? 0) > 0.004) {
            $rows['Downpayment'] = money($r['required_downpayment']) . ' (required online to confirm)';
        } else {
            $rows['Payment'] = 'Pay at the resort';
        }

        $text = "Hi {$r['first_name']},\n\n$intro\n\n";
        foreach ($rows as $k => $v) {
            $text .= str_pad($k . ':', 12) . $v . "\n";
        }
        $text .= "\nView or manage your booking: $link\n\n{$config['resort']}\n{$config['contact_email']} · {$config['contact_phone']}\n";

        $table = '<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border-top:1px solid #dde5ea;margin:14px 0">';
        foreach ($rows as $k => $v) {
            $strong = $k === 'Reference' ? 'font-weight:bold;font-size:17px;letter-spacing:1px' : '';
            $table .= '<tr><td style="color:#5b6b75;width:90px;border-bottom:1px solid #dde5ea">' . h($k) . '</td>'
                    . '<td style="border-bottom:1px solid #dde5ea;' . $strong . '">' . h($v) . '</td></tr>';
        }
        $table .= '</table>';
        $html = mail_html($heading,
            '<p>Hi ' . h($r['first_name']) . ',</p><p>' . h($intro) . '</p>' . $table
            . '<p><a href="' . h($link) . '" style="display:inline-block;background:#0e7490;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px">View booking</a></p>');

        return send_mail($r['email'], $subject, $text, $html);
    } catch (Throwable $e) {
        error_log('[resort] booking email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * ONE email for a whole trip: every stay with its own reference, so the guest isn't sent a separate email per resort.
 * Safe to call anywhere: failures are logged, never thrown. $reservationIds are the bookings made in the trip.
 */
function send_trip_email(PDO $pdo, string $code, array $reservationIds): bool
{
    global $config;
    try {
        $stays = [];
        $first = '';
        $email = '';
        $total = 0.0;
        foreach ($reservationIds as $id) {
            $r = load_booking_for_email($pdo, (int)$id);
            if (!$r) {
                continue;
            }
            $rs = resort_by_id($pdo, (int)$r['resort_id']) ?: [];
            $first = $first ?: (string)$r['first_name'];
            $email = (string)$r['email'];
            $what  = $r['booking_scope'] === 'Rooms'
                ? implode(', ', array_map(fn($x) => $x['type_name'] . ' × ' . (int)$x['qty'], $r['rooms']))
                : scope_label($r);
            $total += (float)$r['total_amount'];
            $stays[] = [
                'Resort'    => (string)($rs['name'] ?? ''),
                'Reference' => (string)$r['booking_ref'],
                'Booked'    => $what,
                'Check-in'  => fdate($r['check_in_date']) . ' (from ' . ($rs['checkin_time'] ?? '') . ')',
                'Check-out' => fdate($r['check_out_date']) . ' (by ' . ($rs['checkout_time'] ?? '') . ')',
                'Guests'    => party_text($r, load_companions($pdo, (int)$id)),
                'Total'     => money($r['total_amount']),
                'Contact'   => trim(($rs['contact_email'] ?? '') . ' · ' . ($rs['contact_phone'] ?? ''), ' ·'),
            ];
        }
        if (!$stays || $email === '') {
            return false;
        }
        $link  = site_base_url() . '/booking.php?ref=' . urlencode($code);
        $intro = 'Thank you. Each resort will confirm its own stay by email or phone soon. Keep your trip code and the references below: '
               . 'you need them with your email to view or cancel a booking.';

        $text = "Hi $first,\n\n$intro\n\nTrip code: $code\n";
        foreach ($stays as $i => $st) {
            $text .= "\nStay " . ($i + 1) . "\n";
            foreach ($st as $k => $v) {
                $text .= '  ' . str_pad($k . ':', 11) . $v . "\n";
            }
        }
        $text .= "\nTrip total: " . money($total) . " (pay at each resort)\n\nView your trip: $link\n\n{$config['resort']}\n";

        $body = '<p>Hi ' . h($first) . ',</p><p>' . h($intro) . '</p>'
              . '<p style="font-size:17px;font-weight:bold;letter-spacing:1px;margin:12px 0">Trip code: ' . h($code) . '</p>';
        foreach ($stays as $i => $st) {
            $body .= '<p style="margin:18px 0 4px;font-weight:bold">Stay ' . ($i + 1) . '</p>'
                   . '<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border-top:1px solid #dde5ea">';
            foreach ($st as $k => $v) {
                $strong = $k === 'Reference' ? 'font-weight:bold;letter-spacing:1px' : '';
                $body .= '<tr><td style="color:#5b6b75;width:90px;border-bottom:1px solid #dde5ea">' . h($k) . '</td>'
                       . '<td style="border-bottom:1px solid #dde5ea;' . $strong . '">' . h($v) . '</td></tr>';
            }
            $body .= '</table>';
        }
        $body .= '<p style="margin:18px 0"><strong>Trip total: ' . h(money($total)) . '</strong> <span style="color:#5b6b75">(pay at each resort)</span></p>'
               . '<p><a href="' . h($link) . '" style="display:inline-block;background:#0e7490;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px">View your trip</a></p>';

        return send_mail($email, 'We received your trip (' . $code . ')', $text, mail_html('Trip received', $body));
    } catch (Throwable $e) {
        error_log('[resort] trip email failed: ' . $e->getMessage());
        return false;
    }
}

/** Email a staff member a one-time password reset link. */
function send_staff_reset_email(string $email, string $fullName, string $link): bool
{
    global $config;
    $text = "Hi $fullName,\n\nSomeone asked to reset your staff password for {$config['resort']}.\n"
          . "Open this link within 1 hour to choose a new one:\n\n$link\n\n"
          . "If this wasn't you, ignore this email. Your password stays the same.\n";
    $html = mail_html('Reset your password',
        '<p>Hi ' . h($fullName) . ',</p><p>Someone asked to reset your staff password. This link works once, for 1 hour:</p>'
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#0e7490;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px">Choose a new password</a></p>'
        . '<p style="color:#5b6b75">If this wasn’t you, ignore this email. Your password stays the same.</p>');
    return send_mail($email, 'Reset your staff password', $text, $html);
}
