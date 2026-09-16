<?php

require_once __DIR__ . '/../app/bootstrap.php';

if (function_exists('isAuthenticated') && isAuthenticated()) {
    redirect('dashboard.php');
}

redirect('login.php');