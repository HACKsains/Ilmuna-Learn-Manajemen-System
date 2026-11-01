<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_username'] = 'admin';
$_SESSION['active_role'] = 'admin';
$_SESSION['user_roles'] = ['admin'];

$_GET['action'] = 'export_excel';
$_GET['search'] = '';
$_GET['f_tahun'] = '';

require_once __DIR__ . '/dashboard/admin/classes-excel.php';
