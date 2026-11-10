<?php
require __DIR__ . '/../includes/crud.php';

// Super admin only: every sign-in on the platform. Leave "Resort" on "All resorts" to make another super admin.
crud_page($pdo, [
    'title' => 'Admin accounts', 'singular' => 'Account', 'active' => 'admins', 'platform_only' => true, 'protect_self' => true,
    'sub' => 'Every sign-in on the platform: super admins, resort admins and front-desk staff.',
    'table' => 'staff', 'pk' => 'staff_id', 'order' => 'full_name',
    'fields' => [
        ['name' => 'full_name',     'label' => 'Full name',  'type' => 'text',  'required' => true],
        ['name' => 'email',         'label' => 'Email (sign-in)', 'type' => 'email', 'required' => true],
        ['name' => 'resort_id',     'label' => 'Resort',     'type' => 'select', 'none_label' => 'All resorts (super admin)',
         'options_sql' => 'SELECT resort_id, name FROM resort ORDER BY name', 'hint' => 'Pick a resort to limit this person to it.'],
        ['name' => 'access',        'label' => 'Access',     'type' => 'select', 'required' => true,
         'options' => ['Admin' => 'Admin (runs the resort)', 'Staff' => 'Staff (desk work only)'], 'hint' => 'Ignored for a super admin, who can do everything.'],
        ['name' => 'role',          'label' => 'Job title',  'type' => 'text',  'required' => true],
        ['name' => 'password_hash', 'label' => 'Password',   'type' => 'password', 'hint' => 'At least 8 characters. Leave empty on edit to keep the current one.'],
    ],
    'list_sql' => "SELECT s.staff_id, s.full_name, s.email, COALESCE(r.name, 'All resorts') AS resort_name,
                          CASE WHEN s.resort_id IS NULL THEN 'Super admin' WHEN s.access = 'Admin' THEN 'Resort admin' ELSE 'Staff' END AS level,
                          (s.password_hash IS NOT NULL) AS can_login
                     FROM staff s LEFT JOIN resort r ON r.resort_id = s.resort_id
                    ORDER BY (s.resort_id IS NOT NULL), r.name, s.access, s.full_name",
    'columns' => [
        ['label' => 'Name',        'key' => 'full_name'],
        ['label' => 'Email',       'key' => 'email'],
        ['label' => 'Resort',      'key' => 'resort_name'],
        ['label' => 'Level',       'key' => 'level', 'format' => 'badge'],
        ['label' => 'Can sign in', 'key' => 'can_login', 'format' => 'yesno'],
    ],
]);
