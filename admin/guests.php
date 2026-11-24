<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Guests', 'singular' => 'Guest', 'active' => 'guests', 'level' => 'staff',
    // A resort sees only guests who booked with it (or that its own staff added).
    'scope_sql' => '(created_resort_id = {rid} OR EXISTS (SELECT 1 FROM reservation v WHERE v.guest_id = guest.guest_id AND v.resort_id = {rid}))',
    'insert_extra' => ['created_resort_id' => true],
    'table' => 'guest', 'pk' => 'guest_id', 'order' => 'last_name, first_name',
    // Guests with bookings can't be deleted, so "Delete" archives them (Archive page); their bookings stay as they were.
    'archive' => true,
    'fields' => [
        ['name' => 'last_name',   'label' => 'Last name',   'type' => 'text',  'required' => true],
        ['name' => 'first_name',  'label' => 'First name',  'type' => 'text',  'required' => true],
        ['name' => 'middle_name', 'label' => 'Middle name', 'type' => 'text',  'placeholder' => 'Optional'],
        ['name' => 'email',       'label' => 'Email',       'type' => 'email', 'required' => true],
        ['name' => 'phone',       'label' => 'Phone',       'type' => 'text',  'placeholder' => 'e.g. 0917 123 4567'],
    ],
    'columns' => [
        ['label' => 'Last name',   'key' => 'last_name'],
        ['label' => 'First name',  'key' => 'first_name'],
        ['label' => 'Middle name', 'key' => 'middle_name'],
        ['label' => 'Email',       'key' => 'email'],
        ['label' => 'Phone',       'key' => 'phone'],
    ],
]);
