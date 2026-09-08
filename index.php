<?php
/** Entry point: sends each visitor to their role's home screen. */
require_once __DIR__ . '/core/config.php';
redirect(is_logged_in() ? home_for_role(user_role()) : 'public/login.php');
