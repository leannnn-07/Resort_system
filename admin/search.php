<?php
// Super admin: one search box across the whole platform. Each result opens the page where it can be edited.
require __DIR__ . '/../includes/layout.php';

$staffMember = require_login($pdo, true);
$q = trim((string)($_GET['q'] ?? ''));
$like = '%' . addcslashes($q, '%_\\') . '%';

/** Groups: label, SQL (every ? is the search text; the first column is the title, the second the detail), and where a row leads. */
$LIMIT = 8;
$groups = [
    'Resorts' => [
        "SELECT r.resort_id AS id, r.name AS title, CONCAT_WS(' · ', r.location, IF(r.is_active, 'Open', 'Hidden')) AS detail, r.resort_id AS rid
           FROM resort r WHERE r.name LIKE ? OR r.slug LIKE ? OR r.location LIKE ? OR r.tagline LIKE ? ORDER BY r.name",
        4, fn($r) => 'resort_new.php?id=' . $r['id'] . '&step=1', 'resorts.php?q=',
    ],
    'Staff & admins' => [
        "SELECT s.staff_id AS id, s.full_name AS title,
                CONCAT_WS(' · ', s.email, s.role, COALESCE(r.name, 'All resorts'), IF(s.archived_at IS NOT NULL, 'Archived', IF(s.login_enabled, NULL, 'Login off'))) AS detail, s.archived_at
           FROM staff s LEFT JOIN resort r ON r.resort_id = s.resort_id
          WHERE s.full_name LIKE ? OR s.email LIKE ? OR s.role LIKE ? ORDER BY s.full_name",
        3, fn($r) => $r['archived_at'] ? 'archive.php?type=staff&q=' . urlencode($r['title']) : 'accounts.php?edit=' . $r['id'], 'accounts.php?q=',
    ],
    'Users (guest accounts)' => [
        "SELECT g.guest_id AS id, CONCAT(g.first_name, ' ', g.last_name) AS title,
                CONCAT_WS(' · ', g.email, g.phone, IF(g.archived_at IS NOT NULL, 'Archived', IF(g.login_enabled, NULL, 'Login off'))) AS detail, g.archived_at
           FROM guest g WHERE g.password_hash IS NOT NULL AND (CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR g.email LIKE ? OR g.phone LIKE ?) ORDER BY g.first_name, g.last_name",
        3, fn($r) => ($r['archived_at'] ? 'archive.php?type=guests&' : 'users.php?') . 'q=' . urlencode($r['detail'] ? explode(' · ', $r['detail'])[0] : $r['title']), 'users.php?q=',
    ],
    'Guests' => [
        "SELECT g.guest_id AS id, CONCAT(g.first_name, ' ', g.last_name) AS title, CONCAT_WS(' · ', g.email, g.phone, IF(g.archived_at IS NOT NULL, 'Archived', NULL)) AS detail, g.archived_at,
                COALESCE(g.created_resort_id, (SELECT v.resort_id FROM reservation v WHERE v.guest_id = g.guest_id ORDER BY v.reservation_id DESC LIMIT 1)) AS rid
           FROM guest g WHERE CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR g.email LIKE ? OR g.phone LIKE ? ORDER BY g.first_name, g.last_name",
        3, fn($r) => $r['archived_at'] ? 'archive.php?type=guests&q=' . urlencode(explode(' · ', $r['detail'])[0]) : ($r['rid'] ? 'guests.php?resort=' . $r['rid'] . '&edit=' . $r['id'] : ''), '',
    ],
    'Reservations' => [
        "SELECT v.reservation_id AS id, CONCAT(v.booking_ref, ' · ', g.first_name, ' ', g.last_name) AS title,
                CONCAT_WS(' · ', r.name, v.status, v.check_in_date) AS detail, v.resort_id AS rid
           FROM reservation v JOIN guest g ON g.guest_id = v.guest_id JOIN resort r ON r.resort_id = v.resort_id
          WHERE v.booking_ref LIKE ? OR CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR g.email LIKE ? ORDER BY v.reservation_id DESC",
        3, fn($r) => 'reservation_view.php?resort=' . $r['rid'] . '&id=' . $r['id'], '',
    ],
    'Rooms' => [
        "SELECT x.room_id AS id, CONCAT('Room ', x.room_number) AS title, CONCAT_WS(' · ', r.name, t.type_name, b.name) AS detail, x.resort_id AS rid
           FROM room x JOIN resort r ON r.resort_id = x.resort_id LEFT JOIN room_type t ON t.room_type_id = x.room_type_id LEFT JOIN building b ON b.building_id = x.building_id
          WHERE x.room_number LIKE ? OR t.type_name LIKE ? OR b.name LIKE ? ORDER BY r.name, x.room_number",
        3, fn($r) => 'rooms.php?resort=' . $r['rid'] . '&edit=' . $r['id'], '',
    ],
    'Room types' => [
        "SELECT t.room_type_id AS id, t.type_name AS title, r.name AS detail, t.resort_id AS rid FROM room_type t JOIN resort r ON r.resort_id = t.resort_id
          WHERE t.type_name LIKE ? OR t.description LIKE ? ORDER BY r.name, t.type_name",
        2, fn($r) => 'room_types.php?resort=' . $r['rid'] . '&edit=' . $r['id'], '',
    ],
    'Buildings' => [
        "SELECT b.building_id AS id, b.name AS title, r.name AS detail, b.resort_id AS rid FROM building b JOIN resort r ON r.resort_id = b.resort_id
          WHERE b.name LIKE ? OR b.description LIKE ? ORDER BY r.name, b.name",
        2, fn($r) => 'buildings.php?resort=' . $r['rid'] . '&edit=' . $r['id'], '',
    ],
    'Pools' => [
        "SELECT p.pool_id AS id, p.name AS title, r.name AS detail, p.resort_id AS rid FROM pool p JOIN resort r ON r.resort_id = p.resort_id
          WHERE p.name LIKE ? OR p.description LIKE ? ORDER BY r.name, p.name",
        2, fn($r) => 'pools.php?resort=' . $r['rid'] . '&edit=' . $r['id'], '',
    ],
    'Add-ons' => [
        "SELECT s.service_id AS id, s.service_name AS title, CONCAT_WS(' · ', r.name, s.category) AS detail, s.resort_id AS rid FROM service s JOIN resort r ON r.resort_id = s.resort_id
          WHERE s.service_name LIKE ? OR s.category LIKE ? ORDER BY r.name, s.service_name",
        2, fn($r) => 'services.php?resort=' . $r['rid'] . '&edit=' . $r['id'], '',
    ],
    'Price list' => [
        "SELECT i.price_item_id AS id, i.item_name AS title, CONCAT_WS(' · ', r.name, i.category) AS detail, i.resort_id AS rid FROM price_item i JOIN resort r ON r.resort_id = i.resort_id
          WHERE i.item_name LIKE ? OR i.category LIKE ? OR i.note LIKE ? ORDER BY r.name, i.item_name",
        3, fn($r) => 'pricelist.php?resort=' . $r['rid'] . '&edit=' . $r['id'], '',
    ],
];

$results = [];
$total = 0;
if ($q !== '') {
    foreach ($groups as $label => [$sql, $n, $link, $all]) {
        $stmt = $pdo->prepare($sql . " LIMIT $LIMIT");
        $stmt->execute(array_fill(0, $n, $like));
        $rows = $stmt->fetchAll();
        if ($rows) {
            $results[$label] = array_map(fn($r) => ['title' => $r['title'], 'detail' => (string)($r['detail'] ?? ''), 'href' => $link($r)], $rows);
            $total += count($rows);
        }
    }
}

admin_start('Search', 'search');
admin_head('Search');
?>
<form method="get" class="filters" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search names, emails, booking references, room numbers…" aria-label="Search everything" maxlength="80" autocomplete="off" autofocus>
  <button class="btn primary" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="search.php">Clear</a><?php endif; ?>
</form>

<?php if ($q === ''): ?>
  <p class="empty">Type something to search every resort at once. Each result opens where you can edit it.</p>
<?php elseif (!$results): ?>
  <p class="empty">Nothing matches “<?= h($q) ?>”.</p>
<?php else: ?>
  <?php foreach ($results as $label => $items): ?>
  <section class="panel flush" style="margin-bottom:14px">
    <div class="panel-head"><h2><?= h($label) ?> <small class="muted">(<?= count($items) ?><?= count($items) >= $LIMIT ? '+' : '' ?>)</small></h2>
      <?php if (($groups[$label][3] ?? '') !== ''): ?><a class="btn sm" href="<?= h($groups[$label][3] . urlencode($q)) ?>">See all</a><?php endif; ?></div>
    <ul class="search-results">
      <?php foreach ($items as $it): ?>
        <li>
          <?php if ($it['href'] !== ''): ?><a href="<?= h($it['href']) ?>"><strong><?= h($it['title']) ?></strong><?php else: ?><span><strong><?= h($it['title']) ?></strong><?php endif; ?>
          <small class="muted-line"><?= h($it['detail']) ?></small>
          <?= $it['href'] !== '' ? '</a>' : '</span>' ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
<?php admin_end();
