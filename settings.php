<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { exit('Invalid request'); }

/* Checkbox unchecked হলে POST আসে না → default 0 */
$_SESSION['allow_edit']   = isset($_POST['allow_edit'])   ? 1 : 0;
$_SESSION['allow_delete'] = isset($_POST['allow_delete']) ? 1 : 0;

/* Session flash */
$_SESSION['flash'] = ['text'=>'Settings saved','type'=>'success'];
header("Location: index.php");
exit;
