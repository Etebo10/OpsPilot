<?php

require_once __DIR__ . '/../app/bootstrap.php';

logoutUser();

redirect('login.php');