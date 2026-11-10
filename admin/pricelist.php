<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Price list', 'singular' => 'Price', 'active' => 'pricelist', 'scoped' => true,
    'table' => 'price_item', 'pk' => 'price_item_id', 'order' => 'category, sort_order, item_name',
    'fields' => [
        ['name' => 'category',   'label' => 'Group',      'type' => 'select', 'required' => true, 'options' => ['Entrance fee', 'Cottage (day use)']],
        ['name' => 'item_name',  'label' => 'Name',       'type' => 'text',   'required' => true],
        ['name' => 'price',      'label' => 'Price',      'type' => 'number', 'required' => true, 'step' => '0.01'],
        ['name' => 'unit',       'label' => 'Per',        'type' => 'text',   'required' => true, 'default' => 'per person'],
        ['name' => 'note',       'label' => 'Note (shown under the name)', 'type' => 'text'],
        ['name' => 'sort_order', 'label' => 'Order in list', 'type' => 'number', 'step' => '1', 'default' => 0],
    ],
    'columns' => [
        ['label' => 'Group', 'key' => 'category'],
        ['label' => 'Name',  'key' => 'item_name'],
        ['label' => 'Price', 'key' => 'price', 'format' => 'money'],
        ['label' => 'Per',   'key' => 'unit'],
        ['label' => 'Note',  'key' => 'note', 'muted' => true],
    ],
]);
