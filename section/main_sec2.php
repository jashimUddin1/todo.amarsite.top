<?php
// section/main_sec.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($con)) {
  require_once __DIR__ . "/../db/dbcon.php";
}

$allowEdit   = !empty($_SESSION['allow_edit']);
$allowDelete = !empty($_SESSION['allow_delete']);

/* $year, $month আসে index.php থেকে */
$y = isset($year) ? (int)$year : (int)date('Y');
$m = isset($month) ? (int)$month : (int)date('n');

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
        'day_name'    => $r['day_name'],
        'report_date' => $r['report_date'],
        'is_off'      => false,
        'items'       => []
      ];
    }
    $byDate[$d]['items'][] = [
      'id'          => $r['id'],
      'description' => $r['description'],
      'start_time'  => $r['start_time'],
      'end_time'    => $r['end_time'],
      'duration'    => $r['duration'], // stored "NNN min"
      'remark'      => $r['remark']
    ];
  }
}

/* === Auto OFF DAY (RANGE-BASED): "first data date" থেকে "আজ" পর্যন্তই === */

/* 1) এই টেবিল/অ্যাপ-ওয়াইড প্রথম ডেটের দিন বের করি */
$firstEver = null;
$qr = $con->query("SELECT MIN(report_date) AS first_date FROM daily_reports");
if ($qr && $qr->num_rows > 0) {
  $row = $qr->fetch_assoc();
  if (!empty($row['first_date'])) {
    $firstEver = new DateTime($row['first_date']);
  }
}

/* 2) এই মাসের ১ তারিখ আর মাসের শেষ দিন */
$firstDayOfMonth = new DateTime(sprintf('%04d-%02d-01', $y, $m));
$lastDayOfMonth  = (clone $firstDayOfMonth)->modify('last day of this month');

/* 3) আজকের তারিখ (ফিউচার OFF DAY থামাতে) */
$today = new DateTime('yesterday');

/* 4) রেঞ্জ ঠিক করি:
      - শুরু: মাসের ১ তারিখের সাথে firstEver এর ম্যাক্স
      - শেষ:  মাসের শেষ দিনের সাথে আজকের মিন */
if ($firstEver) {
  $rangeStart = max($firstDayOfMonth, $firstEver);
} else {
  // যদি টেবিলে কোনো ডেটাই না থাকে, তাহলে অফ-ডে ইনজেক্ট করার দরকার নেই
  $rangeStart = null;
}
$rangeEnd = min($lastDayOfMonth, $today);

/* 5) রেঞ্জ ভ্যালিড হলে তবেই OFF DAY ইনজেক্ট */
if ($rangeStart && $rangeStart <= $rangeEnd) {
  // বিদ্যমান দিনগুলোতে is_off ফ্ল্যাগ না থাকলে false করে নিই
  foreach ($byDate as $k => &$g) {
    if (!isset($g['is_off'])) $g['is_off'] = false;
  }
  unset($g);

  // rangeStart..rangeEnd এর মধ্যে যেসব দিন নেই, সেগুলো OFF DAY
  for ($d = clone $rangeStart; $d <= $rangeEnd; $d->modify('+1 day')) {
    $key = $d->format('Y-m-d');
    if (!isset($byDate[$key])) {
      $byDate[$key] = [
        'day_name'    => $d->format('l'),
        'report_date' => $key,
        'is_off'      => true,
        'items'       => [[
          'id'          => 0,
          'description' => 'OFF DAY',
          'start_time'  => null,
          'end_time'    => null,
          'duration'    => null,
          'remark'      => null,
        ]],
      ];
    }
  }
}

/* তারিখ অনুযায়ী sort */
uksort($byDate, fn($a,$b) => strcmp($a, $b));

/* Duration display formatter: "190 min" -> "3 hr 10 min" */
function fmt_hr_min(?string $durStr): string {
  if (!$durStr) return "—";
  if (!preg_match('/\d+/', $durStr, $m)) return htmlspecialchars($durStr);
  $mins = (int)$m[0];
  if ($mins < 60) return $mins . " min";
  $h = intdiv($mins, 60);
  $rem = $mins % 60;
  return $h . " hr" . ($rem ? " $rem min" : "");
}
?>

<section class="pb-4">
  <div class="container">

    <div class="d-flex justify-content-between align-items-center">
      <button class="btn btn-success btn-glow" data-bs-toggle="modal" data-bs-target="#reportModal" onclick="openAddForm()">+ Add</button>
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
            <th style="min-width:5px;" class="text-center">#</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($byDate)): ?>
            <?php foreach ($byDate as $dateKey => $group): ?>
              <?php
                $isOff   = !empty($group['is_off']);
                $dayName = $group['day_name'] ?: date('l', strtotime($group['report_date']));
                $dateTxt = date('d/m/Y', strtotime($group['report_date']));
              ?>
              <tr class="<?= $isOff ? 'row-off' : '' ?>">
                <td>
                  <?= htmlspecialchars($dayName) ?><br>
                  <?= htmlspecialchars($dateTxt) ?>
                </td>

                <td>
                  <?php if ($isOff): ?>
                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">OFF DAY</span>
                  <?php else: ?>
                    <ol class="mb-0 ps-3">
                      <?php foreach ($group['items'] as $it): ?>
                        <li><?= htmlspecialchars($it['description'] ?? '—') ?></li>
                      <?php endforeach; ?>
                    </ol>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($isOff): ?>
                    —
                  <?php else: ?>
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
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($isOff): ?>
                    —
                  <?php else: ?>
                    <ul class="list-unstyled mb-0">
                      <?php foreach ($group['items'] as $it): ?>
                        <li><?= htmlspecialchars(fmt_hr_min($it['duration'])) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>

                <?php if ($allowEdit || $allowDelete): ?>
                <td class="text-center" style="padding:1px; padding-top:4px;">
                  <?php if ($isOff): ?>
                    —
                  <?php else: ?>
                    <ul class="list-unstyled mb-0 d-flex flex-column align-items-center">
                      <?php foreach ($group['items'] as $it): ?>
                        <li class="d-flex gap-1 justify-content-center mb-1">
                          <?php if ($allowEdit): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-info"
                                    data-bs-toggle="modal"
                                    data-bs-target="#reportModal"
                                    data-id="<?= (int)$it['id'] ?>"
                                    data-date="<?= htmlspecialchars($group['report_date']) ?>"
                                    data-desc="<?= htmlspecialchars($it['description']) ?>"
                                    data-start="<?= htmlspecialchars($it['start_time']) ?>"
                                    data-end="<?= htmlspecialchars($it['end_time']) ?>"
                                    data-remark="<?= htmlspecialchars($it['remark']) ?>"
                                    onclick="openEditFromButton(this)">
                              Edit
                            </button>
                          <?php endif; ?>

                          <?php if ($allowDelete): ?>
                            <form method="post" action="delete.php" onsubmit="return confirm('Delete this item?');">
                              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger">Del</button>
                            </form>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
                <?php endif; ?>

                <!-- # column with per-item 3-dot buttons (remark viewer) -->
                <td class="text-center" style="padding:1px; padding-top:4px;">
                  <?php if ($isOff): ?>
                    —
                  <?php else: ?>
                    <ul class="list-unstyled mb-0 d-flex flex-column align-items-center">
                      <?php foreach ($group['items'] as $it): ?>
                        <li class="mb-1">
                          <button type="button"
                                  class="btn btn-ghost-3dot"
                                  title="View remark"
                                  data-bs-toggle="modal"
                                  data-bs-target="#remarkModal"
                                  data-remark="<?= htmlspecialchars($it['remark'] ?: 'No remark') ?>"
                                  data-desc="<?= htmlspecialchars($it['description'] ?? '') ?>"
                                  data-date="<?= htmlspecialchars($group['report_date'] ?? '') ?>"
                                  data-start="<?= htmlspecialchars($it['start_time'] ?? '') ?>"
                                  data-end="<?= htmlspecialchars($it['end_time'] ?? '') ?>">
                            &vellip;
                          </button>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>

              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="<?= ($allowEdit || $allowDelete) ? 6 : 5 ?>" class="text-center text-secondary">
                No data for this month
              </td>
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
              <input type="text" name="description" id="description" class="form-control" placeholder="e.g., typing practice" required>
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

<!-- REMARK VIEW MODAL (Styled, date on right, time+duration in footer) -->
<div class="modal fade remark-modal" id="remarkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header py-3 remark-header">
        <strong class="modal-title" id="remarkTitle">Remark</strong>
        <small id="remarkMeta" class="ms-auto text-white-60 text-end text-nowrap"></small>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0">
        <div class="remark-body p-3 text-dark" id="remarkContent">…</div>
      </div>

      <div class="modal-footer d-flex justify-content-between align-items-center">
        <div class="small text-secondary-emphasis" id="remarkTime"></div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="copyRemarkBtn">Copy</button>
          <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- SETTINGS MODAL -->
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
            <input class="form-check-input" type="checkbox" role="switch" id="toggleEdit" name="allow_edit" <?= $allowEdit ? 'checked' : ''; ?>>
            <label class="form-check-label" for="toggleEdit">Enable Edit</label>
          </div>
          <div class="form-check form-switch fs-5 mt-2">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleDelete" name="allow_delete" <?= $allowDelete ? 'checked' : ''; ?>>
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

<!-- TOAST -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="appToast" class="toast text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex align-items-center">
      <div class="toast-body flex-grow-1"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- Styles -->
<style>
  .remark-modal .remark-header { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #fff; }
  .remark-modal .remark-body {
    white-space: pre-wrap; word-break: break-word;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    background: rgba(255,255,255,0.03);
    border-top: 1px solid rgba(255,255,255,0.06);
    border-bottom: 1px solid rgba(0,0,0,0.15);
    max-height: 50vh; overflow: auto;
  }
  .btn-ghost-3dot {
    line-height: 1; font-size: 22px; padding: .1rem .45rem;
    background: transparent; color: #adb5bd;
    border: 1px dashed transparent; border-radius: .5rem;
  }
  .btn-ghost-3dot:hover { color: #fff; border-color: rgba(255,255,255,0.2); background: rgba(255,255,255,0.06); }
  .btn-ghost-3dot:focus-visible { outline: 2px solid rgba(13,110,253,0.5); outline-offset: 2px; }
  #remarkMeta { max-width: 60ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .row-off { background: rgba(0, 0, 0, 0.08); }
  .bg-danger-subtle { background: rgba(25, 167, 51, 0.15) !important; }
  .text-danger-emphasis { color: #00f863ff !important; }
  .border-danger-subtle { border-color: rgba(0, 251, 67, 0.35) !important; }
</style>

<!-- Scripts -->
<script>
  const toastEl   = document.getElementById('appToast');
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

    const st = (b.dataset.start || '');
    const et = (b.dataset.end || '');
    document.getElementById('start_time').value = st.length === 8 ? st.substring(0, 5) : st;
    document.getElementById('end_time').value  = et.length === 8 ? et.substring(0, 5) : et;

    document.getElementById('remark').value = b.dataset.remark || '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('reportModal')).show();
  }

  function toHM(str) {
    if (!str) return null;
    const parts = str.split(':');
    if (parts.length < 2) return null;
    return [parseInt(parts[0],10)||0, parseInt(parts[1],10)||0];
  }
  function minutesBetween(startStr, endStr) {
    const s = toHM(startStr), e = toHM(endStr);
    if (!s || !e) return null;
    let sm = s[0]*60 + s[1];
    let em = e[0]*60 + e[1];
    if (em < sm) em += 24*60; // overnight
    return em - sm;
  }
  function fmtHrMin(mins) {
    if (mins == null) return '';
    if (mins < 60) return mins + ' min';
    const h = Math.floor(mins/60), r = mins % 60;
    return h + ' hr' + (r ? (' ' + r + ' min') : '');
  }
  function fmtTimeHM(str) {
    const hm = toHM(str);
    if (!hm) return '';
    const d = new Date(1970,0,1, hm[0], hm[1], 0);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  (function () {
    const modalEl   = document.getElementById('remarkModal');
    const contentEl = document.getElementById('remarkContent');
    const titleEl   = document.getElementById('remarkTitle');
    const metaEl    = document.getElementById('remarkMeta');
    const timeEl    = document.getElementById('remarkTime');
    const copyBtn   = document.getElementById('copyRemarkBtn');

    // Helper functions (same as আগে ছিল)
    function toHM(str){ if(!str) return null; const p=str.split(':'); if(p.length<2) return null; return [parseInt(p[0],10)||0, parseInt(p[1],10)||0]; }
    function minutesBetween(s,e){ const S=toHM(s), E=toHM(e); if(!S||!E) return null; let sm=S[0]*60+S[1], em=E[0]*60+E[1]; if(em<sm) em+=1440; return em-sm; }
    function fmtHrMin(mins){ if(mins==null) return ''; if(mins<60) return mins+' min'; const h=Math.floor(mins/60), r=mins%60; return h+' hr'+(r?(' '+r+' min'):''); }
    function fmtTimeHM(str){ const hm=toHM(str); if(!hm) return ''; const d=new Date(1970,0,1,hm[0],hm[1],0); return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }

    // ✅ Delegated click handler: বাটনে ক্লিক হলেই ডেটা বসিয়ে দেই
    document.addEventListener('click', function(e){
      const btn = e.target.closest('.btn-ghost-3dot');
      if(!btn) return;

      const remark = btn.getAttribute('data-remark') || 'No remark';
      const desc   = btn.getAttribute('data-desc')   || '';
      const date   = btn.getAttribute('data-date')   || '';
      const st     = btn.getAttribute('data-start')  || '';
      const et     = btn.getAttribute('data-end')    || '';

      // Title & Meta
      titleEl.textContent = desc ? `Remark — ${desc}` : 'Remark';
      let metaText = date;
      try { const d = new Date(date); if(!isNaN(d)) metaText = d.toLocaleDateString(); } catch(e) {}
      metaEl.textContent = metaText;

      // Body
      contentEl.textContent = remark;

      // Footer: Time + Duration
      const stText = fmtTimeHM(st);
      const etText = fmtTimeHM(et);
      const diff   = minutesBetween(st, et);
      const durTxt = diff != null ? ` (${fmtHrMin(diff)})` : '';
      let footerTxt = '';
      if (stText && etText) footerTxt = `${stText} – ${etText}${durTxt}`;
      else if (stText) footerTxt = stText;
      else if (etText) footerTxt = etText;
      timeEl.textContent = footerTxt;
    });

    // Copy বাটন একই থাকবে
    copyBtn.addEventListener('click', async function () {
      try {
        await navigator.clipboard.writeText(contentEl.textContent || '');
        copyBtn.textContent = 'Copied!';
        setTimeout(() => (copyBtn.textContent = 'Copy'), 1200);
      } catch (e) {
        copyBtn.textContent = 'Copy failed';
        setTimeout(() => (copyBtn.textContent = 'Copy'), 1200);
      }
    });
  })();
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
