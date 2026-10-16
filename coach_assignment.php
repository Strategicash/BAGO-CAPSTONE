<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['assign_coach'])) {
    $mid=intval($_POST['member_id']??0); $cid=intval($_POST['coach_id']??0);
    if ($mid&&$cid) {
        $s=$conn->prepare("UPDATE users SET coach_id=? WHERE id=?"); $s->bind_param("ii",$cid,$mid); $s->execute(); $s->close();
        $s=$conn->prepare("UPDATE payments SET paid_at=NOW(), expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY) WHERE user_id=? AND status='paid' AND expires_at IS NULL ORDER BY id DESC LIMIT 1"); $s->bind_param("i",$mid); $s->execute(); $s->close();
    }
    header('Location: coach_assignment.php?assigned=1'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['unassign_coach'])) {
    $mid=intval($_POST['member_id']??0);
    if ($mid) { $s=$conn->prepare("UPDATE users SET coach_id=NULL WHERE id=?"); $s->bind_param("i",$mid); $s->execute(); $s->close(); }
    header('Location: coach_assignment.php?unassigned=1'); exit;
}

$r=$conn->query("SELECT u.id,u.full_name,u.email,u.fitness_level,u.created_at FROM users u WHERE u.role='member' AND u.tier='premium' AND (u.coach_id IS NULL OR u.coach_id=0) ORDER BY u.created_at ASC");
$unassigned_members=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$r=$conn->query("SELECT u.id,u.full_name,u.email,u.fitness_level,c.id AS coach_id,c.full_name AS coach_name,(SELECT COUNT(*) FROM workout_logs WHERE user_id=u.id AND MONTH(logged_at)=MONTH(NOW())) AS workouts_month FROM users u JOIN users c ON c.id=u.coach_id WHERE u.role='member' AND u.tier='premium' ORDER BY c.full_name,u.full_name");
$assigned_members=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$r=$conn->query("SELECT u.id,u.full_name,u.email,(SELECT COUNT(*) FROM users WHERE coach_id=u.id AND role='member') AS client_count,(SELECT COUNT(*) FROM users WHERE coach_id=u.id AND role='member' AND tier='premium') AS premium_clients FROM users u WHERE u.role='coach' ORDER BY u.full_name");
$all_coaches=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='premium'"); $total_premium=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='coach'"); $total_coaches=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='premium' AND (coach_id IS NULL OR coach_id=0)"); $total_unassigned=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='premium' AND coach_id IS NOT NULL AND coach_id!=0"); $total_assigned=$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_payments=$r->fetch_assoc()['c']; $r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Coach Assignment</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;--text:#f0f0f0;--sub:#666;--muted:#999;--red:#dc2626;--red-dim:rgba(220,38,38,0.12);--red-mid:#b91c1c;--green:#16a34a;--green-dim:rgba(22,163,74,0.12);--blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);--amber:#d97706;--amber-dim:rgba(217,119,6,0.12);--font:'Inter',sans-serif;--nav-h:56px;--banner-h:180px} /* Updated banner height to 180px */
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh}
    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px}
    .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}.nav-logo{width:32px;height:32px;border-radius:6px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
    .nav-brand-text{font-size:14px;font-weight:700;color:var(--text)}.nav-brand-text span{color:var(--red)}
    .nav-divider{width:1px;height:20px;background:var(--border2);flex-shrink:0}
    .nav-links{display:flex;align-items:center;gap:2px;flex:1}
    .nav-link{display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:6px;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;transition:all 0.15s;white-space:nowrap}
    .nav-link:hover{background:var(--card);color:var(--text)}.nav-link.active{background:var(--red-dim);color:var(--red);font-weight:600}
    .nav-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:8px;margin-left:2px}
    .nav-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}.nav-time{font-size:12px;color:var(--sub)}
    .nav-admin-chip{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border2);border-radius:20px;padding:4px 12px 4px 5px}
    .nav-avatar{width:24px;height:24px;border-radius:50%;background:var(--red);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
    .nav-admin-name{font-size:12px;font-weight:600}.nav-logout{font-size:12px;color:var(--sub);text-decoration:none;padding:5px 9px;border-radius:5px;border:1px solid var(--border);transition:all 0.15s}
    .nav-logout:hover{color:var(--text);border-color:var(--border2)}
    
    /* Updated Banner Sauce from Main Dashboard */
    .banner{position:relative;height:var(--banner-h);overflow:hidden;border-bottom:1px solid var(--border)}
    .banner-img{width:100%;height:100%;object-fit:cover;opacity:0.4;display:block}
    .banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(13,13,13,0.88) 0%,rgba(13,13,13,0.5) 55%,rgba(13,13,13,0.15) 100%)}
    .banner-content{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;padding:0 40px}
    .banner-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:7px}
    .banner-title{font-size:28px;font-weight:700;letter-spacing:-0.5px;line-height:1.1}
    .banner-sub{font-size:13px;color:var(--muted);margin-top:6px}
    .banner-right{text-align:right}.banner-stat-val{font-size:26px;font-weight:700;color:var(--text);line-height:1}.banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}
    
    .wrapper{max-width:1400px;margin:0 auto;padding:24px 32px}
    .alert-green{background:rgba(22,163,74,0.12);border:1px solid rgba(22,163,74,0.2);color:#4ade80;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .alert-amber{background:rgba(217,119,6,0.12);border:1px solid rgba(217,119,6,0.2);color:#fbbf24;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
    .scard{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:18px 20px;display:flex;align-items:center;gap:14px}
    .scard-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .ic-blue{background:var(--blue-dim)}.ic-green{background:var(--green-dim)}.ic-amber{background:var(--amber-dim)}.ic-red{background:var(--red-dim)}
    .scard-val{font-size:26px;font-weight:700;letter-spacing:-0.5px;line-height:1}
    .scard-lbl{font-size:12px;color:var(--sub);margin-top:3px;font-weight:500}
    .scard-note{font-size:11px;color:var(--muted);margin-top:2px}
    .scard-note.warn{color:#f87171}.scard-note.ok{color:#4ade80}
    .coaches-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:16px 20px}
    .coach-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px}
    .coach-av{width:36px;height:36px;border-radius:50%;background:var(--green-dim);color:#4ade80;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;margin-bottom:10px}
    .coach-name{font-size:13px;font-weight:700;margin-bottom:2px;color:var(--text)}.coach-email{font-size:11px;color:var(--sub);margin-bottom:10px}
    .coach-stats{display:flex;gap:16px}.coach-stat{font-size:10px;color:var(--sub)}.coach-stat strong{display:block;font-size:18px;font-weight:700;color:var(--text)}
    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-body{padding:0 20px 16px}
    .section-empty{padding:28px 20px;text-align:center;font-size:13px;color:var(--sub)}
    .pill{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px}
    .pill.unassigned{background:var(--red-dim);color:#f87171}.pill.assigned{background:var(--green-dim);color:#4ade80}
    .pill.beginner{background:var(--green-dim);color:#4ade80}.pill.intermediate{background:var(--amber-dim);color:#fbbf24}.pill.advanced{background:var(--red-dim);color:#f87171}
    table{width:100%;border-collapse:collapse}
    thead th{text-align:left;padding:12px 0 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);border-bottom:1px solid var(--border)}
    tbody td{padding:11px 0;font-size:13px;border-bottom:1px solid var(--border);color:var(--muted);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    .td-name{font-weight:600;color:var(--text)}.td-email{font-size:11px;color:var(--sub);margin-top:1px}
    .btn-assign{border:1px solid var(--border2);background:transparent;color:var(--muted);border-radius:5px;padding:5px 12px;font-family:var(--font);font-size:11px;font-weight:600;cursor:pointer;transition:all 0.15s}
    .btn-assign:hover{border-color:#60a5fa;color:#60a5fa;background:var(--blue-dim)}
    .btn-unassign{border:1px solid var(--border2);background:transparent;color:var(--muted);border-radius:5px;padding:5px 12px;font-family:var(--font);font-size:11px;font-weight:600;cursor:pointer;transition:all 0.15s}
    .btn-unassign:hover{border-color:#f87171;color:#f87171;background:var(--red-dim)}
    .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:300;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
    .overlay.open{display:flex}
    .modal{background:var(--card);border:1px solid var(--border2);border-radius:10px;padding:26px;width:400px;max-width:92vw}
    .modal-tag{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
    .modal h3{font-size:16px;font-weight:700;margin-bottom:4px}.modal .sub{font-size:12px;color:var(--sub);margin-bottom:14px}
    .reassign-info{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:var(--sub)}
    .reassign-info strong{color:var(--text)}
    .field{margin-bottom:12px}.field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);margin-bottom:5px}
    .field select{width:100%;background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:9px 11px;font-family:var(--font);font-size:13px;color:var(--text);outline:none}
    .field select:focus{border-color:#60a5fa}
    .modal-btns{display:flex;gap:8px;margin-top:18px}
    .btn-confirm{flex:1;background:#2563eb;color:#fff;border:none;border-radius:6px;padding:10px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer}
    .btn-cancel{background:transparent;color:var(--sub);border:1px solid var(--border2);border-radius:6px;padding:10px 16px;font-family:var(--font);font-size:13px;cursor:pointer}
    .btn-cancel:hover{color:var(--text)}
    @media(max-width:1100px){.stats-grid,.coaches-grid{grid-template-columns:1fr 1fr}}
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
    <a href="coach_assignment.php" class="nav-link active">🏆 Coaches <?php if($total_unassigned>0): ?><span class="nav-badge"><?= $total_unassigned ?></span><?php endif; ?></a>
    <a href="payments.php"         class="nav-link">💳 Payments <?php if($pending_payments>0): ?><span class="nav-badge"><?= $pending_payments ?></span><?php endif; ?></a>
    <a href="staff_coach.php"      class="nav-link">🧑‍🏫 Staff</a>
    <a href="analytics.php"        class="nav-link">📈 Analytics</a>
    <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
    <a href="admin_messages.php"   class="nav-link">💬 Messages</a> <a href="settings.php"         class="nav-link">⚙️ Settings</a>
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
      <div class="banner-eyebrow">Admin — Manage</div>
      <div class="banner-title">Coach Assignment</div>
      <div class="banner-sub">Manage premium member — coach pairings</div>
    </div>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:<?= $total_unassigned>0?'#f87171':'#4ade80' ?>"><?= $total_unassigned ?></div>
      <div class="banner-stat-lbl">Unassigned premium</div>
    </div>
  </div>
</div>

<div class="wrapper">
  <?php if(isset($_GET['assigned'])): ?><div class="alert-green">✓ Coach assigned successfully!</div><?php endif; ?>
  <?php if(isset($_GET['unassigned'])): ?><div class="alert-amber">↩ Coach unassigned. Member remains Premium.</div><?php endif; ?>

  <div class="stats-grid">
    <div class="scard"><div class="scard-icon ic-blue">⭐</div><div><div class="scard-val" style="color:#60a5fa"><?= $total_premium ?></div><div class="scard-lbl">Premium Members</div><div class="scard-note">Total with coaching tier</div></div></div>
    <div class="scard"><div class="scard-icon ic-green">✅</div><div><div class="scard-val" style="color:#4ade80"><?= $total_assigned ?></div><div class="scard-lbl">Assigned</div><div class="scard-note ok"><?= $total_assigned>0?'Active pairings':'None yet' ?></div></div></div>
    <div class="scard"><div class="scard-icon ic-red">⚠️</div><div><div class="scard-val" style="color:#f87171"><?= $total_unassigned ?></div><div class="scard-lbl">Unassigned</div><div class="scard-note warn"><?= $total_unassigned>0?'Needs a coach':'All assigned' ?></div></div></div>
    <div class="scard"><div class="scard-icon ic-amber">🧑‍🏫</div><div><div class="scard-val" style="color:#fbbf24"><?= $total_coaches ?></div><div class="scard-lbl">Total Coaches</div><div class="scard-note">Available staff</div></div></div>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">Coach Workload</span><span style="font-size:12px;color:var(--sub)"><?= $total_coaches ?> coaches</span></div>
    <?php if(empty($all_coaches)): ?>
      <div class="section-empty">No coach accounts found. Create one first in Staff & Coaches.</div>
    <?php else: ?>
    <div class="coaches-grid">
      <?php foreach($all_coaches as $c): ?>
      <div class="coach-card">
        <div class="coach-av"><?= strtoupper(substr($c['full_name'],0,1)) ?></div>
        <div class="coach-name"><?= htmlspecialchars($c['full_name']) ?></div>
        <div class="coach-email"><?= htmlspecialchars($c['email']) ?></div>
        <div class="coach-stats">
          <div class="coach-stat"><strong><?= $c['client_count'] ?></strong>Clients</div>
          <div class="coach-stat"><strong><?= $c['premium_clients'] ?></strong>Premium</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">Pending Assignments</span><span class="pill unassigned"><?= count($unassigned_members) ?> unassigned</span></div>
    <?php if(empty($unassigned_members)): ?>
      <div class="section-empty">✓ All premium members have been assigned a coach.</div>
    <?php else: ?>
    <div class="section-body">
      <table>
        <thead><tr><th>Member</th><th>Fitness Level</th><th>Premium Since</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach($unassigned_members as $m): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:30px;height:30px;border-radius:50%;background:var(--blue-dim);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;"><?= strtoupper(substr($m['full_name'],0,1)) ?></div>
                <div><div class="td-name"><?= htmlspecialchars($m['full_name']) ?></div><div class="td-email"><?= htmlspecialchars($m['email']) ?></div></div>
              </div>
            </td>
            <td><span class="pill <?= $m['fitness_level'] ?>"><?= ucfirst($m['fitness_level']) ?></span></td>
            <td style="font-size:12px;"><?= date('M j, Y',strtotime($m['created_at'])) ?></td>
            <td><span class="pill unassigned">Unassigned</span></td>
            <td><button class="btn-assign" onclick="openAssign(<?= $m['id'] ?>,'<?= htmlspecialchars($m['full_name'],ENT_QUOTES) ?>','<?= $m['fitness_level'] ?>')">Assign Coach</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">Current Assignments</span><span class="pill assigned"><?= count($assigned_members) ?> assigned</span></div>
    <?php if(empty($assigned_members)): ?>
      <div class="section-empty">No assignments yet.</div>
    <?php else: ?>
    <div class="section-body">
      <table>
        <thead><tr><th>Member</th><th>Level</th><th>Assigned Coach</th><th>Workouts This Month</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach($assigned_members as $m): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:30px;height:30px;border-radius:50%;background:var(--blue-dim);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;"><?= strtoupper(substr($m['full_name'],0,1)) ?></div>
                <div><div class="td-name"><?= htmlspecialchars($m['full_name']) ?></div><div class="td-email"><?= htmlspecialchars($m['email']) ?></div></div>
              </div>
            </td>
            <td><span class="pill <?= $m['fitness_level'] ?>"><?= ucfirst($m['fitness_level']) ?></span></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:26px;height:26px;border-radius:50%;background:var(--green-dim);color:#4ade80;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;"><?= strtoupper(substr($m['coach_name'],0,1)) ?></div>
                <span style="font-weight:600;color:var(--text)"><?= htmlspecialchars($m['coach_name']) ?></span>
              </div>
            </td>
            <td><span style="font-weight:600;color:var(--text)"><?= $m['workouts_month'] ?></span> <span style="font-size:11px;color:var(--sub)">workouts</span></td>
            <td style="display:flex;gap:6px;padding-top:11px;">
              <button class="btn-assign" onclick="openReassign(<?= $m['id'] ?>,'<?= htmlspecialchars($m['full_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($m['coach_name'],ENT_QUOTES) ?>')">Reassign</button>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="unassign_coach" value="1"/><input type="hidden" name="member_id" value="<?= $m['id'] ?>"/>
                <button type="submit" class="btn-unassign" onclick="return confirm('Remove coach from <?= htmlspecialchars($m['full_name'],ENT_QUOTES) ?>?')">Unassign</button>
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

<div class="overlay" id="assign-modal">
  <div class="modal">
    <div class="modal-tag">Coach Assignment</div>
    <h3>Assign Coach</h3>
    <p class="sub">Assigning to: <strong id="assign-name" style="color:var(--text)"></strong></p>
    <div class="reassign-info" id="assign-level-info"></div>
    <form method="POST" action="coach_assignment.php">
      <input type="hidden" name="assign_coach" value="1"/><input type="hidden" name="member_id" id="assign-member-id"/>
      <div class="field"><label>Select Coach</label>
        <select name="coach_id" required>
          <option value="" disabled selected>Choose a coach</option>
          <?php foreach($all_coaches as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?> — <?= $c['client_count'] ?> client<?= $c['client_count']!=1?'s':'' ?></option><?php endforeach; ?>
          <?php if(empty($all_coaches)): ?><option disabled>No coaches available</option><?php endif; ?>
        </select>
      </div>
      <div class="modal-btns"><button type="submit" class="btn-confirm" <?= empty($all_coaches)?'disabled':'' ?>>Confirm Assignment</button><button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button></div>
    </form>
  </div>
</div>

<div class="overlay" id="reassign-modal">
  <div class="modal">
    <div class="modal-tag">Reassignment</div>
    <h3>Reassign Coach</h3>
    <p class="sub">Member: <strong id="reassign-name" style="color:var(--text)"></strong></p>
    <div class="reassign-info">Current coach: <strong id="reassign-current" style="color:var(--text)"></strong></div>
    <form method="POST" action="coach_assignment.php">
      <input type="hidden" name="assign_coach" value="1"/><input type="hidden" name="member_id" id="reassign-member-id"/>
      <div class="field"><label>Select New Coach</label>
        <select name="coach_id" required>
          <option value="" disabled selected>Choose a coach</option>
          <?php foreach($all_coaches as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?> — <?= $c['client_count'] ?> client<?= $c['client_count']!=1?'s':'' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-btns"><button type="submit" class="btn-confirm">Confirm Reassignment</button><button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button></div>
    </form>
  </div>
</div>

<script>
  function openAssign(id,name,level){document.getElementById('assign-name').textContent=name;document.getElementById('assign-member-id').value=id;document.getElementById('assign-level-info').innerHTML='Fitness level: <strong>'+level.charAt(0).toUpperCase()+level.slice(1)+'</strong>';document.getElementById('assign-modal').classList.add('open');}
  function openReassign(id,name,coach){document.getElementById('reassign-name').textContent=name;document.getElementById('reassign-member-id').value=id;document.getElementById('reassign-current').textContent=coach;document.getElementById('reassign-modal').classList.add('open');}
  function closeModals(){document.getElementById('assign-modal').classList.remove('open');document.getElementById('reassign-modal').classList.remove('open');}
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);
</script>
</body>
</html>