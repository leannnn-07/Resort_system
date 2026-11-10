<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/reviews.php';
$allStats = review_stats_all($pdo);

$resorts = $pdo->query(
    "SELECT r.*,
            (SELECT MIN(t.base_rate) FROM room_type t WHERE t.resort_id = r.resort_id) AS from_rate,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id AND x.status <> 'Maintenance') AS rooms,
            (SELECT COUNT(*) FROM pool p WHERE p.resort_id = r.resort_id) AS pools,
            (SELECT COUNT(*) FROM building b WHERE b.resort_id = r.resort_id) AS buildings
       FROM resort r WHERE r.is_active = 1 ORDER BY r.sort_order, r.name"
)->fetchAll();

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $resorts = array_values(array_filter($resorts, fn($r) => stripos($r['name'] . ' ' . $r['location'] . ' ' . $r['tagline'], $q) !== false));
}

site_start('Find a resort', 'all', 'platform');
$totalRooms = array_sum(array_column($resorts, 'rooms'));
$cheapest   = array_filter(array_column($resorts, 'from_rate'), fn($v) => $v !== null);
?>
<section class="landing-hero">
  <div class="lh-copy">
    <h1><?= h($config['platform']) ?></h1>
    <p class="lead">One place to book every resort.</p>
    <p class="lh-sub">Choose a resort, pick your dates, and book a room, a pool, a whole building or the entire resort. Pay at the resort.</p>
    <div class="actions">
      <a class="btn primary" href="#resorts">Browse resorts</a>
      <a class="btn" href="booking.php">Manage booking</a>
    </div>
  </div>

  <form method="get" class="search-card plat-search" id="find">
    <label class="wide"><span>Search resorts by name or place</span><input type="search" name="q" value="<?= h($q) ?>" placeholder="For example: Palawan, pool, lakeview"></label>
    <button class="btn primary" type="submit">Search</button>
  </form>

  <ul class="lh-facts">
    <li><b><?= count($resorts) ?> <?= count($resorts) === 1 ? 'resort' : 'resorts' ?></b><span><?= $q !== '' ? 'match your search' : 'to choose from' ?></span></li>
    <?php if ($cheapest): ?><li><b>From <?= h(money((float)min($cheapest))) ?></b><span>per night</span></li><?php endif; ?>
    <li><b>Pay at the resort</b><span>nothing charged online</span></li>
  </ul>
</section>

<section class="block" id="resorts">
  <div class="block-head"><h2><?= $q !== '' ? h(plural(count($resorts), 'resort')) . ' found' : 'Our resorts' ?></h2></div>
  <?php if (!$resorts): ?>
    <p class="empty">No resorts match. <a href="index.php">Show all resorts</a>.</p>
  <?php endif; ?>
  <div class="room-cards">
    <?php foreach ($resorts as $r):
        $theme = RESORT_THEMES[$r['theme']] ?? RESORT_THEMES['ocean'];
        $hero  = (preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-.]+)?\.(jpe?g|png|webp)$#i', (string)$r['hero_image']) && is_file(__DIR__ . '/assets/' . $r['hero_image'])) ? 'assets/' . $r['hero_image'] : '';
        $url   = 'landing.php?r=' . rawurlencode($r['slug']);
        ?>
    <article class="room-card">
      <a class="rc-photo<?= $hero === '' ? ' no-photo' : '' ?>" href="<?= h($url) ?>" style="<?= $hero !== '' ? 'background-image:url(\'' . h($hero) . '\')' : 'background-image:' . h($theme[1]) . '!important' ?>" aria-label="Open <?= h($r['name']) ?>">
        <?php if ($r['location']): ?><span class="rc-tag"><?= h($r['location']) ?></span><?php endif; ?>
      </a>
      <div class="rc-body">
        <h3><a href="<?= h($url) ?>"><?= h($r['name']) ?></a></h3>
        <p class="rc-desc"><?= h($r['tagline']) ?></p>
        <p class="rc-rating"><?= rating_line($allStats[(int)$r['resort_id']] ?? [], ru('reviews.php', $r['slug'])) ?></p>
        <p class="muted"><?= h(plural((int)$r['rooms'], 'room')) ?> · <?= h(plural((int)$r['buildings'], 'building')) ?> · <?= h(plural((int)$r['pools'], 'pool')) ?></p>
        <div class="rc-foot">
          <p class="price"><?php if ($r['from_rate'] !== null): ?>From <?= h(money($r['from_rate'])) ?> <small>per night</small><?php endif; ?></p>
          <a class="btn primary" href="<?= h($url) ?>">View resort</a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="block why">
  <h2 class="why-title">Book it your way</h2>
  <ul class="why-list">
    <li><h3>Rooms and cottages</h3><p>Choose the room type and how many rooms. We only show what is really free for your dates.</p></li>
    <li><h3>An entire pool</h3><p>Book a pool for a day with no other guests in it. Great for parties and family days.</p></li>
    <li><h3>An entire building</h3><p>Every room in a building for your group, from a wing of rooms to a row of cottages.</p></li>
    <li><h3>The entire resort</h3><p>All rooms and all pools, for your wedding, retreat or reunion. Nobody else is there.</p></li>
  </ul>
</section>
<?php site_end();
