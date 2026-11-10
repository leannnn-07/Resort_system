<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Guests', 'singular' => 'Guest', 'active' => 'guests', 'level' => 'staff',
    // A resort sees only guests who booked with it (or that its own staff added).
    'scope_sql' => '(created_resort_id = {rid} OR EXISTS (SELECT 1 FROM reservation v WHERE v.guest_id = guest.guest_id AND v.resort_id = {rid}))',
    'insert_extra' => ['created_resort_id' => true],
    'table' => 'guest', 'pk' => 'guest_id', 'order' => 'last_name, first_name',
    'fields' => [
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text',  'required' => true],
        ['name' => 'last_name',  'label' => 'Last name',  'type' => 'text',  'required' => true],
        ['name' => 'email',      'label' => 'Email',      'type' => 'email', 'required' => true],
        ['name' => 'phone',      'label' => 'Phone',      'type' => 'text'],
        ['name' => 'address',    'label' => 'Address',    'type' => 'text'],
        ['name' => 'id_number',  'label' => 'ID number',  'type' => 'text'],
    ],
    'columns' => [
        ['label' => 'First name', 'key' => 'first_name'],
        ['label' => 'Last name',  'key' => 'last_name'],
        ['label' => 'Email',      'key' => 'email'],
        ['label' => 'Phone',      'key' => 'phone'],
        ['label' => 'ID number',  'key' => 'id_number', 'muted' => true],
        ['label' => 'Address',    'key' => 'address', 'muted' => true],
    ],
]);
