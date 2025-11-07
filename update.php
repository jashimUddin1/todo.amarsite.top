<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit("Method Not Allowed"); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { exit("Invalid request"); }

$id          = (int)($_POST['id'] ?? 0);
$report_date = $_POST['report_date'] ?? '';
$description = trim($_POST['description'] ?? '');
$start_time  = trim($_POST['start_time'] ?? '');
$end_time    = trim($_POST['end_time'] ?? '');
$remark      = trim($_POST['remark'] ?? '');
$updated_at  = date("Y-m-d H:i:s");

if ($id <= 0 || !$report_date || $description === '') {
  $_SESSION['flash'] = ['text'=>'Missing required fields','type'=>'warning'];
  header("Location: index.php"); exit;
}

$year     = (int) date('Y', strtotime($report_date));
$month    = date('F', strtotime($report_date));
$day_name = date('l', strtotime($report_date));

$duration = null;
if ($start_time && $end_time) {
  $s = strtotime($start_time); $e = strtotime($end_time);
  if ($s !== false && $e !== false && $e > $s) $duration = (int)(($e-$s)/60) . " min";
}

$stmt = $con->prepare("UPDATE daily_reports
  SET report_date=?,year=?,month=?,day_name=?,description=?,start_time=?,end_time=?,duration=?,remark=?,updated_at=?
  WHERE id=?");
$stmt->bind_param("sissssssssi",
  $report_date,$year,$month,$day_name,$description,$start_time,$end_time,$duration,$remark,$updated_at,$id);

if ($stmt->execute()) {
  $_SESSION['flash'] = ['text'=>'Updated successfully','type'=>'success'];
} else {
  $_SESSION['flash'] = ['text'=>'Update failed','type'=>'danger'];
}
header("Location: index.php?year=".urlencode(date('Y', strtotime($report_date)))."&month=".urlencode(date('n', strtotime($report_date))));
exit;
