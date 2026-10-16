<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'] ?? 'Admin';

// ── All coaches with their premium clients ────────────────────────────────────
$r = $conn->query("
    SELECT u.id AS coach_id, u.full_name AS coach_name,
           (SELECT COUNT(*) FROM users WHERE coach_id=u.id AND role='member' AND tier='premium') AS client_count
    FROM users u WHERE u.role='coach'
    ORDER BY u.full_name
");
$coaches = $r->fetch_all(MYSQLI_ASSOC); $r->close();

$sel_coach  = intval($_GET['coach']  ?? ($coaches[0]['coach_id'] ?? 0));
$sel_member = intval($_GET['member'] ?? 0);
$search     = trim($_GET['search']   ?? '');

// Get clients for selected coach (with optional search)
$clients = [];
if ($sel_coach) {
    if ($search !== '') {
        $like = "%$search%";
        $r = $conn->prepare("
            SELECT id, full_name, email, fitness_level, created_at
            FROM users
            WHERE coach_id=? AND role='member' AND tier='premium'
            AND (full_name LIKE ? OR email LIKE ?)
            ORDER BY full_name
        ");
        $r->bind_param("iss", $sel_coach, $like, $like);
    } else {
        $r = $conn->prepare("
            SELECT id, full_name, email, fitness_level, created_at
            FROM users WHERE coach_id=? AND role='member' AND tier='premium'
            ORDER BY full_name
        ");
        $r->bind_param("i", $sel_coach);
    }
    $r->execute();
    $clients = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();
    if (!$sel_member && !empty($clients)) $sel_member = $clients[0]['id'];
}

// Selected member info
$sel_member_data = null;
if ($sel_member) {
    $r = $conn->prepare("SELECT id, full_name, email, fitness_level, created_at FROM users WHERE id=? AND role='member'");
    $r->bind_param("i", $sel_member); $r->execute();
    $sel_member_data = $r->get_result()->fetch_assoc(); $r->close();
}

// ── Client Progress Data ──────────────────────────────────────────────────────
$workout_logs   = [];
$body_metrics   = null;
$workout_plan   = null;
$nutrition_plan = null;
$volume_by_day  = [];
$muscle_dist    = [];
$total_workouts = 0;
$workouts_month = 0;
$total_volume   = 0;

if ($sel_member) {
    $r = $conn->prepare("SELECT exercise, sets, reps, weight_kg, muscle_group, logged_at FROM workout_logs WHERE user_id=? ORDER BY logged_at DESC LIMIT 20");
    $r->bind_param("i", $sel_member); $r->execute();
    $workout_logs = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();

    $r = $conn->prepare("SELECT COUNT(*) c FROM workout_logs WHERE user_id=?");
    $r->bind_param("i", $sel_member); $r->execute();
    $total_workouts = (int)$r->get_result()->fetch_assoc()['c']; $r->close();

    $r = $conn->prepare("SELECT COUNT(*) c FROM workout_logs WHERE user_id=? AND MONTH(logged_at)=MONTH(NOW()) AND YEAR(logged_at)=YEAR(NOW())");
    $r->bind_param("i", $sel_member); $r->execute();
    $workouts_month = (int)$r->get_result()->fetch_assoc()['c']; $r->close();

    $r = $conn->prepare("SELECT COALESCE(SUM(sets*reps*weight_kg),0) v FROM workout_logs WHERE user_id=?");
    $r->bind_param("i", $sel_member); $r->execute();
    $total_volume = (float)$r->get_result()->fetch_assoc()['v']; $r->close();

    $r = $conn->prepare("SELECT DATE(logged_at) as day, SUM(sets*reps*weight_kg) as vol FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(logged_at) ORDER BY day ASC");
    $r->bind_param("i", $sel_member); $r->execute();
    foreach ($r->get_result()->fetch_all(MYSQLI_ASSOC) as $v) $volume_by_day[$v['day']] = (float)$v['vol'];
    $r->close();

    $r = $conn->prepare("SELECT muscle_group, COUNT(*) c FROM workout_logs WHERE user_id=? AND muscle_group != '' AND muscle_group IS NOT NULL GROUP BY muscle_group ORDER BY c DESC LIMIT 6");
    $r->bind_param("i", $sel_member); $r->execute();
    $muscle_dist = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();

    $r = $conn->prepare("SELECT weight_kg, height_cm, bmi, chest_cm, right_arm_cm, left_arm_cm, waist_navel_cm, waist_below_cm, hips_cm, right_leg_cm, left_leg_cm, recorded_at FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1");
    $r->bind_param("i", $sel_member); $r->execute();
    $body_metrics = $r->get_result()->fetch_assoc(); $r->close();

    $r = $conn->prepare("SELECT plan_name, phase, duration_weeks, notes, created_at FROM workout_plans WHERE coach_id=? AND member_id=? ORDER BY created_at DESC LIMIT 1");
    $r->bind_param("ii", $sel_coach, $sel_member); $r->execute();
    $workout_plan = $r->get_result()->fetch_assoc(); $r->close();

    $r = $conn->prepare("SELECT plan_name, goal, calories, protein_g, carbs_g, fats_g, meals_per_day, notes, created_at FROM nutrition_plans WHERE coach_id=? AND member_id=? ORDER BY id DESC LIMIT 1");
    $r->bind_param("ii", $sel_coach, $sel_member); $r->execute();
    $nutrition_plan = $r->get_result()->fetch_assoc(); $r->close();
}

$r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'");
$pending_payments = (int)$r->fetch_assoc()['c']; $r->close();

// Total premium clients across ALL coaches (for search across all)
$r = $conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND tier='premium'");
$total_premium_all = (int)$r->fetch_assoc()['c']; $r->close();

$conn->close();

$vol_max    = !empty($volume_by_day) ? max($volume_by_day) : 1;
$muscle_max = !empty($muscle_dist)   ? $muscle_dist[0]['c'] : 1;
$date_labels = [];
for ($i = 13; $i >= 0; $i--) $date_labels[] = date('Y-m-d', strtotime("-$i days"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Client Activity</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;--text:#f0f0f0;--sub:#666;--muted:#999;--red:#dc2626;--red-dim:rgba(220,38,38,0.12);--green:#16a34a;--green-dim:rgba(22,163,74,0.12);--blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);--amber:#d97706;--amber-dim:rgba(217,119,6,0.12);--font:'Inter',sans-serif;--nav-h:56px;--banner-h:130px}
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column}
    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px;flex-shrink:0}
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
    .banner{position:relative;height:var(--banner-h);overflow:hidden;border-bottom:1px solid var(--border);flex-shrink:0}
    .banner-fallback{position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),repeating-linear-gradient(0deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),linear-gradient(135deg,#130303 0%,#1c0505 50%,#0d0d0d 100%)}
    .banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(13,13,13,0.9) 0%,rgba(13,13,13,0.5) 60%,rgba(13,13,13,0.1) 100%)}
    .banner-content{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;padding:0 40px}
    .banner-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:5px}
    .banner-title{font-size:22px;font-weight:700;letter-spacing:-0.5px}
    .banner-sub{font-size:13px;color:var(--muted);margin-top:4px}
    .banner-right{text-align:right}
    .banner-stat-val{font-size:20px;font-weight:700;line-height:1}
    .banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}
    .page-layout{display:flex;flex:1;overflow:hidden;height:calc(100vh - var(--nav-h) - var(--banner-h))}

    /* LEFT PANEL */
    .left-panel{width:270px;flex-shrink:0;border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;background:var(--surface)}

    /* Coach dropdown */
    .coach-select-wrap{padding:10px 12px;border-bottom:1px solid var(--border)}
    .coach-select{width:100%;background:var(--card);border:1px solid var(--border2);border-radius:6px;padding:8px 10px;font-family:var(--font);font-size:13px;color:var(--text);outline:none;cursor:pointer}
    .coach-select:focus{border-color:#60a5fa}
    .coach-select option{background:var(--card)}

    /* SEARCH BOX */
    .search-wrap{padding:10px 12px;border-bottom:1px solid var(--border);background:var(--bg)}
    .search-box{position:relative;display:flex;align-items:center}
    .search-icon{position:absolute;left:9px;font-size:13px;color:var(--sub);pointer-events:none}
    .search-input{width:100%;background:var(--card);border:1px solid var(--border2);border-radius:6px;padding:7px 30px 7px 30px;font-family:var(--font);font-size:12px;color:var(--text);outline:none;transition:border-color 0.15s}
    .search-input:focus{border-color:#60a5fa}
    .search-input::placeholder{color:var(--sub)}
    .search-clear{position:absolute;right:8px;background:none;border:none;color:var(--sub);cursor:pointer;font-size:14px;padding:0;line-height:1;display:none}
    .search-clear.show{display:block}
    .search-count{padding:5px 12px 8px;font-size:10px;color:var(--sub);font-weight:600}

    /* CLIENT LIST */
    .panel-hd{padding:10px 16px 6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);background:var(--bg)}
    .client-list{flex:1;overflow-y:auto}
    .client-item{display:flex;align-items:center;gap:10px;padding:11px 16px;text-decoration:none;border-bottom:1px solid var(--border);transition:background 0.12s}
    .client-item:hover{background:var(--card)}
    .client-item.active{background:var(--card);border-left:2px solid var(--red)}
    .client-av{width:34px;height:34px;border-radius:50%;background:var(--blue-dim);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
    .client-info{flex:1;min-width:0}
    .client-name{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .client-sub{font-size:11px;color:var(--sub);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .no-clients{padding:24px 16px;font-size:12px;color:var(--sub);text-align:center}
    .no-results{padding:20px 16px;text-align:center}
    .no-results-icon{font-size:28px;margin-bottom:8px}
    .no-results-text{font-size:12px;color:var(--sub);margin-bottom:8px}
    .no-results-clear{font-size:11px;color:#60a5fa;text-decoration:none;cursor:pointer;background:none;border:none;font-family:var(--font)}

    /* RIGHT PANEL */
    .right-panel{flex:1;overflow-y:auto;padding:24px 28px;min-width:0}
    .member-header{display:flex;align-items:center;gap:14px;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:16px 20px;margin-bottom:18px}
    .member-header-av{width:48px;height:48px;border-radius:50%;background:var(--blue-dim);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;flex-shrink:0}
    .member-header-name{font-size:16px;font-weight:700;margin-bottom:3px}
    .member-header-sub{font-size:12px;color:var(--sub)}
    .member-header-badges{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
    .mbadge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:4px}
    .mbadge.premium{background:var(--blue-dim);color:#60a5fa}
    .mbadge.level{background:var(--green-dim);color:#4ade80}
    .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
    .scard{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:12px}
    .scard-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
    .ic-blue{background:var(--blue-dim)}.ic-green{background:var(--green-dim)}.ic-amber{background:var(--amber-dim)}.ic-red{background:var(--red-dim)}
    .scard-val{font-size:20px;font-weight:700;letter-spacing:-0.5px;line-height:1}
    .scard-lbl{font-size:11px;color:var(--sub);margin-top:2px}
    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-sub{font-size:11px;color:var(--sub)}
    .section-body{padding:16px 20px}
    .section-empty{padding:24px 20px;text-align:center;font-size:13px;color:var(--sub)}
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .bar-chart{display:flex;align-items:flex-end;gap:5px;height:80px}
    .bar-col{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;height:100%;justify-content:flex-end}
    .bar-fill{width:100%;border-radius:3px 3px 0 0;min-height:3px}
    .bar-lbl{font-size:9px;color:var(--sub);font-weight:600;transform:rotate(-45deg);transform-origin:top left;margin-top:6px;white-space:nowrap}
    .muscle-row{margin-bottom:10px}
    .muscle-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px}
    .muscle-top span:last-child{color:var(--sub)}
    .muscle-bar{height:5px;background:var(--border);border-radius:3px}
    .muscle-fill{height:100%;border-radius:3px;background:#2563eb}
    .metrics-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    .metric-box{background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:12px;text-align:center}
    .metric-val{font-size:18px;font-weight:700;color:var(--text);margin-bottom:3px}
    .metric-lbl{font-size:10px;color:var(--sub);font-weight:600;text-transform:uppercase;letter-spacing:0.5px}
    table{width:100%;border-collapse:collapse}
    thead th{text-align:left;padding:10px 0 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);border-bottom:1px solid var(--border)}
    tbody td{padding:10px 0;font-size:12px;border-bottom:1px solid var(--border);color:var(--muted);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    .td-main{font-weight:600;color:var(--text)}
    .plan-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
    .plan-row:last-child{border-bottom:none}
    .plan-lbl{color:var(--sub)}
    .plan-val{font-weight:600;color:var(--text)}
    .page-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--sub);font-size:13px;gap:8px;padding:40px}
    .page-empty-icon{font-size:40px;margin-bottom:8px}
    .page-empty-title{font-size:15px;font-weight:600;color:var(--muted)}

    /* highlight matched text */
    mark{background:rgba(37,99,235,0.3);color:#93c5fd;border-radius:2px;padding:0 1px}

    @media(max-width:1100px){.stats-row{grid-template-columns:1fr 1fr}.metrics-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:900px){.two-col{grid-template-columns:1fr}}
    @media(max-width:768px){.left-panel{display:none}.nav-links{display:none}}
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
  <a href="settings.php"              class="nav-link">⚙️ Settings</a>
</div>
  <div class="nav-right">
    <span class="nav-time" id="live-time"></span>
    <div class="nav-admin-chip">
      <div class="nav-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div>
      <span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span>
    </div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<div class="banner">
  <div class="banner-fallback"></div>
  <div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin — Monitor</div>
      <div class="banner-title">Client Activity</div>
      <div class="banner-sub">Search and view progress, plans, and logs for any client</div>
    </div>
    <?php if ($sel_member_data): ?>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#60a5fa"><?= $total_workouts ?></div>
      <div class="banner-stat-lbl">Total workouts — <?= htmlspecialchars($sel_member_data['full_name']) ?></div>
    </div>
    <?php else: ?>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#fbbf24"><?= $total_premium_all ?></div>
      <div class="banner-stat-lbl">Total premium clients</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="page-layout">

  <!-- LEFT PANEL -->
  <div class="left-panel">

    <!-- Coach dropdown -->
    <div class="coach-select-wrap">
      <form method="GET" id="coach-form">
        <select name="coach" class="coach-select" onchange="this.form.submit()">
          <?php foreach ($coaches as $c): ?>
            <option value="<?= $c['coach_id'] ?>" <?= $c['coach_id']==$sel_coach?'selected':'' ?>>
              🧑‍🏫 <?= htmlspecialchars($c['coach_name']) ?> (<?= $c['client_count'] ?>)
            </option>
          <?php endforeach; ?>
          <?php if (empty($coaches)): ?>
            <option disabled>No coaches found</option>
          <?php endif; ?>
        </select>
      </form>
    </div>

    <!-- SEARCH BOX -->
    <div class="search-wrap">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" class="search-input" id="client-search"
               placeholder="Search by name or email..."
               value="<?= htmlspecialchars($search) ?>"
               oninput="liveSearch(this.value)"
               autocomplete="off"/>
        <button class="search-clear <?= $search ? 'show' : '' ?>" id="search-clear-btn" onclick="clearSearch()" title="Clear search">✕</button>
      </div>
    </div>

    <!-- Result count -->
    <div class="search-count" id="search-count">
      <?php if ($search): ?>
        <?= count($clients) ?> result<?= count($clients)!==1?'s':'' ?> for "<?= htmlspecialchars($search) ?>"
      <?php else: ?>
        <?= count($clients) ?> client<?= count($clients)!==1?'s':'' ?>
      <?php endif; ?>
    </div>

    <!-- Client list -->
    <div class="client-list" id="client-list">
      <?php if (empty($clients) && $search): ?>
        <div class="no-results">
          <div class="no-results-icon">🔍</div>
          <div class="no-results-text">No clients found for<br>"<?= htmlspecialchars($search) ?>"</div>
          <button class="no-results-clear" onclick="clearSearch()">Clear search</button>
        </div>
      <?php elseif (empty($clients)): ?>
        <div class="no-clients">No premium clients assigned to this coach.</div>
      <?php else: foreach ($clients as $c):
        // Highlight matched text
        $display_name  = htmlspecialchars($c['full_name']);
        $display_email = htmlspecialchars($c['email']);
        if ($search) {
          $esc = preg_quote(htmlspecialchars($search), '/');
          $display_name  = preg_replace('/('.$esc.')/i', '<mark>$1</mark>', $display_name);
          $display_email = preg_replace('/('.$esc.')/i', '<mark>$1</mark>', $display_email);
        }
      ?>
        <a href="admin_client_activity.php?coach=<?= $sel_coach ?>&member=<?= $c['id'] ?>&search=<?= urlencode($search) ?>"
           class="client-item <?= $c['id']==$sel_member?'active':'' ?>">
          <div class="client-av"><?= strtoupper(substr($c['full_name'],0,1)) ?></div>
          <div class="client-info">
            <div class="client-name"><?= $display_name ?></div>
            <div class="client-sub"><?= ucfirst($c['fitness_level']) ?> · <?= $display_email ?></div>
          </div>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right-panel">

    <?php if (!$sel_member || !$sel_member_data): ?>
    <div class="page-empty">
      <div class="page-empty-icon">📋</div>
      <div class="page-empty-title">Select a client</div>
      <div>Use the search box or choose a coach, then pick a client to view their full activity.</div>
    </div>

    <?php else: ?>

    <!-- MEMBER HEADER -->
    <div class="member-header">
      <div class="member-header-av"><?= strtoupper(substr($sel_member_data['full_name'],0,1)) ?></div>
      <div style="flex:1;min-width:0">
        <div class="member-header-name"><?= htmlspecialchars($sel_member_data['full_name']) ?></div>
        <div class="member-header-sub"><?= htmlspecialchars($sel_member_data['email']) ?> · Joined <?= date('M j, Y', strtotime($sel_member_data['created_at'])) ?></div>
        <div class="member-header-badges">
          <span class="mbadge premium">Premium</span>
          <span class="mbadge level"><?= ucfirst($sel_member_data['fitness_level']) ?></span>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:11px;color:var(--sub);margin-bottom:4px;">Assigned Coach</div>
        <?php $coach_name_sel=''; foreach($coaches as $c){if($c['coach_id']==$sel_coach){$coach_name_sel=$c['coach_name'];break;}} ?>
        <div style="font-size:13px;font-weight:600;color:#4ade80">🧑‍🏫 <?= htmlspecialchars($coach_name_sel) ?></div>
      </div>
    </div>

    <!-- STATS -->
    <div class="stats-row">
      <div class="scard"><div class="scard-icon ic-blue">🏋️</div><div><div class="scard-val" style="color:#60a5fa"><?= $total_workouts ?></div><div class="scard-lbl">Total Workouts</div></div></div>
      <div class="scard"><div class="scard-icon ic-green">📅</div><div><div class="scard-val" style="color:#4ade80"><?= $workouts_month ?></div><div class="scard-lbl">This Month</div></div></div>
      <div class="scard"><div class="scard-icon ic-amber">📋</div><div><div class="scard-val" style="color:#fbbf24"><?= $workout_plan ? '✓' : '—' ?></div><div class="scard-lbl">Workout Plan</div></div></div>
      <div class="scard"><div class="scard-icon ic-red">🥗</div><div><div class="scard-val" style="color:#f87171"><?= $nutrition_plan ? '✓' : '—' ?></div><div class="scard-lbl">Nutrition Plan</div></div></div>
    </div>

    <!-- CHARTS -->
    <div class="two-col">
      <div class="section">
        <div class="section-head"><span class="section-title">Workout Volume</span><span class="section-sub">Last 14 days</span></div>
        <div class="section-body">
          <?php if(empty($volume_by_day)): ?>
            <div style="text-align:center;color:var(--sub);font-size:12px;padding:20px 0;">No workout data in the last 14 days.</div>
          <?php else: ?>
          <div class="bar-chart">
            <?php foreach($date_labels as $d):
              $vol=$volume_by_day[$d]??0;
              $h=$vol_max>0?max(3,round(($vol/$vol_max)*80)):3;
            ?>
            <div class="bar-col">
              <div class="bar-fill" style="height:<?= $h ?>px;background:#2563eb;opacity:<?= $vol>0?'1':'0.2' ?>;" title="<?= date('M j',strtotime($d)) ?>: <?= number_format($vol,0) ?>"></div>
              <div class="bar-lbl"><?= date('j',strtotime($d)) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:10px;font-size:11px;color:var(--sub);">Volume = sets × reps × weight (kg) per day</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="section">
        <div class="section-head"><span class="section-title">Muscle Groups</span><span class="section-sub">Most trained</span></div>
        <div class="section-body">
          <?php if(empty($muscle_dist)): ?>
            <div style="text-align:center;color:var(--sub);font-size:12px;padding:20px 0;">No muscle group data yet.</div>
          <?php else: foreach($muscle_dist as $m):
            $pct=$muscle_max>0?round(($m['c']/$muscle_max)*100):0;
          ?>
          <div class="muscle-row">
            <div class="muscle-top"><span><?= htmlspecialchars($m['muscle_group']) ?></span><span><?= $m['c'] ?> sets</span></div>
            <div class="muscle-bar"><div class="muscle-fill" style="width:<?= $pct ?>%"></div></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- BODY METRICS -->
    <div class="section" style="margin-bottom:14px;">
      <div class="section-head">
        <span class="section-title">Latest Body Metrics</span>
        <span class="section-sub"><?= $body_metrics ? 'Recorded '.date('M j, Y',strtotime($body_metrics['recorded_at'])) : 'No data' ?></span>
      </div>
      <?php if(!$body_metrics): ?>
        <div class="section-empty">No body metrics recorded yet.</div>
      <?php else: ?>
      <div class="section-body">
        <div class="metrics-grid">
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['weight_kg']??'—' ?> <span style="font-size:12px;color:var(--sub)">kg</span></div><div class="metric-lbl">Weight</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['height_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">Height</div></div>
          <div class="metric-box"><div class="metric-val" style="color:#60a5fa"><?= $body_metrics['bmi']?number_format($body_metrics['bmi'],1):'—' ?></div><div class="metric-lbl">BMI</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['chest_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">Chest</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['right_arm_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">R. Arm</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['left_arm_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">L. Arm</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['waist_navel_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">Waist (Navel)</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['waist_below_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">Waist (-2")</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['hips_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">Hips</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['right_leg_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">R. Leg</div></div>
          <div class="metric-box"><div class="metric-val"><?= $body_metrics['left_leg_cm']??'—' ?> <span style="font-size:12px;color:var(--sub)">cm</span></div><div class="metric-lbl">L. Leg</div></div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- PLANS -->
    <div class="two-col">
      <div class="section">
        <div class="section-head"><span class="section-title">📋 Workout Plan</span><span class="section-sub"><?= $workout_plan?'Active':'None assigned' ?></span></div>
        <?php if(!$workout_plan): ?>
          <div class="section-empty">No workout plan assigned yet.</div>
        <?php else: ?>
        <div class="section-body">
          <div class="plan-row"><span class="plan-lbl">Plan Name</span><span class="plan-val"><?= htmlspecialchars($workout_plan['plan_name']) ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Phase</span><span class="plan-val"><?= $workout_plan['phase']?htmlspecialchars($workout_plan['phase']):'—' ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Duration</span><span class="plan-val"><?= $workout_plan['duration_weeks'] ?> weeks</span></div>
          <div class="plan-row"><span class="plan-lbl">Assigned</span><span class="plan-val"><?= date('M j, Y',strtotime($workout_plan['created_at'])) ?></span></div>
          <?php if($workout_plan['notes']): ?>
          <div style="margin-top:12px;"><div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--sub);margin-bottom:6px;">Coach Notes</div><div style="font-size:12px;color:var(--muted);line-height:1.7;background:var(--surface);border-radius:6px;padding:10px 12px;"><?= nl2br(htmlspecialchars($workout_plan['notes'])) ?></div></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="section">
        <div class="section-head"><span class="section-title">🥗 Nutrition Plan</span><span class="section-sub"><?= $nutrition_plan?'Active':'None assigned' ?></span></div>
        <?php if(!$nutrition_plan): ?>
          <div class="section-empty">No nutrition plan assigned yet.</div>
        <?php else: ?>
        <div class="section-body">
          <div class="plan-row"><span class="plan-lbl">Plan Name</span><span class="plan-val"><?= htmlspecialchars($nutrition_plan['plan_name']) ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Goal</span><span class="plan-val"><?= $nutrition_plan['goal']?htmlspecialchars($nutrition_plan['goal']):'—' ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Calories</span><span class="plan-val" style="color:#fbbf24"><?= $nutrition_plan['calories']?number_format($nutrition_plan['calories']).' kcal/day':'—' ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Protein</span><span class="plan-val"><?= $nutrition_plan['protein_g']?$nutrition_plan['protein_g'].'g':'—' ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Carbs</span><span class="plan-val"><?= $nutrition_plan['carbs_g']?$nutrition_plan['carbs_g'].'g':'—' ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Fats</span><span class="plan-val"><?= $nutrition_plan['fats_g']?$nutrition_plan['fats_g'].'g':'—' ?></span></div>
          <div class="plan-row"><span class="plan-lbl">Meals/Day</span><span class="plan-val"><?= $nutrition_plan['meals_per_day']??'—' ?></span></div>
          <?php if($nutrition_plan['notes']): ?>
          <div style="margin-top:12px;"><div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--sub);margin-bottom:6px;">Notes</div><div style="font-size:12px;color:var(--muted);line-height:1.7;background:var(--surface);border-radius:6px;padding:10px 12px;"><?= nl2br(htmlspecialchars($nutrition_plan['notes'])) ?></div></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- WORKOUT LOGS -->
    <div class="section">
      <div class="section-head"><span class="section-title">Recent Workout Logs</span><span class="section-sub">Last 20 entries</span></div>
      <?php if(empty($workout_logs)): ?>
        <div class="section-empty">No workouts logged yet.</div>
      <?php else: ?>
      <div class="section-body" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Exercise</th><th>Muscle Group</th><th>Sets</th><th>Reps</th><th>Weight</th><th>Volume</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach($workout_logs as $log):
              $vol=$log['sets']*$log['reps']*$log['weight_kg'];
            ?>
            <tr>
              <td class="td-main"><?= htmlspecialchars($log['exercise']) ?></td>
              <td><?= htmlspecialchars($log['muscle_group']??'—') ?></td>
              <td><?= $log['sets'] ?></td>
              <td><?= $log['reps'] ?></td>
              <td><?= $log['weight_kg'] ?> kg</td>
              <td style="color:#60a5fa;font-weight:600;"><?= number_format($vol,0) ?></td>
              <td style="font-size:11px;"><?= date('M j, Y g:i A',strtotime($log['logged_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>
</div>

<script>
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);

  // Live search — debounced, submits to server
  let searchTimer = null;
  function liveSearch(val) {
    const clearBtn = document.getElementById('search-clear-btn');
    clearBtn.classList.toggle('show', val.length > 0);
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      const coach = <?= $sel_coach ?>;
      const member = <?= $sel_member ?>;
      const url = new URL(window.location.href);
      url.searchParams.set('coach', coach);
      url.searchParams.set('search', val);
      if (val === '') url.searchParams.delete('search');
      // Keep member if still searching, reset if new search
      url.searchParams.delete('member');
      window.location.href = url.toString();
    }, 400); // 400ms debounce
  }

  function clearSearch() {
    const coach = <?= $sel_coach ?>;
    window.location.href = 'admin_client_activity.php?coach=' + coach;
  }

  // Focus search on load if it has a value
  <?php if ($search): ?>
  document.getElementById('client-search').focus();
  <?php endif; ?>
</script>
</body>
</html>