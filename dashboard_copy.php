<?php
// dashboard.php — Summary of work logs (daily_reports)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

function format_hours($minutes)
{
  if ($minutes === null) return "0 min";
  $minutes = (int)$minutes;
  if ($minutes <= 0) return "0 min";
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  if ($h && $m) return $h . " hr " . $m . " min";
  if ($h) return $h . " hr";
  return $m . " min";
}

$today = date('Y-m-d');

// ===== All descriptions for filter list =====
$allDescriptions = [];
$sqlDesc = "
  SELECT DISTINCT description
  FROM daily_reports
  WHERE description IS NOT NULL AND description <> ''
  ORDER BY description ASC
";
if ($res = $con->query($sqlDesc)) {
  while ($row = $res->fetch_assoc()) {
    $allDescriptions[] = $row['description'];
  }
  $res->free();
}

// ===== Read filter from GET =====
// Accept: ?desc[]=All  OR ?desc[]=Task1&desc[]=Task2 ...
$selected = isset($_GET['desc']) ? (array)$_GET['desc'] : ['All'];
$selected = array_values(array_filter(array_map('trim', $selected), fn($v) => $v !== ''));

$hasAll = in_array('All', $selected, true);
if (empty($selected)) {
  $hasAll = true;
  $selected = ['All'];
}

// Build filter SQL if not All
$filterSql = "";
$filterParams = [];
if (!$hasAll) {
  $validSelected = array_values(array_intersect($selected, $allDescriptions));
  if (empty($validSelected)) {
    $hasAll = true;
    $selected = ['All'];
  } else {
    $placeholders = implode(',', array_fill(0, count($validSelected), '?'));
    $filterSql = " AND description IN ($placeholders) ";
    $filterParams = $validSelected;
    $selected = $validSelected;
  }
}

// ===== Overall summary (filtered if applied) =====
$summary = [
  'total_entries' => 0,
  'total_days' => 0,
  'total_minutes' => 0,
];

$sqlSummary = "
  SELECT
    COUNT(*) AS total_entries,
    COUNT(DISTINCT report_date) AS total_days,
    COALESCE(SUM(CAST(SUBSTRING_INDEX(duration,' ',1) AS UNSIGNED)),0) AS total_minutes
  FROM daily_reports
  WHERE duration IS NOT NULL AND duration <> ''
";
if (!$hasAll) $sqlSummary .= $filterSql;

$stmt = $con->prepare($sqlSummary);
if ($stmt) {
  if (!$hasAll && !empty($filterParams)) {
    $types = str_repeat('s', count($filterParams));
    $stmt->bind_param($types, ...$filterParams);
  }
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $summary['total_entries'] = (int)$row['total_entries'];
      $summary['total_days']    = (int)$row['total_days'];
      $summary['total_minutes'] = (int)$row['total_minutes'];
    }
    $res->free();
  }
  $stmt->close();
}

// ===== Per-task summary (filtered if applied) =====
$tasks = [];

$sqlTasks = "
  SELECT
    description,
    COUNT(*) AS run_count,
    COUNT(DISTINCT report_date) AS days_used,
    MIN(report_date) AS start_date,
    MAX(report_date) AS end_date,
    COALESCE(SUM(CAST(SUBSTRING_INDEX(duration,' ',1) AS UNSIGNED)),0) AS total_minutes
  FROM daily_reports
  WHERE description IS NOT NULL AND description <> ''
    AND duration IS NOT NULL AND duration <> ''
";
if (!$hasAll) $sqlTasks .= $filterSql;

$sqlTasks .= "
  GROUP BY description
  ORDER BY total_minutes DESC, days_used DESC, description ASC
";

$stmt2 = $con->prepare($sqlTasks);
if ($stmt2) {
  if (!$hasAll && !empty($filterParams)) {
    $types = str_repeat('s', count($filterParams));
    $stmt2->bind_param($types, ...$filterParams);
  }
  if ($stmt2->execute()) {
    $res = $stmt2->get_result();
    while ($row = $res->fetch_assoc()) {
      $tasks[] = [
        'description'   => $row['description'],
        'run_count'     => (int)$row['run_count'],
        'days_used'     => (int)$row['days_used'],
        'start_date'    => $row['start_date'],
        'end_date'      => $row['end_date'],
        'total_minutes' => (int)$row['total_minutes'],
      ];
    }
    $res->free();
  }
  $stmt2->close();
}

$grandMinutes   = (int)$summary['total_minutes'];
$grandHoursText = format_hours($grandMinutes);
$allChecked = $hasAll;
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <title>Dashboard — Work Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#020617; color:#e5e7eb; min-height:100vh; }
    :root{
      --glass-bg: rgba(15, 23, 42, .85);
      --glass-brd: rgba(148, 163, 184, .35);
      --text-dim:#9ca3af;
    }
    .glass-card{
      background: var(--glass-bg);
      border-radius: 1rem;
      border: 1px solid var(--glass-brd);
      box-shadow: 0 18px 45px rgba(0,0,0,.65);
    }
    .text-dim{ color: var(--text-dim); }
    .navbar{
      position: sticky; top:0; z-index:1030;
      backdrop-filter: blur(10px);
      background: rgba(15, 23, 42, .92) !important;
      border-bottom: 1px solid rgba(148, 163, 184, .35);
    }
    a,.btn-link{ text-decoration:none; }
    table thead th{
      background: rgba(15, 23, 42, .95);
      border-bottom: 2px solid rgba(148, 163, 184, .5);
      white-space: nowrap;
    }
    .badge-soft{
      background: rgba(79, 70, 229, .15);
      color: #c7d2fe;
      border: 1px solid rgba(129, 140, 248, .5);
    }

    /* 3-dot button */
    .dot-btn{
      width: 42px; height: 36px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:10px;
    }

    /* modal + rows */
    .modal-content.glass-card{
      background: rgba(15, 23, 42, .92);
      border: 1px solid rgba(148, 163, 184, .35);
      box-shadow: 0 18px 45px rgba(0,0,0,.65);
    }
    .chk-row{
      display:flex;
      align-items:center;
      gap:.6rem;
      padding:.55rem .65rem;
      border-radius:.75rem;
      cursor:pointer;
      user-select:none;
    }
    .chk-row:hover{ background: rgba(148,163,184,.10); }
    .chk-row:active{ transform: translateY(0.5px); }

    .task-list{
      max-height: 50vh;
      overflow: auto;
      padding-right: .25rem;
    }
  </style>
</head>

<body>

<nav class="navbar navbar-expand-lg">
  <div class="container py-2 d-flex align-items-center justify-content-between">

    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="index.php">Daily Report</a>
      <a class="btn btn-sm btn-outline-light" href="todo_list.php">Todo List</a>
      <div class="vr d-none d-md-block"></div>

      <a class="navbar-brand fw-semibold text-white me-0" href="dashboard.php">Dashboard</a>
      <span class="badge bg-primary rounded-pill me-1">★</span>

      <?php if (!$hasAll && !empty($selected)): ?>
        <span class="badge bg-info text-dark ms-2">Filtered: <?= (int)count($selected) ?></span>
      <?php else: ?>
        <span class="badge bg-secondary ms-2">All</span>
      <?php endif; ?>
    </div>

    <div class="right">
      <button type="button"
              class="btn bg-white text-dark opacity-80 fw-bold dot-btn"
              data-bs-toggle="modal"
              data-bs-target="#taskFilterModal"
              title="Filter tasks">
        ⋮
      </button>
    </div>

  </div>
</nav>

<!-- Task Filter Modal -->
<div class="modal fade" id="taskFilterModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-md">
    <div class="modal-content glass-card">
      <div class="modal-header border-0">
        <div>
          <h5 class="modal-title mb-0">Filter by Task</h5>
          <div class="text-dim small">Apply to cards + table</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-0">
        <form method="GET" action="dashboard.php" id="filterForm">

          <!-- Search -->
          <div class="mb-3">
            <input type="text" id="taskSearch" class="form-control"
                   placeholder="Search task..." autocomplete="off">
            <small class="text-dim">Type to filter the list inside this modal.</small>
          </div>

          <!-- All row -->
          <div class="chk-row row-toggle" data-target="#chkAll">
            <input class="form-check-input" type="checkbox" value="All" id="chkAll" name="desc[]"
                   <?= $allChecked ? 'checked' : '' ?>>
            <label class="form-check-label flex-grow-1 ms-2" for="chkAll">All</label>
          </div>

          <hr class="my-2">

          <div class="task-list" id="taskList">
            <?php if (empty($allDescriptions)): ?>
              <div class="text-dim small">No tasks found yet.</div>
            <?php else: ?>
              <?php foreach ($allDescriptions as $d):
                $isChecked = $allChecked ? true : in_array($d, $selected, true);
                $id = 'task_' . md5($d);
              ?>
                <div class="chk-row row-toggle task-row"
                     data-target="#<?= $id ?>"
                     data-text="<?= htmlspecialchars(mb_strtolower($d)) ?>">
                  <input class="form-check-input chkTask" type="checkbox"
                         value="<?= htmlspecialchars($d) ?>"
                         id="<?= $id ?>"
                         name="desc[]"
                         <?= $isChecked ? 'checked' : '' ?>>
                  <label class="form-check-label flex-grow-1 ms-2" for="<?= $id ?>">
                    <?= htmlspecialchars($d) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="d-flex gap-2 mt-3">
            <a class="btn btn-sm btn-outline-light w-50" href="dashboard.php">Reset</a>
            <button class="btn btn-sm btn-primary w-50" type="submit">Apply</button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

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
          <h3 class="mb-0"><?= (int)$summary['total_days'] ?></h3>
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
          <small class="text-dim">Sum of completed entries (duration field)</small>
        </div>
      </div>

      <div class="col-md-4">
        <div class="glass-card p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 text-uppercase text-dim small">Total Entries</h6>
            <span class="badge badge-soft">Runs</span>
          </div>
          <h3 class="mb-0"><?= (int)$summary['total_entries'] ?></h3>
          <small class="text-dim">Number of times work has been logged</small>
        </div>
      </div>
    </div>

    <!-- Per task table -->
    <div class="glass-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Per Task Summary</h5>
        <small class="text-dim">Days, hours, runs + start/end</small>
      </div>

      <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">Task / Description</th>
              <th scope="col">Start Date</th>
              <th scope="col">End Date</th>
              <th scope="col">Days Used</th>
              <th scope="col">Total Time</th>
              <th scope="col">Total Runs</th>
              <th scope="col">Details</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($tasks)): ?>
            <tr>
              <td colspan="8" class="text-center text-dim py-4">
                No data found for this filter.
              </td>
            </tr>
          <?php else: ?>
            <?php
              $i = 1;
              foreach ($tasks as $t):
                $minutes = (int)$t['total_minutes'];
                $timeTxt = format_hours($minutes);

                $start = $t['start_date'] ? $t['start_date'] : '-';
                $endRaw = $t['end_date'] ? $t['end_date'] : '-';
                $isRunning = ($endRaw === $today);
            ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($t['description']) ?></td>
                <td><?= htmlspecialchars($start) ?></td>
                <td>
                  <?php if ($isRunning): ?>
                    <span class="badge bg-success">Running</span>
                  <?php else: ?>
                    <?= htmlspecialchars($endRaw) ?>
                  <?php endif; ?>
                </td>
                <td><?= (int)$t['days_used'] ?> day<?= ((int)$t['days_used'] !== 1 ? 's' : '') ?></td>
                <td><?= htmlspecialchars($timeTxt) ?></td>
                <td><?= (int)$t['run_count'] ?> time<?= ((int)$t['run_count'] !== 1 ? 's' : '') ?></td>
                <td>
                  <a class="btn btn-sm btn-info"
                     href="detail.php?desc=<?= urlencode($t['description']) ?>">
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

<script>
(function(){
  const chkAll = document.getElementById('chkAll');
  const taskChecks = Array.from(document.querySelectorAll('.chkTask'));
  const rowToggles = Array.from(document.querySelectorAll('.row-toggle'));
  const taskSearch = document.getElementById('taskSearch');
  const taskRows = Array.from(document.querySelectorAll('.task-row'));

  function getActiveRows(){
    // visible rows only
    return taskRows.filter(r => r.style.display !== 'none');
  }

  function getRelevantTaskChecks(){
    const q = (taskSearch?.value || '').trim();
    if (q) {
      // search active → only visible tasks
      const visibleIds = new Set(getActiveRows().map(r => (r.getAttribute('data-target') || '').replace('#','')));
      return taskChecks.filter(c => visibleIds.has(c.id));
    }
    // no search → all tasks
    return taskChecks;
  }

  function setChecks(list, state){
    list.forEach(c => c.checked = state);
  }

  function syncAllCheckbox(){
    const rel = getRelevantTaskChecks();
    if (!rel.length) {
      chkAll.checked = false;
      return;
    }
    chkAll.checked = rel.every(x => x.checked);
  }

  // ===== Row click toggles checkbox =====
  rowToggles.forEach(row => {
    row.addEventListener('click', (e) => {
      const tag = e.target?.tagName?.toUpperCase();
      if (tag === 'INPUT') return;
      if (tag === 'LABEL') e.preventDefault();

      const sel = row.getAttribute('data-target');
      if (!sel) return;

      const input = document.querySelector(sel);
      if (!input) return;

      input.checked = !input.checked;
      input.dispatchEvent(new Event('change', { bubbles:true }));
    });
  });

  // ✅ All checkbox: search থাকলে শুধু visible গুলো, না থাকলে সবগুলো
  chkAll?.addEventListener('change', function(){
    const rel = getRelevantTaskChecks();
    setChecks(rel, this.checked);
    syncAllCheckbox();
  });

  // Task change: search active হলে visible set অনুযায়ী All sync হবে
  taskChecks.forEach(c => {
    c.addEventListener('change', function(){
      syncAllCheckbox();
    });
  });

  // ===== Search inside modal =====
  taskSearch?.addEventListener('input', function(){
    const q = (this.value || '').toLowerCase().trim();
    taskRows.forEach(r => {
      const t = (r.getAttribute('data-text') || '');
      r.style.display = (!q || t.includes(q)) ? '' : 'none';
    });

    // search বদলালে All checkbox-ও relevant সেট অনুযায়ী sync
    syncAllCheckbox();
  });

  // initial sync
  // (যদি server থেকে All selected আসে, search empty থাকলে সব checked থাকবে)
  syncAllCheckbox();
})();
</script>

</body>
</html>
