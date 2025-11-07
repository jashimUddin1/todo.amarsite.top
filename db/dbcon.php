<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "todo_list";

$con = new mysqli($host, $user, $pass, $db);
if ($con->connect_error) {
  die("DB Connection failed: " . $con->connect_error);
}
$con->set_charset("utf8mb4");
