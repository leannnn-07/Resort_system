<?php
require __DIR__ . '/../includes/crud.php';

$seg = '<nav class="seg" aria-label="Buildings and rooms">'
     . '<a href="buildings.php" aria-current="page">Buildings</a>'
     . '<a href="rooms.php">All rooms</a>'
     . '<a href="room_types.php">Room types</a>'
     . '</nav>';

crud_page($pdo, [
    'title' => 'Buildings', 'singular' => 'Building', 'active' => 'buildings', 'scoped' => true, 'archive' => true, 'archive_col' => 'b.archived_at',
    'table' => 'building', 'pk' => 'building_id', 'order' => 'name',
    'before_panel' => $seg . admin_quick_book_bar(),
    'fields' => [
        ['name' => 'name',           'label' => 'Name',        'type' => 'text',   'required' => true, 'placeholder' => 'e.g. Lakeside Wing'],
        ['name' => 'exclusive_rate', 'label' => 'Whole-building price per night', 'type' => 'number', 'step' => '0.01', 'placeholder' => 'Optional, e.g. 12000'],
        ['name' => 'description',    'label' => 'Description', 'type' => 'textarea', 'placeholder' => 'e.g. Two-storey wing facing the lake'],
        ['name' => 'photo',          'label' => 'Building photo', 'type' => 'image', 'dir' => 'buildings'],
    ],
    'list_sql' => 'SELECT b.*, (SELECT COUNT(*) FROM room r WHERE r.building_id = b.building_id AND r.archived_at IS NULL) AS rooms
                     FROM building b WHERE b.resort_id = :rid AND {archived} ORDER BY b.name',
    'columns' => [
        ['label' => 'Photo', 'key' => 'photo', 'format' => 'image'],
        ['label' => 'Name',  'key' => 'name'],
        ['label' => 'Rooms', 'key' => 'rooms'],
        ['label' => 'Whole building per night', 'key' => 'exclusive_rate', 'format' => 'money'],
        ['label' => 'Description', 'key' => 'description', 'muted' => true],
    ],
    'row_actions' => function (array $r): string {
        $bid = (int)$r['building_id'];
        $cnt = (int)($r['rooms'] ?? 0);
        return '<button type="button" class="btn sm" data-form-open="bldg-rooms-' . $bid . '">See rooms (' . $cnt . ')</button>';
    },
    'after_panel' => function (array $rows) use ($pdo): string {
        if (!$rows) {
            return '';
        }
        $st = $pdo->prepare(
            'SELECT r.room_id, r.building_id, r.room_number, r.floor, r.status, t.type_name, t.base_rate
               FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id
              WHERE r.resort_id = ? AND r.archived_at IS NULL
              ORDER BY r.floor, r.room_number'
        );
        $st->execute([resort_id()]);
        $byBldg = [];
        foreach ($st->fetchAll() as $rm) {
            $byBldg[(int)$rm['building_id']][] = $rm;
        }
        ob_start();
        foreach ($rows as $b):
            $bid   = (int)$b['building_id'];
            $rooms = $byBldg[$bid] ?? [];
            ?>
        <div class="modal" id="bldg-rooms-<?= $bid ?>" data-form-modal role="dialog" aria-modal="true" aria-labelledby="br-t-<?= $bid ?>">
          <div class="modal-card form-card wide-card">
            <div class="modal-head">
              <h2 id="br-t-<?= $bid ?>"><?= h($b['name']) ?> · <?= h(plural(count($rooms), 'room')) ?></h2>
              <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
            </div>
            <?php if (!$rooms): ?>
              <p class="muted">No rooms in this building yet.</p>
            <?php else: ?>
            <div class="table-scroll">
              <table>
                <thead><tr><th>Room</th><th>Type</th><th class="num">Floor</th><th class="num">Rate / night</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rooms as $rm): ?>
                  <tr>
                    <td class="strong"><?= h($rm['room_number']) ?></td>
                    <td><?= h($rm['type_name']) ?></td>
                    <td class="num"><?= (int)$rm['floor'] ?></td>
                    <td class="num"><?= h(money($rm['base_rate'])) ?></td>
                    <td><?= badge($rm['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
            <div class="modal-actions" style="margin-top:16px">
              <a class="btn primary" href="rooms.php?building=<?= $bid ?>">Manage rooms →</a>
              <button class="btn" type="button" data-modal-close>Close</button>
            </div>
          </div>
        </div>
        <?php endforeach;
        return (string)ob_get_clean();
    },
]);
