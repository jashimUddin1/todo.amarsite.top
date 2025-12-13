<?php
// dashboard.php — Summary of work logs (daily_reports)
if (session_status() === PHP_SESSION_NONE)
  session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

/**
 * durationMinutesFromString
 * "120 min" -> 120
 * "0 min"   -> 0
 * null/empty/invalid -> 0
 */
function durationMinutesFromString($str)
{
  if (!$str)
    return 0;
  // expect like "123 min"
  $parts = explode(' ', trim($str));
  if (!isset($parts[0]))
    return 0;
  $num = (int) $parts[0];
  return $num > 0 ? $num : 0;
}

/**
 * format_hours
 * 130 -> "2 hr 10 min"
 */
function format_hours($minutes)
{
  if ($minutes === null)
    return "0 min";
  $minutes = (int) $minutes;
  if ($minutes <= 0)
    return "0 min";
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  if ($h && $m)
    return $h . " hr " . $m . " min";
  if ($h)
    return $h . " hr";
  return $m . " min";
}

// ===== Overall summary (all tasks) =====
$summary = [
  'total_entries' => 0,
  'total_days' => 0,
  'total_minutes' => 0,
];

// এখানে duration কলাম থেকেই মিনিট sum করছি
$sqlSummary = "
  SELECT
    COUNT(*) AS total_entries,
    COUNT(DISTINCT report_date) AS total_days,
    COALESCE(
      SUM(
        CAST(SUBSTRING_INDEX(duration,' ',1) AS UNSIGNED)
      ),
      0
    ) AS total_minutes
  FROM daily_reports
  WHERE duration IS NOT NULL
    AND duration <> ''
";

if ($res = $con->query($sqlSummary)) {
  $row = $res->fetch_assoc();
  if ($row) {
    $summary['total_entries'] = (int) $row['total_entries'];
    $summary['total_days'] = (int) $row['total_days'];
    $summary['total_minutes'] = (int) $row['total_minutes'];
  }
  $res->free();
}

// ===== Per-task summary (প্রতিটি কাজ অনুযায়ী) =====
$tasks = [];

$sqlTasks = "
  SELECT
    description,
    COUNT(*) AS run_count,
    COUNT(DISTINCT report_date) AS days_used,
    COALESCE(
      SUM(
        CAST(SUBSTRING_INDEX(duration,' ',1) AS UNSIGNED)
      ),
      0
    ) AS total_minutes
  FROM daily_reports
  WHERE description IS NOT NULL
    AND description <> ''
    AND duration IS NOT NULL
    AND duration <> ''
  GROUP BY description
  ORDER BY total_minutes DESC, days_used DESC, description ASC
";

if ($res = $con->query($sqlTasks)) {
  while ($row = $res->fetch_assoc()) {
    $tasks[] = [
      'description' => $row['description'],
      'run_count' => (int) $row['run_count'],
      'days_used' => (int) $row['days_used'],
      'total_minutes' => (int) $row['total_minutes'],
    ];
  }
  $res->free();
}

// For card
$grandMinutes = (int) $summary['total_minutes'];
$grandHoursText = format_hours($grandMinutes);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <title>Dashboard — Work Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body {
      background: #020617;
      color: #e5e7eb;
      min-height: 100vh;
    }

    :root {
      --glass-bg: rgba(15, 23, 42, .85);
      --glass-brd: rgba(148, 163, 184, .35);
      --text-dim: #9ca3af;
    }

    .glass-card {
      background: var(--glass-bg);
      border-radius: 1rem;
      border: 1px solid var(--glass-brd);
      box-shadow: 0 18px 45px rgba(0, 0, 0, .65);
    }

    .text-dim {
      color: var(--text-dim);
    }

    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
      backdrop-filter: blur(10px);
      background: rgba(15, 23, 42, .92) !important;
      border-bottom: 1px solid rgba(148, 163, 184, .35);
    }

    a,
    .btn-link {
      text-decoration: none;
    }

    table thead th {
      background: rgba(15, 23, 42, .95);
      border-bottom: 2px solid rgba(148, 163, 184, .5);
    }

    .badge-soft {
      background: rgba(79, 70, 229, .15);
      color: #c7d2fe;
      border: 1px solid rgba(129, 140, 248, .5);
    }
  </style>
</head>

<body>

  <nav class="navbar navbar-expand-lg">
    <div class="container py-2">
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-light" href="index.php">Daily Report</a>
        <a class="btn btn-sm btn-outline-light" href="todo_list.php">Todo List</a>
        <div class="vr d-none d-md-block"></div>

        <a class="navbar-brand fw-semibold text-white me-0" href="dashboard.php">Dashboard</a>
          <span class="badge bg-primary rounded-pill me-1">★</span>
      </div>
    </div>
  </nav>

  <section class="py-4">
    <div class="container">

      <!-- Top summary cards -->
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="glass-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0 text-uppercase text-dim small">Total Active Days</h6>
              <span class="badge badge-soft">Days</span>
            </div>
            <h3 class="mb-0"><?= (int) $summary['total_days'] ?></h3>
            <small class="text-dim">Unique dates with at least one work log</small>
          </div>
        </div>

        <div class="col-md-4">
          <div class="glass-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0 text-uppercase text-dim small">Total Logged Time</h6>
              <span class="badge badge-soft">Hours</span>
            </div>
            <h3 class="mb-0"><?= htmlspecialchars($grandHoursText) ?></h3>
            <small class="text-dim">Sum of all completed entries (duration field)</small>
          </div>
        </div>

        <div class="col-md-4">
          <div class="glass-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0 text-uppercase text-dim small">Total Entries</h6>
              <span class="badge badge-soft">Runs</span>
            </div>
            <h3 class="mb-0"><?= (int) $summary['total_entries'] ?></h3>
            <small class="text-dim">Number of times work has been logged</small>
          </div>
        </div>
      </div>

      <!-- Per task table -->
      <div class="glass-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Per Task Summary</h5>
          <small class="text-dim">Which task ran how many days, hours and times</small>
        </div>
        <div class="table-responsive">
          <table class="table table-dark table-hover align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">Task / Description</th>
                <th scope="col">Days Used</th>
                <th scope="col">Total Time</th>
                <th scope="col">Total Runs</th>
                <th scope="col">Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($tasks)): ?>
                <tr>
                  <td colspan="5" class="text-center text-dim py-4">
                    No data yet. Start logging work from Daily Report or via Todo List O.T.
                  </td>
                </tr>
              <?php else: ?>
                <?php $i = 1;
                foreach ($tasks as $t):
                  $minutes = (int) $t['total_minutes'];
                  $timeTxt = format_hours($minutes);
                  ?>
                  <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($t['description']) ?></td>
                    <td><?= (int) $t['days_used'] ?> day<?= ((int) $t['days_used'] !== 1 ? 's' : '') ?></td>
                    <td><?= htmlspecialchars($timeTxt) ?></td>
                    <td><?= (int) $t['run_count'] ?> time<?= ((int) $t['run_count'] !== 1 ? 's' : '') ?></td>
                    <td>
                      <a class="btn btn-sm btn-info" href="detail.php?desc=<?= urlencode($t['description']) ?>">
                        Details
                      </a>
                    </td>

                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </section>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>