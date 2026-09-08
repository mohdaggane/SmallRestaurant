<?php
/** Ends the session and returns to the sign-in screen. */
require_once __DIR__ . '/../core/config.php';
logout_user();
redirect('public/login.php');
