<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');
// The platform's own name is used wherever no single resort is selected (resort_apply() replaces these per resort).
$config['platform'] ??= 'Vista';
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
$isWebhook = defined('RESORT_WEBHOOK') && RESORT_WEBHOOK === true;
if (PHP_SAPI !== 'cli' && !$isWebhook && !headers_sent()) {
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
if (!$isWebhook) {
    ini_set('session.use_strict_mode', '1');
    session_name('baje_sid');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

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

// Older databases: add the resort's "allow online cancellation" switch the first time the site runs (nothing to import by hand).
try {
    if (!$pdo->query("SHOW COLUMNS FROM resort LIKE 'allow_cancellation'")->fetch()) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN allow_cancellation TINYINT(1) NOT NULL DEFAULT 1 AFTER free_cancel_days');
    }
} catch (Throwable $e) {
    // The column is optional: without it every resort keeps allowing cancellation by its free-cancellation days.
}

// Older databases: login on/off switch and archive date for staff and guest accounts (nothing to import by hand).
try {
    $have = $pdo->query("SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns
                          WHERE table_schema = DATABASE()
                            AND ((table_name IN ('staff', 'guest') AND column_name IN ('login_enabled', 'archived_at'))
                              OR (table_name IN ('resort', 'building', 'room_type', 'room', 'pool', 'service', 'price_item') AND column_name = 'archived_at'))")
                ->fetchAll(PDO::FETCH_COLUMN);
    foreach (['staff', 'guest'] as $t) {
        if (!in_array("$t.login_enabled", $have, true)) { $pdo->exec("ALTER TABLE $t ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 1"); }
        if (!in_array("$t.archived_at", $have, true))   { $pdo->exec("ALTER TABLE $t ADD COLUMN archived_at DATETIME NULL"); }
    }
    foreach (['resort', 'building', 'room_type', 'room', 'pool', 'service', 'price_item'] as $t) {
        if (!in_array("$t.archived_at", $have, true))   { $pdo->exec("ALTER TABLE $t ADD COLUMN archived_at DATETIME NULL"); }
    }
} catch (Throwable $e) {
    // Without these columns sign-in still works; the on/off switch and the archive just won't be available.
}

// Linked staff/user accounts (switch between them without logging out): created on first run for older databases.
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS account_link (
        link_id       INT AUTO_INCREMENT PRIMARY KEY,
        staff_id      INT NOT NULL,
        guest_id      INT NOT NULL,
        user_to_staff TINYINT(1) NOT NULL DEFAULT 1,
        staff_to_user TINYINT(1) NOT NULL DEFAULT 0,
        created_by    INT NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_link_staff (staff_id),
        UNIQUE KEY uk_link_guest (guest_id),
        CONSTRAINT fk_link_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
        CONSTRAINT fk_link_guest FOREIGN KEY (guest_id) REFERENCES guest(guest_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {
    // Without the table, switching is simply unavailable.
}

// GCash & Maya downpayments with staff reference-number verification (created on first run for older databases).
try {
    $payCols = $pdo->query(
        "SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND ((table_name = 'resort' AND column_name IN ('online_payment_enabled', 'downpayment_percent', 'gcash_name', 'gcash_number', 'maya_name', 'maya_number'))
              OR (table_name = 'reservation' AND column_name IN ('required_downpayment'))
              OR (table_name = 'payment' AND column_name IN ('reference_no', 'sender_name', 'proof_image', 'source', 'verified_at', 'verified_by_staff_id', 'approved_by_name', 'approved_by_email', 'verification_note', 'recorded_by_staff_id')))"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('resort.online_payment_enabled', $payCols, true)) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN online_payment_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_cancellation');
    }
    if (!in_array('resort.downpayment_percent', $payCols, true)) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN downpayment_percent TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER online_payment_enabled');
    }
    if (!in_array('resort.gcash_name', $payCols, true)) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN gcash_name VARCHAR(100) NULL AFTER downpayment_percent');
    }
    if (!in_array('resort.gcash_number', $payCols, true)) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN gcash_number VARCHAR(30) NULL AFTER gcash_name');
    }
    if (!in_array('resort.maya_name', $payCols, true)) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN maya_name VARCHAR(100) NULL AFTER gcash_number');
    }
    if (!in_array('resort.maya_number', $payCols, true)) {
        $pdo->exec('ALTER TABLE resort ADD COLUMN maya_number VARCHAR(30) NULL AFTER maya_name');
    }
    if (!in_array('reservation.required_downpayment', $payCols, true)) {
        $pdo->exec('ALTER TABLE reservation ADD COLUMN required_downpayment DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount');
    }
    if (!in_array('payment.reference_no', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN reference_no VARCHAR(80) NULL AFTER payment_status');
    }
    if (!in_array('payment.sender_name', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN sender_name VARCHAR(100) NULL AFTER reference_no');
    }
    if (!in_array('payment.proof_image', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN proof_image VARCHAR(255) NULL AFTER sender_name');
    }
    if (!in_array('payment.source', $payCols, true)) {
        $pdo->exec("ALTER TABLE payment ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'Staff' AFTER sender_name");
    }
    if (!in_array('payment.verified_at', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN verified_at DATETIME NULL AFTER source');
    }
    if (!in_array('payment.verified_by_staff_id', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN verified_by_staff_id INT NULL AFTER verified_at');
    }
    if (!in_array('payment.approved_by_name', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN approved_by_name VARCHAR(120) NULL AFTER verified_by_staff_id');
    }
    if (!in_array('payment.approved_by_email', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN approved_by_email VARCHAR(120) NULL AFTER approved_by_name');
        // Backfill already-verified or staff-recorded rows so audit history has staff name & email.
        $pdo->exec(
            'UPDATE payment p
               JOIN staff s ON s.staff_id = COALESCE(p.verified_by_staff_id, p.recorded_by_staff_id)
                SET p.approved_by_name  = s.full_name,
                    p.approved_by_email = s.email
              WHERE p.approved_by_name IS NULL'
        );
    }
    if (!in_array('payment.verification_note', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN verification_note VARCHAR(255) NULL AFTER verified_by_staff_id');
    }
    if (!in_array('payment.recorded_by_staff_id', $payCols, true)) {
        $pdo->exec('ALTER TABLE payment ADD COLUMN recorded_by_staff_id INT NULL AFTER verification_note');
    }
    $locLen = (int)$pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resort' AND COLUMN_NAME = 'location'")->fetchColumn();
    if ($locLen > 0 && $locLen < 220) {
        $pdo->exec('ALTER TABLE resort MODIFY COLUMN location VARCHAR(220) NULL');
    }
    $bpCols = $pdo->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('building', 'pool') AND COLUMN_NAME = 'photo'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('building.photo', $bpCols, true)) {
        $pdo->exec('ALTER TABLE building ADD COLUMN photo VARCHAR(200) NULL AFTER description');
    }
    if (!in_array('pool.photo', $bpCols, true)) {
        $pdo->exec('ALTER TABLE pool ADD COLUMN photo VARCHAR(200) NULL AFTER description');
    }
    $hasRefIdx = $pdo->query("SHOW INDEX FROM payment WHERE Key_name = 'idx_pay_ref'")->fetch();
    if (!$hasRefIdx) {
        $pdo->exec('ALTER TABLE payment ADD INDEX idx_pay_ref (method, reference_no)');
    }
} catch (Throwable $e) {
    error_log('[resort] payment schema check failed: ' . $e->getMessage());
}

// Staff / admin / super-admin notifications (created on first run for older databases).
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS staff_notification (
        notif_id       INT AUTO_INCREMENT PRIMARY KEY,
        resort_id      INT NOT NULL,
        reservation_id INT NULL,
        kind           VARCHAR(30) NOT NULL,
        title          VARCHAR(120) NOT NULL,
        body           VARCHAR(220) NOT NULL,
        link           VARCHAR(200) NOT NULL,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sn_resort (resort_id, created_at),
        INDEX idx_sn_res (reservation_id),
        CONSTRAINT fk_sn_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE,
        CONSTRAINT fk_sn_res    FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS staff_notification_read (
        staff_id INT NOT NULL,
        notif_id INT NOT NULL,
        read_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (staff_id, notif_id),
        CONSTRAINT fk_snr_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
        CONSTRAINT fk_snr_notif FOREIGN KEY (notif_id) REFERENCES staff_notification(notif_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {
    error_log('[resort] notification schema check failed: ' . $e->getMessage());
}

// Single-cottage day-use bookings (created on first run for older databases).
try {
    $cotCols = $pdo->query(
        "SELECT column_name FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'price_item' AND column_name IN ('max_guests', 'quantity_available')"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('max_guests', $cotCols, true)) {
        $pdo->exec('ALTER TABLE price_item ADD COLUMN max_guests INT NOT NULL DEFAULT 15 AFTER price');
    }
    if (!in_array('quantity_available', $cotCols, true)) {
        $pdo->exec('ALTER TABLE price_item ADD COLUMN quantity_available INT NOT NULL DEFAULT 5 AFTER max_guests');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS reservation_cottage (
        reservation_id INT NOT NULL,
        price_item_id  INT NOT NULL,
        cottage_name   VARCHAR(80) NOT NULL,
        cottage_rate   DECIMAL(10,2) NOT NULL,
        entrance_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity       INT NOT NULL DEFAULT 1,
        PRIMARY KEY (reservation_id, price_item_id),
        CONSTRAINT fk_rcot_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
        CONSTRAINT fk_rcot_item FOREIGN KEY (price_item_id)  REFERENCES price_item(price_item_id)  ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Allow booking_scope = 'Cottage' in reservation check constraint if not already included.
    $cc = $pdo->query(
        "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'reservation' AND CONSTRAINT_NAME = 'ck_res_scope'"
    )->fetchColumn();
    if ($cc !== false && !str_contains((string)$cc, 'Cottage')) {
        $pdo->exec('ALTER TABLE reservation DROP CONSTRAINT ck_res_scope');
        $pdo->exec("ALTER TABLE reservation ADD CONSTRAINT ck_res_scope CHECK (booking_scope IN ('Rooms','Cottage','Building','Pool','Resort'))");
    }
} catch (Throwable $e) {
    error_log('[resort] cottage booking schema check failed: ' . $e->getMessage());
}

// Over-the-counter discounts off original price (Senior / PWD pro-rated share + Admin / Superadmin special offers; verified & approved over the counter by Staff/Admin/Superadmin, with NO ID photo upload or ID number storage).
try {
    // Remove separate Senior / PWD price list rows so discounts always come from the original price.
    $pdo->exec("DELETE FROM price_item WHERE category = 'Entrance fee' AND (item_name LIKE '%Senior%' OR item_name LIKE '%PWD%')");
    // Do not store or record any guest valid ID type or ID number in the guest table.
    $pdo->exec('UPDATE guest SET id_type = NULL, id_number = NULL WHERE id_type IS NOT NULL OR id_number IS NOT NULL');

    // Optional middle_name on guest and reservation_companion tables.
    $midCols = $pdo->query(
        "SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name IN ('guest', 'reservation_companion')
            AND column_name = 'middle_name'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('guest.middle_name', $midCols, true)) {
        $pdo->exec('ALTER TABLE guest ADD COLUMN middle_name VARCHAR(60) NULL AFTER first_name');
    }
    if (!in_array('reservation_companion.middle_name', $midCols, true)) {
        $pdo->exec('ALTER TABLE reservation_companion ADD COLUMN middle_name VARCHAR(60) NULL AFTER first_name');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS resort_discount (
        discount_id   INT AUTO_INCREMENT PRIMARY KEY,
        resort_id     INT NULL,
        title         VARCHAR(100) NOT NULL,
        percent_off   DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        start_date    DATE NULL,
        end_date      DATE NULL,
        is_active     TINYINT(1) NOT NULL DEFAULT 1,
        note          VARCHAR(200) NULL,
        created_by    INT NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at   DATETIME NULL,
        INDEX idx_rd_resort (resort_id, is_active),
        CONSTRAINT fk_rd_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $discCols = $pdo->query(
        "SELECT column_name FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'reservation'
            AND column_name IN (
              'discount_type','discount_label','discount_percent','discount_amount','discount_status','discount_id_image','discount_offer_id','discount_verified_at','discount_verified_by','discount_approver_name','discount_note',
              'has_senior_claim','claimed_senior_count','verified_senior_count','senior_verification_status','senior_full_name','osca_or_gov_id_number','id_image_path','senior_physical_present','vat_exempt_sales','vatable_sales','vat_amount','senior_discount_amount','overpayment_amount','overpayment_resolution'
            )"
    )->fetchAll(PDO::FETCH_COLUMN);
    $addCol = function (string $col, string $def) use ($pdo, $discCols): void {
        if (!in_array($col, $discCols, true)) {
            $pdo->exec("ALTER TABLE reservation ADD COLUMN $col $def");
        }
    };
    $addCol('discount_type', 'VARCHAR(20) NULL AFTER total_amount');
    $addCol('discount_label', 'VARCHAR(120) NULL AFTER discount_type');
    $addCol('discount_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_label');
    $addCol('discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_percent');
    $addCol('discount_status', 'VARCHAR(20) NULL AFTER discount_amount');
    $addCol('discount_id_image', 'VARCHAR(255) NULL AFTER discount_status');
    $addCol('discount_offer_id', 'INT NULL AFTER discount_id_image');
    $addCol('discount_verified_at', 'DATETIME NULL AFTER discount_offer_id');
    $addCol('discount_verified_by', 'INT NULL AFTER discount_verified_at');
    $addCol('discount_approver_name', 'VARCHAR(120) NULL AFTER discount_verified_by');
    $addCol('discount_note', 'VARCHAR(255) NULL AFTER discount_approver_name');
    $addCol('has_senior_claim', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_note');
    $addCol('claimed_senior_count', 'INT NOT NULL DEFAULT 0 AFTER has_senior_claim');
    $addCol('verified_senior_count', 'INT NOT NULL DEFAULT 0 AFTER claimed_senior_count');
    $addCol('senior_verification_status', 'VARCHAR(20) NULL AFTER verified_senior_count');
    $addCol('senior_full_name', 'VARCHAR(255) NULL AFTER senior_verification_status');
    $addCol('osca_or_gov_id_number', 'VARCHAR(160) NULL AFTER senior_full_name');
    $addCol('id_image_path', 'VARCHAR(255) NULL AFTER osca_or_gov_id_number');
    $addCol('senior_physical_present', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER id_image_path');
    $addCol('vat_exempt_sales', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER senior_physical_present');
    $addCol('vatable_sales', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vat_exempt_sales');
    $addCol('vat_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vatable_sales');
    $addCol('senior_discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vat_amount');
    $addCol('overpayment_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER senior_discount_amount');
    $addCol('overpayment_resolution', 'VARCHAR(30) NULL AFTER overpayment_amount');
    // Ensure no ID image paths or ID numbers are ever stored in reservation rows.
    $pdo->exec('UPDATE reservation SET discount_id_image = NULL, id_image_path = NULL, osca_or_gov_id_number = NULL WHERE discount_id_image IS NOT NULL OR id_image_path IS NOT NULL OR osca_or_gov_id_number IS NOT NULL');
} catch (Throwable $e) {
    error_log('[resort] discount schema check failed: ' . $e->getMessage());
}

/** SQL for "this account may sign in": has a password, login not switched off, not archived. */
const STAFF_CAN_LOGIN = 'password_hash IS NOT NULL AND login_enabled = 1 AND archived_at IS NULL';
const GUEST_CAN_LOGIN = 'password_hash IS NOT NULL AND login_enabled = 1 AND archived_at IS NULL';

// ---------- Resorts (one platform, many resorts) ----------
$resort = null;   // the resort the current page is about (set by require_resort() or the staff sign-in)

function resort_find(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM resort WHERE slug = ? AND is_active = 1 AND archived_at IS NULL');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function resort_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM resort WHERE resort_id = ? AND archived_at IS NULL');
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
    $config['allow_cancellation'] = (int)($r['allow_cancellation'] ?? 1) === 1;
    $config['online_payment_enabled'] = (int)($r['online_payment_enabled'] ?? 1) === 1;
    $config['downpayment_percent'] = max(0, min(100, (int)($r['downpayment_percent'] ?? 50)));
    $config['gcash_name']   = (string)($r['gcash_name'] ?? '');
    $config['gcash_number'] = (string)($r['gcash_number'] ?? '');
    $config['maya_name']    = (string)($r['maya_name'] ?? '');
    $config['maya_number']  = (string)($r['maya_number'] ?? '');
    $config['mail']['from_name'] = $r['name'];
}

/** Public pages: pick the resort from ?r=slug. Sends visitors to the resort list when it is missing or unknown. */
function require_resort(PDO $pdo): array
{
    $slug = (string)($_POST['r'] ?? $_GET['r'] ?? '');
    $r = $slug !== '' ? resort_find($pdo, $slug) : null;
    if (!$r) {
        redirect('resorts.php');
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

/** SQL expression (for a reservation aliased v): room numbers, cottage name, or "Entire resort / building / pool". */
const ROOMS_LABEL_SQL = "CASE v.booking_scope
    WHEN 'Cottage' THEN CONCAT('Cottage: ', COALESCE((SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1), ''))
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

/** Queue a message for the next page. $link = ['page.php', 'Link text'] adds a link after the message. */
function flash(string $message, string $type = 'ok', array $link = []): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type, 'link' => $link];
}

function flash_render(): void
{
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $a = !empty($f['link'][0]) ? ' <a href="' . h($f['link'][0]) . '">' . h($f['link'][1] ?? '') . '</a>' : '';
        echo '<div class="notice ' . h($f['type']) . '" role="status">' . h($f['message']) . $a . '</div>';
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
/** Raw subtotal before any approved discount = rooms x nights + pools x days + cottages/entrance + services. */
function reservation_gross_subtotal(PDO $pdo, int $reservationId): float
{
    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COALESCE(SUM(rr.rate_applied), 0) FROM reservation_room rr WHERE rr.reservation_id = r.reservation_id)
              * DATEDIFF(r.check_out_date, r.check_in_date)
          + (SELECT COALESCE(SUM(rp.rate_applied), 0) FROM reservation_pool rp WHERE rp.reservation_id = r.reservation_id)
              * DATEDIFF(r.check_out_date, r.check_in_date)
          + (SELECT COALESCE(SUM(rc.cottage_rate * rc.quantity + rc.entrance_total), 0) FROM reservation_cottage rc WHERE rc.reservation_id = r.reservation_id)
          + (SELECT COALESCE(SUM(rs.price_charged), 0) FROM reservation_service rs WHERE rs.reservation_id = r.reservation_id)
          FROM reservation r WHERE r.reservation_id = ?'
    );
    $stmt->execute([$reservationId]);
    return round(max(0.0, (float)$stmt->fetchColumn()), 2);
}

/**
 * Philippine RA 9994 & BIR RR 7-2010 Pro-Rated Discount & VAT Split Calculator.
 * Regular rate is 12% VAT-inclusive. Even if the senior citizen is the primary booker/payor,
 * the 20% discount + 12% VAT exemption applies strictly to the personal pro-rated share of verified seniors.
 *
 * Formulas:
 *   VAT_Exclusive_Total   = Regular_Rate / 1.12
 *   Per_Pax_Base_Share    = VAT_Exclusive_Total / Total_Guests_In_Room
 *   Senior_Share_Total    = (Per_Pax_Base_Share * 0.80) * Verified_Senior_Count
 *   Regular_Share_Total   = (Per_Pax_Base_Share * 1.12) * Regular_Guest_Count
 *   Final_Discounted_Total = Senior_Share_Total + Regular_Share_Total
 */
function calculate_ra9994_discount(
    float $regularTotal,
    int $totalGuests,
    int $verifiedSeniorCount,
    float $downpaymentPaid = 0.0
): array {
    $regularTotal = round(max(0.0, $regularTotal), 2);
    $totalGuests  = max(1, $totalGuests);
    $seniors      = max(0, min($totalGuests, $verifiedSeniorCount));
    $regularPax   = $totalGuests - $seniors;

    $vatExclusiveTotal = $regularTotal / 1.12;
    $perPaxBaseShare   = $vatExclusiveTotal / $totalGuests;

    // Senior share: VAT-exempt base share minus 20% statutory discount
    $seniorBaseShare      = $perPaxBaseShare * $seniors;
    $seniorDiscountAmount = $seniorBaseShare * 0.20;
    $seniorShareTotal     = $seniorBaseShare * 0.80; // VAT-Exempt Sales billed

    // Regular companion share: VAT-exclusive base + 12% VAT
    $vatableSales      = $perPaxBaseShare * $regularPax;
    $vatAmount         = $vatableSales * 0.12;
    $regularShareTotal = $perPaxBaseShare * 1.12 * $regularPax;

    $finalDiscountedTotal = round($seniorShareTotal + $regularShareTotal, 2);
    $totalSavingsFromReg  = round(max(0.0, $regularTotal - $finalDiscountedTotal), 2);
    $rawBalance           = round($finalDiscountedTotal - $downpaymentPaid, 2);
    $remainingBalance     = max(0.0, $rawBalance);
    $overpaymentAmount    = $rawBalance < -0.004 ? round(abs($rawBalance), 2) : 0.0;

    return [
        'regular_total'          => $regularTotal,
        'total_guests'           => $totalGuests,
        'verified_senior_count'  => $seniors,
        'regular_guest_count'    => $regularPax,
        'vat_exclusive_total'    => round($vatExclusiveTotal, 2),
        'per_pax_base_share'     => round($perPaxBaseShare, 2),
        'vat_exempt_sales'       => round($seniorBaseShare, 2),
        'senior_discount_amount' => round($seniorDiscountAmount, 2),
        'senior_share_total'     => round($seniorShareTotal, 2),
        'vatable_sales'          => round($vatableSales, 2),
        'vat_amount'             => round($vatAmount, 2),
        'regular_share_total'    => round($regularShareTotal, 2),
        'final_discounted_total' => $finalDiscountedTotal,
        'total_savings'          => $totalSavingsFromReg,
        'downpayment_paid'       => round($downpaymentPaid, 2),
        'raw_balance'            => $rawBalance,
        'remaining_balance'      => $remainingBalance,
        'overpayment_amount'     => $overpaymentAmount,
        'needs_refund_or_credit' => $overpaymentAmount > 0.004,
    ];
}

/**
 * Rebuild reservation.total_amount and BIR split-billing fields from original subtotal.
 * Downpayment during online checkout is strictly calculated from the regular (non-discounted) total,
 * and the discount is only deducted upon front-desk/admin verification.
 */
function recalc_total(PDO $pdo, int $reservationId): void
{
    $gross = reservation_gross_subtotal($pdo, $reservationId);
    $st = $pdo->prepare(
        'SELECT resort_id, num_adults, num_children, num_guests,
                discount_type, discount_percent, discount_status,
                has_senior_claim, claimed_senior_count, verified_senior_count, senior_verification_status,
                required_downpayment
           FROM reservation WHERE reservation_id = ?'
    );
    $st->execute([$reservationId]);
    $row = $st->fetch() ?: [];

    $totalGuests = max(1, (int)($row['num_guests'] ?? ((int)($row['num_adults'] ?? 1) + (int)($row['num_children'] ?? 0))));
    $discType    = (string)($row['discount_type'] ?? '');
    $discStatus  = (string)($row['discount_status'] ?? '');
    $srStatus    = (string)($row['senior_verification_status'] ?? '');
    $hasSrClaim  = (int)($row['has_senior_claim'] ?? 0) === 1 || in_array($discType, ['Senior', 'PWD'], true);
    $paidSoFar   = amount_paid($pdo, $reservationId);

    if ($hasSrClaim) {
        $claimedCnt  = max(1, min($totalGuests, (int)($row['claimed_senior_count'] ?: 1)));
        $verifiedCnt = ($srStatus === 'verified' || $discStatus === 'Approved')
            ? max(1, min($totalGuests, (int)($row['verified_senior_count'] ?: $claimedCnt)))
            : 0;
        $calc = calculate_ra9994_discount($gross, $totalGuests, $verifiedCnt, $paidSoFar);
        // Estimate for pending display
        $estCalc = calculate_ra9994_discount($gross, $totalGuests, $claimedCnt, $paidSoFar);
        $net     = $verifiedCnt > 0 ? $calc['final_discounted_total'] : $gross;
        $discAmt = $verifiedCnt > 0 ? $calc['total_savings'] : $estCalc['total_savings'];

        $pdo->prepare(
            'UPDATE reservation
                SET discount_amount = ?,
                    vat_exempt_sales = ?,
                    vatable_sales = ?,
                    vat_amount = ?,
                    senior_discount_amount = ?,
                    overpayment_amount = ?,
                    total_amount = ?
              WHERE reservation_id = ?'
        )->execute([
            $discAmt,
            $calc['vat_exempt_sales'],
            $calc['vatable_sales'],
            $calc['vat_amount'],
            $calc['senior_discount_amount'],
            $calc['overpayment_amount'],
            $net,
            $reservationId,
        ]);
    } else {
        // Promotional / Special Offer percentage discount (or no discount)
        $pct     = max(0.0, min(90.0, (float)($row['discount_percent'] ?? 0)));
        $discAmt = ($pct > 0 && in_array($discStatus, ['Pending', 'Approved'], true))
            ? round($gross * $pct / 100, 2)
            : 0.0;
        $net     = ($discStatus === 'Approved') ? round(max(0.0, $gross - $discAmt), 2) : $gross;
        $vatable = round($net / 1.12, 2);
        $vatAmt  = round($net - $vatable, 2);
        $overpay = ($paidSoFar > $net + 0.004) ? round($paidSoFar - $net, 2) : 0.0;

        $pdo->prepare(
            'UPDATE reservation
                SET discount_amount = ?,
                    vat_exempt_sales = 0.00,
                    vatable_sales = ?,
                    vat_amount = ?,
                    senior_discount_amount = 0.00,
                    overpayment_amount = ?,
                    total_amount = ?
              WHERE reservation_id = ?'
        )->execute([$discAmt, $vatable, $vatAmt, $overpay, $net, $reservationId]);
    }

    // Rule 1: Downpayment during online checkout is strictly calculated from the REGULAR (non-discounted) total ($gross).
    if (!empty($row['resort_id']) && (float)($row['required_downpayment'] ?? 0) > 0) {
        $rStmt = $pdo->prepare('SELECT online_payment_enabled, downpayment_percent FROM resort WHERE resort_id = ?');
        $rStmt->execute([(int)$row['resort_id']]);
        if ($rRow = $rStmt->fetch()) {
            $on  = (int)($rRow['online_payment_enabled'] ?? 1) === 1;
            $dpP = $on ? max(0, min(100, (int)($rRow['downpayment_percent'] ?? 50))) : 0;
            $reqDp = ($on && $dpP > 0) ? ($dpP >= 100 ? $gross : round($gross * $dpP / 100, 2)) : 0.0;
            $pdo->prepare('UPDATE reservation SET required_downpayment = ? WHERE reservation_id = ?')
                ->execute([$reqDp, $reservationId]);
        }
    }
}

/** Amount received so far (paid minus refunded). */
/**
 * Can a guest cancel this booking online? It follows the resort's own terms: a switch (online cancellation allowed or not)
 * and how many days before check-in the last cancellation is. $b needs: status, check_in_date, allow_cancellation, free_cancel_days.
 * Returns: active (still a live booking), can (the button works), note (full sentence), short (one line for tables).
 */
function cancel_policy(array $b, float $paid): array
{
    if (!in_array($b['status'] ?? '', ['Pending', 'Confirmed'], true)) {
        return ['active' => false, 'can' => false, 'note' => '', 'short' => ''];
    }
    $days = max(0, (int)($b['free_cancel_days'] ?? 0));
    $last = add_days((string)$b['check_in_date'], -$days);
    if ((int)($b['allow_cancellation'] ?? 1) !== 1) {
        return ['active' => true, 'can' => false,
                'note' => 'This resort does not take cancellations online. Please contact the resort to change or cancel this booking.',
                'short' => 'Not cancellable online'];
    }
    if (today() > $last) {
        return ['active' => true, 'can' => false,
                'note' => 'Online cancellation closed on ' . fdate($last) . ' (' . ($days === 0 ? 'the resort only allows it until the day before arrival' : plural($days, 'day') . ' before check-in')
                        . '). Please contact the resort.',
                'short' => 'Cancellation closed ' . fdate($last)];
    }
    if ($paid > 0.004) {
        return ['active' => true, 'can' => false,
                'note' => 'A payment is on file for this booking. Please contact the resort to cancel and arrange a refund.',
                'short' => 'Contact resort to cancel'];
    }
    return ['active' => true, 'can' => true,
            'note' => 'You can cancel online until ' . fdate($last) . ($days > 0 ? ' (' . plural($days, 'day') . ' before check-in)' : '') . '. Your rooms are released straight away.',
            'short' => 'Free to cancel until ' . fdate($last)];
}

function amount_paid(PDO $pdo, int $reservationId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE payment_status WHEN 'Paid' THEN amount WHEN 'Refunded' THEN -amount ELSE 0 END), 0)
           FROM payment WHERE reservation_id = ?"
    );
    $stmt->execute([$reservationId]);
    return (float)$stmt->fetchColumn();
}


/** Inverted-pyramid display name: "Lastname, Firstname" or "Lastname, Firstname Middlename". */
function format_name_inv(string $last, string $first, ?string $middle = ''): string
{
    $l = trim($last);
    $f = trim($first);
    $m = trim((string)$middle);
    $fm = trim($f . ($m !== '' ? ' ' . $m : ''));
    if ($l !== '' && $fm !== '') {
        return $l . ', ' . $fm;
    }
    return $l !== '' ? $l : $fm;
}

/** Standard full name: "Firstname Middlename Lastname". */
function format_full_name(string $first, ?string $middle, string $last): string
{
    return implode(' ', array_filter([trim($first), trim((string)$middle), trim($last)], fn($s) => $s !== ''));
}

/**
 * Parse a comma-separated inverted-pyramid address into structured parts:
 * City, Barangay, Street / Drive, House / Building no.
 */
function address_parse(?string $address): array
{
    $raw = trim((string)$address);
    if ($raw === '') {
        return ['city' => '', 'barangay' => '', 'street' => '', 'house' => ''];
    }
    $parts = array_map('trim', explode(',', $raw, 4));
    return [
        'city'     => $parts[0] ?? '',
        'barangay' => $parts[1] ?? '',
        'street'   => $parts[2] ?? '',
        'house'    => $parts[3] ?? '',
    ];
}

/**
 * Compose an inverted-pyramid address string ("City, Barangay, Street/Drive, House no.")
 * from structured POST fields (with fallback to a single legacy field).
 */
function address_compose(array $post, string $prefix = 'addr_', string $fallbackKey = 'address'): string
{
    $hasParts = array_key_exists($prefix . 'city', $post)
             || array_key_exists($prefix . 'barangay', $post)
             || array_key_exists($prefix . 'street', $post)
             || array_key_exists($prefix . 'house', $post);
    if (!$hasParts) {
        return trim((string)($post[$fallbackKey] ?? ''));
    }
    $parts = [];
    foreach (['city', 'barangay', 'street', 'house'] as $k) {
        $v = trim((string)($post[$prefix . $k] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return implode(', ', $parts);
}

/**
 * Render balanced 2x2 inverted-pyramid address inputs:
 * Row 1: City | Barangay
 * Row 2: Street / Drive | House / Bldg. no.
 */
function address_fields_html(array $parts, string $prefix = 'addr_', bool $requireComplete = false): string
{
    $req = $requireComplete ? ' required' : '';
    $c = h((string)($parts['city'] ?? ''));
    $b = h((string)($parts['barangay'] ?? ''));
    $s = h((string)($parts['street'] ?? ''));
    $n = h((string)($parts['house'] ?? ''));
    $houseLbl = $requireComplete ? 'House / Lot / Bldg. no.' : 'House / Bldg. no. <small class="muted">(optional)</small>';
    return '<div class="grid-form two-col">'
         . '<label><span>City</span><input name="' . h($prefix) . 'city" value="' . $c . '" maxlength="60" placeholder="e.g. Zamboanga City" autocomplete="address-level2"' . $req . '></label>'
         . '<label><span>Barangay</span><input name="' . h($prefix) . 'barangay" value="' . $b . '" maxlength="60" placeholder="e.g. Brgy. Upper Calarian" autocomplete="address-level3"' . $req . '></label>'
         . '<label><span>Street / Drive</span><input name="' . h($prefix) . 'street" value="' . $s . '" maxlength="70" placeholder="e.g. RT Lim Blvd. / Gov. Ramos Ave." autocomplete="street-address"' . $req . '></label>'
         . '<label><span>' . $houseLbl . '</span><input name="' . h($prefix) . 'house" value="' . $n . '" maxlength="40" placeholder="e.g. #45 / Lot 12 / Km. 7"></label>'
         . '</div>';
}


/** Short inverted-pyramid address for cards: "City, Barangay" only. */
function address_short(?string $address): string
{
    $p = address_parse($address);
    $parts = array_values(array_filter([$p['city'], $p['barangay']], fn($v) => $v !== ''));
    return $parts ? implode(', ', $parts) : trim((string)$address);
}


/** Verified public URL for a building/pool photo stored under /assets, or '' if missing. */
function exclusive_photo_url(?string $rel): string
{
    $f = trim((string)$rel);
    if ($f === '' || !preg_match('#^[a-z0-9_\-]+/[a-z0-9_\-.]+\.(jpe?g|png|webp)$#i', $f)) {
        return '';
    }
    $full = __DIR__ . '/../assets/' . $f;
    return is_file($full) ? ('assets/' . $f . '?v=' . filemtime($full)) : '';
}
