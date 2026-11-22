<?php
require __DIR__ . '/../includes/crud.php';

$filterBldg = (int)($_GET['building'] ?? 0);
$filterType = (int)($_GET['type'] ?? 0);

$seg = '<nav class="seg" aria-label="Buildings and rooms">'
     . '<a href="buildings.php">Buildings</a>'
     . '<a href="rooms.php" aria-current="page">All rooms</a>'
     . '<a href="room_types.php">Room types</a>'
     . '</nav>';

crud_page($pdo, [
    'title' => 'Rooms', 'singular' => 'Room', 'active' => 'rooms', 'scoped' => true, 'level' => 'staff', 'staff_edit' => ['status'], 'archive' => true, 'archive_col' => 'r.archived_at',
    'table' => 'room', 'pk' => 'room_id', 'order' => 'room_number',
    'before_panel' => fn(array $s): string => (staff_can($s, 'admin') ? $seg : '') . admin_quick_book_bar(),
    'head_actions' => ($filterBldg > 0 || $filterType > 0) ? '<a class="btn" href="rooms.php">Show all rooms</a>' : '',
    'fields' => [
        ['name' => 'room_number',  'label' => 'Room number', 'type' => 'text',   'required' => true],
        ['name' => 'room_type_id', 'label' => 'Room type',   'type' => 'select', 'required' => true,
         'options_sql' => 'SELECT room_type_id, type_name FROM room_type WHERE resort_id = :rid AND archived_at IS NULL ORDER BY base_rate'],
        ['name' => 'building_id',  'label' => 'Building',    'type' => 'select', 'required' => true,
         'options_sql' => 'SELECT building_id, name FROM building WHERE resort_id = :rid AND archived_at IS NULL ORDER BY name'],
        ['name' => 'floor',        'label' => 'Floor',       'type' => 'number', 'required' => true, 'step' => '1', 'default' => 1],
        ['name' => 'status',       'label' => 'Status',      'type' => 'select', 'required' => true,
         'options' => ['Available', 'Cleaning', 'Maintenance']],
    ],
    'list_sql' => 'SELECT r.*, t.type_name, t.base_rate, b.name AS building_name
                     FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id
                     LEFT JOIN building b ON b.building_id = r.building_id
                    WHERE r.resort_id = :rid AND {archived}
                    ORDER BY b.name, r.floor, r.room_number',
    'filter_rows' => function (array $rows) use ($filterBldg, $filterType): array {
        if ($filterBldg > 0) {
            $rows = array_filter($rows, fn($r) => (int)($r['building_id'] ?? 0) === $filterBldg);
        }
        if ($filterType > 0) {
            $rows = array_filter($rows, fn($r) => (int)($r['room_type_id'] ?? 0) === $filterType);
        }
        return array_values($rows);
    },
    'columns' => [
        ['label' => 'Room',     'key' => 'room_number'],
        ['label' => 'Type',     'key' => 'type_name'],
        ['label' => 'Building', 'key' => 'building_name'],
        ['label' => 'Floor',    'key' => 'floor'],
        ['label' => 'Rate per night', 'key' => 'base_rate', 'format' => 'money'],
        ['label' => 'Status',   'key' => 'status', 'format' => 'badge'],
    ],
]);
