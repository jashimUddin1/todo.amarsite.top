<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit("Method Not Allowed"); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { exit("Invalid request"); }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  $_SESSION['flash'] = ['text'=>'Invalid id','type'=>'warning'];
  header("Location: index.php"); exit;
}

/* আমরা delete করার আগে যে রো-র date লাগাতে পারি, যাতে delete-এর পরও user একই মাসে থাকে */
$sel = $con->prepare("SELECT report_date FROM daily_reports WHERE id=?");
$sel->bind_param("i", $id);
$sel->execute();
$rr = $sel->get_result()->fetch_assoc();
$sel->close();

$stmt = $con->prepare("DELETE FROM daily_reports WHERE id=?");
$stmt->bind_param("i", $id);
$ok = $stmt->execute();

$_SESSION['flash'] = $ok
  ? ['text'=>'Deleted','type'=>'success']
  : ['text'=>'Delete failed','type'=>'danger'];

$redirY = $rr ? date('Y', strtotime($rr['report_date'])) : date('Y');
$redirM = $rr ? date('n', strtotime($rr['report_date'])) : date('n');

header("Location: index.php?year=".$redirY."&month=".$redirM);
exit;
