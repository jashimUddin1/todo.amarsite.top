<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

$desc = $_GET['desc'] ?? '';
if ($desc === '') {
    echo "No task selected";
    exit;
}

$stmt = $con->prepare("SELECT report_date,start_time,end_time,duration,remark
                       FROM daily_reports
                       WHERE description = ?
                       ORDER BY report_date DESC, start_time DESC");
$stmt->bind_param("s", $desc);
$stmt->execute();
$res = $stmt->get_result();
?>
<!doctype html>
<html lang='en' data-bs-theme='dark'>
<head>
<meta charset='utf-8'>
<title>Task Details – <?= htmlspecialchars($desc) ?></title>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='bg-dark text-light'>
<div class='container py-4'>

  <h3>Task Details: <?= htmlspecialchars($desc) ?></h3>
  <a class='btn btn-secondary my-2' href='dashboard.php'>&larr; Back to Dashboard</a>

  <table class='table table-dark table-bordered'>
    <thead>
      <tr>
        <th>Date</th>
        <th>Start</th>
        <th>End</th>
        <th>Duration</th>
        <th>Remark</th>
      </tr>
    </thead>
    <tbody>
      <?php while($r = $res->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($r['report_date']) ?></td>
          <td><?= htmlspecialchars($r['start_time']) ?></td>
          <td><?= htmlspecialchars($r['end_time']) ?></td>
          <td><?= htmlspecialchars($r['duration']) ?></td>
          <td><?= htmlspecialchars($r['remark']) ?></td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

</div>
</body>
</html>
