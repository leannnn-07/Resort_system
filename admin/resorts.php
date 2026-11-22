<?php
require __DIR__ . '/../includes/resort_admin.php';

$staffMember = require_login($pdo, true);   // super admin only

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $rid    = (int)($_POST['resort_id'] ?? 0);
    if ($action === 'delete' && $rid > 0) {
        $st = $pdo->prepare('UPDATE resort SET archived_at = NOW(), is_active = 0 WHERE resort_id = ? AND archived_at IS NULL');
        $st->execute([$rid]);
        if ($st->rowCount() > 0) {
            if ((int)($_SESSION['admin_resort'] ?? 0) === $rid) {
                $nextId = (int)$pdo->query('SELECT resort_id FROM resort WHERE archived_at IS NULL ORDER BY sort_order, resort_id LIMIT 1')->fetchColumn();
                if ($nextId > 0) {
                    $_SESSION['admin_resort'] = $nextId;
                } else {
                    unset($_SESSION['admin_resort']);
                }
            }
            flash('Resort deleted and moved to All Records → Archived. You can restore it anytime.');
            redirect('archive.php?type=resorts');
        }
        flash('That resort was already archived or no longer exists.', 'error');
    }
    redirect('resorts.php');
}

// Search (name, address, location, tagline) and sort (default order, A to Z, Z to A, best or lowest rated).
$q = trim((string)($_GET['q'] ?? ''));
$sorts = ['default' => 'Default order', 'name' => 'Name, A to Z', 'name_desc' => 'Name, Z to A', 'rating' => 'Rating, high to low', 'rating_asc' => 'Rating, low to high'];
$sort = array_key_exists((string)($_GET['sort'] ?? ''), $sorts) ? (string)$_GET['sort'] : 'default';
$order = [
    'default'    => 'r.sort_order, r.name',
    'name'       => 'r.name',
    'name_desc'  => 'r.name DESC',
    // Resorts without reviews always come last, whichever way the ratings are sorted.
    'rating'     => 'rating IS NULL, rating DESC, reviews DESC, r.name',
    'rating_asc' => 'rating IS NULL, rating ASC, r.name',
][$sort];
$where = 'WHERE r.archived_at IS NULL' . ($q !== '' ? ' AND (r.name LIKE :q1 OR r.slug LIKE :q2 OR r.location LIKE :q3 OR r.tagline LIKE :q4)' : '');
$stmt = $pdo->prepare(
    "SELECT r.*,
            (SELECT ROUND(AVG(w.rating), 1) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published') AS rating,
            (SELECT COUNT(*) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published') AS reviews,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id AND x.archived_at IS NULL) AS rooms,
            (SELECT COUNT(*) FROM pool p WHERE p.resort_id = r.resort_id AND p.archived_at IS NULL) AS pools,
            (SELECT COUNT(*) FROM building b WHERE b.resort_id = r.resort_id AND b.archived_at IS NULL) AS buildings,
            (SELECT COUNT(*) FROM room_type t WHERE t.resort_id = r.resort_id AND t.archived_at IS NULL) AS types,
            (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id AND v.status NOT IN ('Cancelled','Checked-out')) AS open_bookings
       FROM resort r $where ORDER BY $order"
);
$like = '%' . addcslashes($q, '%_\\') . '%';
$stmt->execute($q !== '' ? ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like] : []);
$rows = $stmt->fetchAll();

admin_start('All resorts', 'resorts');
?>
<?php admin_head('All resorts', $q !== '' ? plural(count($rows), 'resort') . ' match' . (count($rows) === 1 ? 'es' : '') : '', '<a class="btn primary" href="resort_new.php">Add a resort</a>'); ?>

<form method="get" class="filters" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search resorts by name or location" aria-label="Search resorts" maxlength="80" autocomplete="off">
  <select name="sort" aria-label="Sort resorts" onchange="this.form.submit()">
    <?php foreach ($sorts as $k => $label): ?><option value="<?= h($k) ?>"<?= $k === $sort ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $sort !== 'default'): ?><a class="btn" href="resorts.php">Reset</a><?php endif; ?>
</form>

<section class="panel flush">
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Resort</th>
          <th class="num" title="Average of public guest reviews">Rating</th>
          <th>Size</th>
          <th class="num" title="Pending, confirmed and checked-in bookings">Bookings</th>
          <th>Status</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="empty"><?= $q !== '' ? 'No resorts match “' . h($q) . '”.' : 'No resorts yet. <a href="resort_new.php">Add the first resort</a>.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="col-primary">
            <div class="res-cell">
              <?= admin_thumb_html($r['hero_image'] ?? null, (string)$r['name']) ?>
              <div class="res-cell-body">
                <strong><a href="index.php?resort=<?= (int)$r['resort_id'] ?>"><?= h($r['name']) ?></a></strong>
                <small class="muted-line" title="<?= h((string)$r['location']) ?>"><?= h(address_short((string)$r['location']) ?: (string)$r['location']) ?> · <span class="res-slug"><?= h($r['slug']) ?></span></small>
              </div>
            </div>
          </td>
          <td class="num"><?= $r['rating'] !== null ? '<strong>' . h($r['rating']) . '</strong> <small class="muted">(' . (int)$r['reviews'] . ')</small>' : '<span class="muted">–</span>' ?></td>
          <td class="col-meta"><strong><?= h(plural((int)$r['rooms'], 'room')) ?></strong><span class="muted-line"><?= h(plural((int)$r['buildings'], 'building')) ?> · <?= h(plural((int)$r['pools'], 'pool')) ?></span></td>
          <td class="num"><?= (int)$r['open_bookings'] ?></td>
          <td class="nowrap"><?= badge($r['is_active'] ? 'Open' : 'Hidden') ?></td>
          <td class="row-actions">
            <div class="act-group">
              <?php if (!$r['is_active'] && (int)$r['rooms'] === 0): ?>
                <a class="btn sm primary" href="resort_new.php?id=<?= (int)$r['resort_id'] ?>&amp;step=<?= (!$r['contact_email'] && !$r['contact_phone']) ? 2 : ((int)$r['buildings'] === 0 ? 3 : ((int)$r['types'] === 0 ? 4 : 5)) ?>">Continue setup</a>
              <?php endif; ?>
              <a class="btn sm" href="resort_new.php?id=<?= (int)$r['resort_id'] ?>&amp;step=1" title="Name, tagline, colour theme and background photo">Edit</a>
              <a class="btn sm" href="index.php?resort=<?= (int)$r['resort_id'] ?>">Manage</a>
              <a class="btn sm" href="../landing.php?r=<?= h(rawurlencode($r['slug'])) ?>" target="_blank" rel="noopener">View site</a>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="resort_id" value="<?= (int)$r['resort_id'] ?>">
                <button class="btn sm danger" type="submit"
                        data-confirm="Delete <?= h($r['name']) ?>? It will be hidden from guests and moved to All Records → Archived, where you can restore it anytime. Existing bookings are kept."
                        data-confirm-title="Delete resort"
                        data-confirm-ok="Yes, delete"
                        data-confirm-tone="danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php admin_end();
