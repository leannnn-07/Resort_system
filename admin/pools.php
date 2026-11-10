<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Pools', 'singular' => 'Pool', 'active' => 'pools', 'scoped' => true,
    'table' => 'pool', 'pk' => 'pool_id', 'order' => 'name',
    'fields' => [
        ['name' => 'name',         'label' => 'Name',        'type' => 'text',   'required' => true],
        ['name' => 'max_guests',   'label' => 'Max guests',  'type' => 'number', 'required' => true, 'step' => '1', 'default' => 20, 'hint' => 'Most people allowed in the pool at once.'],
        ['name' => 'rate_per_day', 'label' => 'Whole-pool price per day', 'type' => 'number', 'step' => '0.01', 'hint' => 'Leave empty if guests can’t book the whole pool.'],
        ['name' => 'description',  'label' => 'Description', 'type' => 'textarea'],
    ],
    'columns' => [
        ['label' => 'Name',       'key' => 'name'],
        ['label' => 'Max guests', 'key' => 'max_guests'],
        ['label' => 'Whole pool per day', 'key' => 'rate_per_day', 'format' => 'money'],
        ['label' => 'Description', 'key' => 'description', 'muted' => true],
    ],
]);
