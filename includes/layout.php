<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Web fonts (optional: the site falls back to system fonts if offline). Public pages use Fraunces + Inter, the staff desk uses Sora. */
function font_links(bool $public = false): string
{
    $family = $public
        ? 'family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600'
        : 'family=Sora:wght@500;600;700';
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
         . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?' . $family . '&display=swap">';
}

/** Favicon, theme colour and a short description for search results and link previews. */
function head_meta(string $title, string $description = ''): string
{
    global $config, $resort;
    $desc = $description !== '' ? $description : ($resort ? $config['resort'] . '. ' . $config['tagline'] : $config['platform'] . '. Book rooms, a pool, a building or a whole resort.');
    return '<meta name="description" content="' . h($desc) . '">'
         . '<meta name="theme-color" content="#061220">'
         . '<meta property="og:title" content="' . h($title . ' · ' . ($resort ? $config['resort'] : $config['platform'])) . '">'
         . '<meta property="og:description" content="' . h($desc) . '">'
         . '<meta property="og:type" content="website">'
         . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 32 32%27%3E%3Cdefs%3E%3ClinearGradient id=%27g%27 x1=%270%27 y1=%270%27 x2=%271%27 y2=%271%27%3E%3Cstop offset=%270%27 stop-color=%27%234fd3f0%27/%3E%3Cstop offset=%271%27 stop-color=%27%23ffcf70%27/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2732%27 height=%2732%27 rx=%279%27 fill=%27url(%23g)%27/%3E%3C/svg%3E">';
}

/** URL for a file in /assets with a version stamp so browsers never show a stale copy. */
function asset_url(string $file, string $prefix = ''): string
{
    $full = __DIR__ . '/../assets/' . $file;
    return $prefix . 'assets/' . $file . '?v=' . (is_file($full) ? filemtime($full) : time());
}

/** Small outline icons (24px grid). */
function icon(string $name, int $size = 20): string
{
    static $paths = [
        'dashboard'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'reservations' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'guests'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'rooms'        => '<path d="M3 19V6M3 15h18v4M21 15v-3a3 3 0 0 0-3-3h-7v6"/><circle cx="7" cy="11.5" r="1.8"/>',
        'external'     => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'room_types'   => '<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
        'star'         => '<path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z"/>',
        'photos'       => '<rect x="3" y="5" width="18" height="15" rx="3"/><circle cx="12" cy="12.5" r="3.5"/><path d="M8 5l1.5-2h5L16 5"/>',
        'services'     => '<path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2z"/>',
        'staff'        => '<rect x="2" y="7" width="20" height="14" rx="3"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'globe'        => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'logout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'tag'          => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><path d="M7 7h.01"/>',
        'buildings'    => '<path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6M8 10h.01M12 10h.01M16 10h.01"/>',
        'pools'        => '<path d="M2 18c2 0 2-1.5 5-1.5S9 18 12 18s3-1.5 5-1.5S20 18 22 18"/><path d="M2 22c2 0 2-1.5 5-1.5S9 22 12 22s3-1.5 5-1.5S20 22 22 22"/><path d="M8 14V6a2 2 0 0 1 4 0M14 14V6a2 2 0 0 1 4 0M8 9h6"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'shield'       => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'arrow'        => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** "Family Villa" becomes "family-villa" (used to find a room type's photos). */
function slugify(string $text): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
}

/**
 * Photos for a room type: every file named assets/rooms/<resort-slug>/<type-name-slug>-<number>.(jpg|jpeg|png|webp),
 * in number order. fit tells the slideshow how to show it: cover, contain or a fixed width.
 * Resorts without photos get a themed colour backdrop instead (see theme_backdrop()).
 */
function room_photos(string $typeName): array
{
    global $resort;
    $folder = 'rooms/' . ($resort['slug'] ?? '_') . '/';
    $dir    = __DIR__ . '/../assets/' . $folder;
    $slug   = slugify($typeName);
    $found  = [];
    foreach (glob($dir . $slug . '-*') ?: [] as $file) {
        if (preg_match('/^' . preg_quote($slug, '/') . '-(\d+)\.(jpe?g|png|webp)$/i', basename($file), $m)) {
            $found[(int)$m[1]] = $file;
        }
    }
    ksort($found);
    $photos = [];
    foreach ($found as $file) {
        $size = @getimagesize($file) ?: [0, 0];
        [$w, $hgt] = [(int)$size[0], (int)$size[1]];
        $ratio = $hgt > 0 ? $w / $hgt : 1.5;
        if ($w < 400)         { $fit = 'min(100%, 520px) auto'; }   // very small file: keep it modest
        elseif ($ratio > 2.2) { $fit = 'min(100%, 1000px) auto'; }  // wide banner
        elseif ($ratio < 0.85){ $fit = 'contain'; }                 // portrait
        else                  { $fit = 'cover'; }
        $photos[] = ['url' => 'assets/' . $folder . basename($file) . '?v=' . filemtime($file), 'fit' => $fit, 'portrait' => $ratio < 0.85];
    }
    return $photos;
}

/** The resort's main picture (hero_image in /assets), or '' when it has none. */
function resort_hero(): string
{
    global $resort;
    $f = (string)($resort['hero_image'] ?? '');
    return (preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', $f) && is_file(__DIR__ . '/../assets/' . $f)) ? 'assets/' . $f : '';
}

/** Colours for each resort theme: [accent, gradient]. A resort picks one in Admin > Resorts. */
const RESORT_THEMES = [
    'ocean'  => ['#0b7f97', 'linear-gradient(160deg,#08324a 0%,#1a86a8 55%,#9fe3ef 100%)'],
    'lagoon' => ['#0d8f7e', 'linear-gradient(160deg,#0b4f5c 0%,#1b9aa3 55%,#8fe0d0 100%)'],
    'pine'   => ['#2f7d4f', 'linear-gradient(160deg,#10301f 0%,#2e6b45 55%,#9bc7a0 100%)'],
    'sunset' => ['#c2561e', 'linear-gradient(160deg,#5a1a3a 0%,#d2562f 55%,#ffc46b 100%)'],
    'forest' => ['#5b7f1f', 'linear-gradient(160deg,#17361c 0%,#4c8a2e 60%,#c9d97a 100%)'],
    'dusk'   => ['#6b4fc0', 'linear-gradient(160deg,#1f1a4a 0%,#5b4bb0 55%,#e0a1c8 100%)'],
];

/** CSS that gives the public pages this resort's colours and backdrop. */
function theme_css(): string
{
    global $resort;
    if (!$resort) return '';   // pages outside a resort use the same backdrop as the staff area
    $theme = RESORT_THEMES[$resort['theme'] ?? 'ocean'] ?? RESORT_THEMES['ocean'];
    $hero  = resort_hero();
    $bg    = $hero !== '' ? 'url("' . $hero . '")' : $theme[1];
    $accent = $theme[0];
    $soft = sprintf('rgba(%d,%d,%d,0.12)', hexdec(substr($accent, 1, 2)), hexdec(substr($accent, 3, 2)), hexdec(substr($accent, 5, 2)));
    return 'body.site{--accent:' . $accent . ';--accent-soft:' . $soft . ';--price:' . $accent . '}'
         . 'body.site .bg-layer,body.site .bg-layer::before,body.landing .bg-layer,body.landing .bg-layer::before{background-image:' . $bg . '}'
         . 'body.room-page .rp-bg{background:' . $theme[1] . '}'
         . '.no-photo{background-image:' . $theme[1] . '!important}';
}

/** One room or cottage as a photo card (landing, booking home and rooms pages). */
function room_card(array $t): string
{
    $photos = room_photos($t['type_name']);
    $cover  = $photos ? $photos[0]['url'] : '';
    $url    = ru('room.php?id=' . (int)$t['room_type_id']);
    ob_start();
    ?>
<article class="room-card">
  <a class="rc-photo<?= $cover === '' ? ' no-photo' : '' ?>" href="<?= h($url) ?>"<?= $cover !== '' ? ' style="background-image:url(\'' . h($cover) . '\')"' : '' ?> aria-label="View the <?= h($t['type_name']) ?>">
    <span class="rc-tag">Sleeps up to <?= (int)$t['max_occupancy'] ?></span>
    <?php if (count($photos) > 1): ?><span class="rc-count"><?= count($photos) ?> photos</span><?php endif; ?>
  </a>
  <div class="rc-body">
    <h3><a href="<?= h($url) ?>"><?= h($t['type_name']) ?></a></h3>
    <p class="rc-desc"><?= h($t['description']) ?></p>
    <div class="rc-foot">
      <p class="price"><?= h(money($t['base_rate'])) ?> <small>per night</small></p>
      <a class="btn primary" href="<?= h($url . '#book') ?>">View and book</a>
    </div>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

/** Progress bar for the booking flow: 1 Dates, 2 Room, 3 Your details, 4 Confirmed. */
function booking_steps(int $current): string
{
    $labels = [1 => 'Dates', 2 => 'Room', 3 => 'Your details', 4 => 'Confirmed'];
    $html = '<ol class="steps" aria-label="Booking progress">';
    foreach ($labels as $n => $label) {
        $cls = $n < $current ? ' class="is-done"' : ($n === $current ? ' class="is-current" aria-current="step"' : '');
        $html .= '<li' . $cls . '><span>' . ($n < $current ? '✓' : $n) . '</span><b>' . h($label) . '</b></li>';
    }
    return $html . '</ol>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

/** Public site header. */
function site_start(string $title, string $active = '', string $bodyClass = ''): void
{
    global $config, $resort;
    $signedIn = !empty($_SESSION['guest_id']);
    if ($resort) {
        $nav = ['rooms' => [ru('rooms.php'), 'Rooms'], 'pricelist' => [ru('pricelist.php'), 'Price list'], 'stay' => [ru('book.php#exclusive'), 'Whole resort'], 'reviews' => [ru('reviews.php'), 'Reviews'],
                'booking' => [ru('booking.php'), 'Manage booking']];
    } else {
        $nav = ['all' => ['index.php', 'Resorts'], 'booking' => ['booking.php', 'Manage booking']];
    }
    $nav['account'] = $signedIn ? [ru('account.php'), 'My account'] : [ru('login.php'), 'Log in'];
    $brandName = $resort ? $config['resort'] : $config['platform'];
    $brandHref = $resort ? ru('landing.php') : 'index.php';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<script>document.documentElement.className+=' js';try{var b=sessionStorage.getItem('baje-blend');if(b){document.documentElement.classList.add('blend-'+b);sessionStorage.removeItem('baje-blend')}}catch(e){}</script>
<title><?= h($title) ?> · <?= h($brandName) ?></title>
<?= head_meta($title) ?>
<?php if ($resort && resort_hero() !== '' && str_contains($bodyClass, 'landing')): ?><link rel="preload" as="image" href="<?= h(asset_url(substr(resort_hero(), 7))) ?>"><?php endif; ?>
<?= font_links(true) ?>
<link rel="stylesheet" href="<?= h(asset_url('style.css')) ?>">
<style><?= theme_css() ?></style>
<script src="<?= h(asset_url('app.js')) ?>" defer></script>
</head>
<body class="site<?= $bodyClass !== '' ? ' ' . h($bodyClass) : '' ?>">
<a class="skip-link" href="#content">Skip to content</a>
<div class="bg-layer" aria-hidden="true"></div>
<div class="frame">
<header class="top" id="top">
  <div class="bar">
    <?php if ($resort): ?><a class="back-btn" href="index.php" title="Back to all resorts" aria-label="Back to all resorts"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></a><?php endif; ?>
    <a class="brand" href="<?= h($brandHref) ?>"><?= h($brandName) ?></a>
    <nav id="site-nav" aria-label="Main">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="btn primary push" href="<?= h($resort ? ru('book.php#book') : 'index.php#resorts') ?>"><?= $resort ? 'Book a stay' : 'Find a resort' ?></a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Menu"><span></span></button>
  </div>
</header>
<main class="wrap" id="content">
<?php flash_render();
}

function site_end(): void
{
    global $config, $resort;
    $tel = preg_replace('/[^0-9+]/', '', (string)($resort ? $config['contact_phone'] : ''));
    ?>
</main>
<footer class="foot">
  <div class="foot-grid">
    <div class="foot-about">
      <strong class="foot-brand"><?= h($resort ? $config['resort'] : $config['platform']) ?></strong>
      <p><?= h($resort ? $config['tagline'] : 'Many resorts, one simple booking system.') ?></p>
    </div>
    <div>
      <h3>Explore</h3>
      <?php if ($resort): ?>
        <a href="<?= h(ru('rooms.php')) ?>">Rooms and cottages</a>
        <a href="<?= h(ru('pricelist.php')) ?>">Price list</a>
        <a href="<?= h(ru('book.php#exclusive')) ?>">Book the whole resort</a>
      <?php endif; ?>
      <a href="index.php">All resorts</a>
      <a href="<?= h(ru('booking.php')) ?>">Manage booking</a>
      <?php if (!empty($_SESSION['guest_id'])): ?><a href="<?= h(ru('account.php')) ?>">My account</a><?php else: ?><a href="<?= h(ru('login.php')) ?>">Log in</a> <a href="<?= h(ru('register.php')) ?>">Create account</a><?php endif; ?>
    </div>
    <?php if ($resort): ?>
    <div>
      <h3>Contact</h3>
      <a href="mailto:<?= h($config['contact_email']) ?>"><?= h($config['contact_email']) ?></a>
      <a href="tel:<?= h($tel) ?>"><?= h($config['contact_phone']) ?></a>
    </div>
    <div>
      <h3>Your stay</h3>
      <p>Check-in from <?= h($config['checkin_time']) ?></p>
      <p>Check-out by <?= h($config['checkout_time']) ?></p>
    </div>
    <?php endif; ?>
  </div>
  <div class="foot-bottom">
    <span>© <?= date('Y') ?> <?= h($resort ? $config['resort'] : $config['platform']) ?></span>
    <a href="admin/">Staff sign in</a>
  </div>
</footer>
</div>
</body>
</html>
<?php
}

// ---------- Staff area ----------

/**
 * Redirects to the login page unless a staff member is signed in. Returns the staff row (with 'is_platform').
 * Resort staff work inside their own resort. Platform administrators (no resort) pick one with ?resort=ID
 * and the choice is remembered in the session.
 */
/**
 * Three access levels. 'super' = the site's super admin (no resort of their own, manages everything),
 * 'admin' = a resort admin (runs one resort), 'staff' = front desk (reservations, calendar, guests, room status).
 */
function staff_can(array $staff, string $need): bool
{
    $rank = ['staff' => 1, 'admin' => 2, 'super' => 3];
    return ($rank[$staff['level'] ?? 'staff'] ?? 0) >= ($rank[$need] ?? 99);
}

/** $need is the lowest level allowed on the page: 'staff' (default), 'admin' or 'super' ($platformOnly is the same as 'super'). */
function require_login(PDO $pdo, bool $platformOnly = false, string $need = 'staff'): array
{
    global $resort;
    $id = (int)($_SESSION['staff_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT staff_id, full_name, role, access, resort_id FROM staff WHERE staff_id = ? AND password_hash IS NOT NULL');
        $stmt->execute([$id]);
        $staff = $stmt->fetch();
        if ($staff) {
            $staff['is_platform'] = $staff['resort_id'] === null;
            $staff['level'] = $staff['is_platform'] ? 'super' : ($staff['access'] === 'Admin' ? 'admin' : 'staff');
            $staff['level_label'] = ['super' => 'Super admin', 'admin' => 'Resort admin', 'staff' => 'Staff'][$staff['level']];
            if ($staff['is_platform']) {
                if (isset($_GET['resort']) && ctype_digit((string)$_GET['resort']) && resort_by_id($pdo, (int)$_GET['resort'])) {
                    $_SESSION['admin_resort'] = (int)$_GET['resort'];
                }
                $r = resort_by_id($pdo, (int)($_SESSION['admin_resort'] ?? 0))
                    ?: ($pdo->query('SELECT * FROM resort ORDER BY sort_order, resort_id LIMIT 1')->fetch() ?: null);
                if ($r) {
                    resort_apply($r);
                }
            } else {
                $r = resort_by_id($pdo, (int)$staff['resort_id']);
                if (!$r) {
                    unset($_SESSION['staff_id']);
                    redirect('login.php');
                }
                resort_apply($r);
            }
            if ($platformOnly) {
                $need = 'super';
            }
            if (!staff_can($staff, $need)) {
                flash($need === 'super' ? 'Only the super admin can open that page.' : 'Only resort admins can open that page.', 'error');
                redirect('index.php');
            }
            if (!$resort && !in_array(basename($_SERVER['SCRIPT_NAME']), ['resorts.php', 'platform.php', 'admins.php'], true)) {
                flash('Add your first resort to get started.');
                redirect('resorts.php');
            }
            return $staff;
        }
        unset($_SESSION['staff_id']);
    }
    redirect('login.php');
}

function admin_start(string $title, string $active = ''): void
{
    global $config, $staffMember, $resort, $pdo;
    // Grouped so the long list reads at a glance. Each item shows only to people who may open it:
    // 'staff' (everyone), 'admin' (resort admins and the super admin), 'super' (the super admin only).
    $all = [
        'Platform' => [
            'platform' => ['platform.php', 'Overview', 'dashboard', 'super'],
            'resorts'  => ['resorts.php', 'All resorts', 'globe', 'super'],
            'admins'   => ['admins.php', 'Admin accounts', 'shield', 'super'],
        ],
        'Operations' => [
            'index'        => ['index.php', 'Dashboard', 'dashboard', 'staff'],
            'reservations' => ['reservations.php', 'Reservations', 'reservations', 'staff'],
            'calendar'     => ['calendar.php', 'Calendar', 'calendar', 'staff'],
            'guests'       => ['guests.php', 'Guests', 'guests', 'staff'],
            'rooms'        => ['rooms.php', 'Rooms', 'rooms', 'staff'],
        ],
        'Property' => [
            'buildings'    => ['buildings.php', 'Buildings', 'buildings', 'admin'],
            'room_types'   => ['room_types.php', 'Room types', 'room_types', 'admin'],
            'photos'       => ['photos.php', 'Room photos', 'photos', 'admin'],
            'pools'        => ['pools.php', 'Pools', 'pools', 'admin'],
            'services'     => ['services.php', 'Services', 'services', 'admin'],
            'pricelist'    => ['pricelist.php', 'Price list', 'tag', 'admin'],
        ],
        'Resort' => [
            'reviews'      => ['reviews.php', 'Reviews', 'star', 'admin'],
            'staff'        => ['staff.php', 'Staff', 'staff', 'admin'],
            'settings'     => ['settings.php', 'Settings', 'settings', 'admin'],
        ],
    ];
    $groups = [];
    foreach ($all as $gName => $items) {
        $items = array_filter($items, fn($it) => staff_can($staffMember, $it[3]));
        if ($items) {
            $groups[$gName] = $items;
        }
    }
    $platformPage = in_array($active, ['platform', 'resorts', 'admins'], true);
    $resortName = $resort ? $config['resort'] : $config['platform'];
    $name = (string)($staffMember['full_name'] ?? '');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="dark">
<title><?= h($title) ?> · Staff · <?= h($resortName) ?></title>
<?= font_links() ?>
<link rel="stylesheet" href="<?= h(asset_url('style.css', '../')) ?>">
<script src="<?= h(asset_url('app.js', '../')) ?>" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#content">Skip to content</a>
<div class="bg-layer" aria-hidden="true"></div>
<div class="frame shell">
  <aside class="rail" aria-label="Staff navigation">
    <a class="rail-brand" href="<?= $platformPage ? 'platform.php' : 'index.php' ?>" title="<?= h($platformPage ? $config['platform'] : $resortName) ?>">
      <span class="rail-logo" aria-hidden="true"></span>
      <span class="lbl"><strong><?= h($platformPage ? $config['platform'] : $resortName) ?></strong><small><?= h($platformPage ? 'Super admin' : 'Staff desk') ?></small></span>
    </a>
    <nav class="rail-nav">
      <?php foreach ($groups as $gName => $items): ?>
        <p class="rail-group"><?= h($gName) ?><?php if (!empty($staffMember['is_platform']) && $gName !== 'Platform' && $resort): ?> <span class="rg-resort">· <?= h($resort['name']) ?></span><?php endif; ?></p>
        <?php foreach ($items as $key => [$href, $label, $ic]): ?>
          <a href="<?= h($href) ?>" title="<?= h($label) ?>" aria-label="<?= h($label) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
            <?= icon($ic) ?><span class="lbl"><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="rail-end">
      <a href="<?= h($resort ? '../' . ru('landing.php') : '../index.php') ?>" target="_blank" rel="noopener" title="View website" aria-label="View website"><?= icon('external') ?><span class="lbl">View website</span></a>
      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" title="Sign out" aria-label="Sign out"><?= icon('logout') ?><span class="lbl">Sign out</span></button>
      </form>
      <span class="rail-user"><span class="avatar" aria-hidden="true"><?= h(initials($name)) ?></span><span class="lbl"><strong><?= h($name) ?></strong><small><?= h((string)($staffMember['level_label'] ?? '')) ?></small></span></span>
    </div>
  </aside>
  <div class="stage">
<main class="stage-main" id="content">
<?php if (($resort && !$platformPage) || !empty($_SESSION['default_password'])): ?>
<div class="context-bar">
<?php if ($platformPage): ?>
<?php elseif (!empty($staffMember['is_platform']) && $resort): ?>
  <form method="get" class="resort-switch" aria-label="Switch resort">
    <label><span>Managing</span>
      <select name="resort" onchange="this.form.submit()">
        <?php foreach ($pdo->query('SELECT resort_id, name FROM resort ORDER BY sort_order, name')->fetchAll() as $rr): ?>
          <option value="<?= (int)$rr['resort_id'] ?>"<?= (int)$rr['resort_id'] === (int)$resort['resort_id'] ? ' selected' : '' ?>><?= h($rr['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button class="btn sm" type="submit">Switch</button></noscript>
  </form>
<?php elseif ($resort): ?>
  <p class="resort-badge"><span>Managing</span> <strong><?= h($resort['name']) ?></strong></p>
<?php endif; ?>
<?php if (!empty($_SESSION['default_password'])): ?>
  <p class="ctx-warn" role="status">Sample password in use. <a href="staff.php">Change it in Staff</a> before going live.</p>
<?php endif; ?>
</div>
<?php endif; ?>
<?php flash_render();
}

/** The same heading block on every staff page: title, optional one-line subtitle, optional actions (HTML). */
function admin_head(string $title, string $sub = '', string $actions = ''): void
{
    echo '<header class="page-head"><div class="ph-text"><h1>' . h($title) . '</h1>'
       . ($sub !== '' ? '<p class="page-sub">' . h($sub) . '</p>' : '') . '</div>'
       . ($actions !== '' ? '<div class="actions">' . $actions . '</div>' : '') . '</header>';
}

function admin_end(): void
{
    ?>
</main>
  </div>
</div>
</body>
</html>
<?php
}

// ---------- Whole resort / building / pool cards ----------
/**
 * One option for booking a whole resort, building or pool.
 * $stay = null shows the "from" price and links to the dates page; with $stay (check_in, check_out, adults, children)
 * it shows whether the option is free, the total, and a Reserve button.
 */
function exclusive_card(array $t, ?array $stay): string
{
    $kind  = ['Resort' => 'Entire resort', 'Building' => 'Entire building', 'Pool' => 'Entire pool'][$t['scope']] ?? '';
    $unit  = $t['scope'] === 'Pool' ? 'per day' : 'per night';
    $sub   = match ($t['scope']) {
        'Resort'   => plural(count($t['rooms']), 'room') . ' and ' . plural(count($t['pools']), 'pool') . ', all yours',
        'Building' => plural(count($t['rooms']), 'room') . ', sleeps up to ' . $t['capacity'],
        default    => 'Up to ' . plural((int)$t['capacity'], 'guest') . ' in the water',
    };
    if ($t['scope'] === 'Resort') {
        $sub .= ', sleeps up to ' . $t['capacity'];
    }
    ob_start(); ?>
<article class="ex-card<?= $stay && !$t['free'] ? ' is-off' : '' ?>">
  <p class="ex-kind"><?= h($kind) ?></p>
  <h3><?= h($t['name']) ?></h3>
  <?php if ($t['info'] !== ''): ?><p class="muted"><?= h($t['info']) ?></p><?php endif; ?>
  <p class="muted"><?= h($sub) ?></p>
  <p class="price"><?= h(money($t['rate'])) ?> <small><?= h($unit) ?></small></p>
  <?php if ($stay === null): ?>
    <a class="btn" href="<?= h(ru('exclusive.php#' . strtolower($t['scope']) . '-' . $t['id'])) ?>">Check dates</a>
  <?php else:
      $guests = $stay['adults'] + $stay['children'];
      $n = nights($stay['check_in'], $stay['check_out']);
      $tooMany = $guests > $t['capacity'];
      if (!$t['free']): ?>
        <p class="ex-why"><?= h($t['why'] ?: 'Not available for those dates.') ?></p>
      <?php elseif ($tooMany): ?>
        <p class="ex-why">Holds up to <?= (int)$t['capacity'] ?> guests, and your group is <?= $guests ?>.</p>
      <?php else: ?>
        <p class="muted"><?= h(plural($n, $t['scope'] === 'Pool' ? 'day' : 'night')) ?>: <strong><?= h(money($t['rate'] * $n)) ?></strong></p>
        <form method="get" action="checkout.php" class="reserve">
          <?= rhidden() ?>
          <input type="hidden" name="scope" value="<?= h($t['scope']) ?>">
          <input type="hidden" name="target" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="check_in" value="<?= h($stay['check_in']) ?>">
          <input type="hidden" name="check_out" value="<?= h($stay['check_out']) ?>">
          <input type="hidden" name="adults" value="<?= (int)$stay['adults'] ?>">
          <input type="hidden" name="children" value="<?= (int)$stay['children'] ?>">
          <button class="btn primary" type="submit">Reserve</button>
        </form>
      <?php endif;
  endif; ?>
</article>
<?php
    return (string)ob_get_clean();
}

/** The "book the whole place" section: the resort, each building and each pool. */
function exclusive_cards(array $opts, ?array $stay): string
{
    $groups = [];
    if (!empty($opts['Resort']) && $opts['Resort']['rooms']) { $groups['Entire resort'] = [$opts['Resort']]; }
    if (!empty($opts['Building'])) { $groups['Entire buildings'] = $opts['Building']; }
    if (!empty($opts['Pool']))     { $groups['Entire pools'] = $opts['Pool']; }
    if (!$groups) {
        return '';
    }
    $html = '<section class="block" id="exclusive"><div class="block-head"><h2>Book the whole place</h2>'
          . ($stay === null ? '<a href="' . h(ru('exclusive.php')) . '">Check dates</a>' : '') . '</div>'
          . '<p class="muted">Celebrating, a retreat or a family reunion? Book an entire pool, an entire building or the entire resort so nobody else is there. Pay at the resort, same as any booking.</p>';
    foreach ($groups as $label => $items) {
        $html .= '<h3 class="ex-group">' . h($label) . '</h3><div class="ex-grid">';
        foreach ($items as $t) {
            $html .= '<div id="' . h(strtolower($t['scope']) . '-' . $t['id']) . '">' . exclusive_card($t, $stay) . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</section>';
}
