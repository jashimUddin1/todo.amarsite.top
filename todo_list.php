<?php //todo_list-3.php <> todo_list.php exchange kora hoice
// todo_list.php — Range + Live Timer + Single-running + Off Day (Worked/OT) + Column Management (CLEANED: no prev/next/today navbar vars)
if (session_status() === PHP_SESSION_NONE)
  session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

/* CSRF */
if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* Column visibility defaults */
if (!isset($_SESSION['todo_cols'])) {
  $_SESSION['todo_cols'] = [
    'col_num' => 1,
    'col_desc' => 1,
    'col_date' => 1,
    'col_priority' => 1,
    'col_status' => 1,
    'col_timer' => 1,
    'col_actions' => 1,
    'col_done' => 1,
  ];
}
$C = $_SESSION['todo_cols'];

/* Auto create/alter TODOS table (adds off_worked) */
$con->query("
  CREATE TABLE IF NOT EXISTS todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    notes VARCHAR(500) NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    duration VARCHAR(50) NULL,
    priority TINYINT DEFAULT 2,  -- 1=Low,2=Medium,3=High
    status TINYINT DEFAULT 0,    -- 0=Pending,1=Done
    running_start DATETIME NULL,
    off_worked TINYINT DEFAULT 0, -- 0=no,1=yes (manual mark for off day)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(25) NULL
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
$con->query("ALTER TABLE todos ADD COLUMN IF NOT EXISTS date_from DATE NULL");
$con->query("ALTER TABLE todos ADD COLUMN IF NOT EXISTS date_to DATE NULL");
$con->query("ALTER TABLE todos ADD COLUMN IF NOT EXISTS duration VARCHAR(50) NULL");
$con->query("ALTER TABLE todos ADD COLUMN IF NOT EXISTS running_start DATETIME NULL");
$con->query("ALTER TABLE todos ADD COLUMN IF NOT EXISTS off_worked TINYINT DEFAULT 0");

/* Month (URL থেকে year/month দেওয়া থাকলে ব্যবহার; নইলে current) */
$year = isset($_GET['year']) ? max(1970, (int) $_GET['year']) : (int) date('Y');
$month = isset($_GET['month']) ? min(12, max(1, (int) $_GET['month'])) : (int) date('n');

/* Helpers */
function flash($text, $type = 'dark')
{
  $_SESSION['flash'] = ['text' => $text, 'type' => $type];
}
function keep_month_qs($default = '')
{
  $y = $_GET['year'] ?? null;
  $m = $_GET['month'] ?? null;
  return ($y && $m) ? "?year=" . urlencode($y) . "&month=" . urlencode($m) : $default;
}

/* Same-month day-only range (e.g., 01–07) */
function fmt_range($from, $to)
{
  if (!$from && !$to)
    return '—';
  if ($from && !$to)
    return date('d', strtotime($from));
  if (!$from && $to)
    return date('d', strtotime($to));
  $f = strtotime($from);
  $t = strtotime($to);
  if (date('mY', $f) === date('mY', $t))
    return date('d', $f) . '–' . date('d', $t);
  return date('d M Y', $f) . ' → ' . date('d M Y', $t);
}

function is_off_day_title($title)
{
  return (mb_strtolower(trim($title)) === 'off day');
}
function off_worked_effective($row, mysqli $con)
{
  if (!is_off_day_title($row['title']))
    return false;
  if ((int) $row['off_worked'] === 1)
    return true;
  $from = $row['date_from'] ?: null;
  $to = $row['date_to'] ?: $from;
  if (!$from)
    return false;
  $q = $con->prepare("SELECT COUNT(*) c FROM daily_reports WHERE report_date BETWEEN ? AND ?");
  $q->bind_param("ss", $from, $to);
  $q->execute();
  $c = $q->get_result()->fetch_assoc()['c'] ?? 0;
  return ((int) $c > 0);
}
function status_badge($row, mysqli $con)
{
  if (is_off_day_title($row['title'])) {
    if (off_worked_effective($row, $con))
      return '<span class="badge text-bg-warning">Worked</span>';
    return '<span class="badge text-bg-success">Off&nbsp;Day</span>';
  }
  if (!empty($row['running_start']))
    return '<span class="badge text-bg-primary">In&nbsp;Progress</span>';
  return ((int) $row['status'] === 1)
    ? '<span class="badge text-bg-success">Completed</span>'
    : '<span class="badge text-bg-info">Pending</span>';
}
function pr_badge($p)
{
  if ($p >= 3)
    return '<span class="badge text-bg-danger">High</span>';
  if ($p == 2)
    return '<span class="badge text-bg-warning">Medium</span>';
  return '<span class="badge text-bg-secondary">Low</span>';
}

/* Get currently running task (if any) */
$runningAny = null;
$res = $con->query("SELECT id, title, notes, running_start FROM todos WHERE running_start IS NOT NULL ORDER BY running_start ASC LIMIT 1");
if ($res && $res->num_rows)
  $runningAny = $res->fetch_assoc();

/* POST Actions (unchanged) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    die("Invalid request");
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'save_settings') {
    $_SESSION['todo_cols'] = [
      'col_num' => isset($_POST['col_num']) ? 1 : 0,
      'col_desc' => 1,
      'col_date' => isset($_POST['col_date']) ? 1 : 0,
      'col_priority' => isset($_POST['col_priority']) ? 1 : 0,
      'col_status' => isset($_POST['col_status']) ? 1 : 0,
      'col_timer' => isset($_POST['col_timer']) ? 1 : 0,
      'col_actions' => isset($_POST['col_actions']) ? 1 : 0,
      'col_done' => isset($_POST['col_done']) ? 1 : 0,
    ];
    flash('Column visibility saved', 'success');
    header("Location: todo_list.php" . keep_month_qs());
    exit;
  }

  if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $date_from = $_POST['date_from'] ?: null;
    $date_to = $_POST['date_to'] ?: null;
    $priority = (int) ($_POST['priority'] ?? 2);
    $duration = trim($_POST['duration'] ?? '');
    $updated_at = date("Y-m-d H:i:s");

    if ($title === '' || !$date_from) {
      flash('From Date & Description required', 'warning');
      header("Location: todo_list.php");
      exit;
    }
    if ($date_from && $date_to && strtotime($date_to) < strtotime($date_from)) {
      $tmp = $date_from;
      $date_from = $date_to;
      $date_to = $tmp;
    }

    $stmt = $con->prepare("INSERT INTO todos (title,notes,date_from,date_to,duration,priority,status,updated_at,off_worked) VALUES (?,?,?,?,?,?,0,?,0)");
    $stmt->bind_param("sssssis", $title, $notes, $date_from, $date_to, $duration, $priority, $updated_at);
    $ok = $stmt->execute();
    flash($ok ? 'Task added' : 'Create failed', $ok ? 'success' : 'danger');

    $y = date('Y', strtotime($date_from));
    $m = date('n', strtotime($date_from));
    header("Location: todo_list.php?year=$y&month=$m");
    exit;
  }

  if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $date_from = $_POST['date_from'] ?: null;
    $date_to = $_POST['date_to'] ?: null;
    $priority = (int) ($_POST['priority'] ?? 2);
    $duration = trim($_POST['duration'] ?? '');
    $updated_at = date("Y-m-d H:i:s");

    if ($id <= 0 || $title === '' || !$date_from) {
      flash('Missing required fields', 'warning');
      header("Location: todo_list.php");
      exit;
    }
    if ($date_from && $date_to && strtotime($date_to) < strtotime($date_from)) {
      $tmp = $date_from;
      $date_from = $date_to;
      $date_to = $tmp;
    }

    $stmt = $con->prepare("UPDATE todos SET title=?, notes=?, date_from=?, date_to=?, duration=?, priority=?, updated_at=? WHERE id=?");
    $stmt->bind_param("sssssssi", $title, $notes, $date_from, $date_to, $duration, $priority, $updated_at, $id);
    $ok = $stmt->execute();
    flash($ok ? 'Task updated' : 'Update failed', $ok ? 'success' : 'danger');

    $y = date('Y', strtotime($date_from));
    $m = date('n', strtotime($date_from));
    header("Location: todo_list.php?year=$y&month=$m");
    exit;
  }

  if ($action === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = (int) ($_POST['status'] ?? 0);
    if ($id > 0) {
      $stmt = $con->prepare("UPDATE todos SET status=?, updated_at=? WHERE id=?");
      $up = date("Y-m-d H:i:s");
      $stmt->bind_param("isi", $status, $up, $id);
      $ok = $stmt->execute();
      flash($ok ? ($status ? 'Marked as completed' : 'Marked as pending') : 'Toggle failed', $ok ? 'success' : 'danger');
    }
    header("Location: todo_list.php" . keep_month_qs());
    exit;
  }

  if ($action === 'off_mark_worked') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $con->prepare("UPDATE todos SET off_worked=1, updated_at=? WHERE id=?");
      $up = date("Y-m-d H:i:s");
      $stmt->bind_param("si", $up, $id);
      $ok = $stmt->execute();
      flash($ok ? 'Off Day marked as Worked (O.T enabled)' : 'Could not mark worked', $ok ? 'success' : 'danger');
    }
    header("Location: todo_list.php" . keep_month_qs());
    exit;
  }

  /* START TIMER */
  if ($action === 'start_timer') {
    $id = (int) ($_POST['id'] ?? 0);
    $forceSwitch = (int) ($_POST['force_switch'] ?? 0);

    if ($id > 0) {
      $st = $con->prepare("SELECT title, off_worked FROM todos WHERE id=?");
      $st->bind_param("i", $id);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();

      if ($row && is_off_day_title($row['title'])) {
        $tinfo = $con->prepare("SELECT * FROM todos WHERE id=?");
        $tinfo->bind_param("i", $id);
        $tinfo->execute();
        $full = $tinfo->get_result()->fetch_assoc();
        if (!off_worked_effective($full, $con)) {
          flash('This Off Day is not marked as worked. Click "Work?" first.', 'warning');
          header("Location: todo_list.php" . keep_month_qs());
          exit;
        }
      }

      $r2 = $con->query("SELECT id, title, notes, running_start FROM todos WHERE running_start IS NOT NULL ORDER BY running_start ASC LIMIT 1");
      if ($r2 && $r2->num_rows) {
        $other = $r2->fetch_assoc();
        if ((int) $other['id'] !== $id) {
          if ($forceSwitch !== 1) {
            flash('Another task is running. Please confirm to switch.', 'warning');
            header("Location: todo_list.php" . keep_month_qs());
            exit;
          }
          $startDT = strtotime($other['running_start']);
          $endDT = time();
          if ($endDT > $startDT) {
            $mins = (int) round(($endDT - $startDT) / 60);
            $report_date = date('Y-m-d');
            $yearI = (int) date('Y');
            $monthName = date('F');
            $dayName = date('l');
            $description = $other['title'];
            $start_time = date('H:i:s', $startDT);
            $end_time = date('H:i:s', $endDT);
            $duration = $mins . " min";
            $remark = $other['notes'] ?? '';
            $updated_at = date("Y-m-d H:i:s");

            $ins = $con->prepare("INSERT INTO daily_reports
              (report_date, year, month, day_name, description, start_time, end_time, duration, remark, updated_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("sissssssss", $report_date, $yearI, $monthName, $dayName, $description, $start_time, $end_time, $duration, $remark, $updated_at);
            $ok1 = $ins->execute();

            $clr = $con->prepare("UPDATE todos SET running_start=NULL, updated_at=? WHERE id=?");
            $clr->bind_param("si", $updated_at, $other['id']);
            $ok2 = $clr->execute();
          }
        }
      }

      $stmt = $con->prepare("UPDATE todos SET running_start = NOW(), updated_at=? WHERE id=?");
      $up = date("Y-m-d H:i:s");
      $stmt->bind_param("si", $up, $id);
      $ok = $stmt->execute();
      flash($ok ? 'Timer started' : 'Could not start timer', $ok ? 'success' : 'danger');
    }
    header("Location: todo_list.php" . keep_month_qs());
    exit;
  }

  /* END TIMER -> daily_reports */
  if ($action === 'end_timer') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $con->prepare("SELECT title, notes, running_start FROM todos WHERE id=?");
      $stmt->bind_param("i", $id);
      $stmt->execute();
      $task = $stmt->get_result()->fetch_assoc();

      if ($task && $task['running_start']) {
        $startDT = strtotime($task['running_start']);
        $endDT = time();
        if ($endDT > $startDT) {
          $mins = (int) round(($endDT - $startDT) / 60);
          $report_date = date('Y-m-d');
          $yearI = (int) date('Y');
          $monthName = date('F');
          $dayName = date('l');
          $description = $task['title'];
          $start_time = date('H:i:s', $startDT);
          $end_time = date('H:i:s', $endDT);
          $duration = $mins . " min";
          $remark = $task['notes'] ?? '';
          $updated_at = date("Y-m-d H:i:s");

          $ins = $con->prepare("INSERT INTO daily_reports
            (report_date, year, month, day_name, description, start_time, end_time, duration, remark, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
          $ins->bind_param("sissssssss", $report_date, $yearI, $monthName, $dayName, $description, $start_time, $end_time, $duration, $remark, $updated_at);
          $ok1 = $ins->execute();

          $clr = $con->prepare("UPDATE todos SET running_start=NULL, updated_at=? WHERE id=?");
          $clr->bind_param("si", $updated_at, $id);
          $ok2 = $clr->execute();

          flash(($ok1 && $ok2) ? 'Ended & logged to Daily Report' : 'End failed', ($ok1 && $ok2) ? 'success' : 'danger');
        } else {
          flash('End time must be after start', 'warning');
        }
      } else {
        flash('No running timer found', 'warning');
      }
    }
    header("Location: todo_list.php" . keep_month_qs());
    exit;
  }

  if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $con->prepare("DELETE FROM todos WHERE id=?");
      $stmt->bind_param("i", $id);
      $ok = $stmt->execute();
      flash($ok ? 'Deleted' : 'Delete failed', $ok ? 'success' : 'danger');
    }
    header("Location: todo_list.php" . keep_month_qs());
    exit;
  }
}

/* Fetch tasks overlapping this month */
$month_start = date('Y-m-01', strtotime("$year-$month-01"));
$month_end = date('Y-m-t', strtotime("$year-$month-01"));
$tasks = [];
$stmt = $con->prepare("
  SELECT * FROM todos
  WHERE (date_from IS NOT NULL AND date_from <= ?)
    AND (date_to IS NULL OR date_to >= ?)
  ORDER BY status ASC, priority DESC, COALESCE(date_from, '9999-12-31') ASC, id ASC
");
$stmt->bind_param("ss", $month_end, $month_start);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $tasks[] = $row;
}
$stmt->close();

/* Running-any indicator for JS */
$RUNNING_TASK_ID = $runningAny ? (int) $runningAny['id'] : 0;
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Todo List (Timer + Off Day O.T)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: #0b0f19;
      color: #e5e7eb;
    }

    :root {
      --glass-bg: rgba(17, 20, 28, .55);
      --glass-brd: rgba(255, 255, 255, .08);
      --text-dim: #9ca3af;
    }

    .modal-backdrop.show {
      backdrop-filter: blur(6px);
      opacity: .5;
    }

    .modal-content.glass {
      background: var(--glass-bg);
      backdrop-filter: blur(14px) saturate(120%);
      border: 1px solid var(--glass-brd);
      border-radius: 1rem;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .45);
    }

    .form-control,
    .form-select {
      background: rgba(255, 255, 255, .05);
      border-color: rgba(255, 255, 255, .15);
      color: #e5e7eb;
    }

    .form-control::placeholder {
      color: var(--text-dim);
    }

    .btn-glow:hover {
      box-shadow: 0 0 .8rem rgba(99, 102, 241, .5);
    }

    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
    }

    a,
    .btn-link {
      text-decoration: none;
    }

    .strike {
      text-decoration: line-through;
      opacity: .75;
    }

    .dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #ff4242;
      margin-right: 6px;
    }

    .timer-txt {
      font-variant-numeric: tabular-nums;
    }

    .badge-ot {
      background: #9b87f5;
    }
  </style>
</head>

<body>

  <!-- NAVBAR (no prev/next/today) -->
  <nav class="navbar bg-info-subtle border-bottom">
    <div class="container d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-dark fw-semibold" href="index.php">Daily Report</a>

        <a class="navbar-brand fw-semibold btn btn-secondary text-white" href="todo_list.php">Todo List</a>

        <a class="btn btn-sm btn-outline-dark fw-semibold" href="dashboard.php">Dashboard</a>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-success btn-glow" data-bs-toggle="modal" data-bs-target="#taskModal"
          onclick="openAddTask()">+ Add Task</button>
        <button class="btn btn-secondary fw-bold" data-bs-toggle="modal" data-bs-target="#settingsModal">⋮</button>
      </div>
    </div>
  </nav>

  <section class="py-4">
    <div class="container">
      <div class="table-responsive mb-4">
        <table class="table table-dark table-bordered table-striped align-middle">
          <thead class="table-light text-dark">
            <tr>
              <?php if ($C['col_num']): ?>
                <th style="min-width:02%;" class="text-center">#</th><?php endif; ?>
              <?php if ($C['col_desc']): ?>
                <th style="min-width:165px;">Description</th><?php endif; ?>
              <?php if ($C['col_date']): ?>
                <th style="min-width:60px;">Date</th><?php endif; ?>
              <?php if ($C['col_priority']): ?>
                <th class="text-center">Priority</th><?php endif; ?>
              <?php if ($C['col_status']): ?>
                <th class="text-center">Status</th><?php endif; ?>
              <?php if ($C['col_timer']): ?>
                <th class="text-center">Timer</th><?php endif; ?>
              <?php if ($C['col_actions']): ?>
                <th class="text-center">Actions</th><?php endif; ?>
              <?php if ($C['col_done']): ?>
                <th class="text-center">Ok?</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($tasks)):
              $i = 1; ?>
              <?php foreach ($tasks as $t): ?>
                <?php
                $isDone = (int) $t['status'] === 1;
                $running = !empty($t['running_start']);
                $rowId = (int) $t['id'];
                $startIso = $running ? date('c', strtotime($t['running_start'])) : '';
                $isOff = is_off_day_title($t['title']);
                $offWorked = $isOff ? off_worked_effective($t, $con) : false;
                ?>
                <tr>
                  <?php if ($C['col_num']): ?>
                    <td class="text-center"><?= $i++ ?></td><?php endif; ?>
                  <?php if ($C['col_desc']): ?>
                    <td class="<?= $isDone ? 'strike' : '' ?>"><?= htmlspecialchars($t['title']) ?></td><?php endif; ?>
                  <?php if ($C['col_date']): ?>
                    <td><?= htmlspecialchars(fmt_range($t['date_from'], $t['date_to'])) ?></td><?php endif; ?>
                  <?php if ($C['col_priority']): ?>
                    <td class="text-center"><?= pr_badge((int) $t['priority']) ?></td><?php endif; ?>
                  <?php if ($C['col_status']): ?>
                    <td class="text-center"><?= status_badge($t, $con) ?></td><?php endif; ?>

                  <?php if ($C['col_timer']): ?>
                    <td>
                      <?php if ($isOff): ?>
                        <?php if (!$offWorked): ?>
                          <form class="d-inline" method="post" action="todo_list.php<?= keep_month_qs() ?>"
                            onsubmit="return confirm('Mark this Off Day as Worked and enable O.T?');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="off_mark_worked">
                            <input type="hidden" name="id" value="<?= $rowId ?>">
                            <button title="No timer until worked" class="btn btn-sm btn-outline-info" type="submit">Work?</button>
                          </form>
                        <?php else: ?>
                          <?php if (!$running): ?>
                            <span class="badge badge-ot">O.T</span>
                            <form class="d-inline start-form" method="post" action="todo_list.php<?= keep_month_qs() ?>">
                              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                              <input type="hidden" name="action" value="start_timer">
                              <input type="hidden" name="id" value="<?= $rowId ?>">
                              <button class="btn btn-sm btn-outline-light" type="submit">Start O.T</button>
                            </form>
                          <?php else: ?>
                            <span class="dot" title="Running"></span>
                            <span class="badge badge-ot me-1">O.T</span>
                            <span class="timer-txt" data-start="<?= htmlspecialchars($startIso) ?>"
                              id="timer-<?= $rowId ?>">00:00:00</span>
                            <form class="d-inline ms-2" method="post" action="todo_list.php<?= keep_month_qs() ?>"
                              onsubmit="return confirm('End O.T and log to Daily Reports?');">
                              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                              <input type="hidden" name="action" value="end_timer">
                              <input type="hidden" name="id" value="<?= $rowId ?>">
                              <button class="btn btn-sm btn-warning" type="submit">End O.T &amp; Log</button>
                            </form>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php else: ?>
                        <?php if (!$running): ?>
                          <form class="d-inline start-form" method="post" action="todo_list.php<?= keep_month_qs() ?>">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="start_timer">
                            <input type="hidden" name="id" value="<?= $rowId ?>">
                            <button class="btn btn-sm btn-outline-light" type="submit">Start</button>
                          </form>
                        <?php else: ?>
                          <span class="dot" title="Running"></span>
                          <span class="timer-txt" data-start="<?= htmlspecialchars($startIso) ?>"
                            id="timer-<?= $rowId ?>">00:00:00</span>
                          <form class="d-inline ms-2" method="post" action="todo_list.php<?= keep_month_qs() ?>"
                            onsubmit="return confirm('End timer and log to Daily Reports?');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="end_timer">
                            <input type="hidden" name="id" value="<?= $rowId ?>">
                            <button class="btn btn-sm btn-warning" type="submit">End</button>
                          </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>

                  <?php if ($C['col_actions']): ?>
                    <td class="d-flex flex-wrap gap-1 text-center justify-content-center">
                      <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal"
                        data-bs-target="#taskModal" data-id="<?= $rowId ?>" data-title="<?= htmlspecialchars($t['title']) ?>"
                        data-notes="<?= htmlspecialchars($t['notes']) ?>" data-from="<?= htmlspecialchars($t['date_from']) ?>"
                        data-to="<?= htmlspecialchars($t['date_to']) ?>"
                        data-duration="<?= htmlspecialchars($t['duration']) ?>" data-priority="<?= (int) $t['priority'] ?>"
                        onclick="openEditFromButton(this)">
                        Edit
                      </button>
                      <form method="post" action="todo_list.php<?= keep_month_qs() ?>"
                        onsubmit="return confirm('Delete this task?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $rowId ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Del</button>
                      </form>
                    </td>
                  <?php endif; ?>

                  <?php if ($C['col_done']): ?>
                    <td class="text-center">
                      <form method="post" action="todo_list.php<?= keep_month_qs() ?>">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $rowId ?>">
                        <input type="hidden" name="status" value="<?= $isDone ? 0 : 1 ?>">
                        <button class="btn btn-sm <?= $isDone ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit">
                          <?= $isDone ? 'Undo' : 'Done' ?>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>

                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="<?= array_sum($C) ?>" class="text-center text-secondary">No tasks overlapping this month</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ADD/EDIT MODAL -->
  <div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content glass text-light">
        <form method="post" id="taskForm">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
          <input type="hidden" name="action" id="formAction" value="create">
          <input type="hidden" name="id" id="task_id">

          <div class="modal-header">
            <h5 class="modal-title" id="taskModalTitle">Add Task</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label">Description</label>
                <input type="text" name="title" id="title" class="form-control"
                  placeholder="Costcounter Website / Off Day / Ecommerce Website" required>
              </div>
              <div class="col-md-5">
                <label class="form-label">Duration (optional)</label>
                <input type="text" name="duration" id="duration" class="form-control"
                  placeholder="e.g., 90 min or 1 hr 30 min">
              </div>

              <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" id="date_from" class="form-control" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date (optional)</label>
                <input type="date" name="date_to" id="date_to" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label">Priority</label>
                <select class="form-select" name="priority" id="priority">
                  <option value="3">High</option>
                  <option value="2" selected>Medium</option>
                  <option value="1">Low</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Notes (optional)</label>
                <input type="text" name="notes" id="notes" class="form-control" placeholder="Optional">
              </div>
            </div>
            <small class="text-warning d-block mt-2">Tip: <strong>Off Day</strong> লিখলে – কাজ করলে “Worked” + Timer-এ
              <b>O.T</b> দেখাবে, না করলে সবুজ “Off Day”.</small>
          </div>

          <div class="modal-footer">
            <button class="btn btn-primary btn-glow" type="submit">
              <span class="spinner-border spinner-border-sm me-2 d-none" id="savingSpin"></span>
              Save
            </button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- SETTINGS MODAL (Column visibility) -->
  <div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
      <div class="modal-content glass text-light">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
          <input type="hidden" name="action" value="save_settings">
          <div class="modal-header">
            <h5 class="modal-title">Manage Columns</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <?php function chk($k)
            {
              global $C;
              return $C[$k] ? 'checked' : '';
            } ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c1" name="col_num"
                <?= chk('col_num') ?>><label class="form-check-label" for="c1"># (Serial)</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c2" disabled checked><label
                class="form-check-label" for="c2">Description (always on)</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c3" name="col_date"
                <?= chk('col_date') ?>><label class="form-check-label" for="c3">Date / Range</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c4" name="col_priority"
                <?= chk('col_priority') ?>><label class="form-check-label" for="c4">Priority</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c5" name="col_status"
                <?= chk('col_status') ?>><label class="form-check-label" for="c5">Status</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c6" name="col_timer"
                <?= chk('col_timer') ?>><label class="form-check-label" for="c6">Timer</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c7" name="col_actions"
                <?= chk('col_actions') ?>><label class="form-check-label" for="c7">Actions (Edit/Delete)</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="c8" name="col_done"
                <?= chk('col_done') ?>><label class="form-check-label" for="c8">Done/Undo</label></div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-primary btn-glow" type="submit">Save</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- TOAST -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
    <div id="appToast" class="toast text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex align-items-center">
        <div class="toast-body flex-grow-1"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
          aria-label="Close"></button>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    const RUNNING_TASK_ID = <?= (int) $RUNNING_TASK_ID ?>;

    // Toast
    const toastEl = document.getElementById('appToast');
    const toastBody = toastEl.querySelector('.toast-body');
    let toastObj = null;
    function showToast(text, type = 'dark') {
      toastEl.className = 'toast border-0 text-bg-' + (
        ['success', 'danger', 'warning', 'info', 'dark'].includes(type) ? type : 'dark'
      );
      toastBody.textContent = text;
      if (!toastObj) toastObj = new bootstrap.Toast(toastEl, { delay: 3000 });
      toastObj.show();
    }

    // Modal helpers
    function openAddTask() {
      document.getElementById('taskModalTitle').innerText = 'Add Task';
      document.getElementById('formAction').value = 'create';
      document.getElementById('task_id').value = '';
      document.getElementById('title').value = '';
      document.getElementById('notes').value = '';
      document.getElementById('date_from').value = '';
      document.getElementById('date_to').value = '';
      document.getElementById('duration').value = '';
      document.getElementById('priority').value = '2';
    }
    function openEditFromButton(btn) {
      document.getElementById('taskModalTitle').innerText = 'Edit Task';
      document.getElementById('formAction').value = 'update';
      document.getElementById('task_id').value = btn.dataset.id;
      document.getElementById('title').value = btn.dataset.title || '';
      document.getElementById('notes').value = btn.dataset.notes || '';
      const df = btn.dataset.from || '';
      const dt = btn.dataset.to || '';
      document.getElementById('date_from').value = df.length === 10 ? df : '';
      document.getElementById('date_to').value = (dt && dt.length === 10) ? dt : '';
      document.getElementById('duration').value = btn.dataset.duration || '';
      document.getElementById('priority').value = btn.dataset.priority || '2';
    }
    document.getElementById('taskForm').addEventListener('submit', function () {
      document.getElementById('savingSpin').classList.remove('d-none');
    });

    // Live timer updater
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tickTimers() {
      const nodes = document.querySelectorAll('.timer-txt[data-start]');
      const now = Date.now();
      nodes.forEach(el => {
        const startISO = el.getAttribute('data-start');
        if (!startISO) return;
        const st = new Date(startISO).getTime();
        let diff = Math.max(0, Math.floor((now - st) / 1000));
        const h = Math.floor(diff / 3600); diff %= 3600;
        const m = Math.floor(diff / 60); const s = diff % 60;
        el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
      });
    }
    setInterval(tickTimers, 1000);
    tickTimers();

    // Single-running enforcement on Start buttons
    document.querySelectorAll('form.start-form').forEach(function (f) {
      f.addEventListener('submit', function (ev) {
        const myIdInput = f.querySelector('input[name="id"]');
        const myId = myIdInput ? parseInt(myIdInput.value, 10) : 0;
        if (RUNNING_TASK_ID && RUNNING_TASK_ID !== myId) {
          const ok = confirm("You are running another work. Are you sure to start this work?\n(Previous running task will be stopped and logged.)");
          if (!ok) { ev.preventDefault(); return false; }
          const hid = document.createElement('input');
          hid.type = 'hidden';
          hid.name = 'force_switch';
          hid.value = '1';
          f.appendChild(hid);
        }
      });
    });
  </script>

  <?php
  // SESSION FLASH -> Toast (one-time)
  if (!empty($_SESSION['flash'])) {
    $flashText = htmlspecialchars($_SESSION['flash']['text'] ?? 'Done.');
    $flashType = htmlspecialchars($_SESSION['flash']['type'] ?? 'dark');
    echo "<script>showToast('{$flashText}', '{$flashType}');</script>";
    unset($_SESSION['flash']);
  }
  ?>
</body>

</html>