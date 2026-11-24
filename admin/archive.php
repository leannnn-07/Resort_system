<?php
// Everything that has been deleted/archived (resorts, accounts, services, cottages/prices, buildings, room types, rooms, pools), with one-click Restore.
require __DIR__ . '/../includes/layout.php';

$staffMember = require_login($pdo, false, 'admin');
$self = 'archive.php';
$isSuper = staff_can($staffMember, 'super');
$RID = $resort ? (int)$resort['resort_id'] : 0;

// Category definitions: Super admin sees everything platform-wide; resort admins see their own resort's archived items.
$types = [];
if ($isSuper) {
    $types['resorts'] = [
        'label'  => 'Resorts',
        'badge'  => 'Resort',
        'one'    => 'resort',
        'table'  => 'resort',
        'pk'     => 'resort_id',
        'scope'  => '1=1',
        'select' => "t.resort_id AS id, t.name AS name,
                     CONCAT_WS(' · ', NULLIF(t.location, ''), t.slug) AS detail,
                     t.name AS resort_name, t.archived_at",
        'from'   => 'resort t',
        'search' => 't.name LIKE ? OR t.slug LIKE ? OR t.location LIKE ?',
        'n'      => 3,
    ];
}
$types['staff'] = [
    'label'  => 'Staff & admins',
    'badge'  => 'Staff',
    'one'    => 'account',
    'table'  => 'staff',
    'pk'     => 'staff_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.staff_id AS id, t.full_name AS name,
                 CONCAT_WS(' · ', t.email, IF(t.resort_id IS NULL, 'Super admin', IF(t.access = 'Admin', 'Resort admin', 'Staff')), NULLIF(t.role, '')) AS detail,
                 COALESCE(r.name, 'All resorts') AS resort_name, t.archived_at",
    'from'   => 'staff t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.full_name LIKE ? OR t.email LIKE ? OR t.role LIKE ?',
    'n'      => 3,
];
$types['guests'] = [
    'label'  => 'Guests & users',
    'badge'  => 'Guest',
    'one'    => 'guest',
    'table'  => 'guest',
    'pk'     => 'guest_id',
    'scope'  => $isSuper ? '1=1' : "(t.created_resort_id = $RID OR EXISTS (SELECT 1 FROM reservation v WHERE v.guest_id = t.guest_id AND v.resort_id = $RID))",
    'select' => "t.guest_id AS id, CONCAT(t.last_name, ', ', t.first_name) AS name,
                 CONCAT_WS(' · ', NULLIF(t.email, ''), NULLIF(t.phone, ''), IF(t.password_hash IS NOT NULL, 'Has login', 'No login')) AS detail,
                 COALESCE(r.name, 'Platform') AS resort_name, t.archived_at",
    'from'   => 'guest t LEFT JOIN resort r ON r.resort_id = t.created_resort_id',
    'search' => "CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR t.email LIKE ? OR t.phone LIKE ?",
    'n'      => 3,
];
$types['services'] = [
    'label'  => 'Services & add-ons',
    'badge'  => 'Service',
    'one'    => 'service',
    'table'  => 'service',
    'pk'     => 'service_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.service_id AS id, t.service_name AS name,
                 CONCAT_WS(' · ', NULLIF(t.category, ''), CONCAT('₱', FORMAT(t.unit_price, 2))) AS detail,
                 COALESCE(r.name, '') AS resort_name, t.archived_at",
    'from'   => 'service t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.service_name LIKE ? OR t.category LIKE ?',
    'n'      => 2,
];
$types['prices'] = [
    'label'  => 'Cottages & price list',
    'badge'  => 'Price item',
    'one'    => 'price item',
    'table'  => 'price_item',
    'pk'     => 'price_item_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.price_item_id AS id, t.item_name AS name,
                 CONCAT_WS(' · ', NULLIF(t.category, ''), CONCAT('₱', FORMAT(t.price, 2), ' ', t.unit)) AS detail,
                 COALESCE(r.name, '') AS resort_name, t.archived_at",
    'from'   => 'price_item t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.item_name LIKE ? OR t.category LIKE ? OR t.note LIKE ?',
    'n'      => 3,
];
$types['discounts'] = [
    'label'  => 'Special discounts',
    'badge'  => 'Discount',
    'one'    => 'special discount',
    'table'  => 'resort_discount',
    'pk'     => 'discount_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.discount_id AS id, t.title AS name,
                 CONCAT_WS(' · ', CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM t.percent_off)), '% off'), NULLIF(t.note, '')) AS detail,
                 COALESCE(r.name, 'All resorts') AS resort_name, t.archived_at",
    'from'   => 'resort_discount t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.title LIKE ? OR t.note LIKE ?',
    'n'      => 2,
];
$types['buildings'] = [
    'label'  => 'Buildings',
    'badge'  => 'Building',
    'one'    => 'building',
    'table'  => 'building',
    'pk'     => 'building_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.building_id AS id, t.name AS name,
                 COALESCE(NULLIF(t.description, ''), IF(t.exclusive_rate IS NOT NULL, CONCAT('₱', FORMAT(t.exclusive_rate, 2), '/night'), '')) AS detail,
                 COALESCE(r.name, '') AS resort_name, t.archived_at",
    'from'   => 'building t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.name LIKE ? OR t.description LIKE ?',
    'n'      => 2,
];
$types['room_types'] = [
    'label'  => 'Room types',
    'badge'  => 'Room type',
    'one'    => 'room type',
    'table'  => 'room_type',
    'pk'     => 'room_type_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.room_type_id AS id, t.type_name AS name,
                 CONCAT('Up to ', t.max_occupancy, ' guests · ₱', FORMAT(t.base_rate, 2), '/night') AS detail,
                 COALESCE(r.name, '') AS resort_name, t.archived_at",
    'from'   => 'room_type t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.type_name LIKE ? OR t.description LIKE ?',
    'n'      => 2,
];
$types['rooms'] = [
    'label'  => 'Rooms',
    'badge'  => 'Room',
    'one'    => 'room',
    'table'  => 'room',
    'pk'     => 'room_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.room_id AS id, CONCAT('Room ', t.room_number) AS name,
                 CONCAT_WS(' · ', rt.type_name, b.name, CONCAT('Floor ', t.floor)) AS detail,
                 COALESCE(r.name, '') AS resort_name, t.archived_at",
    'from'   => 'room t LEFT JOIN resort r ON r.resort_id = t.resort_id LEFT JOIN room_type rt ON rt.room_type_id = t.room_type_id LEFT JOIN building b ON b.building_id = t.building_id',
    'search' => 't.room_number LIKE ? OR rt.type_name LIKE ? OR b.name LIKE ?',
    'n'      => 3,
];
$types['pools'] = [
    'label'  => 'Pools',
    'badge'  => 'Pool',
    'one'    => 'pool',
    'table'  => 'pool',
    'pk'     => 'pool_id',
    'scope'  => $isSuper ? '1=1' : "t.resort_id = $RID",
    'select' => "t.pool_id AS id, t.name AS name,
                 CONCAT_WS(' · ', CONCAT('Up to ', t.max_guests, ' guests'), IF(t.rate_per_day IS NOT NULL, CONCAT('₱', FORMAT(t.rate_per_day, 2), '/day'), NULL)) AS detail,
                 COALESCE(r.name, '') AS resort_name, t.archived_at",
    'from'   => 'pool t LEFT JOIN resort r ON r.resort_id = t.resort_id',
    'search' => 't.name LIKE ? OR t.description LIKE ?',
    'n'      => 2,
];

$reqType = (string)($_GET['type'] ?? 'all');
$type    = ($reqType === 'all' || array_key_exists($reqType, $types)) ? $reqType : 'all';
$q       = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pt = (string)($_POST['type'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $retType = (string)($_POST['ret_type'] ?? $type);
    if (!array_key_exists($retType, $types)) {
        $retType = 'all';
    }
    if (array_key_exists($pt, $types) && ($_POST['action'] ?? '') === 'restore' && $id > 0) {
        $t = $types[$pt];
        if ($pt === 'resorts') {
            $hasRooms = $pdo->prepare('SELECT COUNT(*) FROM room WHERE resort_id = ? AND archived_at IS NULL');
            $hasRooms->execute([$id]);
            $openFlag = ((int)$hasRooms->fetchColumn() > 0) ? 1 : 0;
            $st = $pdo->prepare("UPDATE resort t SET t.archived_at = NULL, t.is_active = ? WHERE t.resort_id = ? AND t.archived_at IS NOT NULL");
            $st->execute([$openFlag, $id]);
        } else {
            $st = $pdo->prepare("UPDATE {$t['table']} t SET t.archived_at = NULL WHERE t.{$t['pk']} = ? AND t.archived_at IS NOT NULL AND {$t['scope']}");
            $st->execute([$id]);
        }
        flash($st->rowCount() ? ucfirst($t['one']) . ' restored.' : 'That ' . $t['one'] . ' is no longer in the archive.', $st->rowCount() ? 'ok' : 'error');
    }
    redirect($self . '?type=' . urlencode($retType) . ($q !== '' ? '&q=' . urlencode($q) : ''));
}

// Live counts per category + total
$counts = [];
$totalArchived = 0;
foreach ($types as $k => $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM {$t['from']} WHERE t.archived_at IS NOT NULL AND {$t['scope']}");
    $counts[$k] = (int)$c->fetchColumn();
    $totalArchived += $counts[$k];
}

// Fetch matching archived rows (either one selected category or all categories merged newest-first)
$selectedKeys = $type === 'all' ? array_keys($types) : [$type];
$rows = [];
$like = '%' . addcslashes($q, '%_\\') . '%';
foreach ($selectedKeys as $k) {
    $t = $types[$k];
    $sql = "SELECT {$t['select']} FROM {$t['from']} WHERE t.archived_at IS NOT NULL AND {$t['scope']}";
    $params = [];
    if ($q !== '') {
        $sql .= " AND ({$t['search']})";
        $params = array_fill(0, $t['n'], $like);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY t.archived_at DESC LIMIT 150');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $r['_type']  = $k;
        $r['_badge'] = $t['badge'];
        $rows[] = $r;
    }
}
usort($rows, fn($a, $b) => strcmp((string)$b['archived_at'], (string)$a['archived_at']));

admin_start('Archived', 'archive');
admin_head('Archived', $q !== '' ? plural(count($rows), 'archived record') . ' match' . (count($rows) === 1 ? 'es' : '') : '');
?>

<form method="get" class="filters" role="search">
  <select name="type" aria-label="Record category" onchange="this.form.submit()">
    <option value="all"<?= $type === 'all' ? ' selected' : '' ?>>All archived (<?= $totalArchived ?>)</option>
    <?php foreach ($types as $k => $tt): ?>
      <option value="<?= h($k) ?>"<?= $k === $type ? ' selected' : '' ?>><?= h($tt['label']) ?> (<?= $counts[$k] ?>)</option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search archived records" aria-label="Search archived records" maxlength="80" autocomplete="off">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $type !== 'all'): ?><a class="btn" href="<?= h($self) ?>">Reset</a><?php endif; ?>
</form>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty"><?= $q !== '' ? 'No archived records match “' . h($q) . '”.' : 'Nothing archived here yet.' ?></p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Type</th>
          <th>Name</th>
          <th>Details</th>
          <?php if ($isSuper): ?><th>Resort</th><?php endif; ?>
          <th>Archived on</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><span class="badge no"><?= h($r['_badge']) ?></span></td>
          <td class="col-primary"><strong><?= h((string)$r['name']) ?></strong></td>
          <td class="sub"><?= trim((string)($r['detail'] ?? '')) !== '' ? h((string)$r['detail']) : '<span class="muted">–</span>' ?></td>
          <?php if ($isSuper): ?><td class="nowrap"><?= h((string)($r['resort_name'] ?? '')) !== '' ? h((string)$r['resort_name']) : '<span class="muted">–</span>' ?></td><?php endif; ?>
          <td class="nowrap sub"><?= h(fdate(substr((string)$r['archived_at'], 0, 10))) ?></td>
          <td class="row-actions">
            <div class="act-group">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="type" value="<?= h($r['_type']) ?>">
                <input type="hidden" name="ret_type" value="<?= h($type) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn sm primary" type="submit">Restore</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php admin_end();
