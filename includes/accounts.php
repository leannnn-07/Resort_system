<?php
declare(strict_types=1);

require_once __DIR__ . '/notify.php';

/**
 * Guest accounts: a guest row that has a password. The email is proved with a 6-digit code before
 * the account exists (and before a forgotten password can be changed).
 */

// ---------- Who is signed in ----------
function guest_logged_in(): bool
{
    return !empty($_SESSION['guest_id']);
}

/** The signed-in guest's row, or null. Signs the session out if the account no longer exists. */
function current_guest(PDO $pdo): ?array
{
    static $cache = [];
    $id = (int)($_SESSION['guest_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $stmt = $pdo->prepare('SELECT * FROM guest WHERE guest_id = ? AND ' . GUEST_CAN_LOGIN);
        $stmt->execute([$id]);
        $cache[$id] = $stmt->fetch() ?: null;
    }
    if ($cache[$id] === null) {
        unset($_SESSION['guest_id'], $_SESSION['guest_name']);
    }
    return $cache[$id];
}

function require_guest(PDO $pdo): array
{
    $g = current_guest($pdo);
    if (!$g) {
        flash('Please log in to see your account.', 'error');
        redirect(ru('login.php?next=' . urlencode('account.php')));
    }
    return $g;
}

/** Start a signed-in session for this guest id. The verified email lets checkout skip its own code. */
function log_in_guest(PDO $pdo, int $guestId): void
{
    $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM guest WHERE guest_id = ?');
    $stmt->execute([$guestId]);
    $g = $stmt->fetch();
    session_regenerate_id(true);
    $_SESSION['guest_id']   = $guestId;
    $_SESSION['guest_name'] = $g['first_name'];
    $_SESSION['verified_emails'][strtolower($g['email'])] = true;
    // They own these bookings, so open them without asking for the reference and email again.
    $stmt = $pdo->prepare('SELECT booking_ref FROM reservation WHERE guest_id = ?');
    $stmt->execute([$guestId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        $_SESSION['grants'][$ref] = true;
    }
}

function log_out_guest(): void
{
    unset($_SESSION['guest_id'], $_SESSION['guest_name'], $_SESSION['codes']);
    unset($_SESSION['verified_emails']);
    $_SESSION['grants'] = [];
    session_regenerate_id(true);
}

/** Only allow redirects to a page inside this site (no scheme, no host). */
function safe_next(mixed $next, string $default = 'account.php'): string
{
    $next = is_string($next) ? $next : '';
    return preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&.%\-]*)?$#', $next) ? $next : $default;
}

// ---------- Passwords ----------
/** Returns an error message, or '' when the password is acceptable. */
function password_problem(string $password, string $email = ''): string
{
    if (strlen($password) < 8) {
        return 'Use at least 8 characters for your password.';
    }
    if (strlen($password) > 72) {
        return 'Use 72 characters or fewer for your password.';
    }
    if ($email !== '' && strcasecmp($password, $email) === 0) {
        return 'Your password can’t be your email address.';
    }
    if (in_array(strtolower($password), ['password', 'password1', '12345678', '123456789', 'qwerty123', 'iloveyou'], true)) {
        return 'That password is too easy to guess. Choose another.';
    }
    return '';
}

/** A throwaway hash so a login for an unknown email takes as long as a real one. */
function dummy_password_check(string $password): void
{
    password_verify($password, '$2y$10$usesomesillystringforsalt.OqWAfGBzcPkx3ugG3KN7hVKdtDpb2');
}

// ---------- Email codes (kept in the session, never in the page) ----------
/**
 * Create a 6-digit code for $kind ('register' or 'reset') and email it.
 * With $real = false nothing useful is sent and no code can ever match: used when the email has
 * no account to reset, or already has one, so the page looks the same either way.
 * Throws RuntimeException with a guest-friendly message.
 */
function code_issue(PDO $pdo, string $kind, string $email, string $firstName, array $payload, bool $real = true): void
{
    if (throttle_blocked($pdo, 'code_send', 6)) {
        throw new RuntimeException('Too many codes requested. Please wait a few minutes and try again.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(8));
    if ($real && !send_verification_email($email, $firstName, $code, $kind)) {
        throw new RuntimeException('We couldn’t send an email to that address. Check it and try again, or contact us.');
    }
    throttle_fail($pdo, 'code_send');
    $_SESSION['codes'][$kind] = [
        'email'   => $email,
        'first'   => $firstName,
        'salt'    => $salt,
        'hash'    => $real ? hash('sha256', $salt . $code) : hash('sha256', $salt . bin2hex(random_bytes(8))),
        'real'    => $real,
        'payload' => $payload,
        'expires' => time() + 600,
        'sent_at' => time(),
        'tries'   => 0,
    ];
}

function code_pending(string $kind): ?array
{
    $p = $_SESSION['codes'][$kind] ?? null;
    return is_array($p) ? $p : null;
}

function code_cancel(string $kind): void
{
    unset($_SESSION['codes'][$kind]);
}

/** Seconds before another code may be requested (0 = now). */
function code_wait(string $kind): int
{
    $p = code_pending($kind);
    return $p ? max(0, 60 - (time() - (int)$p['sent_at'])) : 0;
}

/** Check what the guest typed. Returns the stored payload on success. Throws RuntimeException otherwise. */
function code_verify(PDO $pdo, string $kind, string $entered): array
{
    $p = code_pending($kind);
    if (!$p) {
        throw new RuntimeException('Start again: there is no code waiting.');
    }
    $entered = preg_replace('/\D/', '', $entered);
    if (time() > $p['expires']) {
        throw new RuntimeException('That code has expired. Send a new one.');
    }
    if ($p['tries'] >= 5 || throttle_blocked($pdo, 'code_fail', 10)) {
        throw new RuntimeException('Too many wrong codes. Send a new code and try again.');
    }
    if (!hash_equals($p['hash'], hash('sha256', $p['salt'] . $entered))) {
        $_SESSION['codes'][$kind]['tries']++;
        throttle_fail($pdo, 'code_fail');
        $left = 5 - $_SESSION['codes'][$kind]['tries'];
        throw new RuntimeException('That code isn’t right.' . ($left > 0 ? ' ' . plural($left, 'try', 'tries') . ' left.' : ' Send a new code.'));
    }
    throttle_clear($pdo, 'code_fail');
    code_cancel($kind);
    return $p['payload'];
}
