<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/trip_basics.php';
require_once __DIR__ . '/secure_id.php';
require_once __DIR__ . '/account_link.php';
require_once __DIR__ . '/notifications.php';

/** Web fonts (optional: the site falls back to system fonts if offline). Public pages use Fraunces + Inter, the showcase homepage uses Cormorant Garamond + Instrument Sans, and the staff desk uses Inter + Sora. */
function font_links(bool $public = false, bool $magazine = false): string
{
    if ($magazine) {
        $family = 'family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=Instrument+Sans:wght@400;500;600;700';
    } else {
        $family = $public
            ? 'family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700'
            : 'family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700';
    }
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
         . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?' . $family . '&display=swap">';
}

/** Favicon, theme colour and a short description for search results and link previews. */
function head_meta(string $title, string $description = ''): string
{
    global $config, $resort;
    $desc = $description !== '' ? $description : ($resort ? $config['resort'] . '. ' . $config['tagline'] : $config['platform'] . '. Book a resort in Zamboanga now.');
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
        'archive'      => '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>',
        'switch'       => '<path d="M7 4L3 8l4 4"/><path d="M3 8h14"/><path d="M17 20l4-4-4-4"/><path d="M21 16H7"/>',
        'globe'        => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'logout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'tag'          => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><path d="M7 7h.01"/>',
        'buildings'    => '<path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6M8 10h.01M12 10h.01M16 10h.01"/>',
        'pools'        => '<path d="M2 18c2 0 2-1.5 5-1.5S9 18 12 18s3-1.5 5-1.5S20 18 22 18"/><path d="M2 22c2 0 2-1.5 5-1.5S9 22 12 22s3-1.5 5-1.5S20 22 22 22"/><path d="M8 14V6a2 2 0 0 1 4 0M14 14V6a2 2 0 0 1 4 0M8 9h6"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'shield'       => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'arrow'        => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'trip'         => '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
        'search'       => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'bell'         => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'receipt'      => '<path d="M4 3v18l3-2 3 2 2-2 2 2 3-2 3 2V3z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'chart'        => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-6"/><path d="M16 8h4v4"/>',
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
    if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', $f) || !is_file(__DIR__ . '/../assets/' . $f)) {
        return '';
    }
    // The file name stays the same when a cover photo is replaced, so the address carries the file's change time.
    // Without it the browser keeps showing the old picture.
    return 'assets/' . $f . '?v=' . filemtime(__DIR__ . '/../assets/' . $f);
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
         . 'body.site .bg-layer,body.site .bg-layer::before,body.landing .bg-layer,body.landing .bg-layer::before,body.site.landing .landing-hero::before{background-image:' . $bg . '}'
         . '.no-photo{background-image:' . $theme[1] . '!important}';
}

/** One room as a horizontal detail row (landing, booking home and rooms pages). */
function room_card(array $t): string
{
    global $resort;
    $photos = room_photos($t['type_name']);
    $cover  = $photos ? $photos[0]['url'] : resort_hero();
    $slug   = (string)($resort['slug'] ?? '');
    $url    = ru('room.php?id=' . (int)$t['room_type_id']);
    $in     = add_days(today(), 1);
    $out    = add_days(today(), 3);
    $cap    = max(1, (int)$t['max_occupancy']);
    ob_start();
    ?>
<article class="type-row has-photo">
  <a class="tr-photo<?= $cover === '' ? ' no-photo' : '' ?>" href="<?= h($url) ?>"<?= $cover !== '' ? ' style="background-image:url(\'' . h($cover) . '\')"' : '' ?> aria-label="View <?= h($t['type_name']) ?>"></a>
  <div>
    <h2><a href="<?= h($url) ?>"><?= h($t['type_name']) ?></a></h2>
    <?php if (!empty($t['description'])): ?><p><?= h($t['description']) ?></p><?php endif; ?>
    <p class="muted">Sleeps up to <?= $cap ?> guests<?= count($photos) > 1 ? ' · ' . count($photos) . ' photos' : '' ?></p>
  </div>
  <div class="type-side">
    <p class="price"><?= h(money($t['base_rate'])) ?> <small>per night</small></p>
    <form method="post" action="trip_add.php" class="reserve">
      <input type="hidden" name="r" value="<?= h($slug) ?>">
      <input type="hidden" name="type" value="<?= (int)$t['room_type_id'] ?>">
      <input type="hidden" name="rooms" value="1">
      <input type="hidden" name="check_in" value="<?= h($in) ?>">
      <input type="hidden" name="check_out" value="<?= h($out) ?>">
      <input type="hidden" name="adults" value="<?= min(2, $cap) ?>">
      <input type="hidden" name="children" value="0">
      <a class="btn primary" href="<?= h($url . '#book') ?>">View &amp; book</a>
      <?= trip_add_button() ?>
    </form>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

/** One day-use cottage as a horizontal detail row, matching search.php layout and opening room.php detail view. */
function cottage_card(array $c, string $visitDate, int $adults = 2, int $children = 0): string
{
    global $resort;
    $photos = room_photos((string)$c['item_name']);
    $cover  = $photos ? $photos[0]['url'] : resort_hero();
    $slug   = (string)($resort['slug'] ?? '');
    $guests = max(1, $adults + $children);
    $out    = valid_date($visitDate) ? add_days($visitDate, 1) : add_days(today(), 2);
    $url    = ru('room.php?' . http_build_query([
        'cottage'  => (int)$c['price_item_id'],
        'check_in' => $visitDate,
        'adults'   => $guests,
    ]));
    $cap  = max(1, (int)($c['max_guests'] ?? 15));
    $desc = trim((string)($c['note'] ?? ''));
    if ($desc === '') {
        $desc = 'Single cottage · Day use';
    }
    $availNote = isset($c['free'])
        ? ((int)$c['free'] > 0 ? plural((int)$c['free'], 'cottage') . ' available.' : 'Fully booked on this date.')
        : 'Day use' . (count($photos) > 1 ? ' · ' . count($photos) . ' photos' : '');
    ob_start();
    ?>
<article class="type-row has-photo">
  <a class="tr-photo<?= $cover === '' ? ' no-photo' : '' ?>" href="<?= h($url) ?>"<?= $cover !== '' ? ' style="background-image:url(\'' . h($cover) . '\')"' : '' ?> aria-label="View <?= h($c['item_name']) ?>"></a>
  <div>
    <h2><a href="<?= h($url) ?>"><?= h($c['item_name']) ?></a></h2>
    <p><?= h($desc) ?></p>
    <p class="muted">Up to <?= $cap ?> guests · <?= h($availNote) ?></p>
  </div>
  <div class="type-side">
    <p class="price"><?= h(money($c['price'])) ?> <small><?= h($c['unit'] ?: 'per cottage') ?></small></p>
    <form method="post" action="trip_add.php" class="reserve">
      <input type="hidden" name="r" value="<?= h($slug) ?>">
      <input type="hidden" name="scope" value="Cottage">
      <input type="hidden" name="target" value="<?= (int)$c['price_item_id'] ?>">
      <input type="hidden" name="check_in" value="<?= h($visitDate) ?>">
      <input type="hidden" name="check_out" value="<?= h($out) ?>">
      <input type="hidden" name="adults" value="<?= $guests ?>">
      <input type="hidden" name="children" value="0">
      <a class="btn primary" href="<?= h($url . '#book') ?>">View &amp; book</a>
      <?= trip_add_button() ?>
    </form>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

/** Progress bar for the booking flow (starts at checkout): 1 Your details, 2 Payment, 3 Confirmed.
 *  Pass $links (e.g. [1 => 'checkout.php?...']) to make completed or reachable steps clickable. */
function booking_steps(int $current, array $links = []): string
{
    $labels = [1 => 'Your details', 2 => 'Payment', 3 => 'Confirmed'];
    $html = '<ol class="steps" aria-label="Booking progress">';
    foreach ($labels as $n => $label) {
        $classes = [];
        if ($n < $current) {
            $classes[] = 'is-done';
        } elseif ($n === $current) {
            $classes[] = 'is-current';
        }
        $href = ($n !== $current && !empty($links[$n])) ? (string)$links[$n] : '';
        if ($href !== '') {
            $classes[] = 'is-link';
        }
        $attr = ($classes ? ' class="' . implode(' ', $classes) . '"' : '')
              . ($n === $current ? ' aria-current="step"' : '');
        $inner = '<span>' . ($n < $current ? '✓' : $n) . '</span><b>' . h($label) . '</b>';
        $html .= '<li' . $attr . '>' . ($href !== '' ? '<a href="' . h($href) . '">' . $inner . '</a>' : $inner) . '</li>';
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

/**
 * Booking needs an account. Call this at the top of any page that checks availability, books a stay or builds a trip.
 * A guest who is not logged in is sent to the login page (which opens the "Create an account first to book" pop-up),
 * and after logging in or signing up they come straight back to where they were ($next, or the page they asked for).
 */
function require_guest_to_book(PDO $pdo, ?string $next = null): void
{
    $id = (int)($_SESSION['guest_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM guest WHERE guest_id = ? AND ' . GUEST_CAN_LOGIN);
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) {
            return;
        }
        unset($_SESSION['guest_id'], $_SESSION['guest_name']);   // the account no longer exists
    }
    if ($next === null) {
        $path = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $qs   = (string)($_SERVER['QUERY_STRING'] ?? '');
        $next = $path . ($qs !== '' ? '?' . $qs : '');
    }
    // Same rule as safe_next(): only a page inside this site.
    if (!preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&.%\-]*)?$#', $next)) {
        $next = 'resorts.php';
    }
    $_SESSION['gate_popup'] = 'book';
    redirect('login.php?next=' . urlencode($next));   // the main log in / create account page (the one with the search bar)
}

/** The "Create an account first to book" pop-up. Shown once, on the login page, after a guest was sent there by require_guest_to_book(). */
function auth_gate_popup(string $next): string
{
    if (empty($_SESSION['gate_popup'])) {
        return '';
    }
    unset($_SESSION['gate_popup']);
    $register = 'register.php?next=' . urlencode($next);   // the all-resorts page, not a resort's own
    return '<div class="modal is-open" data-modal data-gate role="alertdialog" aria-modal="true" aria-labelledby="gate-title" aria-describedby="gate-text">'
         . '<div class="modal-card">'
         . '<span class="modal-icon" aria-hidden="true">' . icon('shield', 26) . '</span>'
         . '<h2 id="gate-title">Create an account first to book</h2>'
         . '<p id="gate-text">You need an account to check availability, book a stay or plan a trip. It only takes a minute. Already have one? Just log in.</p>'
         . '<div class="modal-actions">'
         . '<a class="btn primary" href="' . h($register) . '" data-modal-focus>Create account</a>'
         . '<button class="btn" type="button" data-modal-close>Log in</button>'
         . '</div></div></div>';
}

/** Public site header. */
function site_start(string $title, string $active = '', string $bodyClass = ''): void
{
    global $config, $resort;
    $signedIn = !empty($_SESSION['guest_id']);
    $isHome   = ($active === 'home' && !$resort);
    $hideNav  = str_contains(' ' . $bodyClass . ' ', ' no-nav ');
    if ($resort) {
        $nav = ['rooms' => [ru('rooms.php'), 'Book'], 'pricelist' => [ru('pricelist.php'), 'Price list'],
                'reviews' => [ru('reviews.php'), 'Reviews']];
    } elseif ($isHome) {
        $nav = ['all' => ['resorts.php', 'Resorts'], 'how' => ['#how', 'How it works'], 'reviews' => ['#reviews', 'Reviews'], 'booking' => ['booking.php', 'Manage booking']];
    } else {
        $nav = ['all' => ['resorts.php', 'Resorts'], 'booking' => ['booking.php', 'Manage booking']];
    }
    // Resort pages keep the navbar focused on the resort itself (no "Log in" or "My account" link).
    // The showcase homepage puts auth actions cleanly in the right CTA cluster.
    if (!$resort && !$isHome) {
        $nav['account'] = $signedIn ? ['account.php', 'My account'] : ['login.php', 'Log in'];
    }
    $brandName = $resort ? $config['resort'] : $config['platform'];
    $brandHref = $resort ? ru('landing.php') : ($signedIn && !$isHome ? 'resorts.php' : 'index.php');
    // The longer the name, the smaller it is written (20 letters or fewer keep the normal size).
    $nameLen   = mb_strlen($brandName);
    $brandSize = $nameLen > 20 ? ' style="--bn:' . number_format(max(12.5, 19 * 20 / $nameLen), 1, '.', '') . 'px"' : '';
    $showSearch = !$resort && !$isHome;   // all-resorts hub & subpages have the top search bar; showcase homepage stays editorial
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<script>document.documentElement.className+=' js';try{var b=sessionStorage.getItem('baje-blend');if(b){document.documentElement.classList.add('blend-'+b);sessionStorage.removeItem('baje-blend')}}catch(e){}</script>
<title><?= h($title) ?> · <?= h($brandName) ?></title>
<?= head_meta($title) ?>
<?php if ($resort && resort_hero() !== '' && str_contains($bodyClass, 'landing')): ?><link rel="preload" as="image" href="<?= h(resort_hero()) ?>"><?php elseif ($isHome && is_file(__DIR__ . '/../assets/landing.jpg')): ?><link rel="preload" as="image" href="<?= h(asset_url('landing.jpg')) ?>"><?php endif; ?>
<?= font_links(true, $isHome || str_contains(' ' . $bodyClass . ' ', ' landing ') || str_contains(' ' . $bodyClass . ' ', ' platform ')) ?>
<link rel="stylesheet" href="<?= h(asset_url('style.css')) ?>">
<style><?= theme_css() ?></style>
<script src="<?= h(asset_url('app.js')) ?>" defer></script>
</head>
<body class="site<?= $bodyClass !== '' ? ' ' . h($bodyClass) : '' ?>">
<a class="skip-link" href="#content">Skip to content</a>
<div class="bg-layer" aria-hidden="true"></div>
<div class="frame">
<?php if (!$hideNav): ?>
<header class="top" id="top">
  <div class="bar">
    <?php if ($resort): ?><a class="back-btn" href="resorts.php" title="Back to all resorts" aria-label="Back to all resorts"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></a><?php endif; ?>
    <a class="brand" href="<?= h($brandHref) ?>" title="<?= h($brandName) ?>"><span class="brand-name"<?= $brandSize ?>><?= h($brandName) ?></span></a>
    <?php if ($showSearch): ?>
    <form class="top-search" method="get" action="resorts.php" role="search">
      <?php if (in_array((string)($_GET['sort'] ?? ''), ['rating', 'name', 'name_desc'], true)): ?><input type="hidden" name="sort" value="<?= h((string)$_GET['sort']) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h((string)($_GET['q'] ?? '')) ?>" placeholder="Search resorts" aria-label="Search resorts" maxlength="80" autocomplete="off">
      <button type="submit" aria-label="Search"><?= icon('search', 18) ?></button>
    </form>
    <?php endif; ?>
    <nav id="site-nav" aria-label="Main">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="bar-end push">
      <?php if ($signedIn && isset($GLOBALS['pdo']) && ($hdrStaff = link_to_staff($GLOBALS['pdo'], (int)$_SESSION['guest_id']))):
          $hdrLbl = $hdrStaff['resort_id'] === null ? 'Admin' : (($hdrStaff['access'] ?? '') === 'Admin' ? 'Admin' : 'Staff');
      ?>
        <form method="post" action="<?= h(ru('switch_staff.php')) ?>" style="margin:0">
          <?= csrf_field() ?>
          <button class="trip-btn" type="submit" title="Switch to <?= h(strtolower($hdrLbl)) ?> account"><?= icon('switch', 17) ?><span class="trip-label"><?= h($hdrLbl) ?> desk</span></button>
        </form>
      <?php endif; ?>
      <?php if ($isHome): ?>
        <?php if ($signedIn): ?>
          <a class="home-nav-link" href="account.php">My account</a>
          <a class="btn primary" href="resorts.php">All resorts</a>
        <?php else: ?>
          <a class="home-nav-link" href="login.php">Log in</a>
          <a class="btn primary" href="register.php">Create account</a>
        <?php endif; ?>
      <?php else: ?>
        <?= trip_header_link() ?>
        <?php if ($resort): ?><a class="btn primary" href="<?= h(ru('book.php#book')) ?>"><span>Book<span class="xs-hide"> a stay</span></span></a><?php endif; ?>
      <?php endif; ?>
    </div>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Menu"><span></span></button>
  </div>
</header>
<?php endif; ?>
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
      <?php if ($resort): ?><p><?= h($config['tagline']) ?></p><?php endif; ?>
    </div>
    <div>
      <h3>Explore</h3>
      <?php if ($resort): ?>
        <a href="<?= h(ru('photos.php')) ?>">See photos</a>
        <a href="<?= h(ru('rooms.php')) ?>">Book</a>
        <a href="<?= h(ru('pricelist.php')) ?>">Price list</a>
      <?php endif; ?>
      <a href="resorts.php">All resorts</a>
      <a href="<?= h(ru('booking.php')) ?>">Manage booking</a>
      <?php if (!empty($_SESSION['guest_id'])): ?><a href="<?= h(ru('account.php')) ?>">My account</a><?php else: ?><a href="<?= h(ru('login.php')) ?>">Log in</a> <a href="<?= h(ru('register.php')) ?>">Create account</a><?php endif; ?>
    </div>
    <?php if ($resort): ?>
    <div>
      <h3>Contact &amp; address</h3>
      <?php if (!empty($resort['location'])): ?><p><?= h($resort['location']) ?></p><?php endif; ?>
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
        $stmt = $pdo->prepare('SELECT staff_id, full_name, role, access, resort_id FROM staff WHERE staff_id = ? AND ' . STAFF_CAN_LOGIN);
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
                    ?: ($pdo->query('SELECT * FROM resort WHERE archived_at IS NULL ORDER BY sort_order, resort_id LIMIT 1')->fetch() ?: null);
                if ($r) {
                    resort_apply($r);
                }
            } else {
                $r = resort_by_id($pdo, (int)$staff['resort_id']);
                if (!$r) {
                    unset($_SESSION['staff_id']);
                    redirect('../login.php');
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
            if (!$resort && !in_array(basename($_SERVER['SCRIPT_NAME']), ['resorts.php', 'resort_new.php', 'platform.php', 'analytics.php', 'accounts.php', 'users.php', 'search.php', 'archive.php', 'links.php'], true)) {
                flash('Add your first resort to get started.');
                redirect('resort_new.php');
            }
            return $staff;
        }
        unset($_SESSION['staff_id'], $_SESSION['default_password']);
        flash('Your account is no longer active. Contact your administrator.', 'error');
    }
    redirect('../login.php');
}

/**
 * Pages that share one sidebar entry. The entry opens the first page and every page of the hub is a tab across the top.
 * page key => [file, tab label, lowest access level]
 */
function admin_hubs(): array
{
    return [
        // One Dashboard entry. The super admin gets two tabs: all resorts at a glance, and the resort being managed.
        'dashboard' => ['title' => 'Dashboard', 'icon' => 'dashboard', 'pages' => [
            'platform' => ['platform.php', 'Platform', 'super'],
            'index'    => ['index.php',    'Resort',   'staff'],
        ]],
        'management' => ['title' => 'Resort Management', 'icon' => 'buildings', 'pages' => [
            'reservations' => ['reservations.php', 'Reservations', 'staff'],
            'calendar'     => ['calendar.php',     'Calendar',     'staff'],
            'guests'       => ['guests.php',       'Guests',       'staff'],
            'rooms'        => ['rooms.php',        'Rooms',        'staff'],
            'staff'        => ['staff.php',        'Staff',        'admin'],
        ]],
        'services' => ['title' => 'Services', 'icon' => 'services', 'pages' => [
            'buildings'    => ['buildings.php',  'Buildings',     'admin'],
            'room_types'   => ['room_types.php', 'Room types',    'admin'],
            'pools'        => ['pools.php',      'Pools',         'admin'],
            'services'     => ['services.php',   'Add-ons',       'admin'],
            'pricelist'    => ['pricelist.php',  'Price list',    'admin'],
            'gallery'      => ['gallery.php',    'Resort photos', 'admin'],
            'photos'       => ['photos.php',     'Room photos',   'admin'],
        ]],
        // Super admin only. Staff sign-ins and guest (user) accounts are kept apart, as two tabs.
        'accounts' => ['title' => 'Accounts', 'icon' => 'shield', 'group' => 'Platform', 'pages' => [
            'admins' => ['accounts.php', 'Staff & admins', 'super'],
            'users'  => ['users.php',    'Users',          'super'],
            'links'  => ['links.php',    'Linked accounts', 'admin'],
        ]],
    ];
}

function admin_start(string $title, string $active = ''): void
{
    global $config, $staffMember, $resort, $pdo;
    // Five entries instead of eighteen: the hubs hold the day-to-day pages as tabs.
    // Each entry shows only to people who may open it: 'staff' (everyone), 'admin' (resort admins and the super admin), 'super'.
    $isSuper = staff_can($staffMember, 'super');
    $hubs = admin_hubs();
    $tabActive = $active;
    // On Super Admin side, consolidate Resort Management & Services into clean grouped tabs (sub-pages map to their parent tab).
    if ($isSuper && isset($hubs['management'], $hubs['services'])) {
        $hubs['management']['pages'] = [
            'reservations' => ['reservations.php', 'Reservations',      'staff'],
            'calendar'     => ['calendar.php',     'Calendar',          'staff'],
            'guests'       => ['guests.php',       'Guests',            'staff'],
            'buildings'    => ['buildings.php',    'Buildings & Rooms', 'admin'],
            'pools'        => ['pools.php',        'Pools',             'admin'],
            'pricelist'    => ['pricelist.php',    'Pricing & Add-ons', 'admin'],
            'gallery'      => ['gallery.php',      'Photos',            'admin'],
            'staff'        => ['staff.php',        'Staff',             'admin'],
        ];
        unset($hubs['services']);
        unset($hubs['accounts']);
        $hubs['all_records'] = [
            'title' => 'All Records',
            'icon'  => 'archive',
            'pages' => [
                'resorts' => ['resorts.php',  'All resorts',    'super'],
                'admins'  => ['accounts.php', 'Staff & admins', 'super'],
                'users'   => ['users.php',    'Users',          'super'],
                'archive' => ['archive.php',  'Archived',       'super'],
            ],
        ];
        $subMap = [
            'rooms'      => 'buildings',
            'room_types' => 'buildings',
            'services'   => 'pricelist',
            'photos'     => 'gallery',
        ];
        if (isset($subMap[$active])) {
            $tabActive = $subMap[$active];
        }
    }
    $hubOf = null;      // the hub the current page belongs to
    $hubItems = [];     // that hub's tabs this person may open
    $hubFirst = [];     // hub key => [first file, title, icon]
    foreach ($hubs as $hk => $hub) {
        $open = array_filter($hub['pages'], fn($pg) => staff_can($staffMember, $pg[2]));
        if (!$open) {
            continue;
        }
        $first = reset($open);
        $hubFirst[$hk] = [$first[0], $hub['title'], $hub['icon'], 'staff'];
        if (isset($hub['pages'][$tabActive])) {
            $hubOf = $hk;
            $hubItems = $open;
        }
    }

    if ($isSuper) {
        $nav = [
            'Platform' => array_filter([
                'dashboard'   => $hubFirst['dashboard'] ?? null,
                'analytics'   => ['analytics.php', 'Analytics', 'chart', 'super'],
                'all_records' => $hubFirst['all_records'] ?? null,
                'search'      => ['search.php',    'Search',    'search', 'super'],
            ]),
            'Resort' => array_filter([
                'management' => $hubFirst['management'] ?? null,
                'reviews'    => ['reviews.php',  'Reviews',  'star',     'admin'],
                'records'    => ['records.php',  'Records',  'receipt',  'staff'],
                'settings'   => ['settings.php', 'Settings', 'settings', 'admin'],
            ]),
        ];
    } else {
        $nav = [
            '' => array_filter([
                'dashboard'  => $hubFirst['dashboard']  ?? null,
                'management' => $hubFirst['management'] ?? null,
                'services'   => $hubFirst['services']   ?? null,
                'accounts'   => $hubFirst['accounts']   ?? null,
                'reviews'    => ['reviews.php',  'Reviews',  'star',     'admin'],
                'records'    => ['records.php',  'Records',  'receipt',  'staff'],
                'archive'    => ['archive.php',  'Archive',  'archive',  'admin'],
                'settings'   => ['settings.php', 'Settings', 'settings', 'admin'],
            ]),
        ];
    }
    $current = $hubOf ?? $active;
    $groups = [];
    foreach ($nav as $gName => $items) {
        $items = array_filter($items, fn($it) => staff_can($staffMember, $it[3]));
        if ($items) {
            $groups[$gName] = $items;
        }
    }
    $platformPage = in_array($active, ['platform', 'analytics', 'resorts', 'admins', 'users', 'search'], true)
                 || ($isSuper && $active === 'archive')
                 || (in_array($active, ['archive', 'links'], true) && !$resort);
    $resortName = $resort ? $config['resort'] : $config['platform'];
    $name = (string)($staffMember['full_name'] ?? '');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light">
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
        <?php if ($gName !== ''): ?><p class="rail-group"><?= h($gName) ?></p><?php endif; ?>
        <?php foreach ($items as $key => [$href, $label, $ic]): ?>
          <a href="<?= h($href) ?>" title="<?= h($label) ?>" aria-label="<?= h($label) ?>"<?= (string)$key === (string)$current ? ' aria-current="page"' : '' ?>>
            <?= icon($ic) ?><span class="lbl"><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="rail-end">
      <a href="<?= h($resort ? '../' . ru('landing.php') : '../index.php') ?>" target="_blank" rel="noopener" title="View website" aria-label="View website"><?= icon('external') ?><span class="lbl">View website</span></a>
      <?php if (!empty($staffMember['staff_id']) && link_to_user($pdo, (int)$staffMember['staff_id'])): ?>
      <form method="post" action="switch.php">
        <?= csrf_field() ?>
        <button type="submit" title="Switch to your user account" aria-label="Switch to your user account"><?= icon('switch') ?><span class="lbl">Switch to user account</span></button>
      </form>
      <?php endif; ?>
      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" title="Log out" aria-label="Log out" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out" data-confirm-ok="Yes, log out"><?= icon('logout') ?><span class="lbl">Log out</span></button>
      </form>
      <span class="rail-user"><span class="avatar" aria-hidden="true"><?= h(initials($name)) ?></span><span class="lbl"><strong><?= h($name) ?></strong><small><?= h((string)($staffMember['level_label'] ?? '')) ?></small></span></span>
    </div>
  </aside>
  <div class="stage">
<main class="stage-main" id="content">
<?php
$notifs    = staff_notifications_for($pdo, $staffMember);
$notifHtml = admin_notif_html($notifs);
$GLOBALS['pending_notif_bell'] = '';
$GLOBALS['pending_subnav']     = '';

if (!$platformPage && $resort): ?>
<div class="context-bar">
<?php if (!empty($staffMember['is_platform'])): ?>
  <?php
    // Search-and-pick switcher. The list is plain links (?resort=ID), so it works without JavaScript; app.js adds the search box.
    $switchTo = basename($_SERVER['SCRIPT_NAME']);
    if (in_array($switchTo, ['reservation_view.php', 'reservation_new.php'], true)) {
        $switchTo = 'reservations.php';   // a booking belongs to one resort, so go to the list of the other one
    }
    $switchList = $pdo->query('SELECT resort_id, name, location, is_active FROM resort WHERE archived_at IS NULL ORDER BY sort_order, name')->fetchAll();
  ?>
  <div class="resort-switch" data-resort-switch>
    <span class="rs-label" id="rs-label">Managing</span>
    <details class="rs-menu">
      <summary aria-labelledby="rs-label rs-current"><span class="rs-name" id="rs-current"><?= h($resort['name']) ?></span></summary>
      <div class="rs-panel">
        <input type="search" class="rs-search" data-rs-search placeholder="Search <?= count($switchList) ?> resorts…" aria-label="Search resorts" autocomplete="off" hidden>
        <ul class="rs-list" aria-label="Resorts">
          <?php foreach ($switchList as $rr): $isHere = (int)$rr['resort_id'] === (int)$resort['resort_id']; ?>
            <li data-search="<?= h(mb_strtolower($rr['name'] . ' ' . $rr['location'])) ?>">
              <a href="<?= h($switchTo . '?resort=' . (int)$rr['resort_id']) ?>"<?= $isHere ? ' aria-current="true"' : '' ?>>
                <span class="rs-item-name"><?= h($rr['name']) ?><?= $rr['is_active'] ? '' : ' <em>Hidden</em>' ?></span>
                <?php if ($rr['location']): ?><small><?= h($rr['location']) ?></small><?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="rs-empty" data-rs-empty hidden>No resort matches your search.</p>
        <div class="rs-foot"><a href="resorts.php">All resorts</a><a href="resort_new.php">+ Add a resort</a></div>
      </div>
    </details>
  </div>
<?php else: ?>
  <p class="resort-badge"><span>Managing</span> <strong><?= h($resort['name']) ?></strong></p>
<?php endif; ?>
  <?= $notifHtml ?>
</div>
<?php else:
  // Platform page with no left-side Managing switcher: merge the bell into the page header row so no blank bar sits on top.
  $GLOBALS['pending_notif_bell'] = $notifHtml;
endif;

if (count($hubItems) > 1) {
    ob_start();
    ?>
<nav class="subnav" aria-label="<?= h($hubs[$hubOf]['title']) ?>">
  <?php foreach ($hubItems as $key => [$href, $label]): ?>
    <a href="<?= h($href) ?>"<?= $key === $tabActive ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
    <?php
    $GLOBALS['pending_subnav'] = trim((string)ob_get_clean());
}
flash_render();
}

/** Renders the staff notification bell dropdown HTML. */
function admin_notif_html(array $notifs): string
{
    ob_start();
    ?>
  <details class="notif-menu" data-notif-menu data-csrf="<?= h(csrf_token()) ?>" data-unread="<?= (int)$notifs['unread'] ?>">
    <summary class="notif-btn" aria-label="Notifications" title="Notifications">
      <?= icon('bell', 19) ?>
      <span class="notif-badge" data-notif-badge<?= $notifs['unread'] > 0 ? '' : ' hidden' ?>><?= $notifs['unread'] > 99 ? '99+' : (int)$notifs['unread'] ?></span>
    </summary>
    <div class="notif-panel">
      <div class="notif-head">
        <strong>Notifications</strong>
        <form method="post" action="notifications.php" data-notif-readall>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="read_all">
          <button type="submit" class="notif-clear"<?= $notifs['unread'] > 0 ? '' : ' hidden' ?> data-notif-clear-btn>Mark all read</button>
        </form>
      </div>
      <ul class="notif-list" data-notif-list>
        <?php if (!$notifs['items']): ?>
          <li class="notif-empty">No notifications yet.</li>
        <?php else: foreach ($notifs['items'] as $nf): ?>
          <li class="notif-item<?= $nf['is_read'] ? '' : ' is-unread' ?>">
            <a href="<?= h($nf['href']) ?>" data-notif-id="<?= (int)$nf['id'] ?>">
              <span class="notif-top">
                <span class="notif-kind is-<?= h($nf['kind']) ?>"><?= h($nf['kind'] === 'payment' ? 'Payment' : ($nf['kind'] === 'cancelled' ? 'Cancelled' : 'Booking')) ?></span>
                <small class="notif-time"><?= h($nf['time']) ?></small>
              </span>
              <strong><?= h($nf['title']) ?></strong>
              <span class="notif-body"><?= h($nf['body']) ?></span>
              <?php if ($nf['resort_name'] !== ''): ?><small class="notif-resort"><?= h($nf['resort_name']) ?></small><?php endif; ?>
            </a>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </div>
  </details>
    <?php
    return trim((string)ob_get_clean());
}

/** Returns and clears the deferred notification bell on platform pages without a left context bar. */
function admin_pop_notif_bell(): string
{
    $html = (string)($GLOBALS['pending_notif_bell'] ?? '');
    $GLOBALS['pending_notif_bell'] = '';
    return $html;
}

/** Consistent 52x38 thumbnail (or initials placeholder) for admin tables so every row aligns cleanly. */
function admin_thumb_html(?string $relPath, string $label): string
{
    $p = trim((string)$relPath);
    $full = $p !== '' ? __DIR__ . '/../assets/' . $p : '';
    if ($p !== '' && is_file($full)) {
        return '<img class="res-thumb" src="../assets/' . h($p) . '?v=' . (int)filemtime($full)
             . '" alt="' . h($label) . '" width="52" height="38">';
    }
    $words = preg_split('/\s+/u', trim($label)) ?: [];
    $init = '';
    foreach ($words as $w) {
        if ($w !== '') {
            $init .= mb_strtoupper(mb_substr($w, 0, 1));
            if (mb_strlen($init) >= 2) break;
        }
    }
    if ($init === '') $init = '—';
    return '<span class="res-thumb is-empty" aria-hidden="true" title="No photo yet">' . h($init) . '</span>';
}

/** The same heading block on every staff page: title, optional one-line subtitle, optional actions (HTML). */
function admin_head(string $title, string $sub = '', string $actions = ''): void
{
    $bell = admin_pop_notif_bell();
    $right = trim($actions . ($bell !== '' ? ($actions !== '' ? ' ' : '') . $bell : ''));
    echo '<header class="page-head"><div class="ph-text"><h1>' . h($title) . '</h1>'
       . ($sub !== '' ? '<p class="page-sub">' . h($sub) . '</p>' : '') . '</div>'
       . ($right !== '' ? '<div class="actions">' . $right . '</div>' : '') . '</header>';
    if (!empty($GLOBALS['pending_subnav'])) {
        echo $GLOBALS['pending_subnav'];
        $GLOBALS['pending_subnav'] = '';
    }
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
    global $resort;
    $kind  = ['Resort' => 'Entire resort', 'Building' => 'Entire building', 'Pool' => 'Entire pool'][$t['scope']] ?? '';
    $unit  = $t['scope'] === 'Pool' ? 'per day' : 'per night';
    $sub   = match ($t['scope']) {
        'Resort'   => plural(count($t['rooms']), 'room') . ' and ' . plural(count($t['pools']), 'pool') . ', all yours · sleeps up to ' . $t['capacity'],
        'Building' => plural(count($t['rooms']), 'room') . ' · sleeps up to ' . $t['capacity'],
        default    => 'Up to ' . plural((int)$t['capacity'], 'guest') . ' in the water',
    };
    $photo = (string)($t['photo'] ?? '');
    if ($photo === '') {
        $rp = room_photos((string)$t['name']);
        $photo = $rp ? $rp[0]['url'] : resort_hero();
    }
    $checkUrl = ru('exclusive.php#' . strtolower($t['scope']) . '-' . $t['id']);
    $locShort = address_short((string)($resort['location'] ?? ''));
    ob_start(); ?>
<article class="type-row has-photo<?= $stay && !$t['free'] ? ' is-off' : '' ?>">
  <?php if ($photo !== ''): ?>
    <a class="tr-photo" href="<?= h($photo) ?>" data-lightbox="exclusive" style="background-image:url('<?= h($photo) ?>')" aria-label="View photo of <?= h($t['name']) ?>"></a>
  <?php else: ?>
    <div class="tr-photo no-photo" aria-hidden="true"></div>
  <?php endif; ?>
  <div>
    <p class="muted" style="margin:0 0 2px;font-size:12.5px">
      <strong style="color:#0b7f97;font-weight:600"><?= h($kind) ?></strong><?= $locShort !== '' ? ' · ' . h($locShort) : '' ?>
    </p>
    <h2><?= h($t['name']) ?></h2>
    <?php if ($t['info'] !== ''): ?><p><?= h($t['info']) ?></p><?php endif; ?>
    <p class="muted"><?= h($sub) ?></p>
    <?php if ($stay !== null):
        $guests  = $stay['adults'] + $stay['children'];
        $tooMany = $guests > $t['capacity'];
        if (!$t['free']): ?>
          <p class="ex-why"><?= h($t['why'] ?: 'Not available for those dates.') ?></p>
        <?php elseif ($tooMany): ?>
          <p class="ex-why">Holds up to <?= (int)$t['capacity'] ?> guests, and your group is <?= $guests ?>.</p>
        <?php endif;
    endif; ?>
  </div>
  <div class="type-side">
    <p class="price"><?= h(money($t['rate'])) ?> <small><?= h($unit) ?></small></p>
    <?php if ($stay === null): ?>
      <div class="reserve">
        <a class="btn primary" href="<?= h($checkUrl) ?>">Check dates</a>
      </div>
    <?php else:
        $guests  = $stay['adults'] + $stay['children'];
        $n       = nights($stay['check_in'], $stay['check_out']);
        $tooMany = $guests > $t['capacity'];
        if ($t['free'] && !$tooMany): ?>
          <p class="muted"><?= h(plural($n, $t['scope'] === 'Pool' ? 'day' : 'night')) ?>: <strong><?= h(money($t['rate'] * $n)) ?></strong></p>
          <form method="get" action="checkout.php" class="reserve">
            <?= rhidden() ?>
            <input type="hidden" name="scope" value="<?= h($t['scope']) ?>">
            <input type="hidden" name="target" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="check_in" value="<?= h($stay['check_in']) ?>">
            <input type="hidden" name="check_out" value="<?= h($stay['check_out']) ?>">
            <input type="hidden" name="adults" value="<?= (int)$stay['adults'] ?>">
            <input type="hidden" name="children" value="<?= (int)$stay['children'] ?>">
            <button class="btn primary" type="submit">Book now</button>
            <?= trip_add_button() ?>
          </form>
        <?php endif;
    endif; ?>
  </div>
</article>
<?php
    return (string)ob_get_clean();
}

/** Render horizontal rows capped to $limit (default 2) with a clean "View all N" toggle when more exist. */
function capped_list(array $itemsHtml, int $limit = 2): string
{
    $total = count($itemsHtml);
    if ($total === 0) {
        return '';
    }
    $html = '<div class="type-list" data-cap-list data-cap-limit="' . $limit . '">';
    foreach ($itemsHtml as $i => $item) {
        $extra = $i >= $limit ? ' data-cap-extra hidden' : '';
        $html .= '<div class="cap-item"' . $extra . '>' . $item . '</div>';
    }
    $html .= '</div>';
    if ($total > $limit) {
        $html .= '<div class="cap-more" data-cap-more>'
               . '<button type="button" class="btn sm" data-cap-toggle data-total="' . $total . '">View all ' . $total . '</button>'
               . '</div>';
    }
    return $html;
}

/** The "book the whole place" sections in inverted-triangle order: Entire resort -> Whole building -> Entire pool. */
function exclusive_cards(array $opts, ?array $stay, int $limit = 2): string
{
    $groups = [];
    if (!empty($opts['Resort']) && $opts['Resort']['rooms']) { $groups['Book the entire resort'] = [$opts['Resort']]; }
    if (!empty($opts['Building'])) { $groups['Whole building'] = $opts['Building']; }
    if (!empty($opts['Pool']))     { $groups['Entire pool'] = $opts['Pool']; }
    if (!$groups) {
        return '';
    }
    $html = '';
    $first = true;
    foreach ($groups as $label => $items) {
        $cards = [];
        foreach ($items as $t) {
            $cards[] = '<div id="' . h(strtolower($t['scope']) . '-' . $t['id']) . '">' . exclusive_card($t, $stay) . '</div>';
        }
        $html .= '<section class="block"' . ($first ? ' id="exclusive" style="margin-top:20px"' : '') . ' data-filter-section>'
               . '<div class="block-head"><h2>' . h($label) . '</h2>'
               . ($stay === null ? '<a href="' . h(ru('exclusive.php')) . '">Check dates</a>' : '')
               . '</div>'
               . capped_list($cards, $limit)
               . '</section>';
        $first = false;
    }
    return $html;
}


/** Compact quick-booking search bar for Resort Management (staff & admin side): search cottages or rooms to book. */
function admin_quick_book_bar(): string
{
    $in  = add_days(today(), 0);
    $out = add_days(today(), 1);
    ob_start();
    ?>
<section class="panel qb-panel" aria-label="Quick search room or cottage to book">
  <form method="get" action="reservation_new.php" class="qb-form" data-qb-form>
    <label class="qb-q"><span>Search room or cottage</span>
      <input type="search" name="q" placeholder="e.g. Beach Cottage, Deluxe, 101…" maxlength="80" autocomplete="off">
    </label>
    <label class="qb-mode"><span>Booking type</span>
      <select name="mode" data-qb-mode>
        <option value="cottage">Day-use cottage</option>
        <option value="rooms">Overnight room</option>
      </select>
    </label>
    <label><span data-qb-in-lbl>Visit date</span>
      <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" required>
    </label>
    <label data-qb-out-wrap hidden><span>Check-out</span>
      <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>">
    </label>
    <label class="qb-guests"><span>Guests</span>
      <input type="number" name="adults" value="2" min="1" max="50" step="1" required>
    </label>
    <input type="hidden" name="children" value="0">
    <button class="btn primary qb-btn" type="submit">Search &amp; book &rarr;</button>
  </form>
</section>
<script>
(function () {
  var f = document.querySelector('[data-qb-form]');
  if (!f || f.dataset.qbBound) return;
  f.dataset.qbBound = '1';
  var mode = f.querySelector('[data-qb-mode]');
  var outWrap = f.querySelector('[data-qb-out-wrap]');
  var outIn = outWrap ? outWrap.querySelector('input') : null;
  var inEl = f.querySelector('[name="check_in"]');
  var inLbl = f.querySelector('[data-qb-in-lbl]');
  function sync() {
    var isCot = mode.value === 'cottage';
    if (outWrap) outWrap.hidden = isCot;
    if (outIn) outIn.required = !isCot;
    if (inLbl) inLbl.textContent = isCot ? 'Visit date' : 'Check-in';
    if (!isCot && inEl && inEl.value && outIn && (!outIn.value || outIn.value <= inEl.value)) {
      var p = inEl.value.split('-');
      var d = new Date(+p[0], +p[1] - 1, +p[2] + 1);
      var pad = function (n) { return (n < 10 ? '0' : '') + n; };
      outIn.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
  }
  mode.addEventListener('change', sync);
  if (inEl) inEl.addEventListener('change', sync);
  sync();
})();
</script>
<?php
    return (string)ob_get_clean();
}
