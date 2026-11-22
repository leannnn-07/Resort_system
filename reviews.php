<?php
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reviews.php';
require_resort($pdo);

$RID   = resort_id();
$stats = review_stats($pdo, $RID);
$per   = 10;
$page  = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($stats['count'] / $per));
$page  = min($page, $pages);
$filter = (string)($_GET['stars'] ?? '');
$filter = ctype_digit($filter) && (int)$filter >= 1 && (int)$filter <= 5 ? (int)$filter : 0;
$total  = $filter ? $stats['dist'][$filter] : $stats['count'];
$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);

$sql = "SELECT * FROM review WHERE resort_id = ? AND status = 'Published'" . ($filter ? ' AND rating = ' . $filter : '') . ' ORDER BY created_at DESC, review_id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
$stmt = $pdo->prepare($sql);
$stmt->execute([$RID]);
$reviews = $stmt->fetchAll();

site_start('Guest reviews', 'reviews');
?>
<div class="page-head"><h1>Guest reviews</h1></div>

<?php if ($stats['count'] === 0): ?>
  <p class="empty">No reviews yet. Guests can review their stay after they check out.</p>
<?php else: ?>
<section class="panel rating-summary">
  <div class="rs-score"><span class="rs-num"><?= h(number_format($stats['avg'], 1)) ?></span><?= stars($stats['avg']) ?><span class="muted"><?= h(plural($stats['count'], 'review')) ?></span></div>
  <ul class="rs-bars">
    <?php for ($i = 5; $i >= 1; $i--): $n = $stats['dist'][$i]; $pc = $stats['count'] ? round($n / $stats['count'] * 100) : 0; ?>
      <li>
        <a href="<?= h(ru('reviews.php?stars=' . $i)) ?>" aria-label="<?= $i ?> stars, <?= $n ?> reviews"<?= $filter === $i ? ' aria-current="true"' : '' ?>><?= $i ?> ★</a>
        <span class="rs-track"><i style="width:<?= $pc ?>%"></i></span><span class="muted"><?= $n ?></span>
      </li>
    <?php endfor; ?>
  </ul>
</section>

<?php if ($filter): ?><p class="muted">Showing <?= $filter ?>-star reviews. <a href="<?= h(ru('reviews.php')) ?>">Show all</a></p><?php endif; ?>
<div class="review-list">
  <?php $media = review_media_map($pdo, $reviews); foreach ($reviews as $r) { echo review_card($r, $media[(int)$r['review_id']] ?? []); } ?>
</div>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Review pages">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a class="btn sm<?= $i === $page ? ' primary' : '' ?>" href="<?= h(ru('reviews.php?' . http_build_query(array_filter(['stars' => $filter, 'page' => $i > 1 ? $i : null])))) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php site_end();
