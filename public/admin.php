<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\Database;
use App\Core\Security;
Security::requireRole(['tpo','admin']);
$user = Security::currentUser();
$db = Database::connection();
$csrf = Security::csrfToken();
$error = ''; $success = '';
function admin_h(?string $s): string { return App\Core\Security::escape($s); }
function admin_audit(PDO $db, int $actor, string $action, string $entity, ?int $id, array $meta=[]): void {
    $q=$db->prepare('INSERT INTO audit_logs(actor_id,action,entity_type,entity_id,ip_hash,metadata_json) VALUES(?,?,?,?,?,?)');
    $q->execute([$actor,$action,$entity,$id,isset($_SERVER['REMOTE_ADDR'])?hash('sha256',(string)$_SERVER['REMOTE_ADDR']):null,$meta?json_encode($meta,JSON_UNESCAPED_SLASHES):null]);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) { http_response_code(419); $error='Session token expired. Refresh and try again.'; }
    else {
        $action=(string)($_POST['action']??'');
        try {
            $db->beginTransaction();
            if ($action==='user_decision') {
                $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT); $decision=(string)($_POST['decision']??'');
                if (!$id || !in_array($decision,['approve','reject','suspend','reactivate'],true)) throw new InvalidArgumentException('Invalid user decision.');
                $q=$db->prepare("SELECT id,role,status FROM users WHERE id=? AND role IN ('recruiter','student','tpo') FOR UPDATE");$q->execute([$id]);$target=$q->fetch(PDO::FETCH_ASSOC);
                if (!$target) throw new RuntimeException('User not found or not eligible for this action.');
                if ($target['role']==='tpo' && $user['role']!=='admin') throw new RuntimeException('Only an administrator can manage TPO accounts.');
                if ((int)$target['id']===(int)$user['id']) throw new RuntimeException('You cannot change your own account status.');
                $status=match($decision){'approve','reactivate'=>'active','reject'=>'inactive','suspend'=>'suspended'};
                $db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status,$id]);
                admin_audit($db,(int)$user['id'],'user_'.$decision,'user',(int)$id,['role'=>$target['role'],'new_status'=>$status]);
                $db->prepare('INSERT INTO notifications(user_id,title,body) VALUES(?,?,?)')->execute([$id,'Account status updated','Your Campus account status is now '.$status.'.']);
                $success='User account updated.';
            } elseif ($action==='company_decision') {
                $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);$decision=(string)($_POST['decision']??'');
                if(!$id||!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Invalid company decision.');
                $q=$db->prepare('SELECT id,approval_status FROM companies WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetch())throw new RuntimeException('Company not found.');
                $status=$decision==='approve'?'approved':'rejected';$db->prepare('UPDATE companies SET approval_status=? WHERE id=?')->execute([$status,$id]);
                admin_audit($db,(int)$user['id'],'company_'.$decision,'company',(int)$id,['approval_status'=>$status]);
                $q=$db->prepare('SELECT user_id FROM recruiter_profiles WHERE company_id=?');$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid){$db->prepare('INSERT INTO notifications(user_id,title,body) VALUES(?,?,?)')->execute([(int)$uid,'Company review completed','Your company profile was '.$status.'.']);}
                $success='Company review saved.';
            } elseif ($action==='job_decision') {
                $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);$decision=(string)($_POST['decision']??'');
                if(!$id||!in_array($decision,['publish','pause','close','draft'],true))throw new InvalidArgumentException('Invalid job decision.');
                $q=$db->prepare('SELECT j.id,j.status,c.approval_status,c.id AS company_id FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.id=? FOR UPDATE');$q->execute([$id]);$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)throw new RuntimeException('Job not found.');
                if($decision==='publish' && $job['approval_status']!=='approved')throw new RuntimeException('Approve the company before publishing its job.');
                $status=match($decision){'publish'=>'published','pause'=>'paused','close'=>'closed','draft'=>'draft'};
                $db->prepare('UPDATE jobs SET status=? WHERE id=?')->execute([$status,$id]);admin_audit($db,(int)$user['id'],'job_'.$decision,'job',(int)$id,['new_status'=>$status]);
                $success='Job moderation updated.';
            } elseif ($action==='announcement') {
                $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$audience=(string)($_POST['audience']??'all');
                if($title===''||mb_strlen($title)>180||$body===''||mb_strlen($body)>10000||!in_array($audience,['all','students','recruiters','tpo'],true))throw new InvalidArgumentException('Enter a valid title, message and audience.');
                $db->prepare('INSERT INTO announcements(author_id,title,body,audience,published_at) VALUES(?,?,?,?,NOW())')->execute([(int)$user['id'],$title,$body,$audience]);$id=(int)$db->lastInsertId();
                $roles=$audience==='all'?['student','recruiter','tpo']:[$audience==='students'?'student':($audience==='recruiters'?'recruiter':'tpo')];$marks=implode(',',array_fill(0,count($roles),'?'));$q=$db->prepare("SELECT id FROM users WHERE status='active' AND role IN ($marks)");$q->execute($roles);$ins=$db->prepare('INSERT INTO notifications(user_id,title,body) VALUES(?,?,?)');foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid)$ins->execute([(int)$uid,$title,$body]);admin_audit($db,(int)$user['id'],'announcement_published','announcement',$id,['audience'=>$audience]);$success='Announcement published and notifications queued.';
            } else throw new InvalidArgumentException('Unknown action.');
            $db->commit();
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();$error=$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'The action could not be completed. Check the schema and application logs.'; }
    }
}
$stats=[];
foreach ([
 'Students'=>"SELECT COUNT(*) FROM users WHERE role='student'",
 'Active recruiters'=>"SELECT COUNT(*) FROM users WHERE role='recruiter' AND status='active'",
 'Pending users'=>"SELECT COUNT(*) FROM users WHERE status='pending'",
 'Pending companies'=>"SELECT COUNT(*) FROM companies WHERE approval_status='pending'",
 'Published jobs'=>"SELECT COUNT(*) FROM jobs WHERE status='published'",
 'Applications'=>"SELECT COUNT(*) FROM applications WHERE status<>'withdrawn'",
 'Selected students'=>"SELECT COUNT(DISTINCT student_id) FROM applications WHERE status='selected'"
] as $label=>$sql) { try {$stats[$label]=(int)$db->query($sql)->fetchColumn();} catch(Throwable $e) {$stats[$label]='—';} }
$pendingUsers=$db->query("SELECT id,name,email,role,status,created_at FROM users WHERE status='pending' AND role IN ('recruiter','student','tpo') ORDER BY created_at ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$companies=$db->query("SELECT c.id,c.name,c.website,c.industry,c.approval_status,c.created_at,COUNT(rp.user_id) recruiter_count FROM companies c LEFT JOIN recruiter_profiles rp ON rp.company_id=c.id WHERE c.approval_status='pending' GROUP BY c.id ORDER BY c.created_at ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$jobs=$db->query("SELECT j.id,j.title,j.status,j.closes_at,c.name company_name,c.approval_status FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.status IN ('draft','paused','published') ORDER BY j.updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$students=$db->query("SELECT u.id,u.name,u.email,u.status,sp.branch,sp.cgpa,sp.graduation_year,COUNT(a.id) applications,SUM(a.status='selected') selections FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id LEFT JOIN applications a ON a.student_id=u.id WHERE u.role='student' GROUP BY u.id,sp.branch,sp.cgpa,sp.graduation_year ORDER BY u.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$recentAudit=[];try{$recentAudit=$db->query('SELECT al.action,al.entity_type,al.entity_id,al.created_at,u.name actor_name FROM audit_logs al LEFT JOIN users u ON u.id=al.actor_id ORDER BY al.created_at DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){}
$reports=[];try{$reports=$db->query("SELECT COALESCE(sp.branch,'Unspecified') branch,COUNT(DISTINCT u.id) students,COUNT(DISTINCT CASE WHEN a.status='selected' THEN u.id END) selected,COUNT(a.id) applications FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id LEFT JOIN applications a ON a.student_id=u.id WHERE u.role='student' GROUP BY sp.branch ORDER BY students DESC")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Campus · TPO & Admin</title><link rel="stylesheet" href="assets/css/app.css"><style>
.admin-wrap{max-width:1400px;margin:auto;padding:28px 22px 60px}.admin-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px}.admin-head h1{margin:0}.admin-sub{color:#a9a7b8}.admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:20px 0 30px}.admin-stat,.admin-panel{background:#0d0d11;border:1px solid #292832;border-radius:12px;padding:18px}.admin-stat strong{display:block;font-size:28px;margin-top:8px}.admin-panel{margin:18px 0;overflow:auto}.admin-panel h2{font-size:20px;margin:0 0 15px}.admin-table{width:100%;border-collapse:collapse;min-width:680px}.admin-table th,.admin-table td{text-align:left;padding:11px;border-bottom:1px solid #292832;vertical-align:top}.admin-table th{color:#c8c1ff;font-size:12px;text-transform:uppercase;letter-spacing:.05em}.admin-form{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.admin-form input,.admin-form select,.admin-panel input,.admin-panel textarea,.admin-panel select{background:#121216;color:#eee;border:1px solid #383641;border-radius:6px;padding:9px}.admin-btn{border:1px solid #5f57a5;background:#282342;color:#fff;border-radius:6px;padding:8px 11px;cursor:pointer}.admin-btn.danger{border-color:#78383d;background:#35171b}.admin-alert{padding:12px 15px;margin:12px 0;border:1px solid #514b76;background:#171422}.admin-alert.error{border-color:#78383d;background:#291317}.admin-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0}.admin-tabs a{padding:8px 12px;border:1px solid #302e39;border-radius:7px;color:#d8d4ff;text-decoration:none}.admin-tabs a:hover{background:#211d31}@media(max-width:600px){.admin-wrap{padding:18px 10px}.admin-stat strong{font-size:22px}}
</style></head><body><header class="topbar"><a class="brand" href="index.php">CAMPUS <span>PLACEMENT INTELLIGENCE</span></a><nav><a href="index.php">Main portal</a><a href="index.php?page=logout">Sign out</a></nav></header><main class="admin-wrap"><div class="admin-head"><div><p class="admin-sub">CONTROL CENTRE / <?=admin_h(strtoupper((string)$user['role']))?></p><h1>TPO & Admin Portal</h1><p class="admin-sub">Approvals, placements, reports and governance. Data is sourced from Campus records.</p></div><span class="admin-sub">Signed in as <?=admin_h((string)$user['name'])?></span></div>
<?php if($error!==''):?><div class="admin-alert error"><?=admin_h($error)?></div><?php endif;?><?php if($success!==''):?><div class="admin-alert"><?=admin_h($success)?></div><?php endif;?>
<div class="admin-grid"><?php foreach($stats as $label=>$value):?><div class="admin-stat"><span class="admin-sub"><?=admin_h($label)?></span><strong><?=admin_h((string)$value)?></strong></div><?php endforeach;?></div>
<div class="admin-tabs"><a href="#approvals">Approvals</a><a href="#jobs">Job moderation</a><a href="#students">Students</a><a href="#reports">Reports</a><a href="#announcements">Announcements</a><a href="#audit">Audit log</a></div>
<section class="admin-panel" id="approvals"><h2>Pending user accounts</h2><?php if(!$pendingUsers):?><p class="admin-sub">No pending accounts.</p><?php else:?><table class="admin-table"><thead><tr><th>User</th><th>Role</th><th>Created</th><th>Decision</th></tr></thead><tbody><?php foreach($pendingUsers as $r):?><tr><td><?=admin_h($r['name'])?><br><span class="admin-sub"><?=admin_h($r['email'])?></span></td><td><?=admin_h($r['role'])?></td><td><?=admin_h($r['created_at'])?></td><td><form class="admin-form" method="post"><input type="hidden" name="_csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="user_decision"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="admin-btn" name="decision" value="approve">Approve</button><button class="admin-btn danger" name="decision" value="reject">Reject / deactivate</button></form></td></tr><?php endforeach;?></tbody></table><?php endif;?>
<h2 style="margin-top:25px">Pending companies</h2><?php if(!$companies):?><p class="admin-sub">No pending companies.</p><?php else:?><table class="admin-table"><thead><tr><th>Company</th><th>Industry</th><th>Recruiters</th><th>Decision</th></tr></thead><tbody><?php foreach($companies as $c):?><tr><td><?=admin_h($c['name'])?><br><span class="admin-sub"><?=admin_h($c['website']??'')?></span></td><td><?=admin_h($c['industry']??'—')?></td><td><?=(int)$c['recruiter_count']?></td><td><form class="admin-form" method="post"><input type="hidden" name="_csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="company_decision"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="admin-btn" name="decision" value="approve">Approve</button><button class="admin-btn danger" name="decision" value="reject">Reject</button></form></td></tr><?php endforeach;?></tbody></table><?php endif;?></section>
<section class="admin-panel" id="jobs"><h2>Job moderation</h2><table class="admin-table"><thead><tr><th>Job</th><th>Company</th><th>Status</th><th>Moderation</th></tr></thead><tbody><?php foreach($jobs as $j):?><tr><td><?=admin_h($j['title'])?><br><span class="admin-sub">ID <?=(int)$j['id']?></span></td><td><?=admin_h($j['company_name'])?><br><span class="admin-sub">Company: <?=admin_h($j['approval_status'])?></span></td><td><?=admin_h($j['status'])?></td><td><form class="admin-form" method="post"><input type="hidden" name="_csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="job_decision"><input type="hidden" name="id" value="<?=(int)$j['id']?>"><button class="admin-btn" name="decision" value="publish">Publish</button><button class="admin-btn" name="decision" value="pause">Pause</button><button class="admin-btn danger" name="decision" value="close">Close</button></form></td></tr><?php endforeach;?></tbody></table></section>
<section class="admin-panel" id="students"><h2>Student placement overview</h2><table class="admin-table"><thead><tr><th>Student</th><th>Branch</th><th>CGPA</th><th>Applications</th><th>Selected</th><th>Status</th></tr></thead><tbody><?php foreach($students as $s):?><tr><td><?=admin_h($s['name'])?><br><span class="admin-sub"><?=admin_h($s['email'])?></span></td><td><?=admin_h($s['branch']??'Unspecified')?></td><td><?=admin_h((string)($s['cgpa']??'—'))?></td><td><?=(int)$s['applications']?></td><td><?=(int)($s['selections']??0)?></td><td><?=admin_h($s['status'])?></td></tr><?php endforeach;?></tbody></table></section>
<section class="admin-panel" id="reports"><h2>Department-wise report</h2><table class="admin-table"><thead><tr><th>Branch</th><th>Students</th><th>Applications</th><th>Selected students</th><th>Selection rate</th></tr></thead><tbody><?php foreach($reports as $r):$rate=(int)$r['students']?round(((int)$r['selected']/(int)$r['students'])*100,1):0;?><tr><td><?=admin_h($r['branch'])?></td><td><?=(int)$r['students']?></td><td><?=(int)$r['applications']?></td><td><?=(int)$r['selected']?></td><td><?=$rate?>%</td></tr><?php endforeach;?></tbody></table><p class="admin-sub">Selection rate uses distinct students with selected applications; it is not a package or salary statistic.</p></section>
<section class="admin-panel" id="announcements"><h2>Publish announcement</h2><form method="post"><input type="hidden" name="_csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="announcement"><p><label>Title<br><input required maxlength="180" name="title" style="width:min(100%,600px)"></label></p><p><label>Message<br><textarea required maxlength="10000" name="body" rows="4" style="width:min(100%,800px)"></textarea></label></p><p><label>Audience<br><select name="audience"><option value="all">Everyone</option><option value="students">Students</option><option value="recruiters">Recruiters</option><option value="tpo">TPO</option></select></label></p><button class="admin-btn">Publish announcement</button></form></section>
<section class="admin-panel" id="audit"><h2>Recent audit activity</h2><?php if(!$recentAudit):?><p class="admin-sub">No audit entries available yet.</p><?php else:?><table class="admin-table"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>ID</th></tr></thead><tbody><?php foreach($recentAudit as $a):?><tr><td><?=admin_h($a['created_at'])?></td><td><?=admin_h($a['actor_name']??'System')?></td><td><?=admin_h($a['action'])?></td><td><?=admin_h($a['entity_type']??'—')?></td><td><?=admin_h((string)($a['entity_id']??'—'))?></td></tr><?php endforeach;?></tbody></table><?php endif;?></section>
<footer class="admin-sub">Campus administration · All state-changing actions require CSRF validation and are audited.</footer></main></body></html>
