<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Price list', 'singular' => 'Price', 'active' => 'pricelist', 'scoped' => true, 'archive' => true,
    'table' => 'price_item', 'pk' => 'price_item_id', 'order' => 'category, sort_order, item_name',
    'before_panel' => '<nav class="seg" aria-label="Pricing and add-ons"><a href="pricelist.php" aria-current="page">Cottages &amp; Entrance</a><a href="services.php">Add-ons</a></nav>',
    'fields' => [
        ['name' => 'category',   'label' => 'Group',      'type' => 'select', 'required' => true, 'options' => ['Entrance fee', 'Cottage (day use)']],
        ['name' => 'item_name',  'label' => 'Name',       'type' => 'text',   'required' => true],
        ['name' => 'price',      'label' => 'Price',      'type' => 'number', 'required' => true, 'step' => '0.01'],
        ['name' => 'unit',       'label' => 'Per',        'type' => 'text',   'required' => true, 'default' => 'per cottage'],
        ['name' => 'max_guests', 'label' => 'Max guests (cottages)', 'type' => 'number', 'step' => '1', 'default' => 15],
        ['name' => 'quantity_available', 'label' => 'Units available (cottages)', 'type' => 'number', 'step' => '1', 'default' => 5],
        ['name' => 'note',       'label' => 'Note', 'type' => 'text'],
        ['name' => 'sort_order', 'label' => 'Order in list', 'type' => 'number', 'step' => '1', 'default' => 0],
    ],
    'columns' => [
        ['label' => 'Group', 'key' => 'category'],
        ['label' => 'Name',  'key' => 'item_name'],
        ['label' => 'Price', 'key' => 'price', 'format' => 'money'],
        ['label' => 'Per',   'key' => 'unit'],
        ['label' => 'Max guests', 'key' => 'max_guests'],
        ['label' => 'Units', 'key' => 'quantity_available'],
        ['label' => 'Note',  'key' => 'note', 'muted' => true],
    ],
]);
