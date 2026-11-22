<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Add-ons', 'singular' => 'Add-on', 'active' => 'services', 'scoped' => true, 'archive' => true,
    'table' => 'service', 'pk' => 'service_id', 'order' => 'category, service_name',
    'before_panel' => '<nav class="seg" aria-label="Pricing and add-ons"><a href="pricelist.php">Cottages &amp; Entrance</a><a href="services.php" aria-current="page">Add-ons</a></nav>',
    'fields' => [
        ['name' => 'service_name', 'label' => 'Name',       'type' => 'text',   'required' => true],
        ['name' => 'category',     'label' => 'Category',   'type' => 'text'],
        ['name' => 'unit_price',   'label' => 'Unit price', 'type' => 'number', 'required' => true, 'step' => '0.01'],
    ],
    'columns' => [
        ['label' => 'Name',       'key' => 'service_name'],
        ['label' => 'Category',   'key' => 'category'],
        ['label' => 'Unit price', 'key' => 'unit_price', 'format' => 'money'],
    ],
]);
