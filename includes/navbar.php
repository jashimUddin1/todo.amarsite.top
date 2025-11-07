  <nav class="navbar bg-info-subtle border-bottom">
    <div class="container d-flex justify-content-between align-items-center">

      <a class="navbar-brand fw-semibold btn btn-secondary text-white" href="todo_list.php">Todo List</a>

      <div class="d-flex align-items-center gap-2 yearNav">
        <!-- Prev -->
        <a class="fs-3 fw-bold bg-secondary text-white px-2 rounded pb-1"
          href="index.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" title="Previous Month">&lt;</a>

        <!-- Current -->
        <strong class="fs-5 text-center btn btn-secondary">
          <?= htmlspecialchars($currentMonthName . " " . $year) ?>
        </strong>

        <!-- Next -->
        <a class="fs-3 fw-bold bg-secondary text-white px-2 rounded pb-1"
          href="index.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" title="Next Month">&gt;</a>

        <!-- Today shortcut -->
        <!-- <a class="btn btn-warning btn-sm ms-2" href="index.php">Today</a> -->
      </div>

    </div>
  </nav>
