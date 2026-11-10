<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');
// The platform's own name is used wherever no single resort is selected (resort_apply() replaces these per resort).
$config['platform'] ??= 'Resort Booking';
$config['resort'] = $config['platform'];

// ---------- HTTPS ----------
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (PHP_SAPI !== 'cli' && !empty($config['force_https']) && !$isHttps) {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = preg_replace('/:\d+$/', '', $host);   // drop the http port; https uses 443
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    } else {
        http_response_code(400);
    }
    exit;
}

// ---------- Hardening: headers, sessions, errors ----------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    if ($isHttps && !empty($config['force_https'])) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
         . "script-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}
ini_set('display_errors', !empty($config['debug']) ? '1' : '0');
ini_set('session.use_strict_mode', '1');
session_name('baje_sid');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

set_exception_handler(function (Throwable $e) use ($config): void {
    error_log('[resort] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo !empty($config['debug'])
        ? '<pre style="padding:1rem;white-space:pre-wrap">' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>'
        : '<p style="font-family:sans-serif;padding:2rem">Something went wrong on our side. Please try again in a moment.</p>';
});

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Keep MySQL's clock in step with PHP's so CURDATE() and date() agree.
    $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
} catch (PDOException $e) {
    http_response_code(500);
    exit('<p style="font-family:sans-serif;padding:2rem">Could not connect to the database. '
       . 'Check <code>config.php</code> and make sure you imported <code>database/schema.sql</code>.</p>');
}

// ---------- Resorts (one platform, many resorts) ----------
$resort = null;   // the resort the current page is about (set by require_resort() or the staff sign-in)

function resort_find(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM resort WHERE slug = ? AND is_active = 1');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function resort_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM resort WHERE resort_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Make this resort's name, times and contact details the ones the rest of the code reads from $config. */
function resort_apply(array $r): void
{
    global $config, $resort;
    $resort = $r;
    $config['resort']        = $r['name'];
    $config['tagline']       = (string)$r['tagline'];
    $config['checkin_time']  = $r['checkin_time'];
    $config['checkout_time'] = $r['checkout_time'];
    $config['contact_email'] = (string)$r['contact_email'];
    $config['contact_phone'] = (string)$r['contact_phone'];
    $config['free_cancel_days'] = (int)$r['free_cancel_days'];
    $config['mail']['from_name'] = $r['name'];
}

/** Public pages: pick the resort from ?r=slug. Sends visitors to the resort list when it is missing or unknown. */
function require_resort(PDO $pdo): array
{
    $slug = (string)($_POST['r'] ?? $_GET['r'] ?? '');
    $r = $slug !== '' ? resort_find($pdo, $slug) : null;
    if (!$r) {
        redirect('index.php');
    }
    resort_apply($r);
    return $r;
}

/** Like require_resort(), but pages that work with or without a resort (login, account...) just carry on without one. */
function optional_resort(PDO $pdo): ?array
{
    $slug = (string)($_POST['r'] ?? $_GET['r'] ?? '');
    $r = $slug !== '' ? resort_find($pdo, $slug) : null;
    if ($r) {
        resort_apply($r);
    }
    return $r;
}

/** Add ?r=<slug> to an internal link, keeping any #fragment. ru('rooms.php'), ru('room.php?id=3#book'). */
function ru(string $url, ?string $slug = null): string
{
    global $resort;
    $slug ??= $resort['slug'] ?? '';
    if ($slug === '') {
        return $url;
    }
    $frag = '';
    if (($i = strpos($url, '#')) !== false) {
        $frag = substr($url, $i);
        $url  = substr($url, 0, $i);
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'r=' . rawurlencode($slug) . $frag;
}

/** Hidden field that carries the resort through a form. */
function rhidden(): string
{
    global $resort;
    return $resort ? '<input type="hidden" name="r" value="' . h($resort['slug']) . '">' : '';
}

function resort_id(): int
{
    global $resort;
    return (int)($resort['resort_id'] ?? 0);
}

/** SQL expression (for a reservation aliased v): room numbers, or "Entire resort / building / pool" for exclusive bookings. */
const ROOMS_LABEL_SQL = "CASE v.booking_scope
    WHEN 'Resort' THEN 'Entire resort'
    WHEN 'Building' THEN CONCAT('Entire building: ', COALESCE((SELECT b.name FROM building b WHERE b.building_id = v.building_id), ''))
    WHEN 'Pool' THEN CONCAT('Entire pool: ', COALESCE((SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1), ''))
    ELSE (SELECT GROUP_CONCAT(r.room_number ORDER BY r.room_number SEPARATOR ', ')
            FROM reservation_room rr JOIN room r ON r.room_id = rr.room_id WHERE rr.reservation_id = v.reservation_id) END";

const RESERVATION_FLOW = [
    'Pending'     => ['Confirmed' => 'Confirm', 'Cancelled' => 'Cancel'],
    'Confirmed'   => ['Checked-in' => 'Check in', 'Cancelled' => 'Cancel'],
    'Checked-in'  => ['Checked-out' => 'Check out'],
    'Checked-out' => [],
    'Cancelled'   => [],
];

// ---------- Output helpers ----------
function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $amount): string
{
    global $config;
    return $config['currency'] . number_format((float)$amount, 2);
}

function fdate(?string $date): string
{
    return $date ? date('D, M j, Y', strtotime($date)) : '';
}

function badge(string $status): string
{
    $class = strtolower(str_replace(' ', '-', $status));
    return '<span class="badge ' . h($class) . '">' . h($status) . '</span>';
}

function plural(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

// ---------- Flow helpers ----------
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function flash_render(): void
{
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="notice ' . h($f['type']) . '" role="status">' . h($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}

// ---------- Throttling (kept in the database so clearing cookies doesn't reset it) ----------
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** True when this IP has made too many failed attempts for $scope recently. */
function throttle_blocked(PDO $pdo, string $scope, int $limit = 6, int $window = 900): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit WHERE scope = ? AND ip = ? AND created_at > ?');
        $stmt->execute([$scope, client_ip(), date('Y-m-d H:i:s', time() - $window)]);
        return (int)$stmt->fetchColumn() >= $limit;
    } catch (PDOException $e) {
        return false;   // table not created yet: the per-session limit still applies
    }
}

function throttle_fail(PDO $pdo, string $scope): void
{
    try {
        $pdo->prepare('INSERT INTO rate_limit (scope, ip) VALUES (?, ?)')->execute([$scope, client_ip()]);
        $pdo->prepare('DELETE FROM rate_limit WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    } catch (PDOException $e) {
    }
}

function throttle_clear(PDO $pdo, string $scope): void
{
    try {
        $pdo->prepare('DELETE FROM rate_limit WHERE scope = ? AND ip = ?')->execute([$scope, client_ip()]);
    } catch (PDOException $e) {
    }
}

// ---------- Dates ----------
function today(): string
{
    return date('Y-m-d');
}

function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function nights(string $in, string $out): int
{
    return (int)round((strtotime($out) - strtotime($in)) / 86400);
}

function add_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
}

// ---------- Money helpers ----------
/** Rebuild reservation.total_amount = room rates x nights + pools x days + services. Whole-resort and whole-building bookings store a flat price instead. */
function recalc_total(PDO $pdo, int $reservationId): void
{
    $pdo->prepare(
        'UPDATE reservation r SET r.total_amount =
            (SELECT COALESCE(SUM(rr.rate_applied), 0) FROM reservation_room rr WHERE rr.reservation_id = r.reservation_id)
              * DATEDIFF(r.check_out_date, r.check_in_date)
          + (SELECT COALESCE(SUM(rp.rate_applied), 0) FROM reservation_pool rp WHERE rp.reservation_id = r.reservation_id)
              * DATEDIFF(r.check_out_date, r.check_in_date)
          + (SELECT COALESCE(SUM(rs.price_charged), 0) FROM reservation_service rs WHERE rs.reservation_id = r.reservation_id)
         WHERE r.reservation_id = ?'
    )->execute([$reservationId]);
}

/** Amount received so far (paid minus refunded). */
function amount_paid(PDO $pdo, int $reservationId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE payment_status WHEN 'Paid' THEN amount WHEN 'Refunded' THEN -amount ELSE 0 END), 0)
           FROM payment WHERE reservation_id = ?"
    );
    $stmt->execute([$reservationId]);
    return (float)$stmt->fetchColumn();
}
