<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['add_coach'])) {
    $n=trim($_POST['full_name']??''); $e=trim($_POST['email']??''); $p=$_POST['password']??'';
    if ($n!==''&&filter_var($e,FILTER_VALIDATE_EMAIL)&&strlen($p)>=6) {
        $h=password_hash($p,PASSWORD_DEFAULT);
        $q=$conn->prepare("INSERT INTO users (full_name,email,password,role) VALUES (?,?,?,'coach')"); $q->bind_param("sss",$n,$e,$h); $q->execute(); $q->close();
        header('Location: staff_coach.php?added=1'); exit;
    }
    header('Location: staff_coach.php?error=invalid_add'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['update_coach'])) {
    $cid=intval($_POST['coach_id']??0); $n=trim($_POST['full_name']??''); $e=trim($_POST['email']??''); $p=$_POST['password']??'';
    if ($cid>0&&$n!==''&&filter_var($e,FILTER_VALIDATE_EMAIL)) {
        if ($p!=='') { if(strlen($p)<6){header('Location: staff_coach.php?error=weak_pass');exit;} $h=password_hash($p,PASSWORD_DEFAULT); $q=$conn->prepare("UPDATE users SET full_name=?,email=?,password=? WHERE id=? AND role='coach'"); $q->bind_param("sssi",$n,$e,$h,$cid); }
        else { $q=$conn->prepare("UPDATE users SET full_name=?,email=? WHERE id=? AND role='coach'"); $q->bind_param("ssi",$n,$e,$cid); }
        $q->execute(); $q->close();
        header('Location: staff_coach.php?updated=1'); exit;
    }
    header('Location: staff_coach.php?error=invalid_update'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['delete_coach'])) {
    $cid=intval($_POST['coach_id']??0);
    if ($cid>0) {
        $u=$conn->prepare("UPDATE users SET coach_id=NULL WHERE coach_id=? AND role='member'"); $u->bind_param("i",$cid); $u->execute(); $u->close();
        $d=$conn->prepare("DELETE FROM users WHERE id=? AND role='coach'"); $d->bind_param("i",$cid); $d->execute(); $d->close();
        header('Location: staff_coach.php?deleted=1'); exit;
    }
    header('Location: staff_coach.php?error=invalid_delete'); exit;
}

$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='coach'"); $total_coaches=(int)$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND coach_id IS NOT NULL AND coach_id<>0"); $total_assigned=(int)$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT u.id,u.full_name,u.email,u.created_at,(SELECT COUNT(*) FROM users m WHERE m.role='member' AND m.coach_id=u.id) AS client_count,(SELECT COUNT(*) FROM users m WHERE m.role='member' AND m.coach_id=u.id AND m.tier='premium') AS premium_clients FROM users u WHERE u.role='coach' ORDER BY u.created_at DESC,u.full_name ASC");
$coaches=$r->fetch_all(MYSQLI_ASSOC); $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_payments=(int)$r->fetch_assoc()['c']; $r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Staff & Coaches</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;--text:#f0f0f0;--sub:#666;--muted:#999;--red:#dc2626;--red-dim:rgba(220,38,38,0.12);--red-mid:#b91c1c;--green:#16a34a;--green-dim:rgba(22,163,74,0.12);--blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);--amber:#d97706;--amber-dim:rgba(217,119,6,0.12);--font:'Inter',sans-serif;--nav-h:56px;--banner-h:140px}
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh}
    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px}
    .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}.nav-logo{width:32px;height:32px;border-radius:6px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
    .nav-brand-text{font-size:14px;font-weight:700;color:var(--text)}.nav-brand-text span{color:var(--red)}
    .nav-divider{width:1px;height:20px;background:var(--border2);flex-shrink:0}
    .nav-links{display:flex;align-items:center;gap:2px;flex:1}
    .nav-link{display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:6px;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;transition:all 0.15s;white-space:nowrap}
    .nav-link:hover{background:var(--card);color:var(--text)}.nav-link.active{background:var(--red-dim);color:var(--red);font-weight:600}
    .nav-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:8px;margin-left:2px}
    .nav-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}
    .nav-time{font-size:12px;color:var(--sub)}
    .nav-admin-chip{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border2);border-radius:20px;padding:4px 12px 4px 5px}
    .nav-avatar{width:24px;height:24px;border-radius:50%;background:var(--red);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
    .nav-admin-name{font-size:12px;font-weight:600}
    .nav-logout{font-size:12px;color:var(--sub);text-decoration:none;padding:5px 9px;border-radius:5px;border:1px solid var(--border);transition:all 0.15s}
    .nav-logout:hover{color:var(--text);border-color:var(--border2)}
    .banner{position:relative;height:var(--banner-h);overflow:hidden;border-bottom:1px solid var(--border)}
    .banner-fallback{position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),repeating-linear-gradient(0deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),linear-gradient(135deg,#130303 0%,#1c0505 50%,#0d0d0d 100%)}
    .banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(13,13,13,0.88) 0%,rgba(13,13,13,0.5) 55%,rgba(13,13,13,0.15) 100%)}
    .banner-content{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;padding:0 40px}
    .banner-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
    .banner-title{font-size:22px;font-weight:700;letter-spacing:-0.5px}.banner-sub{font-size:13px;color:var(--muted);margin-top:5px}
    .banner-right{text-align:right}.banner-stat-val{font-size:22px;font-weight:700;line-height:1}.banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}
    .wrapper{max-width:1400px;margin:0 auto;padding:24px 32px}
    .alert-green{background:rgba(22,163,74,0.12);border:1px solid rgba(22,163,74,0.2);color:#4ade80;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .alert-red{background:rgba(220,38,38,0.12);border:1px solid rgba(220,38,38,0.2);color:#f87171;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px}
    .scard{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:18px 20px;display:flex;align-items:center;gap:14px}
    .scard-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .ic-blue{background:var(--blue-dim)}.ic-green{background:var(--green-dim)}
    .scard-val{font-size:26px;font-weight:700;letter-spacing:-0.5px;line-height:1}
    .scard-lbl{font-size:12px;color:var(--sub);margin-top:3px;font-weight:500}
    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-body{padding:16px 20px}
    .section-empty{padding:28px 20px;text-align:center;font-size:13px;color:var(--sub)}
    .add-form{display:grid;grid-template-columns:2fr 2fr 2fr auto;gap:8px;align-items:end}
    .field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);margin-bottom:5px}
    .field input{width:100%;background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:9px 12px;font-family:var(--font);font-size:13px;color:var(--text);outline:none;transition:border-color 0.15s}
    .field input:focus{border-color:#60a5fa}
    .pill{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px}
    .pill.blue{background:var(--blue-dim);color:#60a5fa}.pill.green{background:var(--green-dim);color:#4ade80}
    table{width:100%;border-collapse:collapse}
    thead th{text-align:left;padding:12px 0 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);border-bottom:1px solid var(--border)}
    tbody td{padding:11px 0;font-size:13px;border-bottom:1px solid var(--border);color:var(--muted);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    .td-name{font-weight:600;font-size:13px;color:var(--text)}.td-email{font-size:11px;color:var(--sub);margin-top:1px}
    .inline-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
    .inline-input{background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:7px 10px;font-family:var(--font);font-size:12px;color:var(--text);outline:none}
    .inline-input:focus{border-color:#60a5fa}
    .btn{border:1px solid var(--border2);background:transparent;color:var(--muted);border-radius:6px;padding:7px 12px;font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all 0.15s}
    .btn:hover{color:var(--text);border-color:var(--border2)}
    .btn.green{border-color:var(--green-dim)}.btn.green:hover{border-color:#4ade80;color:#4ade80;background:var(--green-dim)}
    .btn.red:hover{border-color:#f87171;color:#f87171;background:var(--red-dim)}
    .btn.add{background:#2563eb;color:#fff;border-color:#2563eb}.btn.add:hover{opacity:0.85}
    @media(max-width:900px){.add-form{grid-template-columns:1fr}.stats-grid{grid-template-columns:1fr}}
    @media(max-width:768px){.nav-links{display:none}.wrapper{padding:20px 16px}.banner-content{padding:0 20px}.banner-right{display:none}}
  </style>
</head>
<body>

<nav class="topnav">
  <a href="admin.php" class="nav-brand"><div class="nav-logo">FX</div><div class="nav-brand-text">FitX <span>Admin</span></div></a>
  <div class="nav-divider"></div>
  <div class="nav-links">
    <a href="admin.php"            class="nav-link">⊞ Dashboard</a>
    <a href="all_members.php"      class="nav-link">👥 Members</a>
    <a href="coach_assignment.php" class="nav-link">🏆 Coaches</a>
    <a href="payments.php"         class="nav-link">💳 Payments <?php if($pending_payments>0): ?><span class="nav-badge"><?= $pending_payments ?></span><?php endif; ?></a>
    <a href="staff_coach.php"      class="nav-link active">🧑‍🏫 Staff</a>
    <a href="analytics.php"        class="nav-link">📈 Analytics</a>
    <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
    <a href="admin_messages.php"   class="nav-link">💬 Messages</a>
    <a href="settings.php"         class="nav-link">⚙️ Settings</a>
  </div>
  <div class="nav-right">
    <span class="nav-time" id="live-time"></span>
    <div class="nav-admin-chip"><div class="nav-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div><span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span></div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<div class="banner">
  <div class="banner-fallback"></div><div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin — Manage</div>
      <div class="banner-title">Staff &amp; Coaches</div>
      <div class="banner-sub">Create, update, and remove coach accounts</div>
    </div>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#fbbf24"><?= $total_coaches ?></div>
      <div class="banner-stat-lbl">Total coaches</div>
    </div>
  </div>
</div>

<div class="wrapper">
  <?php if(isset($_GET['added'])): ?><div class="alert-green">✓ Coach added successfully.</div><?php endif; ?>
  <?php if(isset($_GET['updated'])): ?><div class="alert-green">✓ Coach updated successfully.</div><?php endif; ?>
  <?php if(isset($_GET['deleted'])): ?><div class="alert-green">✓ Coach deleted and members unassigned.</div><?php endif; ?>
  <?php if(isset($_GET['error'])): ?><div class="alert-red">Action failed. Check input and try again.</div><?php endif; ?>

  <div class="stats-grid">
    <div class="scard"><div class="scard-icon ic-blue">🧑‍🏫</div><div><div class="scard-val" style="color:#fbbf24"><?= $total_coaches ?></div><div class="scard-lbl">Total Coaches</div></div></div>
    <div class="scard"><div class="scard-icon ic-green">🔗</div><div><div class="scard-val" style="color:#4ade80"><?= $total_assigned ?></div><div class="scard-lbl">Members Assigned to Coaches</div></div></div>
  </div>

  <!-- Add Coach -->
  <div class="section">
    <div class="section-head"><span class="section-title">Add New Coach</span></div>
    <div class="section-body">
      <form method="POST" action="staff_coach.php" class="add-form">
        <input type="hidden" name="add_coach" value="1"/>
        <div class="field"><label>Full Name</label><input type="text" name="full_name" placeholder="Full name" required/></div>
        <div class="field"><label>Email</label><input type="email" name="email" placeholder="Email address" required/></div>
        <div class="field"><label>Password</label><input type="password" name="password" placeholder="Min 6 characters" minlength="6" required/></div>
        <div class="field"><label>&nbsp;</label><button type="submit" class="btn add" style="width:100%;padding:9px;">Add Coach</button></div>
      </form>
    </div>
  </div>

  <!-- Coach Directory -->
  <div class="section">
    <div class="section-head"><span class="section-title">Coach Directory</span><span style="font-size:12px;color:var(--sub)"><?= $total_coaches ?> coaches</span></div>
    <?php if(empty($coaches)): ?>
      <div class="section-empty">No coach accounts yet.</div>
    <?php else: ?>
    <div class="section-body">
      <table>
        <thead><tr><th>Coach</th><th>Clients</th><th>Premium</th><th>Joined</th><th>Update</th><th>Delete</th></tr></thead>
        <tbody>
          <?php foreach($coaches as $c): ?>
          <tr>
            <td><div class="td-name"><?= htmlspecialchars($c['full_name']) ?></div><div class="td-email"><?= htmlspecialchars($c['email']) ?></div></td>
            <td><span class="pill blue"><?= (int)$c['client_count'] ?></span></td>
            <td><span class="pill green"><?= (int)$c['premium_clients'] ?></span></td>
            <td style="font-size:12px;"><?= !empty($c['created_at']) ? date('M j, Y',strtotime($c['created_at'])) : '—' ?></td>
            <td>
              <form method="POST" action="staff_coach.php" class="inline-form">
                <input type="hidden" name="update_coach" value="1"/><input type="hidden" name="coach_id" value="<?= (int)$c['id'] ?>"/>
                <input type="text"     class="inline-input" name="full_name" value="<?= htmlspecialchars($c['full_name']) ?>" required style="width:130px;"/>
                <input type="email"    class="inline-input" name="email"     value="<?= htmlspecialchars($c['email']) ?>"     required style="width:160px;"/>
                <input type="password" class="inline-input" name="password"  placeholder="New pass (optional)" style="width:150px;"/>
                <button type="submit" class="btn green">Save</button>
              </form>
            </td>
            <td>
              <form method="POST" action="staff_coach.php" onsubmit="return confirm('Delete this coach? Assigned members will be unassigned.');">
                <input type="hidden" name="delete_coach" value="1"/><input type="hidden" name="coach_id" value="<?= (int)$c['id'] ?>"/>
                <button type="submit" class="btn red">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);
</script>
</body>
</html>