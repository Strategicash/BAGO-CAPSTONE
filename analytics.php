<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'] ?? 'Admin';

$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member'"); $total_members=(int)$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"); $new_members_month=(int)$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM users WHERE role='member' AND MONTH(created_at)=MONTH(NOW()-INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(NOW()-INTERVAL 1 MONTH)"); $new_members_last=(int)$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())"); $revenue_month=(float)$r->fetch_assoc()['t']; $r->close();
$r=$conn->query("SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()-INTERVAL 1 MONTH) AND YEAR(paid_at)=YEAR(NOW()-INTERVAL 1 MONTH)"); $revenue_last=(float)$r->fetch_assoc()['t']; $r->close();
$r=$conn->query("SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='paid'"); $revenue_total=(float)$r->fetch_assoc()['t']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_payments=(int)$r->fetch_assoc()['c']; $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM workout_logs WHERE MONTH(logged_at)=MONTH(NOW()) AND YEAR(logged_at)=YEAR(NOW())"); $workouts_month=(int)$r->fetch_assoc()['c']; $r->close();

// Charts data
$r=$conn->query("SELECT DATE_FORMAT(created_at,'%b') mn, DATE_FORMAT(created_at,'%Y-%m') mk, COUNT(*) c FROM users WHERE role='member' AND created_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY mk,mn ORDER BY mk ASC");
$reg_raw=$r->fetch_all(MYSQLI_ASSOC); $r->close();
$reg_data=[]; for($i=5;$i>=0;$i--){$k=date('Y-m',strtotime("-$i months"));$reg_data[$k]=['n'=>date('M',strtotime("-$i months")),'c'=>0];}
foreach($reg_raw as $row){if(isset($reg_data[$row['mk']]))$reg_data[$row['mk']]['c']=$row['c'];}
$reg_max=max(array_column($reg_data,'c')?:[1]);

$r=$conn->query("SELECT DATE_FORMAT(paid_at,'%b') mn, DATE_FORMAT(paid_at,'%Y-%m') mk, SUM(amount) t FROM payments WHERE status='paid' AND paid_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY mk,mn ORDER BY mk ASC");
$rev_raw=$r->fetch_all(MYSQLI_ASSOC); $r->close();
$rev_data=[]; for($i=5;$i>=0;$i--){$k=date('Y-m',strtotime("-$i months"));$rev_data[$k]=['n'=>date('M',strtotime("-$i months")),'t'=>0];}
foreach($rev_raw as $row){if(isset($rev_data[$row['mk']]))$rev_data[$row['mk']]['t']=(float)$row['t'];}
$rev_max=max(array_column($rev_data,'t')?:[1]);

$r=$conn->query("SELECT DATE_FORMAT(logged_at,'%b') mn, DATE_FORMAT(logged_at,'%Y-%m') mk, COUNT(*) c FROM workout_logs WHERE logged_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY mk,mn ORDER BY mk ASC");
$wk_raw=$r->fetch_all(MYSQLI_ASSOC); $r->close();
$wk_data=[]; for($i=5;$i>=0;$i--){$k=date('Y-m',strtotime("-$i months"));$wk_data[$k]=['n'=>date('M',strtotime("-$i months")),'c'=>0];}
foreach($wk_raw as $row){if(isset($wk_data[$row['mk']]))$wk_data[$row['mk']]['c']=$row['c'];}
$wk_max=max(array_column($wk_data,'c')?:[1]);

$r=$conn->query("SELECT u.full_name,COUNT(w.id) wc FROM users u JOIN workout_logs w ON w.user_id=u.id WHERE u.role='member' GROUP BY u.id,u.full_name ORDER BY wc DESC LIMIT 5");
$top_members=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$r=$conn->query("SELECT exercise,COUNT(*) c FROM workout_logs GROUP BY exercise ORDER BY c DESC LIMIT 6");
$top_exercises=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$rev_diff=$revenue_month-$revenue_last;
$rev_diff_pct=$revenue_last>0?round(($rev_diff/$revenue_last)*100):0;
$mem_diff=$new_members_month-$new_members_last;
$avg_workouts=$total_members>0?round($workouts_month/$total_members,1):0;

// New members this month
$r=$conn->query("SELECT full_name,email,created_at FROM users WHERE role='member' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) ORDER BY created_at DESC");
$new_this_month=$r->fetch_all(MYSQLI_ASSOC); $r->close();

// Revenue summary
$r=$conn->query("SELECT DATE_FORMAT(paid_at,'%M %Y') ml, SUM(amount) t, COUNT(*) tx FROM payments WHERE status='paid' GROUP BY DATE_FORMAT(paid_at,'%Y-%m') ORDER BY paid_at DESC LIMIT 8");
$rev_summary=$r->fetch_all(MYSQLI_ASSOC); $r->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Analytics</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;--text:#f0f0f0;--sub:#666;--muted:#999;--red:#dc2626;--red-dim:rgba(220,38,38,0.12);--green:#16a34a;--green-dim:rgba(22,163,74,0.12);--blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);--amber:#d97706;--amber-dim:rgba(217,119,6,0.12);--font:'Inter',sans-serif;--nav-h:56px;--banner-h:140px}
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh}
    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px}
    .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}.nav-logo{width:32px;height:32px;border-radius:6px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
    .nav-brand-text{font-size:14px;font-weight:700;color:var(--text)}.nav-brand-text span{color:var(--red)}
    .nav-divider{width:1px;height:20px;background:var(--border2);flex-shrink:0}
    .nav-links{display:flex;align-items:center;gap:2px;flex:1}
    .nav-link{display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:6px;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;transition:all 0.15s;white-space:nowrap}
    .nav-link:hover{background:var(--card);color:var(--text)}.nav-link.active{background:var(--red-dim);color:var(--red);font-weight:600}
    .nav-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}.nav-time{font-size:12px;color:var(--sub)}
    .nav-admin-chip{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border2);border-radius:20px;padding:4px 12px 4px 5px}
    .nav-avatar{width:24px;height:24px;border-radius:50%;background:var(--red);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
    .nav-admin-name{font-size:12px;font-weight:600}.nav-logout{font-size:12px;color:var(--sub);text-decoration:none;padding:5px 9px;border-radius:5px;border:1px solid var(--border);transition:all 0.15s}
    .nav-logout:hover{color:var(--text);border-color:var(--border2)}
    .banner{position:relative;height:var(--banner-h);overflow:hidden;border-bottom:1px solid var(--border)}
    .banner-fallback{position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),repeating-linear-gradient(0deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),linear-gradient(135deg,#130303 0%,#1c0505 50%,#0d0d0d 100%)}
    .banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(13,13,13,0.88) 0%,rgba(13,13,13,0.5) 55%,rgba(13,13,13,0.15) 100%)}
    .banner-content{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;padding:0 40px}
    .banner-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
    .banner-title{font-size:22px;font-weight:700;letter-spacing:-0.5px}.banner-sub{font-size:13px;color:var(--muted);margin-top:5px}
    .banner-right{text-align:right}.banner-stat-val{font-size:22px;font-weight:700;line-height:1}.banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}
    .wrapper{max-width:1400px;margin:0 auto;padding:24px 32px}
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
    .scard{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:border-color 0.15s}
    .scard:hover{border-color:var(--border2)}
    .scard-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .ic-blue{background:var(--blue-dim)}.ic-green{background:var(--green-dim)}.ic-amber{background:var(--amber-dim)}.ic-red{background:var(--red-dim)}
    .scard-val{font-size:26px;font-weight:700;letter-spacing:-0.5px;line-height:1}
    .scard-lbl{font-size:12px;color:var(--sub);margin-top:3px;font-weight:500}
    .scard-note{font-size:11px;color:var(--muted);margin-top:2px}
    .scard-note.up{color:#4ade80}.scard-note.dn{color:#f87171}
    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-body{padding:16px 20px}
    .section-empty{padding:28px 20px;text-align:center;font-size:13px;color:var(--sub)}
    .three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px}
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    table{width:100%;border-collapse:collapse}
    thead th{text-align:left;padding:12px 0 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);border-bottom:1px solid var(--border)}
    tbody td{padding:10px 0;font-size:13px;border-bottom:1px solid var(--border);color:var(--muted);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    tbody tr{transition:background 0.1s}
    tbody tr:hover td{color:var(--text)}
    .td-name{font-weight:600;color:var(--text)}.td-email{font-size:11px;color:var(--sub);margin-top:1px}
    .bar-chart{display:flex;align-items:flex-end;gap:8px;height:100px}
    .bar-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;height:100%;justify-content:flex-end}
    .bar-fill{width:100%;border-radius:3px 3px 0 0;min-height:4px;transition:opacity 0.15s}
    .bar-col:hover .bar-fill{opacity:0.8}
    .bar-fill.c-blue{background:#2563eb}.bar-fill.c-green{background:#16a34a}.bar-fill.c-amber{background:#d97706}
    .bar-lbl{font-size:10px;color:var(--sub);font-weight:600}.bar-val{font-size:9px;color:var(--muted)}
    .prog-row{margin-bottom:12px}
    .prog-row:last-child{margin-bottom:0}
    .prog-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px}
    .prog-top span:last-child{color:var(--sub)}
    .prog-bar{height:5px;background:var(--border);border-radius:3px}
    .prog-fill{height:100%;border-radius:3px}
    .rank{width:20px;font-size:11px;font-weight:700;color:var(--sub)}
    .kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .kpi-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center}
    .kpi-icon{font-size:18px;margin-bottom:6px}
    .kpi-val{font-size:22px;font-weight:700;margin-bottom:3px}
    .kpi-lbl{font-size:10px;color:var(--sub);font-weight:600;text-transform:uppercase;letter-spacing:0.5px}
    @media(max-width:1100px){.stats-grid,.three-col{grid-template-columns:1fr 1fr}}
    @media(max-width:900px){.two-col{grid-template-columns:1fr}.kpi-row{grid-template-columns:1fr}}
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
  <a href="analytics.php"             class="nav-link active">📈 Analytics</a>
  <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
  <a href="settings.php"              class="nav-link">⚙️ Settings</a>
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
      <div class="banner-eyebrow">Admin — Insights</div>
      <div class="banner-title">Analytics</div>
      <div class="banner-sub">System overview — <?= date('F Y') ?></div>
    </div>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#4ade80">₱<?= number_format($revenue_total,0) ?></div>
      <div class="banner-stat-lbl">Total verified revenue</div>
    </div>
  </div>
</div>

<div class="wrapper">

  <div class="stats-grid">
    <div class="scard"><div class="scard-icon ic-blue">👥</div><div><div class="scard-val" style="color:#60a5fa"><?= $total_members ?></div><div class="scard-lbl">Total Members</div><div class="scard-note <?= $mem_diff>=0?'up':'dn' ?>"><?= $mem_diff>=0?'↑':'↓' ?> <?= abs($mem_diff) ?> vs last month</div></div></div>
    <div class="scard"><div class="scard-icon ic-green">💰</div><div><div class="scard-val" style="color:#4ade80;font-size:20px;">₱<?= number_format($revenue_month,0) ?></div><div class="scard-lbl">Revenue This Month</div><div class="scard-note <?= $rev_diff>=0?'up':'dn' ?>"><?= $rev_diff>=0?'↑':'↓' ?> <?= abs($rev_diff_pct) ?>% vs last month</div></div></div>
    <div class="scard"><div class="scard-icon ic-amber">🏋️</div><div><div class="scard-val" style="color:#fbbf24"><?= $workouts_month ?></div><div class="scard-lbl">Workouts This Month</div><div class="scard-note">All members combined</div></div></div>
    <div class="scard"><div class="scard-icon ic-red">💳</div><div><div class="scard-val" style="color:#f87171;font-size:20px;">₱<?= number_format($revenue_total,0) ?></div><div class="scard-lbl">Total Revenue</div><div class="scard-note">All time verified</div></div></div>
  </div>

  <!-- Charts row -->
  <div class="three-col">
    <div class="section">
      <div class="section-head"><span class="section-title">New Members</span><span style="font-size:12px;color:var(--sub)">Last 6 months</span></div>
      <div class="section-body">
        <div class="bar-chart">
          <?php foreach($reg_data as $m): ?><div class="bar-col"><div class="bar-val"><?= $m['c']>0?$m['c']:'' ?></div><div class="bar-fill c-blue" style="height:<?= $reg_max>0?round(($m['c']/$reg_max)*100):4 ?>%"></div><div class="bar-lbl"><?= $m['n'] ?></div></div><?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="section-head"><span class="section-title">Revenue</span><span style="font-size:12px;color:var(--sub)">Last 6 months</span></div>
      <div class="section-body">
        <div class="bar-chart">
          <?php foreach($rev_data as $m): ?><div class="bar-col"><div class="bar-val"><?= $m['t']>0?'₱'.number_format($m['t'],0):'' ?></div><div class="bar-fill c-green" style="height:<?= $rev_max>0?round(($m['t']/$rev_max)*100):4 ?>%"></div><div class="bar-lbl"><?= $m['n'] ?></div></div><?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="section-head"><span class="section-title">Workouts Logged</span><span style="font-size:12px;color:var(--sub)">Last 6 months</span></div>
      <div class="section-body">
        <div class="bar-chart">
          <?php foreach($wk_data as $m): ?><div class="bar-col"><div class="bar-val"><?= $m['c']>0?$m['c']:'' ?></div><div class="bar-fill c-amber" style="height:<?= $wk_max>0?round(($m['c']/$wk_max)*100):4 ?>%"></div><div class="bar-lbl"><?= $m['n'] ?></div></div><?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick stats + Top members / exercises -->
  <div class="two-col">
    <div class="section">
      <div class="section-head"><span class="section-title">Quick Stats</span></div>
      <div class="section-body">
        <div class="kpi-row">
          <div class="kpi-card"><div class="kpi-icon">⏳</div><div class="kpi-val" style="color:#fbbf24"><?= $pending_payments ?></div><div class="kpi-lbl">Pending Payments</div></div>
          <div class="kpi-card"><div class="kpi-icon">📊</div><div class="kpi-val" style="color:#60a5fa"><?= $avg_workouts ?></div><div class="kpi-lbl">Avg Workouts / Member</div></div>
          <div class="kpi-card"><div class="kpi-icon"><?= $rev_diff_pct>=0?'📈':'📉' ?></div><div class="kpi-val" style="color:<?= $rev_diff_pct>=0?'#4ade80':'#f87171' ?>"><?= $rev_diff_pct>=0?'+':'' ?><?= $rev_diff_pct ?>%</div><div class="kpi-lbl">Revenue Growth</div></div>
        </div>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:14px;">
      <div class="section">
        <div class="section-head"><span class="section-title">Most Active Members</span><span style="font-size:12px;color:var(--sub)">By total workouts</span></div>
        <?php if(empty($top_members)): ?><div class="section-empty">No workout data yet.</div>
        <?php else: ?>
        <div class="section-body">
          <table>
            <thead><tr><th>Member</th><th>Workouts</th></tr></thead>
            <tbody>
              <?php foreach($top_members as $i=>$m): ?>
              <tr>
                <td><div style="display:flex;align-items:center;gap:8px;"><span class="rank"><?= $i+1 ?>.</span><span style="font-weight:600;color:var(--text)"><?= htmlspecialchars($m['full_name']) ?></span></div></td>
                <td style="font-weight:700;color:var(--text)"><?= $m['wc'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="section">
        <div class="section-head"><span class="section-title">Top Exercises</span><span style="font-size:12px;color:var(--sub)">Most logged gym-wide</span></div>
        <?php if(empty($top_exercises)): ?><div class="section-empty">No exercise data yet.</div>
        <?php else: $ex_max=$top_exercises[0]['c']; ?>
        <div class="section-body">
          <?php foreach($top_exercises as $ex): ?>
          <div class="prog-row"><div class="prog-top"><span style="font-weight:600;color:var(--text)"><?= htmlspecialchars($ex['exercise']) ?></span><span><?= $ex['c'] ?> times</span></div><div class="prog-bar"><div class="prog-fill" style="width:<?= $ex_max>0?round(($ex['c']/$ex_max)*100):4 ?>%;background:#2563eb"></div></div></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- New members + Revenue summary -->
  <div class="two-col">
    <div class="section">
      <div class="section-head"><span class="section-title">New Members This Month</span><span style="font-size:12px;color:var(--sub)"><?= date('F Y') ?></span></div>
      <?php if(empty($new_this_month)): ?><div class="section-empty">No new members this month.</div>
      <?php else: ?>
      <div class="section-body">
        <table>
          <thead><tr><th>Member</th><th>Joined</th></tr></thead>
          <tbody>
            <?php foreach($new_this_month as $m): ?>
            <tr>
              <td><div class="td-name"><?= htmlspecialchars($m['full_name']) ?></div><div class="td-email"><?= htmlspecialchars($m['email']) ?></div></td>
              <td style="font-size:12px;"><?= date('M j',strtotime($m['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="section">
      <div class="section-head"><span class="section-title">Revenue Summary</span><span style="font-size:12px;color:var(--sub)">All time</span></div>
      <?php if(empty($rev_summary)): ?><div class="section-empty">No verified payments yet.</div>
      <?php else: ?>
      <div class="section-body">
        <table>
          <thead><tr><th>Month</th><th>Transactions</th><th>Revenue</th></tr></thead>
          <tbody>
            <?php foreach($rev_summary as $rs): ?>
            <tr><td style="font-weight:600;color:var(--text)"><?= $rs['ml'] ?></td><td><?= $rs['tx'] ?></td><td style="font-weight:700;color:#4ade80">₱<?= number_format($rs['t'],0) ?></td></tr>
            <?php endforeach; ?>
            <tr><td style="font-weight:700;color:var(--text)">All Time Total</td><td></td><td style="font-weight:700;color:#4ade80;font-size:15px;">₱<?= number_format($revenue_total,0) ?></td></tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);
</script>
</body>
</html>