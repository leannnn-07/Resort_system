<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Pools', 'singular' => 'Pool', 'active' => 'pools', 'scoped' => true, 'archive' => true,
    'table' => 'pool', 'pk' => 'pool_id', 'order' => 'name',
    'fields' => [
        ['name' => 'name',         'label' => 'Name',        'type' => 'text',   'required' => true, 'placeholder' => 'e.g. Family Pool'],
        ['name' => 'max_guests',   'label' => 'Max guests',  'type' => 'number', 'required' => true, 'step' => '1', 'default' => 20],
        ['name' => 'rate_per_day', 'label' => 'Whole-pool price per day', 'type' => 'number', 'step' => '0.01', 'placeholder' => 'Optional, e.g. 8000'],
        ['name' => 'description',  'label' => 'Description', 'type' => 'textarea', 'placeholder' => 'e.g. Heated outdoor pool, 1.2 m deep'],
        ['name' => 'photo',        'label' => 'Pool photo',  'type' => 'image',  'dir' => 'pools'],
    ],
    'columns' => [
        ['label' => 'Photo',      'key' => 'photo', 'format' => 'image'],
        ['label' => 'Name',       'key' => 'name'],
        ['label' => 'Max guests', 'key' => 'max_guests'],
        ['label' => 'Whole pool per day', 'key' => 'rate_per_day', 'format' => 'money'],
        ['label' => 'Description', 'key' => 'description', 'muted' => true],
    ],
]);
