<?php
// section/main_sec.php
if (session_status() === PHP_SESSION_NONE)
  session_start();
if (!isset($con)) {
  require_once __DIR__ . "/../db/dbcon.php";
}

$allowEdit = !empty($_SESSION['allow_edit']);
$allowDelete = !empty($_SESSION['allow_delete']);

/* $year, $month আসে index.php থেকে */
$y = isset($year) ? (int) $year : (int) date('Y');
$m = isset($month) ? (int) $month : (int) date('n');

/* Fetch & group by report_date, filtered by year-month */
$sql = "SELECT * FROM daily_reports
        WHERE YEAR(report_date) = $y AND MONTH(report_date) = $m
        ORDER BY report_date ASC, id ASC";
$res = $con->query($sql);

$byDate = [];
if ($res && $res->num_rows > 0) {
  while ($r = $res->fetch_assoc()) {
    $d = $r['report_date'];
    if (!isset($byDate[$d])) {
      $byDate[$d] = [
        'day_name' => $r['day_name'],
        'report_date' => $r['report_date'],
        'items' => []
      ];
    }
    $byDate[$d]['items'][] = [
      'id' => $r['id'],
      'description' => $r['description'],
      'start_time' => $r['start_time'],
      'end_time' => $r['end_time'],
      'duration' => $r['duration'], // stored "NNN min"
      'remark' => $r['remark']
    ];
  }
}

/* Duration display formatter: "190 min" -> "3 hr 10 min" */
function fmt_hr_min(?string $durStr): string
{
  if (!$durStr)
    return "—";
  if (!preg_match('/\d+/', $durStr, $m))
    return htmlspecialchars($durStr);
  $mins = (int) $m[0];
  if ($mins < 60)
    return $mins . " min";
  $h = intdiv($mins, 60);
  $rem = $mins % 60;
  return $h . " hr" . ($rem ? " $rem min" : "");
}
?>

<section class="pb-4">
  <div class="container">

    <div class="d-flex justify-content-between align-items-center">
      <button class="btn btn-success btn-glow" data-bs-toggle="modal" data-bs-target="#reportModal"
        onclick="openAddForm()">+ Add</button>
      <h2 class="text-white my-3">Daily Report</h2>
      <button class="btn btn-primary btn-glow" data-bs-toggle="modal" data-bs-target="#settingsModal">Settings</button>
    </div>

    <div class="table-responsive">
      <table class="table table-dark table-bordered table-striped align-middle">
        <thead class="table-light text-dark">
          <tr>
            <th style="min-width:50px">Date</th>
            <th style="min-width:165px">Description</th>
            <th style="min-width:160px">Time</th>
            <th style="min-width:50px">Duration</th>
            <?php if ($allowEdit || $allowDelete): ?>
              <th style="min-width:50px" class="text-center">Actions</th>
            <?php endif; ?>
            <th style="min-width: 5px;" class="text-center">#</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($byDate)): ?>
            <?php foreach ($byDate as $dateKey => $group): ?>
              <?php
              $dayName = $group['day_name'] ?: date('l', strtotime($group['report_date']));
              $dateTxt = date('d/m/Y', strtotime($group['report_date']));
              ?>
              <tr>
                <td>
                  <?= htmlspecialchars($dayName) ?><br>
                  <?= htmlspecialchars($dateTxt) ?>
                </td>

                <td>
                  <ol class="mb-0 ps-3">
                    <?php foreach ($group['items'] as $it): ?>
                      <li><?= htmlspecialchars($it['description'] ?? '—') ?></li>
                    <?php endforeach; ?>
                  </ol>
                </td>

                <td>
                  <ul class="list-unstyled mb-0">
                    <?php foreach ($group['items'] as $it): ?>
                      <li>
                        <?php
                        $st = $it['start_time'] ? date('g:i a', strtotime($it['start_time'])) : '—';
                        $et = $it['end_time'] ? date('g:i a', strtotime($it['end_time'])) : '—';
                        echo $st . ' – ' . $et;
                        ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </td>

                <td>
                  <ul class="list-unstyled mb-0">
                    <?php foreach ($group['items'] as $it): ?>
                      <li><?= htmlspecialchars(fmt_hr_min($it['duration'])) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </td>

                <td class="text-center" style="padding: 1px; padding-top: 4px;">
                  <ul class="list-unstyled mb-0 d-flex flex-column align-items-center">
                    <?php foreach ($group['items'] as $it): ?>
                      <li class="d-flex gap-1 justify-content-center mb-1">
                        <?php if ($allowEdit): ?>
                          <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal"
                            data-bs-target="#reportModal" data-id="<?= (int) $it['id'] ?>"
                            data-date="<?= htmlspecialchars($group['report_date']) ?>"
                            data-desc="<?= htmlspecialchars($it['description']) ?>"
                            data-start="<?= htmlspecialchars($it['start_time']) ?>"
                            data-end="<?= htmlspecialchars($it['end_time']) ?>"
                            data-remark="<?= htmlspecialchars($it['remark']) ?>" onclick="openEditFromButton(this)">
                            Edit
                          </button>
                        <?php endif; ?>

                        <?php if ($allowDelete): ?>
                          <form method="post" action="delete.php" onsubmit="return confirm('Delete this item?');">
                            <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Del</button>
                          </form>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </td>


                <td class="text-center" style="padding: 1px; padding-top: 4px;">
  <ul class="list-unstyled mb-0 d-flex flex-column align-items-center">
    <?php foreach ($group['items'] as $it): ?>
      <li class="mb-1">
        <button type="button" 
                class="btn btn-sm btn-outline-light py-1 px-2 fw-bold"
                data-bs-toggle="modal" 
                data-bs-target="#remarkModal"
                data-remark="<?= htmlspecialchars($it['remark'] ?: 'No remark') ?>">
          ⋮
        </button>
      </li>
    <?php endforeach; ?>
  </ul>
</td>

              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="<?= ($allowEdit || $allowDelete) ? 6 : 5 ?>" class="text-center text-secondary">No data for
                this month</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ADD/EDIT MODAL -->
<div class="modal fade" id="reportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content glass text-light">
      <form method="post" id="reportForm">
        <input type="hidden" name="id" id="item_id">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">

        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Add Report</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Date</label>
              <input type="date" name="report_date" id="report_date" class="form-control" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Description</label>
              <input type="text" name="description" id="description" class="form-control"
                placeholder="e.g., typing practice" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Start Time</label>
              <input type="time" name="start_time" id="start_time" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Time</label>
              <input type="time" name="end_time" id="end_time" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Remark</label>
              <input type="text" name="remark" id="remark" class="form-control" placeholder="Optional">
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-primary btn-glow" type="submit" id="saveBtn">
            <span class="spinner-border spinner-border-sm me-2 d-none" id="savingSpin"></span>
            Save
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- REMARK VIEW MODAL if click 3dot button-->
<div class="modal fade" id="remarkModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header">
        <h5 class="modal-title">Remark</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="remarkContent">
        ...
      </div>
    </div>
  </div>
</div>


<!-- SETTINGS MODAL (toggle edit/delete) -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content glass text-light">
      <form method="post" action="settings.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
        <div class="modal-header">
          <h5 class="modal-title">Settings</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="form-check form-switch fs-5">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleEdit" name="allow_edit"
              <?= $allowEdit ? 'checked' : ''; ?>>
            <label class="form-check-label" for="toggleEdit">Enable Edit</label>
          </div>
          <div class="form-check form-switch fs-5 mt-2">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleDelete" name="allow_delete"
              <?= $allowDelete ? 'checked' : ''; ?>>
            <label class="form-check-label" for="toggleDelete">Enable Delete</label>
          </div>
          <small class="text-secondary d-block mt-3">Both off ⇒ “Actions” column hidden.</small>
        </div>
        <div class="modal-footer">
          <button class="btn btn-primary btn-glow" type="submit">Save Settings</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- TOAST (Session Flash) -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="appToast" class="toast text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex align-items-center">
      <div class="toast-body flex-grow-1"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
        aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- Page Scripts (Bootstrap already loaded in index.php) -->
<script>
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

  function openAddForm() {
    document.getElementById('modalTitle').innerText = "Add Report";
    document.getElementById('reportForm').action = "insert.php";
    document.getElementById('item_id').value = "";
    document.getElementById('report_date').value = "";
    document.getElementById('description').value = "";
    document.getElementById('start_time').value = "";
    document.getElementById('end_time').value = "";
    document.getElementById('remark').value = "";
  }

  function openEditFromButton(btn) {
    const b = btn;
    document.getElementById('modalTitle').innerText = "Edit Report";
    document.getElementById('reportForm').action = "update.php";

    document.getElementById('item_id').value = b.dataset.id;
    document.getElementById('report_date').value = b.dataset.date || '';
    document.getElementById('description').value = b.dataset.desc || '';
    // If DB time is "HH:MM:SS", cut to "HH:MM"
    const st = (b.dataset.start || '');
    const et = (b.dataset.end || '');
    document.getElementById('start_time').value = st.length === 8 ? st.substring(0, 5) : st;
    document.getElementById('end_time').value = et.length === 8 ? et.substring(0, 5) : et;
    document.getElementById('remark').value = b.dataset.remark || '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('reportModal')).show();
  }

  document.addEventListener('click', function(e) {
  if (e.target.matches('[data-remark]')) {
    document.getElementById('remarkContent').innerText = e.target.getAttribute('data-remark');
  }
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