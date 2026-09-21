<?php
/** Ends the platform owner's session (a restaurant login in the same browser is kept). */
require_once __DIR__ . '/../core/config.php';
unset($_SESSION['platform']);
session_regenerate_id(true);
redirect('platform/login.php');
