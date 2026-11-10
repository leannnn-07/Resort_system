<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Rooms', 'singular' => 'Room', 'active' => 'rooms', 'scoped' => true, 'level' => 'staff', 'staff_edit' => ['status'],
    'table' => 'room', 'pk' => 'room_id', 'order' => 'room_number',
    'fields' => [
        ['name' => 'room_number',  'label' => 'Room number', 'type' => 'text',   'required' => true],
        ['name' => 'room_type_id', 'label' => 'Room type',   'type' => 'select', 'required' => true,
         'options_sql' => 'SELECT room_type_id, type_name FROM room_type WHERE resort_id = :rid ORDER BY base_rate'],
        ['name' => 'building_id',  'label' => 'Building',    'type' => 'select', 'required' => true,
         'options_sql' => 'SELECT building_id, name FROM building WHERE resort_id = :rid ORDER BY name'],
        ['name' => 'floor',        'label' => 'Floor',       'type' => 'number', 'required' => true, 'step' => '1', 'default' => 1],
        ['name' => 'status',       'label' => 'Status',      'type' => 'select', 'required' => true,
         'options' => ['Available', 'Cleaning', 'Maintenance']],
    ],
    'list_sql' => 'SELECT r.*, t.type_name, t.base_rate, b.name AS building_name
                     FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id
                     LEFT JOIN building b ON b.building_id = r.building_id
                    WHERE r.resort_id = :rid
                    ORDER BY b.name, r.floor, r.room_number',
    'columns' => [
        ['label' => 'Room',     'key' => 'room_number'],
        ['label' => 'Type',     'key' => 'type_name'],
        ['label' => 'Building', 'key' => 'building_name'],
        ['label' => 'Floor',    'key' => 'floor'],
        ['label' => 'Rate per night', 'key' => 'base_rate', 'format' => 'money'],
        ['label' => 'Status',   'key' => 'status', 'format' => 'badge'],
    ],
]);
