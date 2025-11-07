<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/auth_bootstrap.php";
/* Explicitly set/refresh a guest id and owner_key */
unset($_SESSION['user_id']);
$_SESSION['guest_id'] = bin2hex(random_bytes(8));
$_SESSION['owner_key'] = "g:" . $_SESSION['guest_id'];
$_SESSION['flash'] = ['text'=>'You are continuing as Guest (ID: '.$_SESSION['guest_id'].')','type'=>'info'];
header("Location: ../index.php"); exit;
