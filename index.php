<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reviews.php';

$signedIn = !empty($_SESSION['guest_id']);
$allStats = review_stats_all($pdo);

// Active resorts with live rates & inventory
$resorts = $pdo->query(
    "SELECT r.*,
            (SELECT MIN(p.price) FROM price_item p WHERE p.resort_id = r.resort_id AND p.category = 'Cottage (day use)' AND p.archived_at IS NULL) AS cottage_from,
            (SELECT COUNT(*) FROM price_item p WHERE p.resort_id = r.resort_id AND p.category = 'Cottage (day use)' AND p.archived_at IS NULL) AS cottages,
            (SELECT MIN(t.base_rate) FROM room_type t WHERE t.resort_id = r.resort_id AND t.archived_at IS NULL) AS from_rate,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id AND x.status <> 'Maintenance' AND x.archived_at IS NULL) AS rooms,
            (SELECT COUNT(*) FROM pool p WHERE p.resort_id = r.resort_id AND p.archived_at IS NULL) AS pools
       FROM resort r
      WHERE r.is_active = 1 AND r.archived_at IS NULL
      ORDER BY r.sort_order, r.name"
)->fetchAll();

$totalResorts  = count($resorts);
$totalCottages = 0;
$totalRooms    = 0;
foreach ($resorts as $r) {
    $totalCottages += (int)$r['cottages'];
    $totalRooms    += (int)$r['rooms'];
}

$revTotalCnt = 0;
$revWeighted = 0.0;
foreach ($allStats as $st) {
    $revTotalCnt += (int)$st['count'];
    $revWeighted += (float)$st['avg'] * (int)$st['count'];
}
$revAvg = $revTotalCnt > 0 ? round($revWeighted / $revTotalCnt, 1) : 0.0;

// High-resolution flagship photograph for full-bleed hero
$heroPhoto = '';
if (is_file(__DIR__ . '/assets/landing.jpg')) {
    $heroPhoto = 'assets/landing.jpg?v=' . filemtime(__DIR__ . '/assets/landing.jpg');
} else {
    foreach ($resorts as $r) {
        $hf = (string)($r['hero_image'] ?? '');
        if ($hf !== '' && preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', $hf) && is_file(__DIR__ . '/assets/' . $hf)) {
            $heroPhoto = 'assets/' . $hf . '?v=' . filemtime(__DIR__ . '/assets/' . $hf);
            break;
        }
    }
}

// Helper for resolving a resort's local cover image
$resortPhotoUrl = static function (?string $hf): string {
    $hf = (string)$hf;
    if ($hf !== '' && preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', $hf) && is_file(__DIR__ . '/assets/' . $hf)) {
        return 'assets/' . $hf . '?v=' . filemtime(__DIR__ . '/assets/' . $hf);
    }
    return '';
};

// Curated day-use cottages across resorts (up to 4)
$cotRows = $pdo->query(
    "SELECT p.price_item_id, p.resort_id, p.item_name, p.note, p.price, p.unit,
            COALESCE(p.max_guests, 15) AS max_guests,
            COALESCE(p.quantity_available, 5) AS quantity_available,
            r.name AS resort_name, r.slug AS resort_slug, r.location AS resort_location, r.theme AS resort_theme, r.hero_image AS resort_hero
       FROM price_item p
       JOIN resort r ON r.resort_id = p.resort_id
      WHERE r.is_active = 1 AND r.archived_at IS NULL AND p.archived_at IS NULL AND p.category = 'Cottage (day use)'
      ORDER BY r.sort_order, p.sort_order, p.price ASC"
)->fetchAll();

$featuredCottages = [];
$seenResorts      = [];
foreach ($cotRows as $cr) {
    $crid = (int)$cr['resort_id'];
    if (!isset($seenResorts[$crid])) {
        $seenResorts[$crid] = true;
        $featuredCottages[] = $cr;
    }
    if (count($featuredCottages) >= 4) {
        break;
    }
}
if (count($featuredCottages) < 4) {
    foreach ($cotRows as $cr) {
        if (count($featuredCottages) >= 4) {
            break;
        }
        if (!in_array($cr, $featuredCottages, true)) {
            $featuredCottages[] = $cr;
        }
    }
}

// Live published guest reviews
$topReviews = $pdo->query(
    "SELECT w.rating, w.title, w.comment, w.author, w.created_at, r.name AS resort_name, r.slug AS resort_slug
       FROM review w
       JOIN resort r ON r.resort_id = w.resort_id
      WHERE w.status = 'Published' AND r.is_active = 1 AND r.archived_at IS NULL AND w.rating >= 4 AND TRIM(COALESCE(w.comment, '')) <> ''
      ORDER BY w.rating DESC, w.created_at DESC
      LIMIT 3"
)->fetchAll();

$in  = add_days(today(), 1);
$out = add_days(today(), 3);

// Split resorts into Lead Feature (1) + Folio Row (3) + Directory Index (remainder)
$leadResort  = $resorts[0] ?? null;
$folioRow    = array_slice($resorts, 1, 3);
$extraResorts = array_slice($resorts, 4);

site_start('Book Zamboanga Beach Resorts & Cottages', 'home', 'home-lp bleed');
?>

<!-- =================================================================
     1. COMPACT FULL-BLEED MAGAZINE HERO + LIVE AVAILABILITY FINDER
     ================================================================= -->
<section class="mg-hero" aria-label="Search Zamboanga beach resorts">
  <div class="mg-hero-bg" aria-hidden="true"<?= $heroPhoto !== '' ? ' style="background-image:url(\'' . h($heroPhoto) . '\')"' : '' ?>></div>
  <div class="mg-hero-scrim" aria-hidden="true"></div>

  <div class="mg-hero-inner">
    <div class="mg-hero-head">
      <h1 class="mg-thesis">Zamboanga’s coast, <em>open for your date.</em></h1>
      <p class="mg-lead">Compare live day-use cottages and overnight rooms across <?= h(plural($totalResorts, 'Zamboanga resort')) ?>—pay direct with GCash or Maya.</p>
    </div>

    <form method="get" action="search.php" class="mg-finder is-cottage" data-stay id="hero-search" aria-label="Check live resort availability">
      <label class="mg-f-cell mg-f-type">
        <span>Stay type</span>
        <select name="mode" data-mode-select>
          <option value="cottage" selected>Cottage (Day use)</option>
          <option value="rooms">Overnight room</option>
        </select>
      </label>

      <label class="mg-f-cell mg-f-date">
        <span data-in-label>Visit date</span>
        <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" max="<?= h(add_days(today(), (int)$config['max_advance_days'])) ?>" required>
      </label>

      <label class="mg-f-cell mg-f-date" data-out-wrap hidden>
        <span>Check-out <em class="nights" data-nights></em></span>
        <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>" disabled>
      </label>

      <label class="mg-f-cell mg-f-guests">
        <span>Guests</span>
        <input type="number" name="adults" value="4" min="1" max="50" step="1" required>
      </label>

      <input type="hidden" name="children" value="0">
      <button class="mg-finder-btn" type="submit" data-search-btn>Find cottages</button>
    </form>

    <div class="mg-hero-foot">
      <a class="mg-hero-dir" href="resorts.php">
        <span>Browse all <?= $totalResorts ?> resorts</span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
      <p class="mg-hero-ledger">
        <span><?= h(plural($totalCottages, 'cottage type')) ?></span>
        <span><?= h(plural($totalRooms, 'overnight room')) ?></span>
        <?php if ($revTotalCnt > 0): ?>
          <span class="mg-star-val"><?= h(number_format($revAvg, 1)) ?> <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z"/></svg> (<?= h(plural($revTotalCnt, 'review')) ?>)</span>
        <?php endif; ?>
      </p>
    </div>
  </div>
</section>

<!-- =================================================================
     2. DIRECTORY-FORWARD SHOWCASE: FEATURED RESORTS & DAY-USE COTTAGES
     ================================================================= -->
<?php if ($resorts || $featuredCottages): ?>
<section class="mg-band mg-directory" id="discover" aria-label="Featured resorts and day-use cottages">
  <div class="mg-band-inner">
    <div class="mg-masthead">
      <div class="mg-mh-left">
        <h2>Where to stay in Zamboanga</h2>
      </div>
      <div class="mg-mh-right">
        <div class="mg-tabs" role="tablist" aria-label="Browse resorts or cottages" data-mg-tabs>
          <button type="button" class="mg-tab is-active" data-mg-tab="resorts" role="tab" aria-selected="true">Resorts (<?= $totalResorts ?>)</button>
          <?php if ($featuredCottages): ?>
          <button type="button" class="mg-tab" data-mg-tab="cottages" role="tab" aria-selected="false">Day-use cottages (<?= count($featuredCottages) ?>)</button>
          <?php endif; ?>
        </div>
        <a class="mg-all-link" href="resorts.php">All resorts &rarr;</a>
      </div>
    </div>

    <!-- Tab 1: Featured Resorts (Lead Editorial Spread + 3-Col Folio + Quick Index) -->
    <div class="mg-panel is-active" data-mg-panel="resorts">
      <?php if ($leadResort):
          $lTheme = RESORT_THEMES[$leadResort['theme']] ?? RESORT_THEMES['ocean'];
          $lHero  = $resortPhotoUrl($leadResort['hero_image'] ?? '');
          $lUrl   = 'landing.php?r=' . rawurlencode((string)$leadResort['slug']);
          $lLoc   = address_short((string)($leadResort['location'] ?? ''));
          $lStat  = $allStats[(int)$leadResort['resort_id']] ?? [];
      ?>
      <article class="mg-feature">
        <a class="mg-feat-media<?= $lHero === '' ? ' is-plate' : '' ?>" href="<?= h($lUrl) ?>" style="<?= $lHero !== '' ? 'background-image:url(\'' . h($lHero) . '\')' : 'background-image:' . h($lTheme[1]) ?>" aria-label="Open <?= h($leadResort['name']) ?>"></a>
        <div class="mg-feat-body">
          <h3><a href="<?= h($lUrl) ?>"><?= h($leadResort['name']) ?></a></h3>
          <?php if ($lLoc !== ''): ?><p class="mg-place"><?= h($lLoc) ?></p><?php endif; ?>
          <?php if (!empty($leadResort['tagline'])): ?><p class="mg-tagline"><?= h($leadResort['tagline']) ?></p><?php endif; ?>
          <p class="mg-rating-row"><?= rating_line($lStat, ru('reviews.php', (string)$leadResort['slug'])) ?></p>
          <p class="mg-specs">
            <?= (int)$leadResort['cottages'] > 0 ? h(plural((int)$leadResort['cottages'], 'cottage type')) . ' · ' : '' ?><?= h(plural((int)$leadResort['rooms'], 'room')) ?> · <?= h(plural((int)$leadResort['pools'], 'pool')) ?>
          </p>
          <div class="mg-feat-foot">
            <div class="mg-price-block">
              <?php if ($leadResort['cottage_from'] !== null): ?>
                <span class="mg-price-val">From <?= h(money($leadResort['cottage_from'])) ?></span>
                <span class="mg-price-unit">day-use cottage</span>
              <?php elseif ($leadResort['from_rate'] !== null): ?>
                <span class="mg-price-val">From <?= h(money($leadResort['from_rate'])) ?></span>
                <span class="mg-price-unit">per night</span>
              <?php endif; ?>
            </div>
            <a class="mg-btn-ink" href="<?= h($lUrl) ?>">View resort</a>
          </div>
        </div>
      </article>
      <?php endif; ?>

      <?php if ($folioRow): ?>
      <div class="mg-folio-grid">
        <?php foreach ($folioRow as $r):
            $theme = RESORT_THEMES[$r['theme']] ?? RESORT_THEMES['ocean'];
            $hero  = $resortPhotoUrl($r['hero_image'] ?? '');
            $url   = 'landing.php?r=' . rawurlencode((string)$r['slug']);
            $loc   = address_short((string)($r['location'] ?? ''));
            $locParts = array_map('trim', explode(',', $loc));
            $plateArea = $locParts[1] ?? ($locParts[0] ?? 'Zamboanga');
            $stat  = $allStats[(int)$r['resort_id']] ?? [];
        ?>
        <article class="mg-card">
          <a class="mg-card-media<?= $hero === '' ? ' is-plate' : '' ?>" href="<?= h($url) ?>" style="<?= $hero !== '' ? 'background-image:url(\'' . h($hero) . '\')' : 'background-image:' . h($theme[1]) ?>" aria-label="Open <?= h($r['name']) ?>">
            <?php if ($hero === ''): ?>
              <span class="mg-plate-title" aria-hidden="true"><?= h($plateArea) ?></span>
            <?php endif; ?>
          </a>
          <div class="mg-card-body">
            <h3><a href="<?= h($url) ?>"><?= h($r['name']) ?></a></h3>
            <?php if ($loc !== ''): ?><p class="mg-place"><?= h($loc) ?></p><?php endif; ?>
            <?php if (!empty($r['tagline'])): ?><p class="mg-card-desc"><?= h($r['tagline']) ?></p><?php endif; ?>
            <p class="mg-rating-row"><?= rating_line($stat, ru('reviews.php', (string)$r['slug'])) ?></p>
            <p class="mg-specs">
              <?= (int)$r['cottages'] > 0 ? h(plural((int)$r['cottages'], 'cottage type')) . ' · ' : '' ?><?= h(plural((int)$r['rooms'], 'room')) ?> · <?= h(plural((int)$r['pools'], 'pool')) ?>
            </p>
            <div class="mg-card-foot">
              <div class="mg-price-block">
                <?php if ($r['cottage_from'] !== null): ?>
                  <span class="mg-price-val">From <?= h(money($r['cottage_from'])) ?></span>
                  <span class="mg-price-unit">cottage</span>
                <?php elseif ($r['from_rate'] !== null): ?>
                  <span class="mg-price-val">From <?= h(money($r['from_rate'])) ?></span>
                  <span class="mg-price-unit">/ night</span>
                <?php endif; ?>
              </div>
              <a class="mg-card-link" href="<?= h($url) ?>">View &rarr;</a>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($extraResorts): ?>
      <div class="mg-index-list" aria-label="More active resorts">
        <?php foreach ($extraResorts as $er):
            $eUrl = 'landing.php?r=' . rawurlencode((string)$er['slug']);
            $eLoc = address_short((string)($er['location'] ?? ''));
            $ePrice = $er['cottage_from'] !== null
                ? 'From ' . money($er['cottage_from']) . ' cottage'
                : ($er['from_rate'] !== null ? 'From ' . money($er['from_rate']) . ' / night' : 'See rates');
        ?>
        <a class="mg-index-row" href="<?= h($eUrl) ?>">
          <span class="mg-ir-name"><?= h($er['name']) ?></span>
          <span class="mg-ir-loc"><?= h($eLoc ?: 'Zamboanga City') ?></span>
          <span class="mg-ir-inv"><?= (int)$er['cottages'] > 0 ? h(plural((int)$er['cottages'], 'cottage')) . ' · ' : '' ?><?= h(plural((int)$er['rooms'], 'room')) ?></span>
          <span class="mg-ir-price"><?= h($ePrice) ?></span>
          <span class="mg-ir-go" aria-hidden="true">Open &rarr;</span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Tab 2: Day-Use Cottages (Horizontal .type-row.has-photo Rows) -->
    <?php if ($featuredCottages): ?>
    <div class="mg-panel" data-mg-panel="cottages" hidden>
      <div class="type-list mg-cottage-list">
        <?php foreach ($featuredCottages as $fc):
            $theme    = RESORT_THEMES[$fc['resort_theme']] ?? RESORT_THEMES['ocean'];
            $photo    = $resortPhotoUrl($fc['resort_hero'] ?? '');
            $slug     = (string)$fc['resort_slug'];
            $shortLoc = address_short((string)($fc['resort_location'] ?? ''));
            $detailUrl = 'room.php?' . http_build_query([
                'r' => $slug,
                'cottage' => (int)$fc['price_item_id'],
                'check_in' => $in,
                'adults' => 2,
            ]);
        ?>
        <article class="type-row has-photo">
          <a class="tr-photo<?= $photo === '' ? ' no-photo' : '' ?>" href="<?= h($detailUrl) ?>" style="<?= $photo !== '' ? 'background-image:url(\'' . h($photo) . '\')' : 'background-image:' . h($theme[1]) . '!important' ?>" aria-label="View <?= h($fc['item_name']) ?>"></a>
          <div>
            <h3><a href="<?= h($detailUrl) ?>"><?= h($fc['item_name']) ?></a></h3>
            <p class="mg-cot-resort">
              <a href="landing.php?r=<?= h(rawurlencode($slug)) ?>"><?= h($fc['resort_name']) ?></a><?= $shortLoc !== '' ? ' · ' . h($shortLoc) : '' ?>
            </p>
            <p><?= h($fc['note'] ?: 'Single cottage · Day use') ?></p>
            <p class="muted">Up to <?= (int)$fc['max_guests'] ?> guests · <?= h(plural((int)$fc['quantity_available'], 'cottage')) ?>.</p>
          </div>
          <div class="type-side">
            <p class="price"><?= h(money($fc['price'])) ?> <small><?= h($fc['unit'] ?: 'per cottage') ?></small></p>
            <form method="post" action="trip_add.php" class="reserve">
              <input type="hidden" name="r" value="<?= h($slug) ?>">
              <input type="hidden" name="scope" value="Cottage">
              <input type="hidden" name="target" value="<?= (int)$fc['price_item_id'] ?>">
              <input type="hidden" name="check_in" value="<?= h($in) ?>">
              <input type="hidden" name="check_out" value="<?= h(add_days($in, 1)) ?>">
              <input type="hidden" name="adults" value="2">
              <input type="hidden" name="children" value="0">
              <a class="btn primary" href="<?= h($detailUrl . '#book') ?>">View &amp; book</a>
              <?= trip_add_button() ?>
            </form>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- =================================================================
     3. DEEP TWILIGHT WATER BAND: HOW GCASH & MAYA BOOKING WORKS
     ================================================================= -->
<section class="mg-water" id="how" aria-label="How booking works">
  <div class="mg-water-inner">
    <div class="mg-water-head">
      <h2>Direct booking,<br><em>without the inbox wait.</em></h2>
      <p>Skip Facebook DM back-and-forth. See real cottage capacity, entrance fees, and resort GCash or Maya details upfront.</p>
    </div>

    <ol class="mg-ledger">
      <li class="mg-ledger-item">
        <span class="mg-num" aria-hidden="true">01</span>
        <div>
          <h3>Check live dates &amp; real totals</h3>
          <p>Choose a day-use cottage, overnight room, or whole-place rental with per-guest entrance fees calculated before checkout.</p>
        </div>
      </li>
      <li class="mg-ledger-item">
        <span class="mg-num" aria-hidden="true">02</span>
        <div>
          <h3>Transfer via GCash or Maya</h3>
          <p>Pay a downpayment to hold your date or settle in full directly to the resort’s account, then upload your receipt screenshot.</p>
        </div>
      </li>
      <li class="mg-ledger-item">
        <span class="mg-num" aria-hidden="true">03</span>
        <div>
          <h3>Verified by the resort front desk</h3>
          <p>Staff confirm your transfer and update your booking reference—settle any remaining balance online or in person at arrival.</p>
        </div>
      </li>
    </ol>
  </div>
</section>

<!-- =================================================================
     4. EDITORIAL VERIFIED GUEST REVIEWS
     ================================================================= -->
<?php if ($topReviews): ?>
<section class="mg-band mg-reviews-sec" id="reviews" aria-label="Guest reviews">
  <div class="mg-band-inner">
    <div class="mg-masthead">
      <div class="mg-mh-left">
        <h2>Notes from recent stays</h2>
      </div>
      <?php if ($revTotalCnt > 0): ?>
      <div class="mg-mh-right">
        <span class="mg-rev-summary mg-star-val"><?= h(number_format($revAvg, 1)) ?> <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z"/></svg> across <?= h(plural($revTotalCnt, 'published review')) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <div class="mg-quotes">
      <?php foreach ($topReviews as $idx => $rv): ?>
      <article class="mg-quote">
        <div class="mg-quote-meta">
          <?= stars((float)$rv['rating']) ?>
          <a class="mg-quote-resort" href="landing.php?r=<?= h(rawurlencode((string)$rv['resort_slug'])) ?>"><?= h($rv['resort_name']) ?></a>
        </div>
        <blockquote class="mg-quote-text">“<?= h(mb_strimwidth(trim((string)$rv['comment']), 0, 175, '…')) ?>”</blockquote>
        <footer class="mg-quote-foot">
          <strong><?= h($rv['author']) ?></strong>
          <span><?= h(fdate(substr((string)$rv['created_at'], 0, 10))) ?></span>
        </footer>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- =================================================================
     5. DEEP-WATER CLOSING FOLIO (MERGES SEAMLESSLY INTO SHARED FOOTER)
     ================================================================= -->
<section class="mg-closing" aria-label="Explore all resorts">
  <div class="mg-closing-inner">
    <div class="mg-closing-copy">
      <h2>Plan your next <em>beach day.</em></h2>
      <p><?= $signedIn
        ? 'Jump straight into all ' . $totalResorts . ' active Zamboanga resorts or manage your saved reservations.'
        : 'Browse all ' . $totalResorts . ' active Zamboanga resorts now, or create a free account when you are ready to reserve.' ?></p>
    </div>
    <div class="mg-closing-actions">
      <a class="mg-btn-ochre" href="resorts.php">Browse all <?= $totalResorts ?> resorts</a>
      <?php if ($signedIn): ?>
        <a class="mg-btn-line" href="account.php">My account</a>
      <?php else: ?>
        <a class="mg-btn-line" href="login.php">Log in</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php site_end();
