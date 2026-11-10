<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Buildings', 'singular' => 'Building', 'active' => 'buildings', 'scoped' => true,
    'table' => 'building', 'pk' => 'building_id', 'order' => 'name',
    'fields' => [
        ['name' => 'name',           'label' => 'Name',        'type' => 'text',   'required' => true],
        ['name' => 'exclusive_rate', 'label' => 'Whole-building price per night', 'type' => 'number', 'step' => '0.01', 'hint' => 'Leave empty to charge the total of its room rates.'],
        ['name' => 'description',    'label' => 'Description', 'type' => 'textarea'],
    ],
    'list_sql' => 'SELECT b.*, (SELECT COUNT(*) FROM room r WHERE r.building_id = b.building_id) AS rooms
                     FROM building b WHERE b.resort_id = :rid ORDER BY b.name',
    'columns' => [
        ['label' => 'Name',  'key' => 'name'],
        ['label' => 'Rooms', 'key' => 'rooms'],
        ['label' => 'Whole building per night', 'key' => 'exclusive_rate', 'format' => 'money'],
        ['label' => 'Description', 'key' => 'description', 'muted' => true],
    ],
]);
