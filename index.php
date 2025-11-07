<?php
// index.php
if (session_status() === PHP_SESSION_NONE)
  session_start();
require_once __DIR__ . "/db/dbcon.php";

/* CSRF token ensure */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* Default toggles (first load) */
if (!isset($_SESSION['allow_edit']))
  $_SESSION['allow_edit'] = 1;
if (!isset($_SESSION['allow_delete']))
  $_SESSION['allow_delete'] = 1;

/* Current year/month from URL (refresh-safe) */
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');

/* Normalize (just in case) */
if ($month < 1) {
  $month = 12;
  $year--;
}
if ($month > 12) {
  $month = 1;
  $year++;
}

/* Prev/Next month calc */
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth == 0) {
  $prevMonth = 12;
  $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth == 13) {
  $nextMonth = 1;
  $nextYear++;
}

$currentMonthName = date('F', strtotime("$year-$month-01"));
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Daily Report</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Bootstrap JS MUST be above custom scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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

    .modal.fade .modal-dialog {
      transform: translateY(10px) scale(.98);
      transition: transform .25s ease, opacity .25s ease;
    }

    .modal.show .modal-dialog {
      transform: translateY(0) scale(1);
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

    a,
    .btn-link {
      text-decoration: none;
    }

    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
    }
  </style>
</head>

<body>



  <?php
  // ======= NAVBAR (Dynamic month nav) =======
  include __DIR__ . "/includes/navbar.php";
  
  // ====== MAIN SECTION INCLUDE (uses $year,$month from this scope) ======
  // include __DIR__ . "/section/main_sec.php";
  include __DIR__ . "/section/main_sec2.php";
  ?>



</body>

</html>