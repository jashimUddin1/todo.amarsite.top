<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

function durationMinutesFromString($str)
{
  if (!$str) return 0;
  $parts = explode(' ', trim($str));   // "120 min"
  $num = isset($parts[0]) ? (int)$parts[0] : 0;
  return $num > 0 ? $num : 0;
}

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

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$desc = trim($_GET['desc'] ?? '');
if ($desc === '') { echo "No task selected"; exit; }

$today = date('Y-m-d');

// ===== Quick range filter =====
$range = $_GET['range'] ?? 'all';
$range = in_array($range, ['today','7','30','all'], true) ? $range : 'all';

$dateWhere = "";
$dateParam = null;

if ($range === 'today') {
  $dateWhere = " AND report_date = ? ";
  $dateParam = $today;
} elseif ($range === '7') {
  $dateWhere = " AND report_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ";
} elseif ($range === '30') {
  $dateWhere = " AND report_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) ";
}

// ===== Handle Edit POST (Row click => edit modal => submit) =====
$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_entry') {

  // New values
  $new_date   = trim($_POST['new_report_date'] ?? '');
  $new_start  = trim($_POST['new_start_time'] ?? '');
  $new_end    = trim($_POST['new_end_time'] ?? '');
  $new_remark = trim($_POST['new_remark'] ?? '');
  $new_duration_min = (int)($_POST['new_duration_min'] ?? 0);
  if ($new_duration_min < 0) $new_duration_min = 0;
  $new_duration = $new_duration_min . " min";

  // Original key values (used to locate row)
  $old_date  = trim($_POST['old_report_date'] ?? '');
  $old_start = trim($_POST['old_start_time'] ?? '');
  $old_end   = trim($_POST['old_end_time'] ?? '');

  if ($old_date === '' || $old_start === '' || $old_end === '') {
    $flash = "Update failed: invalid row key.";
  } else {

    $sqlUpd = "
      UPDATE daily_reports
      SET report_date = ?, start_time = ?, end_time = ?, duration = ?, remark = ?
      WHERE description = ?
        AND report_date = ?
        AND start_time = ?
        AND end_time = ?
      LIMIT 1
    ";

    $stmtU = $con->prepare($sqlUpd);
    if (!$stmtU) {
      $flash = "Update failed: prepare error.";
    } else {
      $stmtU->bind_param(
        "sssssssss",
        $new_date, $new_start, $new_end, $new_duration, $new_remark,
        $desc, $old_date, $old_start, $old_end
      );
      if ($stmtU->execute()) {
        $flash = ($stmtU->affected_rows >= 0) ? "Updated successfully." : "No changes applied.";
      } else {
        $flash = "Update failed: execute error.";
      }
      $stmtU->close();
    }
  }

  $q = http_build_query([
    'desc' => $desc,
    'range' => $range,
    'msg' => $flash
  ]);
  header("Location: detail.php?$q");
  exit;
}

$flash = $_GET['msg'] ?? "";

// ===== Fetch rows =====
$sql = "
  SELECT report_date, start_time, end_time, duration, remark
  FROM daily_reports
  WHERE description = ?
  $dateWhere
  ORDER BY report_date DESC, start_time DESC
";

$stmt = $con->prepare($sql);

if ($dateWhere && $dateParam !== null) {
  $stmt->bind_param("ss", $desc, $dateParam);
} else {
  $stmt->bind_param("s", $desc);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totalMinutes = 0;
$firstDate = null; // oldest
$lastDate = null;  // newest

while ($r = $res->fetch_assoc()) {
  $rows[] = $r;
  $m = durationMinutesFromString($r['duration']);
  $totalMinutes += $m;

  $d = $r['report_date'] ?? null;
  if ($d) {
    if ($lastDate === null) $lastDate = $d; // newest (first in DESC)
    $firstDate = $d;                         // ends as oldest
  }
}
$stmt->close();

$totalText = format_hours($totalMinutes);
$totalRuns = count($rows);

function activeBtn($cur, $val){ return $cur === $val ? "btn-primary" : "btn-outline-light"; }
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <title>Task Details — <?= h($desc) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body{ background:#020617; color:#e5e7eb; min-height:100vh; }
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
    a{ text-decoration:none; }

    table thead th{
      background: rgba(15, 23, 42, .95) !important;
      border-bottom: 2px solid rgba(148, 163, 184, .5);
      white-space: nowrap;
    }
    .badge-soft{
      background: rgba(79, 70, 229, .15);
      color: #c7d2fe;
      border: 1px solid rgba(129, 140, 248, .5);
    }
    .pill{
      border: 1px solid rgba(148,163,184,.35);
      background: rgba(2,6,23,.35);
      color:#e5e7eb;
      border-radius: 999px;
      padding: .3rem .7rem;
      font-size: .85rem;
    }
    .truncate{
      max-width: 520px;
      overflow:hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* Row click UX */
    .click-row{ cursor:pointer; }
    .click-row:hover td{ background: rgba(148,163,184,.06); }

    /* remark truncation */
    .remark-short{
      max-width: 520px;
      overflow:hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      display:inline-block;
      vertical-align: bottom;
    }

    .modal-content.glass-card{
      background: rgba(15, 23, 42, .92);
      border: 1px solid rgba(148, 163, 184, .35);
      box-shadow: 0 18px 45px rgba(0,0,0,.65);
    }

    /* ✅ FIX: table scroll stays inside the table section (py + px together) */
    .table-scroll-box{
      max-height: 65vh;             /* adjust: 55vh-75vh */
      overflow: auto;               /* both vertical + horizontal in same box */
      border-radius: 14px;
      border: 1px solid rgba(148, 163, 184, .20);
      -webkit-overflow-scrolling: touch;
    }

    /* sticky header while scrolling inside box */
    .table-scroll-box thead th{
      position: sticky;
      top: 0;
      z-index: 3;
    }
  </style>
</head>

<body>

<nav class="navbar navbar-expand-lg">
  <div class="container py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="dashboard.php">&larr; Back</a>
      <span class="badge bg-primary rounded-pill">Details</span>
      <span class="pill">Task:</span>
      <span class="pill truncate" title="<?= h($desc) ?>"><?= h($desc) ?></span>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
      <span class="badge badge-soft">Runs: <?= (int)$totalRuns ?></span>
      <span class="badge badge-soft">Total: <?= h($totalText) ?></span>
    </div>
  </div>
</nav>

<section class="py-4">
  <div class="container">

    <?php if ($flash): ?>
      <div class="alert alert-info glass-card border-0"><?= h($flash) ?></div>
    <?php endif; ?>

    <!-- Quick filter -->
    <div class="glass-card p-3 mb-3">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <div class="text-dim small mb-1">Quick Filter</div>
          <div class="btn-group" role="group" aria-label="Quick date range">
            <a class="btn btn-sm <?= activeBtn($range,'today') ?>"
               href="detail.php?<?= http_build_query(['desc'=>$desc,'range'=>'today']) ?>">Today</a>

            <a class="btn btn-sm <?= activeBtn($range,'7') ?>"
               href="detail.php?<?= http_build_query(['desc'=>$desc,'range'=>'7']) ?>">Last 7 days</a>

            <a class="btn btn-sm <?= activeBtn($range,'30') ?>"
               href="detail.php?<?= http_build_query(['desc'=>$desc,'range'=>'30']) ?>">Last 30 days</a>

            <a class="btn btn-sm <?= activeBtn($range,'all') ?>"
               href="detail.php?<?= http_build_query(['desc'=>$desc,'range'=>'all']) ?>">All</a>
          </div>
        </div>

        <div class="text-end">
          <div class="text-dim small mb-1">Date Range</div>
          <div class="pill">
            <?= h($firstDate ?? '-') ?> <span class="text-dim">→</span> <?= h($lastDate ?? '-') ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="glass-card p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 text-uppercase text-dim small">Total Time</h6>
            <span class="badge badge-soft">Hours</span>
          </div>
          <h3 class="mb-0"><?= h($totalText) ?></h3>
          <small class="text-dim">Sum of all durations for this task</small>
        </div>
      </div>

      <div class="col-md-4">
        <div class="glass-card p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 text-uppercase text-dim small">Total Runs</h6>
            <span class="badge badge-soft">Count</span>
          </div>
          <h3 class="mb-0"><?= (int)$totalRuns ?></h3>
          <small class="text-dim">Number of entries logged</small>
        </div>
      </div>

      <div class="col-md-4">
        <div class="glass-card p-3 h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 text-uppercase text-dim small">Current Filter</h6>
            <span class="badge badge-soft">Mode</span>
          </div>
          <h3 class="mb-0">
            <?= ($range==='today'?'Today':($range==='7'?'Last 7 days':($range==='30'?'Last 30 days':'All'))) ?>
          </h3>
          <small class="text-dim">Applies only on this details list</small>
        </div>
      </div>
    </div>

    
    <!-- Table -->
    <div class="glass-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Entries</h5>
        <small class="text-dim">Tip: Row click → Edit</small>
      </div>

      <!-- ✅ Updated: Scroll container for both X and Y -->
      <div class="table-scroll-box">
        <table class="table table-dark table-hover align-middle mb-0 text-nowrap">
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
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="5" class="text-center text-dim py-4">No data found for this task.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
              $m = durationMinutesFromString($r['duration']);
              $durationNice = format_hours($m);

              $remark = trim((string)($r['remark'] ?? ''));
              $hasRemark = ($remark !== '');
              $remarkShort = $hasRemark ? $remark : '—';

              $needReadMore = (mb_strlen($remarkShort) > 70);
              $shown = $needReadMore ? (mb_substr($remarkShort, 0, 70) . "…") : $remarkShort;

              $rd = $r['report_date'] ?? '';
              $st = $r['start_time'] ?? '';
              $et = $r['end_time'] ?? '';
            ?>
              <tr class="click-row"
                  data-bs-toggle="modal"
                  data-bs-target="#editEntryModal"
                  data-desc="<?= h($desc) ?>"
                  data-old-date="<?= h($rd) ?>"
                  data-old-start="<?= h($st) ?>"
                  data-old-end="<?= h($et) ?>"
                  data-date="<?= h($rd) ?>"
                  data-start="<?= h($st) ?>"
                  data-end="<?= h($et) ?>"
                  data-duration-min="<?= (int)$m ?>"
                  data-remark="<?= h($remark) ?>">
                <td><?= h($rd ?: '-') ?></td>
                <td><?= h($st ?: '-') ?></td>
                <td><?= h($et ?: '-') ?></td>
                <td><span class="badge bg-info text-dark"><?= h($durationNice) ?></span></td>
                <td class="text-dim">
                  <?php if (!$hasRemark): ?>
                    —
                  <?php else: ?>
                    <span class="remark-short"><?= h($shown) ?></span>
                    <?php if ($needReadMore): ?>
                      <button type="button"
                              class="btn btn-sm btn-outline-light ms-2 readmore-btn"
                              data-bs-toggle="modal"
                              data-bs-target="#readMoreModal"
                              data-remark="<?= h($remark) ?>"
                              onclick="event.stopPropagation();">
                        Read more
                      </button>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <!-- ✅ End scroll container -->

    </div>

  </div>
</section>

<!-- Read More Modal -->
<div class="modal fade" id="readMoreModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-md">
    <div class="modal-content glass-card">
      <div class="modal-header border-0">
        <h5 class="modal-title mb-0">Remark</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="readMoreText" class="text-light" style="white-space:pre-wrap;"></div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Entry Modal (Row click) -->
<div class="modal fade" id="editEntryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content glass-card">
      <div class="modal-header border-0">
        <div>
          <h5 class="modal-title mb-0">Edit Entry</h5>
          <div class="text-dim small">Row click করে খুলেছে — Save করলে DB update হবে</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-0">
        <form method="POST" id="editForm">
          <input type="hidden" name="action" value="update_entry">

          <input type="hidden" name="old_report_date" id="old_report_date">
          <input type="hidden" name="old_start_time" id="old_start_time">
          <input type="hidden" name="old_end_time" id="old_end_time">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Report Date</label>
              <input type="date" class="form-control" name="new_report_date" id="new_report_date" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Start Time</label>
              <input type="time" class="form-control" name="new_start_time" id="new_start_time" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">End Time</label>
              <input type="time" class="form-control" name="new_end_time" id="new_end_time" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Duration (minutes)</label>
              <input type="number" min="0" class="form-control" name="new_duration_min" id="new_duration_min" required>
              <small class="text-dim">Save হলে “X min” হিসেবে DB তে যাবে, UI তে hr/min দেখাবে।</small>
            </div>

            <div class="col-md-8">
              <label class="form-label">Remark</label>
              <textarea class="form-control" rows="3" name="new_remark" id="new_remark" placeholder="Write remark..."></textarea>
            </div>
          </div>

          <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-light w-50" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary w-50">Save Changes</button>
          </div>

          <div class="text-dim small mt-2">
            * যদি একই task/date/time এ duplicate row থাকে, এই update শুধুমাত্র প্রথম ১টা row আপডেট করবে (LIMIT 1)।
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  // Read more
  const readMoreModal = document.getElementById('readMoreModal');
  const readMoreText = document.getElementById('readMoreText');

  readMoreModal?.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    const remark = btn?.getAttribute('data-remark') || '';
    readMoreText.textContent = remark;
  });

  // Edit modal prefill on row click
  const editModal = document.getElementById('editEntryModal');

  const oldDate  = document.getElementById('old_report_date');
  const oldStart = document.getElementById('old_start_time');
  const oldEnd   = document.getElementById('old_end_time');

  const newDate  = document.getElementById('new_report_date');
  const newStart = document.getElementById('new_start_time');
  const newEnd   = document.getElementById('new_end_time');
  const newDur   = document.getElementById('new_duration_min');
  const newRem   = document.getElementById('new_remark');

  editModal?.addEventListener('show.bs.modal', function (event) {
    const row = event.relatedTarget;
    if (!row) return;

    const rd = row.getAttribute('data-date') || '';
    const st = row.getAttribute('data-start') || '';
    const et = row.getAttribute('data-end') || '';
    const dm = row.getAttribute('data-duration-min') || '0';
    const rm = row.getAttribute('data-remark') || '';

    oldDate.value = rd;
    oldStart.value = st;
    oldEnd.value = et;

    newDate.value = rd;
    newStart.value = st;
    newEnd.value = et;
    newDur.value = parseInt(dm, 10) || 0;
    newRem.value = rm;
  });
})();
</script>

</body>
</html>
