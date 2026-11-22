<?php
require __DIR__ . '/../includes/crud.php';

$seg = '<nav class="seg" aria-label="Buildings and rooms">'
     . '<a href="buildings.php">Buildings</a>'
     . '<a href="rooms.php">All rooms</a>'
     . '<a href="room_types.php" aria-current="page">Room types</a>'
     . '</nav>';

crud_page($pdo, [
    'title' => 'Room types', 'singular' => 'Room type', 'active' => 'room_types', 'scoped' => true, 'archive' => true, 'archive_col' => 't.archived_at',
    'table' => 'room_type', 'pk' => 'room_type_id', 'order' => 'base_rate',
    'before_panel' => $seg,
    'fields' => [
        ['name' => 'type_name',     'label' => 'Name',                'type' => 'text',   'required' => true],
        ['name' => 'max_occupancy', 'label' => 'Max guests',          'type' => 'number', 'required' => true, 'step' => '1', 'default' => 2],
        ['name' => 'base_rate',     'label' => 'Rate per night',      'type' => 'number', 'required' => true, 'step' => '0.01'],
        ['name' => 'description',   'label' => 'Short description',   'type' => 'textarea'],
        ['name' => 'details',       'label' => 'Full description',    'type' => 'textarea'],
    ],
    'list_sql' => 'SELECT t.*, (SELECT COUNT(*) FROM room r WHERE r.room_type_id = t.room_type_id AND r.archived_at IS NULL) AS rooms
                     FROM room_type t WHERE t.resort_id = :rid AND {archived} ORDER BY t.base_rate',
    'columns' => [
        ['label' => 'Name',           'key' => 'type_name'],
        ['label' => 'Rooms',          'key' => 'rooms'],
        ['label' => 'Max guests',     'key' => 'max_occupancy'],
        ['label' => 'Rate per night', 'key' => 'base_rate', 'format' => 'money'],
        ['label' => 'Short description', 'key' => 'description', 'muted' => true],
    ],
    'row_actions' => function (array $r): string {
        $tid = (int)$r['room_type_id'];
        $cnt = (int)($r['rooms'] ?? 0);
        return '<button type="button" class="btn sm" data-form-open="type-rooms-' . $tid . '">See rooms (' . $cnt . ')</button>'
             . '<a class="btn sm" href="photos.php">Photos</a>';
    },
    'after_panel' => function (array $rows) use ($pdo): string {
        if (!$rows) {
            return '';
        }
        $st = $pdo->prepare(
            'SELECT r.room_id, r.room_type_id, r.room_number, r.floor, r.status, b.name AS building_name
               FROM room r LEFT JOIN building b ON b.building_id = r.building_id
              WHERE r.resort_id = ? AND r.archived_at IS NULL
              ORDER BY b.name, r.floor, r.room_number'
        );
        $st->execute([resort_id()]);
        $byType = [];
        foreach ($st->fetchAll() as $rm) {
            $byType[(int)$rm['room_type_id']][] = $rm;
        }
        ob_start();
        foreach ($rows as $t):
            $tid   = (int)$t['room_type_id'];
            $rooms = $byType[$tid] ?? [];
            ?>
        <div class="modal" id="type-rooms-<?= $tid ?>" data-form-modal role="dialog" aria-modal="true" aria-labelledby="tr-t-<?= $tid ?>">
          <div class="modal-card form-card wide-card">
            <div class="modal-head">
              <h2 id="tr-t-<?= $tid ?>"><?= h($t['type_name']) ?> · <?= h(plural(count($rooms), 'room')) ?></h2>
              <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
            </div>
            <?php if (!$rooms): ?>
              <p class="muted">No rooms assigned to this type yet.</p>
            <?php else: ?>
            <div class="table-scroll">
              <table>
                <thead><tr><th>Room</th><th>Building</th><th class="num">Floor</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rooms as $rm): ?>
                  <tr>
                    <td class="strong"><?= h($rm['room_number']) ?></td>
                    <td><?= h($rm['building_name'] ?: '–') ?></td>
                    <td class="num"><?= (int)$rm['floor'] ?></td>
                    <td><?= badge($rm['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
            <div class="modal-actions" style="margin-top:16px">
              <a class="btn primary" href="rooms.php?type=<?= $tid ?>">Manage rooms →</a>
              <button class="btn" type="button" data-modal-close>Close</button>
            </div>
          </div>
        </div>
        <?php endforeach;
        return (string)ob_get_clean();
    },
]);
