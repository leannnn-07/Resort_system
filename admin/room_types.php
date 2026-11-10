<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Room types', 'singular' => 'Room type', 'active' => 'room_types', 'scoped' => true,
    'table' => 'room_type', 'pk' => 'room_type_id', 'order' => 'base_rate',
    'fields' => [
        ['name' => 'type_name',     'label' => 'Name',                'type' => 'text',   'required' => true],
        ['name' => 'max_occupancy', 'label' => 'Max guests',          'type' => 'number', 'required' => true, 'step' => '1', 'default' => 2],
        ['name' => 'base_rate',     'label' => 'Rate per night',      'type' => 'number', 'required' => true, 'step' => '0.01'],
        ['name' => 'description',   'label' => 'Short description',   'type' => 'textarea'],
        ['name' => 'details',       'label' => 'Full description (shown on the room page)', 'type' => 'textarea'],
    ],
    'columns' => [
        ['label' => 'Name',           'key' => 'type_name'],
        ['label' => 'Max guests',     'key' => 'max_occupancy'],
        ['label' => 'Rate per night', 'key' => 'base_rate', 'format' => 'money'],
        ['label' => 'Short description', 'key' => 'description', 'muted' => true],
    ],
]);
