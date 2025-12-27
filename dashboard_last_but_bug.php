<?php
// dashboard.php — Summary of work logs (daily_reports)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

function format_hours($minutes)
{
  $minutes = (int)$minutes;
  if ($minutes <= 0) return "0 min";
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  if ($h && $m) return $h . " hr " . $m . " min";
  if ($h) return $h . " hr";
  return $m . " min";
}

$today = date('Y-m-d');

// ===== ISR filter (All/Running/Ended) =====
$isr = $_GET['isr'] ?? 'all';
$isr = in_array($isr, ['all', 'running', 'ended'], true) ? $isr : 'all';

$isrSql = "";
if ($isr === 'running') {
  $isrSql = " AND report_date = CURDATE() ";
} elseif ($isr === 'ended') {
  $isrSql = " AND report_date < CURDATE() ";
}

// ===== Task lists for modal (ALL + RUNNING + ENDED) =====
function fetchDistinctDescriptions(mysqli $con, string $whereSql): array {
  $list = [];
  $sql = "
    SELECT DISTINCT description
    FROM daily_reports
    WHERE description IS NOT NULL AND description <> ''
    $whereSql
    ORDER BY description ASC
  ";
  if ($res = $con->query($sql)) {
    while ($row = $res->fetch_assoc()) $list[] = $row['description'];
    $res->free();
  }
  return $list;
}

$descAll = fetchDistinctDescriptions($con, "");
$descRunning = fetchDistinctDescriptions($con, " AND report_date = CURDATE() ");
$descEnded = fetchDistinctDescriptions($con, " AND report_date < CURDATE() ");

// For current ISR initial list:
$allDescriptions = ($isr === 'running') ? $descRunning : (($isr === 'ended') ? $descEnded : $descAll);

// ===== Read task filter from GET =====
$selected = isset($_GET['desc']) ? (array)$_GET['desc'] : ['All'];
$selected = array_values(array_filter(array_map('trim', $selected), fn($v) => $v !== ''));

$hasAll = in_array('All', $selected, true);
if (empty($selected)) {
  $hasAll = true;
  $selected = ['All'];
}

// Build task filter SQL if not All
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

// ===== Overall summary (filtered) =====
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
  $isrSql
  $filterSql
";

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

// ===== Per-task summary (filtered) =====
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
  $isrSql
  $filterSql
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

$isrLabelText = ($isr === 'running') ? 'Running' : (($isr === 'ended') ? 'Ended' : 'All');

// For JS bootstrap
$jsSelected = $selected; // already validated to current ISR list if not All
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

    /* Running row highlight */
    .row-running{
      background: rgba(34, 197, 94, 0.12) !important;
    }
    .row-running:hover{
      background: rgba(34, 197, 94, 0.18) !important;
    }

    /* clickable badges */
    .filter-badge-btn{
      cursor:pointer;
    }
  </style>
</head>

<body>

<!-- ===== Header (Daily Report, Todo List, Dashboard ★, Three-dot) ===== -->
<nav class="navbar navbar-expand-lg">
  <div class="container py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="index.php">Daily Report</a>
      <a class="btn btn-sm btn-outline-light" href="todo_list.php">Todo List</a>

      <span class="fw-semibold text-white ms-2">Dashboard</span>
      <span class="badge bg-primary rounded-pill">★</span>
    </div>

    <button type="button"
            class="btn bg-white text-dark opacity-80 fw-bold dot-btn"
            data-bs-toggle="modal"
            data-bs-target="#taskFilterModal"
            title="Filter">
      ⋮
    </button>
  </div>
</nav>

<!-- ===== Badges (click to open modal) ===== -->
<div class="container py-3">
  <span class="badge bg-secondary me-2 filter-badge-btn"
        data-bs-toggle="modal" data-bs-target="#taskFilterModal">
    <?= $hasAll ? 'All Tasks' : ('Selected: ' . (int)count($selected)) ?>
  </span>

  <span class="badge bg-info text-dark filter-badge-btn"
        data-bs-toggle="modal" data-bs-target="#taskFilterModal">
    ISR: <?= htmlspecialchars($isrLabelText) ?>
  </span>
</div>

<!-- ===== Task Filter Modal ===== -->
<div class="modal fade" id="taskFilterModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-md">
    <div class="modal-content glass-card">
      <div class="modal-header border-0">
        <div>
          <h5 class="modal-title mb-0">Filter</h5>
          <div class="text-dim small">ISR changes auto-refresh the list (no reload)</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-0">
        <form method="GET" action="dashboard.php" id="filterForm">

          <!-- Search -->
          <div class="mb-3">
            <input type="text" id="taskSearch" class="form-control"
                   placeholder="Search task..." autocomplete="off">
            <small class="text-dim">Search দিলে All শুধু visible list select/unselect করবে।</small>
          </div>

          <!-- ISR + All -->
          <div class="d-flex align-items-center gap-2 mb-2">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-info dropdown-toggle"
                      type="button"
                      id="isrDropdownBtn"
                      data-bs-toggle="dropdown"
                      aria-expanded="false">
                ISR: <span id="isrLabel"><?= htmlspecialchars($isrLabelText) ?></span>
              </button>

              <ul class="dropdown-menu">
                <li><a class="dropdown-item isr-item" href="#" data-value="all">All</a></li>
                <li><a class="dropdown-item isr-item" href="#" data-value="running">Running</a></li>
                <li><a class="dropdown-item isr-item" href="#" data-value="ended">Ended</a></li>
              </ul>
            </div>

            <input type="hidden" name="isr" id="isrInput" value="<?= htmlspecialchars($isr) ?>">

            <!-- All row -->
            <div class="chk-row row-toggle flex-grow-1" data-target="#chkAll">
              <input class="form-check-input" type="checkbox" value="All" id="chkAll" name="desc[]"
                     <?= $hasAll ? 'checked' : '' ?>>
              <label class="form-check-label flex-grow-1 ms-2" for="chkAll">All</label>
            </div>
          </div>

          <hr class="my-2">

          <!-- Task list will be rendered here by JS (ISR auto-refresh) -->
          <div class="task-list" id="taskList"></div>

          <div class="d-flex gap-2 mt-3">
            <a class="btn btn-sm btn-outline-light w-50" href="dashboard.php">Reset</a>
            <button class="btn btn-sm btn-primary w-50" type="submit">Apply</button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== Content ===== -->
<section class="pb-4">
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
        <small class="text-dim">Start/End + Days/Time/Runs</small>
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
              <tr class="<?= $isRunning ? 'row-running' : '' ?>">
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
  // ---------- DATA FROM PHP ----------
  const DESC_ALL = <?= json_encode($descAll, JSON_UNESCAPED_UNICODE) ?>;
  const DESC_RUNNING = <?= json_encode($descRunning, JSON_UNESCAPED_UNICODE) ?>;
  const DESC_ENDED = <?= json_encode($descEnded, JSON_UNESCAPED_UNICODE) ?>;

  const INIT_ISR = <?= json_encode($isr) ?>;
  const INIT_HAS_ALL = <?= json_encode($hasAll) ?>;
  const INIT_SELECTED = <?= json_encode($jsSelected, JSON_UNESCAPED_UNICODE) ?>;

  // ---------- Elements ----------
  const taskListEl = document.getElementById('taskList');
  const taskSearch = document.getElementById('taskSearch');
  const chkAll = document.getElementById('chkAll');

  const isrItems = document.querySelectorAll('.isr-item');
  const isrLabel = document.getElementById('isrLabel');
  const isrInput = document.getElementById('isrInput');

  // ---------- Helpers ----------
  function getListByISR(isr){
    if (isr === 'running') return DESC_RUNNING;
    if (isr === 'ended') return DESC_ENDED;
    return DESC_ALL;
  }

  function normalize(s){ return (s || '').toString().toLowerCase().trim(); }

  // Build task rows based on ISR + selection
  function renderTaskList(isr, keepSelection=true){
    const list = getListByISR(isr);
    const q = normalize(taskSearch.value);

    // Build set of checked items
    let checkedSet = new Set();

    if (!keepSelection) {
      // If ISR changed and we want a predictable UX:
      // - if All is checked -> all tasks in new list checked
      // - else keep intersection of previously selected (excluding "All")
      // We'll compute from current checkboxes before re-render
    } else {
      // On first render, use INIT selection
      if (INIT_HAS_ALL) {
        // if server says All, all tasks checked
        list.forEach(x => checkedSet.add(x));
      } else {
        INIT_SELECTED.filter(x => x !== 'All').forEach(x => checkedSet.add(x));
      }
    }

    // If keepSelection=false -> read current DOM selection and keep intersection
    if (!keepSelection) {
      const currentChecked = new Set(
        Array.from(taskListEl.querySelectorAll('input.chkTask'))
          .filter(i => i.checked)
          .map(i => i.value)
      );

      if (chkAll.checked) {
        // All currently checked => check all items in new list
        checkedSet = new Set(list);
      } else {
        // keep intersection
        checkedSet = new Set(list.filter(x => currentChecked.has(x)));
      }
    }

    // Render HTML
    let html = '';
    for (const d of list) {
      const show = !q || normalize(d).includes(q);
      if (!show) continue;

      const id = 'task_' + md5(d); // stable-ish id
      const checked = checkedSet.has(d) ? 'checked' : '';
      html += `
        <div class="chk-row row-toggle task-row" data-target="#${id}" data-text="${escapeHtml(normalize(d))}">
          <input class="form-check-input chkTask" type="checkbox" value="${escapeAttr(d)}" id="${id}" name="desc[]" ${checked}>
          <label class="form-check-label flex-grow-1 ms-2" for="${id}">${escapeHtml(d)}</label>
        </div>
      `;
    }

    // Empty state
    if (!html) {
      html = `<div class="text-dim small">No tasks found.</div>`;
    }

    taskListEl.innerHTML = html;

    // Re-bind row click toggles for newly rendered rows
    bindRowToggle();

    // After render, sync All checkbox based on visible rows
    syncAllCheckbox();
  }

  function bindRowToggle(){
    const rows = Array.from(taskListEl.querySelectorAll('.row-toggle'));
    rows.forEach(row => {
      row.addEventListener('click', (e) => {
        const tag = e.target?.tagName?.toUpperCase();
        if (tag === 'INPUT') return;
        if (tag === 'LABEL') e.preventDefault();

        const sel = row.getAttribute('data-target');
        if (!sel) return;

        const input = taskListEl.querySelector(sel);
        if (!input) return;

        input.checked = !input.checked;
        input.dispatchEvent(new Event('change', { bubbles:true }));
      });
    });

    // When any task changes -> sync All
    Array.from(taskListEl.querySelectorAll('input.chkTask')).forEach(c => {
      c.addEventListener('change', () => syncAllCheckbox());
    });
  }

  // Visible rows only (search-aware)
  function getVisibleTaskInputs(){
    const q = normalize(taskSearch.value);
    const rows = Array.from(taskListEl.querySelectorAll('.task-row'));
    const visible = rows.filter(r => {
      const txt = r.getAttribute('data-text') || '';
      return !q || txt.includes(q);
    });
    const ids = new Set(visible.map(r => (r.getAttribute('data-target') || '').replace('#','')));
    return Array.from(taskListEl.querySelectorAll('input.chkTask')).filter(i => ids.has(i.id));
  }

  function syncAllCheckbox(){
    const visibleInputs = getVisibleTaskInputs();
    if (!visibleInputs.length) {
      chkAll.checked = false;
      return;
    }
    chkAll.checked = visibleInputs.every(x => x.checked);
  }

  // All checkbox search-aware select/unselect
  chkAll.addEventListener('change', function(){
    const visibleInputs = getVisibleTaskInputs();
    visibleInputs.forEach(i => i.checked = this.checked);
    syncAllCheckbox();
  });

  // Search -> re-render (so list stays ISR aware + fast)
  taskSearch.addEventListener('input', function(){
    // Keep selection while filtering search results
    renderTaskList(isrInput.value || 'all', true);
  });

  // ISR dropdown init + auto-refresh list
  function setISR(val){
    val = ['all','running','ended'].includes(val) ? val : 'all';
    isrInput.value = val;

    // label
    isrLabel.textContent = (val === 'running') ? 'Running' : ((val === 'ended') ? 'Ended' : 'All');

    // highlight active item
    isrItems.forEach(i => i.classList.toggle('active', i.dataset.value === val));

    // clear search for clarity (optional but clean)
    taskSearch.value = '';

    // refresh list immediately (keep selection intersection / predictable)
    renderTaskList(val, false);
  }

  isrItems.forEach(item => {
    item.addEventListener('click', function(e){
      e.preventDefault();
      setISR(this.dataset.value);
    });
  });

  // initial ISR setup
  setISR(INIT_ISR);

  // ---------- Utilities ----------
  // MD5-like for stable IDs (simple hash)
  function md5(str){
    // not real md5 (we just need stable id). small fast hash:
    let h = 2166136261;
    for (let i=0;i<str.length;i++){
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return (h >>> 0).toString(16);
  }

  function escapeHtml(s){
    return (s ?? '').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }
  function escapeAttr(s){ return escapeHtml(s); }

})();
</script>

</body>
</html>
