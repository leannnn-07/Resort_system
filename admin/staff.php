<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Staff', 'singular' => 'Staff member', 'active' => 'staff', 'scoped' => true, 'protect_self' => true, 'archive' => true, 'login_toggle' => true,
    'table' => 'staff', 'pk' => 'staff_id', 'order' => 'full_name',
    'fields' => [
        ['name' => 'full_name',     'label' => 'Full name', 'type' => 'text',     'required' => true, 'placeholder' => 'e.g. Dan Flores'],
        ['name' => 'role',          'label' => 'Job title', 'type' => 'text',     'required' => true, 'placeholder' => 'e.g. Front desk'],
        ['name' => 'access',        'label' => 'Access',    'type' => 'select',   'required' => true, 'options' => ['Staff' => 'Staff', 'Admin' => 'Resort admin']],
        ['name' => 'email',         'label' => 'Email (for logging in)', 'type' => 'email', 'required' => true, 'placeholder' => 'e.g. desk@yourresort.com'],
        ['name' => 'password_hash', 'label' => 'Password',  'type' => 'password'],
    ],
    'list_sql' => "SELECT staff_id, full_name, role, IF(access = 'Admin', 'Resort admin', 'Staff') AS access_label, email, login_enabled, (password_hash IS NOT NULL) AS has_password, archived_at
                     FROM staff WHERE resort_id = :rid AND {archived} ORDER BY full_name",
    'columns' => [
        ['label' => 'Name',      'key' => 'full_name'],
        ['label' => 'Job title', 'key' => 'role'],
        ['label' => 'Access',    'key' => 'access_label', 'format' => 'badge'],
        ['label' => 'Email',     'key' => 'email'],
        ['label' => 'Login',     'key' => 'login_enabled', 'format' => 'switch'],
    ],
]);
