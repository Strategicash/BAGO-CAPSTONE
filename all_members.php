<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_member'])) {
    $uid = intval($_POST['user_id'] ?? 0);
    if ($uid) { $s=$conn->prepare("DELETE FROM users WHERE id=? AND role='member'"); $s->bind_param("i",$uid); $s->execute(); $s->close(); }
    header('Location: all_members.php?deleted=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade_member'])) {
    $uid = intval($_POST['user_id'] ?? 0);
    if ($uid) { $s=$conn->prepare("UPDATE users SET tier='premium' WHERE id=?"); $s->bind_param("i",$uid); $s->execute(); $s->close(); }
    header('Location: all_members.php?upgraded=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['downgrade_member'])) {
    $uid = intval($_POST['user_id'] ?? 0);
    if ($uid) { $s=$conn->prepare("UPDATE users SET tier='basic', coach_id=NULL WHERE id=?"); $s->bind_param("i",$uid); $s->execute(); $s->close(); }
    header('Location: all_members.php?downgraded=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_coach'])) {
    $mid=intval($_POST['member_id']??0); $cid=intval($_POST['coach_id']??0);
    if ($mid&&$cid) {
        $s=$conn->prepare("UPDATE users SET coach_id=?, tier='premium' WHERE id=?"); $s->bind_param("ii",$cid,$mid); $s->execute(); $s->close();
        $s=$conn->prepare("UPDATE payments SET paid_at=NOW(), expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY) WHERE user_id=? AND status='paid' AND expires_at IS NULL ORDER BY id DESC LIMIT 1"); $s->bind_param("i",$mid); $s->execute(); $s->close();
    }
    header('Location: all_members.php?assigned=1'); exit;
}

$filter_tier  = $_GET['tier']   ?? 'all';
$filter_level = $_GET['level']  ?? 'all';
$search       = trim($_GET['search'] ?? '');

$where='u.role=\'member\''; $params=[]; $types='';
if ($filter_tier!=='all')  { $where.=" AND u.tier=?"; $params[]=$filter_tier; $types.='s'; }
if ($filter_level!=='all') { $where.=" AND u.fitness_level=?"; $params[]=$filter_level; $types.='s'; }
if ($search!=='')          { $where.=" AND (u.full_name LIKE ? OR u.email LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $types.='ss'; }

$sql="SELECT u.id,u.full_name,u.email,u.tier,u.fitness_level,u.created_at,c.full_name AS coach_name,(SELECT COUNT(*) FROM workout_logs WHERE user_id=u.id) AS total_workouts,(SELECT COUNT(*) FROM workout_logs WHERE user_id=u.id AND MONTH(logged_at)=MONTH(NOW())) AS workouts_month,(SELECT recorded_at FROM body_metrics WHERE user_id=u.id ORDER BY recorded_at DESC LIMIT 1) AS last_metric FROM users u LEFT JOIN users c ON c.id=u.coach_id WHERE $where ORDER BY u.created_at DESC";
if ($params) { $r=$conn->prepare($sql); $r->bind_param($types,...$params); $r->execute(); $members=$r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close(); }
else { $members=$conn->query($sql)->fetch_all(MYSQLI_ASSOC); }

$r=$conn->query("SELECT id,full_name,(SELECT COUNT(*) FROM users WHERE coach_id=u.id AND role='member') AS client_count FROM users u WHERE role='coach' ORDER BY full_name");
$all_coaches=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member'"); $total_members=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='premium'"); $total_premium=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='basic'"); $total_basic=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='premium' AND (coach_id IS NULL OR coach_id=0)"); $unassigned=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_payments=$r->fetch_assoc()['c']; $r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — All Members</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;--text:#f0f0f0;--sub:#666;--muted:#999;--red:#dc2626;--red-dim:rgba(220,38,38,0.12);--red-mid:#b91c1c;--green:#16a34a;--green-dim:rgba(22,163,74,0.12);--blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);--amber:#d97706;--amber-dim:rgba(217,119,6,0.12);--font:'Inter',sans-serif;--nav-h:56px;--banner-h:140px}
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh}
    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px}
    .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}
    .nav-logo{width:32px;height:32px;border-radius:6px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
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
    .bell-wrap{position:relative}
    .bell-btn{background:var(--card);border:1px solid var(--border2);border-radius:7px;padding:6px 9px;cursor:pointer;color:var(--muted);font-size:14px;display:flex;align-items:center;transition:all 0.15s;position:relative}
    .bell-count{position:absolute;top:-5px;right:-5px;background:var(--red);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:8px;border:1.5px solid var(--surface)}
    .notif-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;width:300px;background:var(--card);border:1px solid var(--border2);border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,0.5);z-index:200;overflow:hidden}
    .notif-dropdown.open{display:block}
    .notif-hd{padding:11px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .notif-hd-title{font-size:12px;font-weight:700}.notif-hd-link{font-size:11px;color:#60a5fa;text-decoration:none;font-weight:600}
    .notif-row{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;gap:8px}
    .notif-row:last-child{border-bottom:none}
    .notif-nm{font-size:12px;font-weight:600}.notif-ds{font-size:11px;color:var(--sub)}.notif-tm{font-size:10px;color:var(--sub);margin-top:1px}
    .notif-empty{padding:18px;text-align:center;font-size:12px;color:var(--sub)}
    
    
    
   /* BANNER — IMAGE SLOT */
    .banner { position: relative; height: var(--banner-h); overflow: hidden; border-bottom: 1px solid var(--border); }
    .banner-img { width: 100%; height: 100%; object-fit: cover; opacity: 0.4; display: block; }
    .banner-fallback {
      position: absolute; inset: 0;
      background:
        repeating-linear-gradient(90deg, rgba(220,38,38,0.05) 0, rgba(220,38,38,0.05) 1px, transparent 1px, transparent 64px),
        repeating-linear-gradient(0deg,  rgba(220,38,38,0.05) 0, rgba(220,38,38,0.05) 1px, transparent 1px, transparent 64px),
        linear-gradient(135deg, #130303 0%, #1c0505 50%, #0d0d0d 100%);
    }
    .banner-overlay { position: absolute; inset: 0; background: linear-gradient(to right, rgba(13,13,13,0.88) 0%, rgba(13,13,13,0.5) 55%, rgba(13,13,13,0.15) 100%); }
    .banner-content { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; }
    .banner-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--red); margin-bottom: 7px; }
    .banner-title { font-size: 28px; font-weight: 700; letter-spacing: -0.5px; line-height: 1.1; }
    .banner-sub { font-size: 13px; color: var(--muted); margin-top: 6px; }
    .banner-right { text-align: right; }
    .banner-rev-val { font-size: 26px; font-weight: 700; color: var(--text); line-height: 1; }
    .banner-rev-lbl { font-size: 11px; color: var(--sub); margin-top: 3px; }
    .banner-right{text-align:right}.banner-stat-val{font-size:22px;font-weight:700;line-height:1}.banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}
    
    .wrapper{max-width:1400px;margin:0 auto;padding:24px 32px}
    .alert-green{background:rgba(22,163,74,0.12);border:1px solid rgba(22,163,74,0.2);color:#4ade80;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .alert-amber{background:rgba(217,119,6,0.12);border:1px solid rgba(217,119,6,0.2);color:#fbbf24;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .alert-red{background:rgba(220,38,38,0.12);border:1px solid rgba(220,38,38,0.2);color:#f87171;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
    .scard{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:border-color 0.15s}
    .scard:hover{border-color:var(--border2)}
    .scard-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .ic-blue{background:var(--blue-dim)}.ic-green{background:var(--green-dim)}.ic-amber{background:var(--amber-dim)}.ic-red{background:var(--red-dim)}
    .scard-val{font-size:26px;font-weight:700;letter-spacing:-0.5px;line-height:1}
    .scard-lbl{font-size:12px;color:var(--sub);margin-top:3px;font-weight:500}
    .scard-note{font-size:11px;color:var(--muted);margin-top:2px}
    .scard-note.warn{color:#f87171}
    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-body{padding:0 20px 16px}
    .section-empty{padding:28px 20px;text-align:center;font-size:13px;color:var(--sub)}
    .pill{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px}
    .pill.premium{background:var(--blue-dim);color:#60a5fa}.pill.basic{background:var(--border);color:var(--sub)}
    .pill.assigned{background:var(--green-dim);color:#4ade80}.pill.unassigned{background:var(--red-dim);color:#f87171}
    .pill.beginner{background:var(--green-dim);color:#4ade80}.pill.intermediate{background:var(--amber-dim);color:#fbbf24}.pill.advanced{background:var(--red-dim);color:#f87171}
    table{width:100%;border-collapse:collapse}
    thead th{text-align:left;padding:12px 0 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);border-bottom:1px solid var(--border)}
    tbody td{padding:11px 0;font-size:13px;border-bottom:1px solid var(--border);color:var(--muted);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    tbody tr:hover td{background:rgba(255,255,255,0.01)}
    .td-name{font-weight:600;font-size:13px;color:var(--text)}.td-email{font-size:11px;color:var(--sub);margin-top:1px}
    .filter-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .filter-select{background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:7px 10px;font-family:var(--font);font-size:12px;color:var(--text);outline:none}
    .search-input{background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:7px 12px;font-family:var(--font);font-size:12px;color:var(--text);outline:none;width:200px}
    .btn-filter{background:#2563eb;color:#fff;border:none;border-radius:6px;padding:7px 14px;font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer}
    .btn-reset{background:transparent;color:var(--sub);border:1px solid var(--border);border-radius:6px;padding:7px 12px;font-family:var(--font);font-size:12px;cursor:pointer;text-decoration:none}
    .btn-sm{border:1px solid var(--border2);background:transparent;color:var(--muted);border-radius:5px;padding:4px 10px;font-family:var(--font);font-size:11px;font-weight:600;cursor:pointer;transition:all 0.15s}
    .btn-sm:hover{border-color:#60a5fa;color:#60a5fa;background:var(--blue-dim)}
    .btn-sm.danger:hover{border-color:#f87171;color:#f87171;background:var(--red-dim)}
    .btn-sm.success:hover{border-color:#4ade80;color:#4ade80;background:var(--green-dim)}
    .actions{display:flex;gap:5px}
    .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:300;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
    .overlay.open{display:flex}
    .modal{background:var(--card);border:1px solid var(--border2);border-radius:10px;padding:26px;width:400px;max-width:92vw}
    .modal-tag{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
    .modal h3{font-size:16px;font-weight:700;margin-bottom:4px}.modal .sub{font-size:12px;color:var(--sub);margin-bottom:18px}
    .field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);margin-bottom:5px}
    .field select{width:100%;background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:9px 11px;font-family:var(--font);font-size:13px;color:var(--text);outline:none}
    .field select:focus{border-color:#60a5fa}
    .modal-btns{display:flex;gap:8px;margin-top:18px}
    .btn-confirm{flex:1;background:#2563eb;color:#fff;border:none;border-radius:6px;padding:10px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer}
    .btn-danger{flex:1;background:var(--red);color:#fff;border:none;border-radius:6px;padding:10px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer}
    .btn-cancel{background:transparent;color:var(--sub);border:1px solid var(--border2);border-radius:6px;padding:10px 16px;font-family:var(--font);font-size:13px;cursor:pointer}
    .btn-cancel:hover{color:var(--text)}
    @media(max-width:1100px){.stats-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:768px){.nav-links{display:none}.wrapper{padding:20px 16px}.banner-content{padding:0 20px}.banner-right{display:none}}
  </style>
</head>
<body>

<nav class="topnav">
  <a href="admin.php" class="nav-brand"><div class="nav-logo">FX</div><div class="nav-brand-text">FitX <span>Admin</span></div></a>
  <div class="nav-divider"></div>
 <div class="nav-links">
  <a href="admin.php"                 class="nav-link">⊞ Dashboard</a>
  <a href="all_members.php"           class="nav-link">👥 Members</a>
  <a href="payments.php"              class="nav-link">💳 Payments</a>
  <a href="admin_enroll_rfid.php"     class="nav-link">📶 Enroll RFID</a>
  <a href="admin_checkin.php"         class="nav-link">🚪 Check-In Kiosk</a>
  <a href="analytics.php"             class="nav-link">📈 Analytics</a>
  <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
  <a href="admin_access_codes.php" class="nav-link">🔑 Access Codes</a>
  <a href="settings.php"              class="nav-link">⚙️ Settings</a>
</div>
  <div class="nav-right">
    <span class="nav-time" id="live-time"></span>
    <div class="nav-admin-chip"><div class="nav-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div><span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span></div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<div class="banner">
  <img class="banner-img" src="equipment.jpg.jpg" alt="">
  
  <div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin — Overview</div>
      <div class="banner-title">All Members</div>
      <div class="banner-sub"><?= count($members) ?> result<?= count($members)!==1?'s':'' ?> shown</div>
    </div>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#60a5fa"><?= $total_members ?></div>
      <div class="banner-stat-lbl">Total registered</div>
    </div>
  </div>
</div>

<div class="wrapper">
  <?php if(isset($_GET['assigned'])): ?><div class="alert-green">✓ Coach assigned successfully!</div><?php endif; ?>
  <?php if(isset($_GET['upgraded'])): ?><div class="alert-green">✓ Member upgraded to Premium!</div><?php endif; ?>
  <?php if(isset($_GET['downgraded'])): ?><div class="alert-amber">↓ Member downgraded to Basic.</div><?php endif; ?>
  <?php if(isset($_GET['deleted'])): ?><div class="alert-red">✓ Member removed.</div><?php endif; ?>

  <div class="stats-grid">
    <div class="scard"><div class="scard-icon ic-blue">👥</div><div><div class="scard-val" style="color:#60a5fa"><?= $total_members ?></div><div class="scard-lbl">Total Members</div><div class="scard-note">All registered</div></div></div>
    <div class="scard"><div class="scard-icon ic-green">⭐</div><div><div class="scard-val" style="color:#4ade80"><?= $total_premium ?></div><div class="scard-lbl">Premium</div><div class="scard-note">With coaching</div></div></div>
    <div class="scard"><div class="scard-icon ic-amber">🔓</div><div><div class="scard-val" style="color:#fbbf24"><?= $total_basic ?></div><div class="scard-lbl">Basic</div><div class="scard-note">Free tier</div></div></div>
    <div class="scard"><div class="scard-icon ic-red">⚠️</div><div><div class="scard-val" style="color:#f87171"><?= $unassigned ?></div><div class="scard-lbl">Unassigned Premium</div><div class="scard-note <?= $unassigned>0?'warn':'' ?>"><?= $unassigned>0?'Needs coach':'All assigned' ?></div></div></div>
  </div>

  <div class="section">
    <div class="section-head">
      <span class="section-title">Members</span>
      <div class="filter-bar">
        <form method="GET" action="all_members.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="text" name="search" class="search-input" placeholder="Search name or email..." value="<?= htmlspecialchars($search) ?>"/>
          <select name="tier" class="filter-select">
            <option value="all" <?= $filter_tier==='all'?'selected':'' ?>>All Tiers</option>
            <option value="basic"   <?= $filter_tier==='basic'?'selected':'' ?>>Basic</option>
            <option value="premium" <?= $filter_tier==='premium'?'selected':'' ?>>Premium</option>
          </select>
          <select name="level" class="filter-select">
            <option value="all"          <?= $filter_level==='all'?'selected':'' ?>>All Levels</option>
            <option value="beginner"     <?= $filter_level==='beginner'?'selected':'' ?>>Beginner</option>
            <option value="intermediate" <?= $filter_level==='intermediate'?'selected':'' ?>>Intermediate</option>
            <option value="advanced"     <?= $filter_level==='advanced'?'selected':'' ?>>Advanced</option>
          </select>
          <button type="submit" class="btn-filter">Search</button>
          <a href="all_members.php" class="btn-reset">Reset</a>
        </form>
      </div>
    </div>
    <div class="section-body" style="overflow-x:auto;">
      <table>
        <thead><tr><th>Member</th><th>Level</th><th>Tier</th><th>Coach</th><th>Workouts</th><th>Last Metric</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($members)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--sub);padding:24px 0;">No members found.</td></tr>
          <?php else: foreach($members as $m): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;border-radius:50%;background:var(--blue-dim);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;"><?= strtoupper(substr($m['full_name'],0,1)) ?></div>
                <div><div class="td-name"><?= htmlspecialchars($m['full_name']) ?></div><div class="td-email"><?= htmlspecialchars($m['email']) ?></div></div>
              </div>
            </td>
            <td><span class="pill <?= $m['fitness_level'] ?>"><?= ucfirst($m['fitness_level']) ?></span></td>
            <td><span class="pill <?= $m['tier'] ?>"><?= ucfirst($m['tier']) ?></span></td>
            <td>
              <?php if($m['coach_name']): ?><span class="pill assigned"><?= htmlspecialchars($m['coach_name']) ?></span>
              <?php elseif($m['tier']==='premium'): ?><span class="pill unassigned">Unassigned</span>
              <?php else: ?><span style="color:var(--sub);font-size:12px;">—</span><?php endif; ?>
            </td>
            <td><div style="font-size:13px;color:var(--text);font-weight:600;"><?= $m['total_workouts'] ?></div><div style="font-size:11px;color:var(--sub)"><?= $m['workouts_month'] ?> this month</div></td>
            <td style="font-size:12px;"><?= $m['last_metric'] ? date('M j, Y',strtotime($m['last_metric'])) : '<span style="color:var(--sub)">—</span>' ?></td>
            <td style="font-size:12px;"><?= date('M j, Y',strtotime($m['created_at'])) ?></td>
            <td>
              <div class="actions">
                <?php if($m['tier']==='premium'&&!$m['coach_name']): ?>
                  <button class="btn-sm" onclick="openAssign(<?= $m['id'] ?>,'<?= htmlspecialchars($m['full_name'],ENT_QUOTES) ?>')">Assign</button>
                <?php endif; ?>
                <?php if($m['tier']==='basic'): ?>
                  <form method="POST" style="display:inline;"><input type="hidden" name="upgrade_member" value="1"/><input type="hidden" name="user_id" value="<?= $m['id'] ?>"/><button type="submit" class="btn-sm success">↑ Premium</button></form>
                <?php else: ?>
                  <form method="POST" style="display:inline;"><input type="hidden" name="downgrade_member" value="1"/><input type="hidden" name="user_id" value="<?= $m['id'] ?>"/><button type="submit" class="btn-sm" onclick="return confirm('Downgrade to Basic?')">↓ Basic</button></form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ASSIGN MODAL -->
<div class="overlay" id="assign-modal">
  <div class="modal">
    <div class="modal-tag">Coach Assignment</div>
    <h3>Assign Coach</h3>
    <p class="sub">Member: <strong id="assign-name" style="color:var(--text)"></strong></p>
    <form method="POST" action="all_members.php">
      <input type="hidden" name="assign_coach" value="1"/><input type="hidden" name="member_id" id="assign-member-id"/>
      <div class="field" style="margin-bottom:12px;"><label>Select Coach</label>
        <select name="coach_id" required>
          <option value="" disabled selected>Select a coach</option>
          <?php foreach($all_coaches as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?> (<?= $c['client_count'] ?> clients)</option><?php endforeach; ?>
          <?php if(empty($all_coaches)): ?><option disabled>No coaches available</option><?php endif; ?>
        </select>
      </div>
      <div class="modal-btns"><button type="submit" class="btn-confirm">Confirm Assignment</button><button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button></div>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="overlay" id="delete-modal">
  <div class="modal">
    <div class="modal-tag">Danger</div>
    <h3 style="color:#f87171;">Remove Member</h3>
    <p class="sub">This permanently deletes <strong id="delete-name" style="color:var(--text)"></strong> and all their data.</p>
    <form method="POST" action="all_members.php">
      <input type="hidden" name="delete_member" value="1"/><input type="hidden" name="user_id" id="delete-member-id"/>
      <div class="modal-btns"><button type="submit" class="btn-danger">Yes, Remove</button><button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button></div>
    </form>
  </div>
</div>

<script>
  function openAssign(id,name){document.getElementById('assign-name').textContent=name;document.getElementById('assign-member-id').value=id;document.getElementById('assign-modal').classList.add('open');}
  function openDelete(id,name){document.getElementById('delete-name').textContent=name;document.getElementById('delete-member-id').value=id;document.getElementById('delete-modal').classList.add('open');}
  function closeModals(){document.getElementById('assign-modal').classList.remove('open');document.getElementById('delete-modal').classList.remove('open');}
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);
</script>
</body>
</html>