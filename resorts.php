<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reviews.php';
$allStats = review_stats_all($pdo);

$resorts = $pdo->query(
    "SELECT r.*,
            (SELECT MIN(p.price) FROM price_item p WHERE p.resort_id = r.resort_id AND p.category = 'Cottage (day use)' AND p.archived_at IS NULL) AS cottage_from,
            (SELECT COUNT(*) FROM price_item p WHERE p.resort_id = r.resort_id AND p.category = 'Cottage (day use)' AND p.archived_at IS NULL) AS cottages,
            (SELECT MIN(t.base_rate) FROM room_type t WHERE t.resort_id = r.resort_id AND t.archived_at IS NULL) AS from_rate,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id AND x.status <> 'Maintenance' AND x.archived_at IS NULL) AS rooms,
            (SELECT COUNT(*) FROM pool p WHERE p.resort_id = r.resort_id AND p.archived_at IS NULL) AS pools,
            (SELECT COUNT(*) FROM building b WHERE b.resort_id = r.resort_id AND b.archived_at IS NULL) AS buildings
       FROM resort r WHERE r.is_active = 1 AND r.archived_at IS NULL ORDER BY r.sort_order, r.name"
)->fetchAll();

// The search bar lives in the page header (see site_start), on top of every resorts page.
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $resorts = array_values(array_filter($resorts, fn($r) => stripos($r['name'] . ' ' . $r['location'] . ' ' . $r['tagline'], $q) !== false));
}

// Sort: the site's own order (default), best rated first, or by name. Resorts with no reviews always come after rated ones.
$sorts = ['default' => 'Featured', 'rating' => 'Top rated', 'name' => 'Name, A to Z', 'name_desc' => 'Name, Z to A'];
$sort = array_key_exists((string)($_GET['sort'] ?? ''), $sorts) ? (string)$_GET['sort'] : 'default';
if ($sort !== 'default') {
    $avg = fn($r) => $allStats[(int)$r['resort_id']]['avg'] ?? null;
    $cnt = fn($r) => $allStats[(int)$r['resort_id']]['count'] ?? 0;
    usort($resorts, function ($a, $b) use ($sort, $avg, $cnt) {
        if ($sort === 'rating') {
            $x = $avg($a); $y = $avg($b);
            if (($x === null) !== ($y === null)) { return $x === null ? 1 : -1; }
            if ($x !== $y) { return $y <=> $x; }
            if ($cnt($a) !== $cnt($b)) { return $cnt($b) <=> $cnt($a); }
            return strcasecmp($a['name'], $b['name']);
        }
        return $sort === 'name' ? strcasecmp($a['name'], $b['name']) : strcasecmp($b['name'], $a['name']);
    });
}

$in       = add_days(today(), 1);
$out      = add_days(today(), 3);
$adults   = 2;
$children = 0;

site_start('Book a resort in Zamboanga', 'all', 'platform');

// Build hero slideshow from active resorts' hero photos + gallery photos (up to 5 slides).
$heroSlides = [];
foreach ($resorts as $r) {
    $hf = (string)($r['hero_image'] ?? '');
    if ($hf !== '' && preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', $hf) && is_file(__DIR__ . '/assets/' . $hf)) {
        $heroSlides[] = [
            'url'      => 'assets/' . $hf . '?v=' . filemtime(__DIR__ . '/assets/' . $hf),
            'name'     => (string)$r['name'],
            'location' => address_short((string)($r['location'] ?? '')),
            'href'     => 'landing.php?r=' . rawurlencode((string)$r['slug']),
        ];
    }
    if (count($heroSlides) >= 5) {
        break;
    }
}
if (count($heroSlides) < 5) {
    try {
        $gStmt = $pdo->query(
            "SELECT rp.resort_id, rp.file_name, rp.caption, r.name, r.slug, r.location
               FROM resort_photo rp
               JOIN resort r ON r.resort_id = rp.resort_id
              WHERE r.is_active = 1
              ORDER BY rp.sort_order, rp.photo_id
              LIMIT 12"
        );
        foreach ($gStmt->fetchAll() as $gp) {
            if (count($heroSlides) >= 5) {
                break;
            }
            $fn = basename((string)$gp['file_name']);
            $rel = 'uploads/gallery/' . (int)$gp['resort_id'] . '/' . $fn;
            if ($fn !== '' && is_file(__DIR__ . '/' . $rel)) {
                $heroSlides[] = [
                    'url'      => $rel . '?v=' . filemtime(__DIR__ . '/' . $rel),
                    'name'     => (string)$gp['name'],
                    'location' => (string)($gp['caption'] ?: address_short((string)($gp['location'] ?? ''))),
                    'href'     => 'landing.php?r=' . rawurlencode((string)$gp['slug']),
                ];
            }
        }
    } catch (Throwable $e) {
        // Gallery table optional
    }
}
if (!$heroSlides && is_file(__DIR__ . '/assets/landing.jpg')) {
    $heroSlides[] = [
        'url'      => 'assets/landing.jpg?v=' . filemtime(__DIR__ . '/assets/landing.jpg'),
        'name'     => $config['platform'],
        'location' => 'Zamboanga',
        'href'     => 'resorts.php#resorts',
    ];
}
?>
<section class="hero-slider" data-hero-slider data-interval="5500" aria-label="Featured resorts">
  <div class="hs-slides">
    <?php foreach ($heroSlides as $i => $sl): ?>
      <div class="hs-slide<?= $i === 0 ? ' is-active' : '' ?>"
           style="background-image:url('<?= h($sl['url']) ?>')"
           data-name="<?= h($sl['name']) ?>"
           data-loc="<?= h($sl['location']) ?>"
           data-href="<?= h($sl['href']) ?>"
           aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="hs-scrim" aria-hidden="true"></div>

  <?php if (count($heroSlides) > 1): ?>
    <button type="button" class="hs-nav hs-prev" data-hs-prev aria-label="Previous photo">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <button type="button" class="hs-nav hs-next" data-hs-next aria-label="Next photo">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
  <?php endif; ?>

  <div class="hs-inner">
    <div class="hs-head">
      <div>
        <h1>Find a resort cottage</h1>
        <p class="hs-sub">Pick a date and group size to see available cottages and rooms across Zamboanga.</p>
      </div>
      <?php if ($heroSlides): ?>
        <a class="hs-credit" data-hs-credit href="<?= h($heroSlides[0]['href']) ?>">
          <strong data-hs-name><?= h($heroSlides[0]['name']) ?></strong>
          <span data-hs-loc><?= h($heroSlides[0]['location']) ?></span>
        </a>
      <?php endif; ?>
    </div>

    <?php if (count($heroSlides) > 1): ?>
    <div class="hs-bars" role="tablist" aria-label="Choose slide">
      <?php foreach ($heroSlides as $i => $sl): ?>
        <button type="button" class="hs-bar<?= $i === 0 ? ' is-active' : '' ?>" data-hs-go="<?= $i ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="<?= h($sl['name']) ?>">
          <span><i></i></span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="hs-search-wrap">
  <?php require __DIR__ . '/includes/search_form.php'; ?>
</div>

<section class="block" id="resorts">
  <div class="block-head" style="margin-bottom:10px">
    <div>
      <h2><?= $q !== '' ? 'Resorts matching “' . h($q) . '”' : 'Explore resorts in Zamboanga' ?></h2>
    </div>
  </div>
  <form class="list-tools" method="get" action="resorts.php#resorts">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
    <label class="sort-pick"><span>Sort by</span>
      <select name="sort" onchange="this.form.submit()" aria-label="Sort resorts">
        <?php foreach ($sorts as $k => $label): ?><option value="<?= h($k) ?>"<?= $k === $sort ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <noscript><button class="btn sm" type="submit">Apply</button></noscript>
  </form>
  <?php if (!$resorts): ?>
    <p class="empty">No resorts match. <a href="resorts.php">Show all resorts</a></p>
  <?php endif; ?>
  <div class="room-cards">
    <?php foreach ($resorts as $r):
        $theme = RESORT_THEMES[$r['theme']] ?? RESORT_THEMES['ocean'];
        $hero  = (preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', (string)$r['hero_image']) && is_file(__DIR__ . '/assets/' . $r['hero_image'])) ? 'assets/' . $r['hero_image'] . '?v=' . filemtime(__DIR__ . '/assets/' . $r['hero_image']) : '';
        $url   = 'landing.php?r=' . rawurlencode($r['slug']);
        ?>
    <article class="room-card">
      <a class="rc-photo<?= $hero === '' ? ' no-photo' : '' ?>" href="<?= h($url) ?>" style="<?= $hero !== '' ? 'background-image:url(\'' . h($hero) . '\')' : 'background-image:' . h($theme[1]) . '!important' ?>" aria-label="Open <?= h($r['name']) ?>">
        <?php $shortLoc = address_short((string)($r['location'] ?? '')); if ($shortLoc !== ''): ?><span class="rc-tag"><?= h($shortLoc) ?></span><?php endif; ?>
      </a>
      <div class="rc-body">
        <h3><a href="<?= h($url) ?>"><?= h($r['name']) ?></a></h3>
        <p class="rc-desc"><?= h($r['tagline']) ?></p>
        <p class="rc-rating"><?= rating_line($allStats[(int)$r['resort_id']] ?? [], ru('reviews.php', $r['slug'])) ?></p>
        <p class="muted"><?= (int)$r['cottages'] > 0 ? h(plural((int)$r['cottages'], 'cottage type')) . ' · ' : '' ?><?= h(plural((int)$r['rooms'], 'room')) ?> · <?= h(plural((int)$r['pools'], 'pool')) ?></p>
        <div class="rc-foot">
          <p class="price"><?php if ($r['cottage_from'] !== null): ?>Cottages from <?= h(money($r['cottage_from'])) ?><?php elseif ($r['from_rate'] !== null): ?>From <?= h(money($r['from_rate'])) ?> <small>per night</small><?php endif; ?></p>
          <a class="btn primary" href="<?= h($url) ?>">View resort</a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php site_end();
