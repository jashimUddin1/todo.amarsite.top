<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db/dbcon.php";
date_default_timezone_set('Asia/Dhaka');

/* ---------- helpers ---------- */
function durationMinutesFromString($str){
  if(!$str) return 0;
  $p = explode(' ', trim($str));
  return isset($p[0]) ? (int)$p[0] : 0;
}
function format_hours($m){
  if($m<=0) return "0 min";
  $h = intdiv($m,60); $r=$m%60;
  if($h && $r) return "$h hr $r min";
  if($h) return "$h hr";
  return "$r min";
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

/* ---------- input ---------- */
$desc = trim($_GET['desc'] ?? '');
if($desc===''){ echo "No task selected"; exit; }

$today = date('Y-m-d');

/* ---------- range filter ---------- */
$range = $_GET['range'] ?? 'all';
$range = in_array($range,['today','7','30','all'],true)?$range:'all';

$whereDate=""; $paramDate=null;
if($range==='today'){ $whereDate=" AND report_date=? "; $paramDate=$today; }
elseif($range==='7'){ $whereDate=" AND report_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) "; }
elseif($range==='30'){ $whereDate=" AND report_date>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) "; }

/* ---------- fetch rows ---------- */
$sql="
 SELECT report_date,start_time,end_time,duration,remark
 FROM daily_reports
 WHERE description=? $whereDate
 ORDER BY report_date DESC,start_time DESC
";
$stmt=$con->prepare($sql);
if($paramDate!==null) $stmt->bind_param("ss",$desc,$paramDate);
else $stmt->bind_param("s",$desc);
$stmt->execute();
$res=$stmt->get_result();

$rows=[]; $totalMin=0; $first=null; $last=null;
while($r=$res->fetch_assoc()){
  $rows[]=$r;
  $m=durationMinutesFromString($r['duration']);
  $totalMin+=$m;
  if($last===null) $last=$r['report_date'];
  $first=$r['report_date'];
}
$stmt->close();

$totalText=format_hours($totalMin);
$totalRuns=count($rows);
function btn($c,$v){ return $c===$v?'btn-primary':'btn-outline-light'; }
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<title>Task Details — <?=h($desc)?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{background:#020617;color:#e5e7eb;}
.glass-card{background:rgba(15,23,42,.85);border:1px solid rgba(148,163,184,.35);
  border-radius:1rem;box-shadow:0 18px 45px rgba(0,0,0,.65);}
.navbar{backdrop-filter:blur(10px);background:rgba(15,23,42,.92)!important;}
.text-dim{color:#9ca3af;}
.badge-soft{background:rgba(79,70,229,.15);color:#c7d2fe;border:1px solid rgba(129,140,248,.5);}
.click-row{cursor:pointer;}
.click-row:hover td{background:rgba(148,163,184,.08);}
.remark-short{max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-block}

/* ---------- FLOATING PX SCROLL ---------- */
.xscroll-dock{
  position:fixed; left:0; bottom:8px;
  height:14px; overflow-x:auto; overflow-y:hidden;
  display:none; z-index:3000;
  background:rgba(2,6,23,.75);
  border:1px solid rgba(148,163,184,.3);
  border-radius:12px; backdrop-filter:blur(8px);
}
.xscroll-inner{height:1px;}
.xscroll-dock::-webkit-scrollbar{height:10px;}
</style>
</head>

<body>

<nav class="navbar navbar-expand-lg">
  <div class="container py-2 d-flex justify-content-between">
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="dashboard.php">&larr; Back</a>
      <span class="badge bg-primary">Details</span>
      <span class="badge bg-dark"><?=h($desc)?></span>
    </div>
    <div class="d-flex gap-2">
      <span class="badge badge-soft">Runs: <?=$totalRuns?></span>
      <span class="badge badge-soft">Total: <?=h($totalText)?></span>
    </div>
  </div>
</nav>

<section class="py-4">
<div class="container">

<div class="glass-card p-3 mb-3">
  <div class="btn-group">
    <a class="btn btn-sm <?=btn($range,'today')?>"
       href="?<?=http_build_query(['desc'=>$desc,'range'=>'today'])?>">Today</a>
    <a class="btn btn-sm <?=btn($range,'7')?>"
       href="?<?=http_build_query(['desc'=>$desc,'range'=>'7'])?>">Last 7 days</a>
    <a class="btn btn-sm <?=btn($range,'30')?>"
       href="?<?=http_build_query(['desc'=>$desc,'range'=>'30'])?>">Last 30 days</a>
    <a class="btn btn-sm <?=btn($range,'all')?>"
       href="?<?=http_build_query(['desc'=>$desc,'range'=>'all'])?>">All</a>
  </div>
</div>

<div class="glass-card p-3">
  <h5 class="mb-2">Entries</h5>

  <!-- table wrapper (NO height fix) -->
  <div class="table-responsive" id="tableWrap">
    <table class="table table-dark table-hover align-middle mb-0 text-nowrap" id="entriesTable">
      <thead>
        <tr>
          <th>Date</th><th>Start</th><th>End</th><th>Duration</th><th>Remark</th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="5" class="text-center text-dim">No data</td></tr>
      <?php else: foreach($rows as $r):
        $m=durationMinutesFromString($r['duration']);
        $nice=format_hours($m);
        $rm=trim($r['remark']??'');
        $short=mb_strlen($rm)>70?mb_substr($rm,0,70).'…':$rm;
      ?>
        <tr class="click-row">
          <td><?=h($r['report_date'])?></td>
          <td><?=h($r['start_time'])?></td>
          <td><?=h($r['end_time'])?></td>
          <td><span class="badge bg-info text-dark"><?=h($nice)?></span></td>
          <td class="text-dim">
            <?= $rm?'<span class="remark-short">'.h($short).'</span>':'—' ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</section>

<!-- FLOATING X SCROLL -->
<div id="xScrollDock" class="xscroll-dock">
  <div id="xScrollInner" class="xscroll-inner"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  const wrap=document.getElementById('tableWrap');
  const dock=document.getElementById('xScrollDock');
  const inner=document.getElementById('xScrollInner');
  const footer=document.querySelector('footer'); // optional

  if(!wrap||!dock||!inner) return;
  let lock=false;

  function needX(){ return wrap.scrollWidth>wrap.clientWidth+2; }

  function inView(){
    const r=wrap.getBoundingClientRect();
    return r.top<window.innerHeight-120 && r.bottom>120;
  }

  function footerOffset(){
    if(!footer) return 8;
    const fr=footer.getBoundingClientRect();
    const overlap=window.innerHeight-fr.top;
    return overlap>0?overlap+8:8;
  }

  function syncGeom(){
    const r=wrap.getBoundingClientRect();
    dock.style.width=r.width+'px';
    dock.style.left=r.left+'px';
    dock.style.bottom=footerOffset()+'px';
    inner.style.width=wrap.scrollWidth+'px';
  }

  function update(){
    const show=needX()&&inView();
    dock.style.display=show?'block':'none';
    if(show) syncGeom();
  }

  dock.addEventListener('scroll',()=>{
    if(lock) return; lock=true;
    wrap.scrollLeft=dock.scrollLeft; lock=false;
  });
  wrap.addEventListener('scroll',()=>{
    if(lock) return; lock=true;
    dock.scrollLeft=wrap.scrollLeft; lock=false;
  });

  window.addEventListener('scroll',update,{passive:true});
  window.addEventListener('resize',update);
  window.addEventListener('load',update);
})();
</script>

</body>
</html>
