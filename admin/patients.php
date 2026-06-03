<?php
session_start();
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  header('Location: ' . url('login.php')); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
/** @var mysqli $con */
if (isset($con) && method_exists($con,'set_charset')) { @$con->set_charset('utf8mb4'); }

/* ---------- helpers ---------- */
function safe($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function tryQuery(callable $fn, $fallback){ try{ return $fn(); }catch(Throwable $e){ error_log($e->getMessage()); return $fallback; } }
function table_exists(mysqli $con,string $t):bool{
  $st=$con->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
  $st->bind_param("s",$t); $st->execute(); $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function column_exists(mysqli $con,string $t,string $c):bool{
  $st=$con->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->bind_param("ss",$t,$c); $st->execute(); $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function time_ago($ts){
  $t=@strtotime($ts); if(!$t) return '—';
  $d=time()-$t; if($d<60) return $d.'s ago';
  $m=floor($d/60); if($m<60) return $m.'m ago';
  $h=floor($m/60); if($h<24) return $h.'h ago';
  $dd=floor($h/24); if($dd<7) return $dd.'d ago';
  return date('M j, Y g:ia', $t);
}
function pct_from_keys(array $row, array $keys): int {
  $tot=count($keys); if(!$tot) return 0; $have=0;
  foreach($keys as $k){ if(array_key_exists($k,$row) && $row[$k]!=='' && $row[$k]!==null) $have++; }
  return (int)round($have/$tot*100);
}
$CURRENCY = defined('CURRENCY') ? CURRENCY : '৳';

/* ---------- AJAX: Patient Resume ---------- */
if (isset($_GET['ajax']) && $_GET['ajax']==='patient_resume' && isset($_GET['id'])) {
  $id=(int)$_GET['id'];
  $st=$con->prepare("SELECT * FROM patients WHERE id=? LIMIT 1");
  $st->bind_param("i",$id); $st->execute(); $p=$st->get_result()->fetch_assoc(); $st->close();
  if(!$p){ echo '<div class="text-danger">Patient not found.</div>'; exit; }

  $dec=function($x){ if(!is_string($x)||$x==='')return[]; $j=json_decode($x,true); return json_last_error()===JSON_ERROR_NONE?($j?:[]):[]; };
  $tasks=$dec($p['tasks_json']??null); $com=$dec($p['comorbidities_json']??null); $med=$dec($p['medications_json']??null); $adls=$dec($p['adls_json']??null);
  ?>
  <style>.resume-key{color:#64748b;min-width:180px;display:inline-block}.badge-soft{background:rgba(29,78,216,.08);color:#1d4ed8;border:1px solid rgba(29,78,216,.18);padding:.15rem .5rem;border-radius:9px}</style>
  <div class="d-flex justify-content-between">
    <div>
      <h5 class="mb-0"><?php echo safe($p['full_name'] ?: ('Patient #'.$p['id'])); ?></h5>
      <div class="text-muted small">ID #<?php echo (int)$p['id']; ?><?php if(!empty($p['created_at'])) echo ' • Created '.safe(time_ago($p['created_at'])); ?></div>
    </div>
    <?php if(!empty($p['case_status'])): ?><span class="badge-soft"><?php echo safe($p['case_status']); ?></span><?php endif; ?>
  </div><hr>
  <div class="row g-3">
    <div class="col-md-6">
      <h6>Contact & Demographics</h6>
      <div><span class="resume-key">Email</span> <?php echo safe($p['email'] ?? '—'); ?></div>
      <div><span class="resume-key">Phone</span> <?php echo safe($p['phone'] ?? '—'); ?></div>
      <div><span class="resume-key">DOB</span> <?php echo safe($p['dob'] ?? '—'); ?></div>
      <div><span class="resume-key">Gender</span> <?php echo safe($p['gender'] ?? '—'); ?></div>
      <div><span class="resume-key">Address</span> <?php echo safe($p['service_address'] ?? '—'); ?></div>
      <div><span class="resume-key">Language</span> <?php echo safe($p['language_pref'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6>Care Request</h6>
      <div><span class="resume-key">Type</span> <?php echo safe($p['caregiver_type'] ?? '—'); ?></div>
      <div><span class="resume-key">Shift</span> <?php echo safe($p['shift_type'] ?? '—'); ?></div>
      <div><span class="resume-key">Start</span> <?php echo safe($p['start_date'] ?? '—'); ?></div>
      <div><span class="resume-key">Hours/Day</span> <?php echo safe($p['hours_per_day'] ?? '—'); ?></div>
      <div><span class="resume-key">Days/Week</span> <?php echo safe($p['days_per_week'] ?? '—'); ?></div>
      <div><span class="resume-key">Tasks</span> <?php echo $tasks?safe(implode(', ',$tasks)):'—'; ?></div>
    </div>
    <div class="col-md-6">
      <h6>Clinical</h6>
      <div><span class="resume-key">Primary Dx</span> <?php echo safe($p['primary_dx'] ?? '—'); ?></div>
      <div><span class="resume-key">Comorbidities</span> <?php echo $com?safe(implode(', ',$com)):'—'; ?></div>
      <div><span class="resume-key">Allergies</span> <?php echo safe($p['allergies'] ?? '—'); ?></div>
      <div><span class="resume-key">Medications</span> <?php echo $med?safe(implode(', ',$med)):'—'; ?></div>
      <div><span class="resume-key">ADLs</span> <?php echo $adls?safe(implode(', ',$adls)):'—'; ?></div>
    </div>
    <div class="col-md-6">
      <h6>Registrant</h6>
      <div><span class="resume-key">Name</span> <?php echo safe($p['registrant_name'] ?? '—'); ?></div>
      <div><span class="resume-key">Relation</span> <?php echo safe($p['registrant_relation'] ?? '—'); ?></div>
      <div><span class="resume-key">Phone</span> <?php echo safe($p['registrant_phone1'] ?? '—'); ?></div>
      <div><span class="resume-key">Email</span> <?php echo safe($p['registrant_email'] ?? '—'); ?></div>
    </div>
  </div>
  <?php exit;
}

/* ---------- AJAX: Notify one / Bulk notify ---------- */
if (isset($_GET['ajax']) && $_GET['ajax']==='nudge_patient' && isset($_POST['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  $pid=(int)$_POST['id'];
  $ps=$con->prepare("SELECT * FROM patients WHERE id=? LIMIT 1"); $ps->bind_param("i",$pid); $ps->execute(); $p=$ps->get_result()->fetch_assoc(); $ps->close();
  if(!$p){ echo json_encode(['ok'=>false,'error'=>'Patient not found']); exit; }
  $uid = column_exists($con,'patients','user_id') ? (int)($p['user_id'] ?? 0) : 0;
  if(!$uid && table_exists($con,'users') && !empty($p['email'])){ $st=$con->prepare("SELECT id FROM users WHERE email=? LIMIT 1"); $st->bind_param("s",$p['email']); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if($r)$uid=(int)$r['id']; }
  if(!$uid && table_exists($con,'users') && !empty($p['registrant_email'])){ $st=$con->prepare("SELECT id FROM users WHERE email=? LIMIT 1"); $st->bind_param("s",$p['registrant_email']); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if($r)$uid=(int)$r['id']; }
  $ok=false;
  if ($uid && table_exists($con,'user_notifications')){
    $url=url('patients/intake.php?pid='.$pid);
    $msg="Please complete the intake form so we can match a caregiver faster.";
    $st=$con->prepare("INSERT INTO user_notifications (user_id,type,message,url,is_read,created_at) VALUES (?,'intake_nudge',?,?,0,NOW())");
    $st->bind_param("iss",$uid,$msg,$url); $ok=$st->execute(); $st->close();
  }
  echo json_encode($ok?['ok'=>true,'message'=>'Nudge sent.']:['ok'=>false,'error'=>$uid?'Insert failed / table missing.':'No linked user']); exit;
}
if (isset($_GET['ajax']) && $_GET['ajax']==='bulk_nudge') {
  header('Content-Type: application/json; charset=utf-8');
  if (!table_exists($con,'user_notifications') || !table_exists($con,'patients')) { echo json_encode(['ok'=>false,'error'=>'Tables missing']); exit; }
  $limit=max(1,min(50,(int)($_POST['limit'] ?? 20)));
  $q=$con->query("SELECT id,user_id,email,registrant_email FROM patients ORDER BY id DESC LIMIT 400");
  $rows=[]; while($q && ($r=$q->fetch_assoc())) $rows[]=$r;
  $sent=0;
  foreach($rows as $r){
    if($sent>=$limit) break;
    $uid=(int)($r['user_id'] ?? 0);
    if(!$uid && table_exists($con,'users') && !empty($r['email'])){ $st=$con->prepare("SELECT id FROM users WHERE email=? LIMIT 1"); $st->bind_param("s",$r['email']); $st->execute(); $x=$st->get_result()->fetch_assoc(); $st->close(); if($x)$uid=(int)$x['id']; }
    if(!$uid && table_exists($con,'users') && !empty($r['registrant_email'])){ $st=$con->prepare("SELECT id FROM users WHERE email=? LIMIT 1"); $st->bind_param("s",$r['registrant_email']); $st->execute(); $x=$st->get_result()->fetch_assoc(); $st->close(); if($x)$uid=(int)$x['id']; }
    if(!$uid) continue;
    $url=url('patients/intake.php?pid='.$r['id']); $msg="Please complete the intake form so we can match a caregiver faster.";
    $st=$con->prepare("INSERT INTO user_notifications (user_id,type,message,url,is_read,created_at) VALUES (?,'intake_nudge',?,?,0,NOW())");
    $st->bind_param("iss",$uid,$msg,$url); if($st->execute()) $sent++; $st->close();
  }
  echo json_encode(['ok'=>true,'sent'=>$sent]); exit;
}

/* ---------- inputs ---------- */
$range = (int)($_GET['range'] ?? 7); if(!in_array($range,[7,15,30],true)) $range=7;
$q      = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$triage = trim((string)($_GET['triage'] ?? ''));
$ctype  = trim((string)($_GET['ctype'] ?? ''));
$dfrom  = trim((string)($_GET['from'] ?? ''));
$dto    = trim((string)($_GET['to'] ?? ''));
$page   = max(1,(int)($_GET['page'] ?? 1));
$perPage= 20; $offset=($page-1)*$perPage;

/* ---------- KPIs ---------- */
$cards=['total'=>0,'active'=>0,'serving'=>0,'sessions'=>0,'paid'=>0.0];
$cards['total']=tryQuery(fn()=>table_exists($con,'patients')?(int)$con->query("SELECT COUNT(*) FROM patients")->fetch_row()[0]:0,0);
$cards['active']=tryQuery(fn()=>table_exists($con,'patients')?(int)$con->query("SELECT COUNT(*) FROM patients WHERE case_status IN ('verified','active','under_review')")->fetch_row()[0]:0,0);
$cards['serving']=tryQuery(fn()=>table_exists($con,'service_assignments')?(int)$con->query("SELECT COUNT(DISTINCT patient_id) FROM service_assignments WHERE status IN ('active','ongoing','scheduled')")->fetch_row()[0]:0,0);
$cards['sessions']=tryQuery(function() use($con,$range){
  if(!table_exists($con,'service_assignments'))return 0;
  $st=$con->prepare("SELECT COUNT(*) FROM service_assignments WHERE end_time IS NOT NULL AND start_time>=NOW()-INTERVAL ? DAY");
  $st->bind_param("i",$range); $st->execute(); $v=(int)$st->get_result()->fetch_row()[0]; $st->close(); return $v;
},0);
$cards['paid']=tryQuery(function() use($con,$range){
  if(!table_exists($con,'payments'))return 0.0;
  $st=$con->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN('paid','completed','success') AND created_at>=NOW()-INTERVAL ? DAY");
  $st->bind_param("i",$range); $st->execute(); $v=(float)$st->get_result()->fetch_row()[0]; $st->close(); return $v;
},0.0);

/* ---------- series helpers ---------- */
function last_n_labels($n){ $L=[]; for($i=$n-1;$i>=0;$i--){ $L[]=(new DateTime("-{$i} days"))->format('M j'); } return $L; }
function last_n_dates($n){ $D=[]; for($i=$n-1;$i>=0;$i--){ $d=(new DateTime("-{$i} days"))->format('Y-m-d'); $D[]=$d; } return $D; }

/* Payments/day + Sessions/day */
$payLabels = last_n_labels($range); $paySeries = array_fill(0,$range,0.0); $sessSeries = array_fill(0,$range,0);
if (table_exists($con,'payments')){
  [$payLabels,$paySeries]=tryQuery(function() use($con,$range){
    $st=$con->prepare("SELECT DATE(created_at)d,SUM(amount)s FROM payments WHERE status IN('paid','completed','success') AND created_at>=CURDATE()-INTERVAL ? DAY GROUP BY DATE(created_at) ORDER BY d ASC");
    $st->bind_param("i",$range); $st->execute(); $rs=$st->get_result(); $map=[]; while($rs && ($r=$rs->fetch_assoc())) $map[$r['d']]=(float)$r['c']??(float)$r['s']; $labels=[]; $vals=[];
    for($i=$range-1;$i>=0;$i--){ $d=(new DateTime("-{$i} days"))->format('Y-m-d'); $labels[]=(new DateTime($d))->format('M j'); $vals[]=(float)($map[$d]??0.0); } $st->close(); return [$labels,$vals];
  },[$payLabels,$paySeries]);
}
if (table_exists($con,'service_assignments')){
  $dates = last_n_dates($range); $map=[];
  $st=$con->prepare("SELECT DATE(start_time)d,COUNT(*)c FROM service_assignments WHERE end_time IS NOT NULL AND start_time>=CURDATE()-INTERVAL ? DAY GROUP BY DATE(start_time) ORDER BY d ASC");
  $st->bind_param("i",$range); try{$st->execute(); $rs=$st->get_result(); while($rs && ($r=$rs->fetch_assoc())) $map[$r['d']]=(int)$r['c']; $st->close(); }catch(Throwable $e){}
  $sessSeries=[]; foreach($dates as $d){ $sessSeries[]=(int)($map[$d]??0); }
}

/* Registrations heatmap (8 weeks) */
$heatX = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$heatY = []; for($w=7;$w>=0;$w--) $heatY[]='W-'.($w===0?'0':'-'.$w);
$heatData = [];
if (table_exists($con,'patients')){
  $st=$con->prepare("SELECT DATE(created_at)d, COUNT(*) c FROM patients WHERE created_at >= CURDATE()-INTERVAL 55 DAY GROUP BY DATE(created_at)");
  $st->execute(); $rs=$st->get_result(); $map=[]; while($rs && ($r=$rs->fetch_assoc())) $map[$r['d']]=(int)$r['c']; $st->close();
  $today = new DateTime('today');
  for($i=55;$i>=0;$i--){
    $date = (new DateTime("-{$i} days"))->format('Y-m-d');
    $dt = new DateTime($date); $dow=(int)$dt->format('N'); $x=$heatX[$dow-1];
    $weeksDiff = (int)$today->diff($dt)->days; $bucket = min(7,(int)floor($weeksDiff/7));
    $y = 'W-'.($bucket===0?'0':'-'.$bucket);
    $heatData[] = ['x'=>$x,'y'=>$y,'v'=>(int)($map[$date]??0)];
  }
}

/* Distributions + intake stage averages */
$caseMix=['labels'=>[],'series'=>[]];
$triageMix=['labels'=>[],'series'=>[]];
$ctypeStack=['labels'=>['Nurse','Attendant','Physiotherapist','Therapist','Mixed'],'series'=>[
  'Routine'=>[0,0,0,0,0],
  'Urgent'=> [0,0,0,0,0],
  'High'=>   [0,0,0,0,0]
]];
$ageBuckets=['labels'=>['0–17','18–39','40–59','60–74','75+'],'series'=>[0,0,0,0,0]];
$stageAvg=['Profile'=>0,'Care'=>0,'Billing'=>0,'Consents'=>0];

if (table_exists($con,'patients')) {
  // case & triage
  $caseMix=tryQuery(function() use($con){ $r=$con->query("SELECT COALESCE(case_status,'unknown') s,COUNT(*) c FROM patients GROUP BY COALESCE(case_status,'unknown')");
    $L=[];$S=[]; while($r && ($x=$r->fetch_assoc())){ $L[]=$x['s']; $S[]=(int)$x['c']; } return ['labels'=>$L,'series'=>$S];
  },$caseMix);
  $triageMix=tryQuery(function() use($con){ $r=$con->query("SELECT COALESCE(triage_priority,'routine') t,COUNT(*) c FROM patients GROUP BY COALESCE(triage_priority,'routine')");
    $L=[];$S=[]; while($r && ($x=$r->fetch_assoc())){ $L[]=ucfirst($x['t']); $S[]=(int)$x['c']; } return ['labels'=>$L,'series'=>$S];
  },$triageMix);
  if (array_sum($caseMix['series'] ?: [0]) === 0) { $caseMix=['labels'=>['No data'],'series'=>[1]]; }
  if (array_sum($triageMix['series'] ?: [0]) === 0) { $triageMix=['labels'=>['No data'],'series'=>[1]]; }

  // caregiver type × triage
  $types=['nurse','attendant','physiotherapist','therapist','mixed']; $idx=array_flip($types);
  $stack=['Routine'=>[0,0,0,0,0],'Urgent'=>[0,0,0,0,0],'High'=>[0,0,0,0,0]];
  $r=$con->query("SELECT COALESCE(caregiver_type,'mixed') t, COALESCE(triage_priority,'routine') p, COUNT(*) c FROM patients GROUP BY COALESCE(caregiver_type,'mixed'), COALESCE(triage_priority,'routine')");
  while($r && ($x=$r->fetch_assoc())){ $ti=strtolower($x['t']); $pi=ucfirst($x['p']); if(isset($idx[$ti]) && isset($stack[$pi])) $stack[$pi][$idx[$ti]]=(int)$x['c']; }
  $ctypeStack=['labels'=>array_map('ucfirst',$types),'series'=>$stack];

  // age buckets
  if (column_exists($con,'patients','dob')){
    $r=$con->query("SELECT TIMESTAMPDIFF(YEAR,dob,CURDATE()) a FROM patients WHERE dob IS NOT NULL");
    $b=[0,0,0,0,0]; while($r && ($x=$r->fetch_row())){ $a=(int)$x[0]; if($a<18)$b[0]++; elseif($a<40)$b[1]++; elseif($a<60)$b[2]++; elseif($a<75)$b[3]++; else $b[4]++; }
    if (array_sum($b)>0) $ageBuckets['series']=$b;
  }

  // intake stages (recent 300)
  $cols="full_name,dob,gender,phone,email,service_address,emergency_contact_name,emergency_contact_relation,emergency_contact_phone,
         caregiver_type,shift_type,start_date,hours_per_day,days_per_week,language_pref,caregiver_gender_pref,tasks_json,
         payer_name,payer_phone,payment_mode,
         consent_data_privacy,consent_treatment,consent_home_visit,consent_emergency_escalation";
  $r=@$con->query("SELECT $cols FROM patients ORDER BY id DESC LIMIT 300");
  $n=0; $sumP=0; $sumC=0; $sumB=0; $sumS=0;
  while($r && ($x=$r->fetch_assoc())){
    $sumP+=pct_from_keys($x,['full_name','dob','gender','phone','email','service_address','emergency_contact_name','emergency_contact_relation','emergency_contact_phone']);
    $sumC+=pct_from_keys($x,['caregiver_type','shift_type','start_date','hours_per_day','days_per_week','language_pref','caregiver_gender_pref','tasks_json']);
    $sumB+=pct_from_keys($x,['payer_name','payer_phone','payment_mode']);
    $sumS+=pct_from_keys($x,['consent_data_privacy','consent_treatment','consent_home_visit','consent_emergency_escalation']);
    $n++;
  }
  if($n){ $stageAvg=['Profile'=>(int)round($sumP/$n),'Care'=>(int)round($sumC/$n),'Billing'=>(int)round($sumB/$n),'Consents'=>(int)round($sumS/$n)]; }
}

/* ---------- list (brief) ---------- */
$listWhere=[]; $types=''; $args=[];
if ($q!==''){ $listWhere[]="(COALESCE(full_name,'') LIKE CONCAT('%',?,'%') OR COALESCE(email,'') LIKE CONCAT('%',?,'%') OR COALESCE(phone,'') LIKE CONCAT('%',?,'%'))"; $types.='sss'; $args[]=$q; $args[]=$q; $args[]=$q; }
if ($status!==''){ $listWhere[]="case_status=?"; $types.='s'; $args[]=$status; }
if ($triage!==''){ $listWhere[]="triage_priority=?"; $types.='s'; $args[]=$triage; }
if ($ctype!==''){ $listWhere[]="caregiver_type=?"; $types.='s'; $args[]=$ctype; }
if ($dfrom!==''){ $listWhere[]="DATE(created_at)>=?"; $types.='s'; $args[]=$dfrom; }
if ($dto!==''){ $listWhere[]="DATE(created_at)<=?"; $types.='s'; $args[]=$dto; }
$totalRows=0; $patients=[];
if (table_exists($con,'patients')) {
  $whereSql=$listWhere?'WHERE '.implode(' AND ',$listWhere):'';
  $st=$con->prepare("SELECT COUNT(*) FROM patients $whereSql"); if($types) $st->bind_param($types,...$args); $st->execute(); $totalRows=(int)$st->get_result()->fetch_row()[0]; $st->close();
  $cols="id,COALESCE(full_name,'') full_name,email,phone,caregiver_type,shift_type,created_at,case_status,service_address";
  $st=$con->prepare("SELECT $cols FROM patients $whereSql ORDER BY id DESC LIMIT ? OFFSET ?");
  if ($types){ $types2=$types.'ii'; $params2=$args; $params2[]=$perPage; $params2[]=$offset; $st->bind_param($types2,...$params2); }
  else { $st->bind_param("ii",$perPage,$offset); }
  $st->execute(); $rs=$st->get_result(); while($rs && ($r=$rs->fetch_assoc())) $patients[]=$r; $st->close();
}
$totalPages=(int)ceil($totalRows/max(1,$perPage));

$sessMap=[]; $payMap=[];
if ($patients){
  $ids=array_column($patients,'id'); $in=implode(',',array_map('intval',$ids));
  if($in && table_exists($con,'service_assignments')){
    $r=@$con->query("SELECT patient_id,COUNT(*) c FROM service_assignments WHERE patient_id IN ($in) AND end_time IS NOT NULL AND start_time>=NOW()-INTERVAL 30 DAY GROUP BY patient_id");
    while($r && ($x=$r->fetch_assoc())) $sessMap[(int)$x['patient_id']]=(int)$x['c'];
  }
  if ($in && table_exists($con,'payments') && column_exists($con,'payments','patient_id')){
    $r=@$con->query("SELECT patient_id,COALESCE(SUM(amount),0) s FROM payments WHERE patient_id IN ($in) AND status IN('paid','completed','success') AND created_at>=NOW()-INTERVAL 30 DAY GROUP BY patient_id");
    while($r && ($x=$r->fetch_assoc())) $payMap[(int)$x['patient_id']]=(float)$x['s'];
  }
}

/* ---------- extras ---------- */
$upcomingStarts=tryQuery(function() use($con){
  if(!table_exists($con,'patients'))return[];
  $r=@$con->query("SELECT id,COALESCE(full_name,'') full_name,start_date,caregiver_type,shift_type FROM patients WHERE start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY) ORDER BY start_date ASC LIMIT 20");
  $out=[]; while($r && ($x=$r->fetch_assoc())) $out[]=$x; return $out;
},[]);
$pairings=tryQuery(function() use($con){
  if(!table_exists($con,'service_assignments')||!table_exists($con,'patients')||!table_exists($con,'caregivers'))return[];
  $sql="SELECT sa.patient_id,COALESCE(p.full_name,p.name,CONCAT('Patient #',p.id)) patient,sa.caregiver_id,CONCAT(c.first_name,' ',c.last_name) caregiver,sa.status FROM service_assignments sa JOIN patients p ON p.id=sa.patient_id JOIN caregivers c ON c.id=sa.caregiver_id WHERE sa.status IN('scheduled','active','ongoing') ORDER BY COALESCE(sa.start_time,NOW()) DESC LIMIT 15";
  $r=@$con->query($sql); $out=[]; while($r && ($x=$r->fetch_assoc())) $out[]=$x; return $out;
},[]);
$reviews=tryQuery(function() use($con){
  if(!table_exists($con,'caregiver_reviews'))return[];
  $r=@$con->query("SELECT r.id,r.patient_id,r.caregiver_id,r.rating,r.created_at FROM caregiver_reviews r ORDER BY r.id DESC LIMIT 8");
  $out=[]; while($r && ($x=$r->fetch_assoc())){
    $p='Patient #'.$x['patient_id']; $c='Caregiver #'.$x['caregiver_id'];
    if(table_exists($con,'patients')){ $q=$con->query("SELECT COALESCE(full_name,name,CONCAT('Patient #',id)) FROM patients WHERE id=".(int)$x['patient_id']." LIMIT 1"); if($q && ($z=$q->fetch_row())) $p=$z[0]; }
    if(table_exists($con,'caregivers')){ $q=$con->query("SELECT CONCAT(first_name,' ',last_name) FROM caregivers WHERE id=".(int)$x['caregiver_id']." LIMIT 1"); if($q && ($z=$q->fetch_row())) $c=$z[0]; }
    $x['patient']=$p; $x['caregiver']=$c; $out[]=$x;
  } return $out;
},[]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Patients — Admin | CGMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-chart-matrix@2.0.1/dist/chartjs-chart-matrix.umd.min.js"></script>

<style>
:root { --pri:#1d4ed8; --ink:#0f172a; --mut:#64748b; --bd:rgba(2,6,23,.08); }
body{ background:#f6f9fc; color:var(--ink); }

/* Sidebar (same look as dashboard) */
.sidebar{ min-height:100vh; background:#0b1220; }
.sidebar a{ color:#cbd5e1; text-decoration:none; display:flex; align-items:center; gap:.6rem; padding:.6rem .9rem; border-radius:.6rem; }
.sidebar a.active, .sidebar a:hover{ background:#111827; color:#fff; }
.brand{ color:#fff; font-weight:800; letter-spacing:.3px; }

/* Cards + typography */
.card-soft{ background:#fff; border:1px solid var(--bd); border-radius:16px; box-shadow:0 12px 34px rgba(2,6,23,.06); }
.kpi{ font-weight:800; font-size:1.6rem; line-height:1; } .kpi-sub{ color:#64748b; font-size:.9rem; }
.pill{ display:inline-flex; align-items:center; gap:.45rem; padding:.28rem .6rem; border:1px solid var(--bd); border-radius:999px; background:#fff; color:#334155; }
.badge-soft{ background:rgba(29,78,216,.08); color:#1d4ed8; border:1px solid rgba(29,78,216,.18); }
.section-title{ font-weight:700; font-size:1rem; }

.table>:not(caption)>*>*{ padding:.58rem .7rem; }
canvas.chart{ width:100% !important; height:240px !important; }
.small-muted{ color:#94a3b8; font-size:.85rem; }
.progress-thin{ height:8px; }
</style>
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar -->
      <div class="col-12 col-md-3 col-lg-2 sidebar p-3">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="brand">CGMS Admin</div>
          <a class="btn btn-sm btn-outline-light" href="<?php echo safe(url('admin/logout.php')); ?>">Logout</a>
        </div>
        <nav class="d-grid gap-1">
          <a href="<?php echo safe(url('admin/dashboard.php')); ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
          <a href="#"><i class="bi bi-people"></i> Caregivers</a>
          <a class="active" href="<?php echo safe(url('admin/patients.php')); ?>"><i class="bi bi-person-heart"></i> Patients</a>
          <a href="#"><i class="bi bi-arrow-left-right"></i> Assignments</a>
          <a href="#"><i class="bi bi-chat-dots"></i> Reviews</a>
          <a href="#"><i class="bi bi-gear"></i> Settings</a>
        </nav>
      </div>

      <!-- Main -->
      <div class="col-12 col-md-9 col-lg-10 p-3 p-md-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h4 class="mb-0">Patients Dashboard</h4>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('admin/dashboard.php')); ?>"><i class="bi bi-speedometer2 me-1"></i> Overview</a>
            <a class="btn btn-sm btn-outline-primary" href="<?php echo safe(url('admin/patients.php?export=csv')); ?>"><i class="bi bi-download me-1"></i> Export CSV</a>
            <button class="btn btn-sm btn-success" id="btnBulkNudge"><i class="bi bi-bell me-1"></i> Bulk Notify Incomplete</button>
          </div>
        </div>

        <!-- KPIs -->
        <div class="row g-3">
          <div class="col-6 col-lg-3"><div class="card-soft p-3 h-100"><div class="d-flex align-items-center gap-2"><span class="pill"><i class="bi bi-people"></i></span><div class="kpi"><?php echo (int)$cards['total']; ?></div></div><div class="kpi-sub">Total Patients</div></div></div>
          <div class="col-6 col-lg-3"><div class="card-soft p-3 h-100"><div class="d-flex align-items-center gap-2"><span class="pill"><i class="bi bi-person-check"></i></span><div class="kpi"><?php echo (int)$cards['active']; ?></div></div><div class="kpi-sub">Verified / Active</div></div></div>
          <div class="col-6 col-lg-3"><div class="card-soft p-3 h-100"><div class="d-flex align-items-center gap-2"><span class="pill"><i class="bi bi-activity"></i></span><div class="kpi"><?php echo (int)$cards['serving']; ?></div></div><div class="kpi-sub">Currently Serving</div></div></div>
          <div class="col-6 col-lg-3"><div class="card-soft p-3 h-100"><div class="d-flex align-items-center justify-content-between"><div><div class="kpi"><?php echo (int)$cards['sessions']; ?></div><div class="kpi-sub">Sessions (<?php echo (int)$range; ?>d)</div></div><div class="text-end"><div class="kpi" style="font-size:1.2rem;"><?php echo $CURRENCY.' '.number_format($cards['paid'],0); ?></div><div class="kpi-sub">Payments (<?php echo (int)$range; ?>d)</div></div></div></div></div>
        </div>

        <!-- Charts -->
        <div class="row g-3 mt-1">
          <div class="col-lg-7">
            <div class="card-soft p-3 h-100">
              <div class="d-flex align-items-center justify-content-between">
                <div><div class="section-title mb-0">Payments & Sessions</div><div class="small-muted">Status: paid / completed / success</div></div>
                <div class="btn-group btn-group-sm">
                  <?php foreach([7,15,30] as $r): ?>
                    <a class="btn <?php echo $r===$range?'btn-primary':'btn-outline-primary'; ?>" href="?<?php echo http_build_query(['range'=>$r,'q'=>$q?:null,'status'=>$status?:null,'triage'=>$triage?:null,'ctype'=>$ctype?:null,'from'=>$dfrom?:null,'to'=>$dto?:null]); ?>"><?php echo $r; ?>d</a>
                  <?php endforeach; ?>
                </div>
              </div>
              <canvas id="paySessChart" class="chart mt-2"></canvas>
            </div>
          </div>

          <div class="col-lg-5">
            <div class="card-soft p-3 h-100">
              <div class="section-title">Registrations (8-week Heatmap)</div>
              <canvas id="regHeat" class="chart mt-2"></canvas>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card-soft p-3 h-100">
              <div class="section-title">Case Status Mix</div>
              <canvas id="casePie" class="chart mt-2"></canvas>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card-soft p-3 h-100">
              <div class="section-title">Triage Priority</div>
              <canvas id="triagePie" class="chart mt-2"></canvas>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card-soft p-3 h-100">
              <div class="d-flex align-items-center justify-content-between">
                <div class="section-title mb-0">Intake Stage Funnel</div>
                <span class="badge-soft">Avg <?php echo (int)round(array_sum($stageAvg)/max(1,count($stageAvg))); ?>%</span>
              </div>
              <canvas id="funnel" class="chart mt-2"></canvas>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card-soft p-3 h-100">
              <div class="section-title">Caregiver Type × Triage</div>
              <canvas id="ctypeStack" class="chart mt-2"></canvas>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card-soft p-3 h-100">
              <div class="section-title">Age Buckets</div>
              <canvas id="ageBar" class="chart mt-2"></canvas>
              <div class="small-muted mt-1">Based on DOB when available</div>
            </div>
          </div>
        </div>

        <!-- Upcoming / Pairings / Reviews -->
        <div class="row g-3 mt-1">
          <div class="col-lg-4">
            <div class="card-soft p-3 h-100">
              <div class="d-flex align-items-center justify-content-between"><div class="section-title mb-0">Upcoming Starts</div><span class="text-muted small"><?php echo count($upcomingStarts); ?></span></div>
              <div class="table-responsive mt-2">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Date</th><th>Patient</th><th>Type</th></tr></thead>
                  <tbody>
                    <?php if(!$upcomingStarts): ?><tr><td colspan="3" class="text-muted">No upcoming start dates in next 14 days.</td></tr>
                    <?php else: foreach($upcomingStarts as $u): ?>
                      <tr><td class="text-muted small"><?php echo safe($u['start_date'] ?: '—'); ?></td><td><?php echo safe($u['full_name'] ?: ('#'.$u['id'])); ?></td><td class="small text-muted"><?php echo safe(ucfirst($u['caregiver_type'] ?? '—')); ?> / <?php echo safe(ucfirst($u['shift_type'] ?? '—')); ?></td></tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card-soft p-3 h-100">
              <div class="d-flex align-items-center justify-content-between"><div class="section-title mb-0">Patients ⇄ Caregivers</div><span class="text-muted small"><?php echo count($pairings); ?></span></div>
              <div class="table-responsive mt-2">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Patient</th><th>Caregiver</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php if(!$pairings): ?><tr><td colspan="3" class="text-muted">No active/scheduled assignments.</td></tr>
                    <?php else: foreach($pairings as $a): ?>
                      <tr>
                        <td><?php echo safe($a['patient']); ?></td>
                        <td><?php echo safe($a['caregiver']); ?></td>
                        <td><span class="badge bg-<?php $s=strtolower($a['status']); echo $s==='active'?'success':($s==='scheduled'?'warning':'secondary'); ?>"><?php echo safe(ucfirst($a['status'])); ?></span></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody></table>
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card-soft p-3 h-100">
              <div class="section-title">Latest Reviews</div>
              <div class="table-responsive mt-2">
                <table class="table align-middle mb-0">
                  <thead><tr><th>When</th><th>Patient</th><th>Caregiver</th><th>Rating</th></tr></thead>
                  <tbody>
                    <?php if(!$reviews): ?><tr><td colspan="4" class="text-muted">No reviews yet.</td></tr>
                    <?php else: foreach($reviews as $rv): ?>
                      <tr>
                        <td class="text-muted small"><?php echo safe(time_ago($rv['created_at'])); ?></td>
                        <td class="small"><?php echo safe($rv['patient']); ?></td>
                        <td class="small"><?php echo safe($rv['caregiver']); ?></td>
                        <td><?php echo (int)$rv['rating']; ?> ★</td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody></table>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters + list -->
        <div class="row g-3 mt-1">
          <div class="col-12">
            <div class="card-soft p-3">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="section-title mb-0">All Patients</div>
                <form class="row g-2 align-items-end" method="get" action="">
                  <input type="hidden" name="range" value="<?php echo (int)$range; ?>">
                  <div class="col-auto">
                    <label class="form-label small mb-1">Search</label>
                    <div class="input-group input-group-sm">
                      <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                      <input class="form-control border-start-0" name="q" placeholder="name / email / phone" value="<?php echo safe($q); ?>">
                    </div>
                  </div>
                  <div class="col-auto">
                    <label class="form-label small mb-1">Case Status</label>
                    <select class="form-select form-select-sm" name="status">
                      <option value="">Any</option>
                      <?php foreach(['new','under_review','verified','active','on_hold','closed'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $status===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <label class="form-label small mb-1">Triage</label>
                    <select class="form-select form-select-sm" name="triage">
                      <option value="">Any</option>
                      <?php foreach(['routine','urgent','high'] as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $triage===$t?'selected':''; ?>><?php echo ucfirst($t); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <label class="form-label small mb-1">Caregiver Type</label>
                    <select class="form-select form-select-sm" name="ctype">
                      <option value="">Any</option>
                      <?php foreach(['nurse','attendant','physiotherapist','therapist','mixed'] as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $ctype===$t?'selected':''; ?>><?php echo ucfirst($t); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?php echo safe($dfrom); ?>"></div>
                  <div class="col-auto"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?php echo safe($dto); ?>"></div>
                  <div class="col-auto">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-filter me-1"></i>Apply</button>
                    <?php if($q!==''||$status!==''||$triage!==''||$ctype!==''||$dfrom!==''||$dto!==''): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('admin/patients.php')); ?>">Reset</a><?php endif; ?>
                  </div>
                </form>
              </div>

              <div class="table-responsive mt-2">
                <table class="table align-middle">
                  <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Type/Shift</th><th>Created</th><th>30d</th><th class="text-end">Actions</th></tr></thead>
                  <tbody>
                    <?php if(!$patients): ?>
                      <tr><td colspan="7" class="text-muted">No patients found.</td></tr>
                    <?php else: foreach($patients as $p):
                      $sid=(int)$p['id']; $sess=(int)($sessMap[$sid]??0); $paid=(float)($payMap[$sid]??0.0);
                    ?>
                      <tr>
                        <td><?php echo $sid; ?></td>
                        <td><div class="fw-semibold"><?php echo safe($p['full_name'] ?: ('Patient #'.$sid)); ?></div><div class="small text-muted"><?php echo safe(ucfirst($p['case_status'] ?? '—')); ?></div></td>
                        <td class="small"><div><i class="bi bi-envelope me-1"></i><?php echo safe($p['email'] ?? ''); ?></div><div><i class="bi bi-telephone me-1"></i><?php echo safe($p['phone'] ?? ''); ?></div></td>
                        <td class="small"><div><?php echo safe(ucfirst($p['caregiver_type'] ?? '—')); ?></div><div class="text-muted"><?php echo safe(ucfirst($p['shift_type'] ?? '—')); ?></div></td>
                        <td class="text-muted small"><?php echo safe(time_ago($p['created_at'] ?? '')); ?></td>
                        <td class="small">
                          <span class="badge bg-primary-subtle text-primary border">Sessions <?php echo $sess; ?></span>
                          <?php if($paid>0): ?><span class="badge bg-success-subtle text-success border ms-1"><?php echo $CURRENCY.' '.number_format($paid,0); ?></span><?php endif; ?>
                        </td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-secondary me-1" onclick="viewPatient(<?php echo $sid; ?>)"><i class="bi bi-person-vcard"></i> Resume</button>
                          <button class="btn btn-sm btn-success" onclick="nudgePatient(<?php echo $sid; ?>)"><i class="bi bi-bell"></i> Notify</button>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>

              <?php if($totalPages>1): ?>
                <nav><ul class="pagination pagination-sm justify-content-end mb-0">
                  <?php for($p=1;$p<=$totalPages;$p++): ?>
                    <li class="page-item <?php echo $p===$page?'active':''; ?>">
                      <a class="page-link" href="?<?php echo http_build_query(['range'=>$range,'q'=>$q?:null,'status'=>$status?:null,'triage'=>$triage?:null,'ctype'=>$ctype?:null,'from'=>$dfrom?:null,'to'=>$dto?:null,'page'=>$p]); ?>"><?php echo $p; ?></a>
                    </li>
                  <?php endfor; ?>
                </ul></nav>
              <?php endif; ?>

            </div>
          </div>
        </div>

        <footer class="text-muted small mt-4">© <?php echo date('Y'); ?> CGMS Admin</footer>
      </div>
    </div>
  </div>

  <!-- Patient Resume Modal -->
  <div class="modal fade" id="ptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Patient Resume</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="ptModalBody">Loading…</div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ===== No-data overlay (handles all chart types) ===== */
const NoData = {
  id:'nodata',
  afterDraw(c, args, opts){
    const cd = c.data?.datasets || [];
    const empty = cd.length===0 || cd.every(ds=>{
      const d = ds.data || [];
      if (!Array.isArray(d) || d.length===0) return true;
      // matrix
      if (typeof d[0]==='object' && d[0] && 'v' in d[0]) return d.every(x => (x?.v||0)===0);
      return d.every(v => (Array.isArray(v) ? (v[1]||0) : (v||0))===0);
    });
    if (!empty) return;
    const {ctx, chartArea} = c; if (!chartArea) return;
    const {left,right,top,bottom} = chartArea;
    ctx.save(); ctx.fillStyle='#9aa4b2'; ctx.font='600 12px system-ui'; ctx.textAlign='center';
    ctx.fillText(opts?.text||'No data', (left+right)/2,(top+bottom)/2); ctx.restore();
  }
};
Chart.register(ChartDataLabels, NoData);

/* ===== Utils ===== */
function grad(ctx, a, b){ const g=ctx.createLinearGradient(0,0,0,240); g.addColorStop(0,a); g.addColorStop(1,b); return g; }
const PALETTE = ['#2563eb','#0ea5e9','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#84cc16'];

/* ===== Charts ===== */
(() => {
  // Payments + Sessions
  const elPS = document.getElementById('paySessChart');
  if (elPS){
    const ctx = elPS.getContext('2d');
    new Chart(elPS,{
      data:{
        labels: <?php echo json_encode($payLabels); ?>,
        datasets:[
          { label:'Payments', type:'bar',
            data: <?php echo json_encode($paySeries, JSON_NUMERIC_CHECK); ?>,
            borderWidth:1, backgroundColor: grad(ctx,'rgba(29,78,216,.35)','rgba(29,78,216,.08)'),
            yAxisID:'y'
          },
          { label:'Sessions', type:'line',
            data: <?php echo json_encode($sessSeries, JSON_NUMERIC_CHECK); ?>,
            borderColor:'rgba(15,118,110,1)', backgroundColor:'rgba(15,118,110,.15)', pointRadius:2, tension:.35,
            yAxisID:'y1'
          }
        ]
      },
      options:{
        plugins:{ legend:{labels:{boxWidth:12}}, datalabels:{display:false}, nodata:{text:'No activity in range'} },
        scales:{
          y:{ beginAtZero:true, position:'left', grid:{color:'rgba(15,23,42,.08)'}, title:{display:true,text:'Amount'} },
          y1:{ beginAtZero:true, position:'right', grid:{drawOnChartArea:false}, title:{display:true,text:'Sessions'} }
        }
      }
    });
  }

  // Heatmap
  const elH = document.getElementById('regHeat');
  if (elH){
    const values = <?php echo json_encode($heatData, JSON_NUMERIC_CHECK); ?>;
    const xLabels = <?php echo json_encode($heatX); ?>;
    const yLabels = <?php echo json_encode($heatY); ?>;
    const maxV = Math.max(1, ...values.map(v=>v.v||0));
    new Chart(elH,{
      type:'matrix',
      data:{ datasets:[{
        label:'Registrations',
        data: values,
        width: ctx => (ctx.chart.chartArea.width / xLabels.length) - 4,
        height: ctx => (ctx.chart.chartArea.height / yLabels.length) - 4,
        backgroundColor: ctx => {
          const v = ctx.raw.v || 0; const t = v / maxV;
          const a = 0.25 + 0.6*t;  // visible even when v=0
          return `rgba(14,165,233,${a})`;
        },
        borderColor:'rgba(15,23,42,.06)', borderWidth:1
      }]},
      options:{
        plugins:{ legend:{display:false}, tooltip:{callbacks:{ label:(c)=>` ${c.raw.v} regs` }}, datalabels:{display:false}, nodata:{text:'No registrations'} },
        scales:{ x:{ type:'category', labels:xLabels, position:'top', grid:{display:false} }, y:{ type:'category', labels:yLabels, reverse:true, grid:{display:false} } }
      }
    });
  }

  // Case status donut
  const casePie = document.getElementById('casePie');
  if (casePie){
    const data = <?php echo json_encode($caseMix['series'], JSON_NUMERIC_CHECK); ?>;
    new Chart(casePie,{ type:'doughnut',
      data:{ labels: <?php echo json_encode($caseMix['labels']); ?>, datasets:[{ data, backgroundColor: PALETTE }] },
      options:{ cutout:'55%', plugins:{ legend:{position:'bottom'},
        datalabels:{ color:'#0f172a', formatter:(v,ctx)=>{ const t=(ctx.dataset.data||[]).reduce((a,b)=>a+Number(b||0),0)||1; return v?Math.round(v/t*100)+'%':''; } },
        nodata:{text:'No data'} } }
    );
  }

  // Triage donut
  const triPie = document.getElementById('triagePie');
  if (triPie){
    const data = <?php echo json_encode($triageMix['series'], JSON_NUMERIC_CHECK); ?>;
    new Chart(triPie,{ type:'doughnut',
      data:{ labels: <?php echo json_encode($triageMix['labels']); ?>, datasets:[{ data, backgroundColor: PALETTE }] },
      options:{ cutout:'55%', plugins:{ legend:{position:'bottom'},
        datalabels:{ color:'#0f172a', formatter:(v,ctx)=>{ const t=(ctx.dataset.data||[]).reduce((a,b)=>a+Number(b||0),0)||1; return v?Math.round(v/t*100)+'%':''; } },
        nodata:{text:'No data'} } }
    );
  }

  // Funnel (horizontal bars)
  const elF = document.getElementById('funnel');
  if (elF){
    const labels = <?php echo json_encode(array_keys($stageAvg)); ?>;
    const vals   = <?php echo json_encode(array_values($stageAvg), JSON_NUMERIC_CHECK); ?>;
    new Chart(elF,{ type:'bar',
      data:{ labels, datasets:[{ data: vals, backgroundColor:'rgba(37,99,235,.45)' }] },
      options:{ indexAxis:'y', elements:{ bar:{ borderRadius:10 } },
        plugins:{ legend:{display:false}, datalabels:{ color:'#0f172a', anchor:'end', align:'right', formatter:(v)=>v+'%' }, tooltip:{enabled:false}, nodata:{text:'No intake data'} },
        scales:{ x:{ beginAtZero:true, max:100, grid:{color:'rgba(15,23,42,.08)'} }, y:{ grid:{display:false} } }
      }
    });
  }

  // Caregiver type × triage (stacked)
  const elS = document.getElementById('ctypeStack');
  if (elS){
    const labels = <?php echo json_encode($ctypeStack['labels']); ?>;
    const series = <?php echo json_encode($ctypeStack['series'], JSON_NUMERIC_CHECK); ?>;
    new Chart(elS,{ type:'bar',
      data:{ labels,
        datasets:[
          {label:'Routine', data: series.Routine || [], backgroundColor:'#60a5fa'},
          {label:'Urgent',  data: series.Urgent  || [], backgroundColor:'#f59e0b'},
          {label:'High',    data: series.High    || [], backgroundColor:'#ef4444'}
        ]
      },
      options:{ indexAxis:'y', plugins:{ datalabels:{ anchor:'end', align:'right', formatter:(v)=>v||'' }, legend:{position:'bottom'}, nodata:{text:'No data'} },
        responsive:true, scales:{ x:{ stacked:true, beginAtZero:true, grid:{color:'rgba(15,23,42,.08)'} }, y:{ stacked:true } } }
    });
  }

  // Age buckets
  const elA = document.getElementById('ageBar');
  if (elA){
    new Chart(elA,{ type:'bar',
      data:{ labels: <?php echo json_encode($ageBuckets['labels']); ?>, datasets:[{ data: <?php echo json_encode($ageBuckets['series'], JSON_NUMERIC_CHECK); ?>, backgroundColor:'#22c55e' }] },
      options:{ plugins:{ legend:{display:false}, datalabels:{ anchor:'end', align:'top', formatter:(v)=>v||'' }, nodata:{text:'No DOBs'} },
        scales:{ y:{ beginAtZero:true, grid:{color:'rgba(15,23,42,.08)'} } } }
    });
  }
})();

/* ===== actions ===== */
function viewPatient(id){
  const body=document.getElementById('ptModalBody'); body.innerHTML='Loading…';
  new bootstrap.Modal(document.getElementById('ptModal')).show();
  fetch('patients.php?ajax=patient_resume&id='+encodeURIComponent(id))
    .then(r=>r.text()).then(html=>body.innerHTML=html)
    .catch(()=>body.innerHTML='<div class="text-danger">Failed to load.</div>');
}
function nudgePatient(id){
  if(!confirm('Send a notification to complete the intake?')) return;
  fetch('patients.php?ajax=nudge_patient',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+encodeURIComponent(id)})
    .then(r=>r.json()).then(j=>alert(j.ok?'Notification sent.':'Failed: '+(j.error||'Unknown error'))).catch(()=>alert('Network error.'));
}
document.getElementById('btnBulkNudge')?.addEventListener('click', ()=>{
  if(!confirm('Send intake reminders to up to 20 recent patients with incomplete profiles?')) return;
  const fd=new FormData(); fd.append('limit','20');
  fetch('patients.php?ajax=bulk_nudge',{method:'POST',body:fd})
    .then(r=>r.json()).then(j=>alert(j.ok?('Sent '+j.sent+' reminder(s).'):'Failed: '+(j.error||'Unknown error')))
    .catch(()=>alert('Network error.'));
});
</script>
</body>
</html>
