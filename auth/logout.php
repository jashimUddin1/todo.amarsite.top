<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/auth_bootstrap.php";
unset($_SESSION['user_id']);
/* Keep guest session so user can still work */
if (empty($_SESSION['guest_id'])) {
  $_SESSION['guest_id'] = bin2hex(random_bytes(8));
}
$_SESSION['owner_key'] = "g:" . $_SESSION['guest_id'];
$_SESSION['flash'] = ['text'=>'Logged out. You are now a Guest.','type'=>'secondary'];
header("Location: ../index.php"); exit;
