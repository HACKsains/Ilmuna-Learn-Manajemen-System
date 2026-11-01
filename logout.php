<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — LOGOUT ACTION
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

logoutUser();

header("Location: " . BASE_URL . "/login.php?msg=logged_out");
exit;
