<?php
declare(strict_types=1);

/**
 * Automatic account switching between Staff / Resort Admin / Super Admin accounts and User (Guest) accounts.
 * Every active staff or admin account can switch to a user account and back without manual setup:
 *  - Staff/Admin -> User: automatically links to an existing user account with the same email, or creates one.
 *  - User -> Staff/Admin: automatically recognizes any linked or same-email active staff/admin account.
 */

/** Split a full name ("Maria Clara Santos") into [first, last] for auto-created guest rows. */
function split_staff_name(string $fullName): array
{
    $fullName = trim($fullName);
    if ($fullName === '') {
        return ['Staff', 'User'];
    }
    $parts = preg_split('/\s+/', $fullName) ?: [$fullName];
    if (count($parts) === 1) {
        return [$parts[0], 'Staff'];
    }
    $last  = array_pop($parts);
    $first = implode(' ', $parts);
    return [mb_substr($first, 0, 60), mb_substr($last, 0, 60)];
}

/** True / link info when an active staff/admin account can switch to a user account (always allowed for active staff/admins). */
function link_to_user(PDO $pdo, int $staffId): ?array
{
    if ($staffId <= 0) {
        return null;
    }
    // 1. Existing active link
    $st = $pdo->prepare(
        'SELECT l.*, g.first_name, g.last_name, g.email AS guest_email
           FROM account_link l
           JOIN guest g ON g.guest_id = l.guest_id
           JOIN staff s ON s.staff_id = l.staff_id
          WHERE l.staff_id = ?
            AND s.' . str_replace(' AND ', ' AND s.', STAFF_CAN_LOGIN) . '
            AND g.' . str_replace(' AND ', ' AND g.', GUEST_CAN_LOGIN)
    );
    $st->execute([$staffId]);
    if ($row = $st->fetch()) {
        return $row;
    }

    // 2. Otherwise, any active staff/admin account can automatically provision/link its user account on switch.
    $st = $pdo->prepare('SELECT staff_id, full_name, email FROM staff WHERE staff_id = ? AND ' . STAFF_CAN_LOGIN);
    $st->execute([$staffId]);
    $staff = $st->fetch();
    if (!$staff) {
        return null;
    }
    return [
        'staff_id'    => (int)$staff['staff_id'],
        'guest_id'    => 0,
        'guest_email' => (string)$staff['email'],
        'auto'        => true,
    ];
}

/**
 * Guarantee an active linked user (guest) account exists for this staff/admin and return the link row.
 * Creates or activates the matching email guest row and persists the bidirectional account_link row.
 */
function ensure_staff_user_link(PDO $pdo, int $staffId): ?array
{
    $st = $pdo->prepare('SELECT * FROM staff WHERE staff_id = ? AND ' . STAFF_CAN_LOGIN);
    $st->execute([$staffId]);
    $staff = $st->fetch();
    if (!$staff) {
        return null;
    }

    // Already linked to an active login-capable guest?
    $existing = $pdo->prepare(
        'SELECT l.*, g.first_name, g.last_name, g.email AS guest_email
           FROM account_link l JOIN guest g ON g.guest_id = l.guest_id
          WHERE l.staff_id = ? AND g.' . str_replace(' AND ', ' AND g.', GUEST_CAN_LOGIN)
    );
    $existing->execute([$staffId]);
    if ($row = $existing->fetch()) {
        $pdo->prepare('UPDATE account_link SET user_to_staff = 1, staff_to_user = 1 WHERE link_id = ?')->execute([(int)$row['link_id']]);
        return $row;
    }

    $email = trim((string)$staff['email']);
    if ($email === '') {
        return null;
    }

    // Find an existing guest row with the same email (active, not archived), or create one.
    $gStmt = $pdo->prepare('SELECT * FROM guest WHERE LOWER(email) = LOWER(?) AND archived_at IS NULL ORDER BY (password_hash IS NOT NULL) DESC, guest_id ASC LIMIT 1');
    $gStmt->execute([$email]);
    $guest = $gStmt->fetch();

    if ($guest) {
        if ((int)($guest['login_enabled'] ?? 1) !== 1) {
            return null;   // explicitly disabled guest account
        }
        $guestId = (int)$guest['guest_id'];
        if (empty($guest['password_hash'])) {
            $pdo->prepare('UPDATE guest SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE guest_id = ?')
                ->execute([$staff['password_hash'], $guestId]);
        } elseif (empty($guest['email_verified_at'])) {
            $pdo->prepare('UPDATE guest SET email_verified_at = NOW() WHERE guest_id = ?')->execute([$guestId]);
        }
    } else {
        [$first, $last] = split_staff_name((string)$staff['full_name']);
        $pdo->prepare(
            'INSERT INTO guest (first_name, last_name, email, password_hash, email_verified_at, login_enabled)
             VALUES (?, ?, ?, ?, NOW(), 1)'
        )->execute([$first, $last, $email, $staff['password_hash']]);
        $guestId = (int)$pdo->lastInsertId();
    }

    // Remove any stale link pointing to a dead/different record for either side, then save bidirectional link.
    $pdo->prepare('DELETE FROM account_link WHERE staff_id = ? OR guest_id = ?')->execute([$staffId, $guestId]);
    $pdo->prepare(
        'INSERT INTO account_link (staff_id, guest_id, user_to_staff, staff_to_user, created_by)
         VALUES (?, ?, 1, 1, ?)'
    )->execute([$staffId, $guestId, $staffId]);

    $existing->execute([$staffId]);
    return $existing->fetch() ?: null;
}

/** The active staff or admin account this user account may switch to (automatically matched via link or email). */
function link_to_staff(PDO $pdo, int $guestId): ?array
{
    if ($guestId <= 0) {
        return null;
    }
    // 1. Existing link to an active staff/admin account
    $st = $pdo->prepare(
        'SELECT l.staff_id, l.guest_id, s.full_name, s.email AS staff_email, s.access, s.resort_id
           FROM account_link l
           JOIN staff s ON s.staff_id = l.staff_id
           JOIN guest g ON g.guest_id = l.guest_id
          WHERE l.guest_id = ?
            AND g.email_verified_at IS NOT NULL
            AND g.' . str_replace(' AND ', ' AND g.', GUEST_CAN_LOGIN) . '
            AND s.' . str_replace(' AND ', ' AND s.', STAFF_CAN_LOGIN)
    );
    $st->execute([$guestId]);
    if ($row = $st->fetch()) {
        return $row;
    }

    // 2. Automatic match by verified email against any active staff, resort admin, or super admin
    $gStmt = $pdo->prepare('SELECT guest_id, email FROM guest WHERE guest_id = ? AND email_verified_at IS NOT NULL AND ' . GUEST_CAN_LOGIN);
    $gStmt->execute([$guestId]);
    $guest = $gStmt->fetch();
    if (!$guest || trim((string)$guest['email']) === '') {
        return null;
    }
    $sStmt = $pdo->prepare('SELECT staff_id, full_name, email AS staff_email, access, resort_id FROM staff WHERE LOWER(email) = LOWER(?) AND ' . STAFF_CAN_LOGIN . ' LIMIT 1');
    $sStmt->execute([$guest['email']]);
    $staff = $sStmt->fetch();
    if (!$staff) {
        return null;
    }

    // Persist the automatic link if neither side is bound to someone else
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO account_link (staff_id, guest_id, user_to_staff, staff_to_user)
             VALUES (?, ?, 1, 1)'
        )->execute([(int)$staff['staff_id'], $guestId]);
    } catch (Throwable $e) {
        // Non-fatal: still allow switching
    }

    return [
        'staff_id'    => (int)$staff['staff_id'],
        'guest_id'    => $guestId,
        'full_name'   => (string)$staff['full_name'],
        'staff_email' => (string)$staff['staff_email'],
        'access'      => (string)$staff['access'],
        'resort_id'   => $staff['resort_id'],
    ];
}
