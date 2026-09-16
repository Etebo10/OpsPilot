<?php

require_once __DIR__ . '/../../bootstrap.php';

$flash = get_flash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/phase4-invoices.css">
    <script>
        document.documentElement.dataset.theme = localStorage.getItem('opspilot-theme') || 'dark';
    </script>
</head>
<body>
