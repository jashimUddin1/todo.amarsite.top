<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../db/dbcon.php";

/* Ensure users table exists */
$con->query("CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

/* Ensure owner_key column exists on daily_reports and todos */
$con->query("ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS owner_key VARCHAR(64) NULL");
$con->query("ALTER TABLE todos ADD COLUMN IF NOT EXISTS owner_key VARCHAR(64) NULL");

/* Helper to get or create guest id */
function ensure_guest_owner_key() {
  if (!isset($_SESSION['guest_id']) || !preg_match('/^[a-f0-9-]{8,}$/', $_SESSION['guest_id'])) {
    $_SESSION['guest_id'] = bin2hex(random_bytes(8));
  }
  $_SESSION['owner_key'] = "g:" . $_SESSION['guest_id'];
}

/* If logged in, owner_key = u:{id}, else guest */
if (!empty($_SESSION['user_id'])) {
  $_SESSION['owner_key'] = "u:" . (int)$_SESSION['user_id'];
} else {
  ensure_guest_owner_key();
}

/* Convenience accessors */
function current_owner_key(mysqli $con) {
  return $_SESSION['owner_key'] ?? null;
}
function is_logged_in() {
  return !empty($_SESSION['user_id']);
}
function current_user(mysqli $con) {
  if (!is_logged_in()) return null;
  $uid = (int)$_SESSION['user_id'];
  $stmt = $con->prepare("SELECT id,name,email FROM users WHERE id=?");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  return $stmt->get_result()->fetch_assoc();
}
?>