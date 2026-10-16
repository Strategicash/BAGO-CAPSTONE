<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }

$admin_name = $_SESSION['full_name'];

// All members, with current membership status derived from payments
$r = $conn->query("
    SELECT u.id, u.full_name, u.email, u.fitness_level, u.created_at,
           (SELECT MAX(p.expires_at) FROM payments p WHERE p.user_id = u.id AND p.status = 'paid') AS expires_at
    FROM users u
    WHERE u.role = 'member'
    ORDER BY u.created_at DESC
");
$all_members = $r->fetch_all(MYSQLI_ASSOC); $r->close();

// Members currently inside the gym (checked in, not yet checked out)
$r = $conn->query("
    SELECT u.full_name, u.email, al.checked_in_at,
           TIMESTAMPDIFF(MINUTE, al.checked_in_at, NOW()) AS minutes_inside
    FROM access_logs al
    JOIN users u ON u.id = al.user_id
    WHERE al.access_granted = 1 AND al.checked_out_at IS NULL
    ORDER BY al.checked_in_at DESC
    LIMIT 8
");
$currently_active = $r->fetch_all(MYSQLI_ASSOC); $r->close();

$r = $conn->query("SELECT COUNT(DISTINCT user_id) c FROM access_logs WHERE access_granted = 1 AND checked_out_at IS NULL");
$active_now = (int)$r->fetch_assoc()['c']; $r->close();

$r = $conn->query("SELECT COUNT(DISTINCT user_id) c FROM access_logs WHERE access_granted = 1 AND DATE(checked_in_at) = CURDATE()");
$checked_in_today = (int)$r->fetch_assoc()['c']; $r->close();

$r = $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'member'");
$total_members = (int)$r->fetch_assoc()['c']; $r->close();

$r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status = 'pending'");
$pending_payments = (int)$r->fetch_assoc()['c']; $r->close();

// Recent payments — last 5 paid, newest first
$r = $conn->query("
    SELECT p.amount, p.paid_at, p.description,
           u.full_name, u.email
    FROM payments p JOIN users u ON u.id = p.user_id
    WHERE p.status = 'paid'
    ORDER BY p.paid_at DESC LIMIT 5
");
$recent_payments = $r->fetch_all(MYSQLI_ASSOC); $r->close();

$rev = $conn->query("SELECT SUM(amount) as total FROM payments WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetch_assoc();
$rev_total = $rev['total'] ?? 0;
$conn->close();

// Helper: human-readable time ago
function time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)         return 'just now';
    if ($diff < 3600)       return floor($diff/60)   . ' min ago';
    if ($diff < 86400)      return floor($diff/3600)  . ' hr ago';
    if ($diff < 604800)     return floor($diff/86400) . ' day' . (floor($diff/86400)>1?'s':'') . ' ago';
    return date('M j', strtotime($datetime));
}

// Helper: minutes inside -> "1h 20m" style
function duration_label($minutes) {
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . 'm';
    return floor($minutes/60) . 'h ' . ($minutes % 60) . 'm';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:       #0d0d0d;
      --surface:  #141414;
      --card:     #1a1a1a;
      --border:   #252525;
      --border2:  #303030;
      --text:     #f0f0f0;
      --sub:      #666;
      --muted:    #999;
      --red:      #dc2626;
      --red-dim:  rgba(220,38,38,0.12);
      --red-mid:  #b91c1c;
      --green:    #16a34a;
      --green-dim:rgba(22,163,74,0.12);
      --blue:     #2563eb;
      --blue-dim: rgba(37,99,235,0.12);
      --amber:    #d97706;
      --amber-dim:rgba(217,119,6,0.12);
      --font:     'Inter', sans-serif;
      --nav-h:    56px;
      --banner-h: 180px;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }

    /* TOP NAV */
    .topnav { position: sticky; top: 0; z-index: 100; background: var(--surface); border-bottom: 1px solid var(--border); height: var(--nav-h); display: flex; align-items: center; padding: 0 32px; gap: 20px; }
    .nav-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .nav-logo { width: 32px; height: 32px; border-radius: 6px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff; }
    .nav-brand-text { font-size: 14px; font-weight: 700; color: var(--text); }
    .nav-brand-text span { color: var(--red); }
    .nav-divider { width: 1px; height: 20px; background: var(--border2); flex-shrink: 0; }
    .nav-links { display: flex; align-items: center; gap: 2px; flex: 1; }
    .nav-link { display: flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .nav-link:hover { background: var(--card); color: var(--text); }
    .nav-link.active { background: var(--red-dim); color: var(--red); font-weight: 600; }
    .nav-right { display: flex; align-items: center; gap: 10px; margin-left: auto; flex-shrink: 0; }
    .nav-time { font-size: 12px; color: var(--sub); }
    .nav-admin-chip { display: flex; align-items: center; gap: 7px; background: var(--card); border: 1px solid var(--border2); border-radius: 20px; padding: 4px 12px 4px 5px; }
    .nav-avatar { width: 24px; height: 24px; border-radius: 50%; background: var(--red); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .nav-admin-name { font-size: 12px; font-weight: 600; }
    .nav-logout { font-size: 12px; color: var(--sub); text-decoration: none; padding: 5px 9px; border-radius: 5px; border: 1px solid var(--border); transition: all 0.15s; }
    .nav-logout:hover { color: var(--text); border-color: var(--border2); }

    /* BANNER */
    .banner { position: relative; height: var(--banner-h); overflow: hidden; border-bottom: 1px solid var(--border); }
    .banner-img { width: 100%; height: 100%; object-fit: cover; opacity: 0.4; display: block; }
    .banner-overlay { position: absolute; inset: 0; background: linear-gradient(to right, rgba(13,13,13,0.88) 0%, rgba(13,13,13,0.5) 55%, rgba(13,13,13,0.15) 100%); }
    .banner-content { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; }
    .banner-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--red); margin-bottom: 7px; }
    .banner-title { font-size: 28px; font-weight: 700; letter-spacing: -0.5px; line-height: 1.1; }
    .banner-sub { font-size: 13px; color: var(--muted); margin-top: 6px; }
    .banner-right { text-align: right; }
    .banner-rev-val { font-size: 26px; font-weight: 700; color: var(--text); line-height: 1; }
    .banner-rev-lbl { font-size: 11px; color: var(--sub); margin-top: 3px; }

    /* MAIN */
    .wrapper { max-width: 1400px; margin: 0 auto; padding: 28px 32px; }
    .alert-green { background: var(--green-dim); border: 1px solid rgba(22,163,74,0.2); color: #4ade80; font-size: 13px; padding: 10px 16px; border-radius: 7px; margin-bottom: 20px; font-weight: 500; }

    /* Stats */
    .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 24px; }
    .scard { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: border-color 0.15s; }
    .scard:hover { border-color: var(--border2); }
    .scard-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    .scard-icon.ic-blue  { background: var(--blue-dim); }
    .scard-icon.ic-green { background: var(--green-dim); }
    .scard-icon.ic-amber { background: var(--amber-dim); }
    .scard-icon.ic-red   { background: var(--red-dim); }
    .scard-val { font-size: 26px; font-weight: 700; letter-spacing: -0.5px; line-height: 1; }
    .scard-lbl { font-size: 12px; color: var(--sub); margin-top: 3px; font-weight: 500; }
    .scard-note { font-size: 11px; color: var(--muted); margin-top: 2px; }

    /* Layout */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    .full-col { margin-bottom: 14px; }

    /* Section */
    .section { background: var(--card); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .section-head { padding: 13px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.018); }
    .section-title { font-size: 13px; font-weight: 700; }
    .section-body { padding: 0 20px 16px; }
    .section-empty { padding: 28px 20px; text-align: center; font-size: 13px; color: var(--sub); }

    /* Pills */
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
    .pill.active   { background: var(--green-dim); color: #4ade80; }
    .pill.expired  { background: var(--red-dim);   color: #f87171; }
    .pill.basic    { background: var(--border);    color: var(--sub); }

    /* Tables */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; padding: 12px 0 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--sub); border-bottom: 1px solid var(--border); }
    tbody td { padding: 11px 0; font-size: 13px; border-bottom: 1px solid var(--border); color: var(--muted); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: rgba(255,255,255,0.01); }
    .td-name { font-weight: 600; font-size: 13px; color: var(--text); }
    .td-email { font-size: 11px; color: var(--sub); margin-top: 1px; }

    /* Activity feed (shared by payments + currently-active lists) */
    .act-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
    .act-row:last-child { border-bottom: none; }
    .act-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; flex-shrink: 0; box-shadow: 0 0 6px rgba(74,222,128,0.5); }
    .act-dot.pulse { animation: dotpulse 1.8s infinite; }
    @keyframes dotpulse { 0%,100% { opacity:1; } 50% { opacity:0.35; } }
    .act-info { flex: 1; min-width: 0; }
    .act-name { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .act-desc { font-size: 11px; color: var(--sub); margin-top: 1px; }
    .act-right { text-align: right; flex-shrink: 0; }
    .act-amount { font-size: 13px; font-weight: 700; color: #4ade80; }
    .act-time { font-size: 10px; color: var(--sub); margin-top: 2px; }

    /* Revenue */
    .rev-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
    .rev-row:last-child { border-bottom: none; }
    .rev-label { color: var(--muted); }
    .rev-val { font-weight: 600; }

    @media (max-width: 1100px) { .stats-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 900px)  { .two-col { grid-template-columns: 1fr; } }
    @media (max-width: 768px)  { .nav-links { display: none; } .wrapper { padding: 20px 16px; } .banner-right { display: none; } .banner-content { padding: 0 20px; } }
  </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
  <a href="admin.php" class="nav-brand">
    <div class="nav-logo">FX</div>
    <div class="nav-brand-text">FitX <span>Admin</span></div>
  </a>
  <div class="nav-divider"></div>
 <div class="nav-links">
  <a href="admin.php"                 class="nav-link active">⊞ Dashboard</a>
  <a href="all_members.php"           class="nav-link">👥 Members</a>
  <a href="payments.php"              class="nav-link">💳 Payments</a>
  <a href="admin_enroll_rfid.php"     class="nav-link">📶 Enroll RFID</a>
  <a href="admin_checkin.php"         class="nav-link">🚪 Check-In Kiosk</a>
  <a href="analytics.php"             class="nav-link">📈 Analytics</a>
  <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
  <a href="settings.php"              class="nav-link">⚙️ Settings</a>
  <a href="admin_access_codes.php" class="nav-link">🔑 Access Codes</a>
</div>
  <div class="nav-right">
    <span class="nav-time" id="live-time"></span>
    <div class="nav-admin-chip">
      <div class="nav-avatar"><?= strtoupper(substr($admin_name, 0, 1)) ?></div>
      <span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span>
    </div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<!-- BANNER -->
<div class="banner">
  <img class="banner-img" src="equipment.jpg.jpg" alt="">
  <div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin Control Panel</div>
      <div class="banner-title">FitX Training Club</div>
      <div class="banner-sub">Welcome back, <?= htmlspecialchars($admin_name) ?> &mdash; <?= date('l, F j, Y') ?></div>
    </div>
    <div class="banner-right">
      <div class="banner-rev-val">₱<?= number_format($rev_total, 0) ?></div>
      <div class="banner-rev-lbl">Revenue this month</div>
    </div>
  </div>
</div>

<div class="wrapper">

  <!-- Stats -->
  <div class="stats-grid">
    <div class="scard">
      <div class="scard-icon ic-blue">👥</div>
      <div>
        <div class="scard-val" style="color:#60a5fa"><?= $total_members ?></div>
        <div class="scard-lbl">Total Members</div>
        <div class="scard-note">Registered accounts</div>
      </div>
    </div>
    <div class="scard">
      <div class="scard-icon ic-green">🟢</div>
      <div>
        <div class="scard-val" style="color:#4ade80"><?= $active_now ?></div>
        <div class="scard-lbl">Active Now</div>
        <div class="scard-note">Currently in the gym</div>
      </div>
    </div>
    <div class="scard">
      <div class="scard-icon ic-amber">🚪</div>
      <div>
        <div class="scard-val" style="color:#fbbf24"><?= $checked_in_today ?></div>
        <div class="scard-lbl">Checked In Today</div>
        <div class="scard-note">Unique visits today</div>
      </div>
    </div>
    <div class="scard">
      <div class="scard-icon ic-red">⏳</div>
      <div>
        <div class="scard-val" style="color:#f87171"><?= $pending_payments ?></div>
        <div class="scard-lbl">Pending Payments</div>
        <div class="scard-note"><?= $pending_payments > 0 ? 'Awaiting verification' : 'All clear' ?></div>
      </div>
    </div>
  </div>

  <!-- Two columns -->
  <div class="two-col">

    <!-- Recent Payments feed -->
    <div class="section">
      <div class="section-head">
        <span class="section-title">Recent Payments</span>
        <a href="payments.php" style="font-size:11px;color:#60a5fa;text-decoration:none;font-weight:600;">View all →</a>
      </div>
      <?php if (empty($recent_payments)): ?>
        <div class="section-empty">No payments yet.</div>
      <?php else: ?>
      <div class="section-body" style="padding-top:2px;">
        <?php foreach ($recent_payments as $act): ?>
        <div class="act-row">
          <div class="act-dot"></div>
          <div class="act-info">
            <div class="act-name"><?= htmlspecialchars($act['full_name']) ?></div>
            <div class="act-desc"><?= htmlspecialchars($act['description']) ?></div>
          </div>
          <div class="act-right">
            <div class="act-amount">₱<?= number_format((float)$act['amount'], 0) ?></div>
            <div class="act-time"><?= time_ago($act['paid_at']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Currently Checked In -->
    <div class="section">
      <div class="section-head">
        <span class="section-title">Currently Checked In</span>
        <a href="admin_checkin.php" style="font-size:11px;color:#60a5fa;text-decoration:none;font-weight:600;">Kiosk →</a>
      </div>
      <?php if (empty($currently_active)): ?>
        <div class="section-empty">No one is checked in right now.</div>
      <?php else: ?>
      <div class="section-body" style="padding-top:2px;">
        <?php foreach ($currently_active as $ca): ?>
        <div class="act-row">
          <div class="act-dot pulse"></div>
          <div class="act-info">
            <div class="act-name"><?= htmlspecialchars($ca['full_name']) ?></div>
            <div class="act-desc">In since <?= date('g:i A', strtotime($ca['checked_in_at'])) ?></div>
          </div>
          <div class="act-right">
            <div class="act-amount" style="color:#60a5fa"><?= duration_label($ca['minutes_inside']) ?></div>
            <div class="act-time">inside</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- All Members -->
  <div class="section full-col">
    <div class="section-head">
      <span class="section-title">All Members</span>
      <span class="pill basic"><?= count($all_members) ?> total</span>
    </div>
    <div class="section-body">
      <table>
        <thead><tr><th>Member</th><th>Level</th><th>Joined</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($all_members)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--sub);padding:20px 0;">No members yet.</td></tr>
          <?php else: foreach ($all_members as $m):
            $is_active = $m['expires_at'] && strtotime($m['expires_at']) >= time();
          ?>
          <tr>
            <td>
              <div class="td-name"><?= htmlspecialchars($m['full_name']) ?></div>
              <div class="td-email"><?= htmlspecialchars($m['email']) ?></div>
            </td>
            <td><?= ucfirst($m['fitness_level']) ?></td>
            <td><?= date('M j, Y', strtotime($m['created_at'])) ?></td>
            <td><span class="pill <?= $is_active ? 'active' : 'expired' ?>"><?= $is_active ? 'Active' : 'Expired' ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Revenue -->
  <div class="section full-col">
    <div class="section-head">
      <span class="section-title">Revenue Summary — <?= date('F Y') ?></span>
    </div>
    <div class="section-body" style="padding-top:4px;">
      <div class="rev-row">
        <span class="rev-label">Payments This Month</span>
        <span class="rev-val" style="color:#60a5fa">₱<?= number_format($rev_total, 0) ?></span>
      </div>
      <div class="rev-row" style="font-weight:700;">
        <span style="color:var(--text)">Total Verified</span>
        <span class="rev-val" style="color:#4ade80;font-size:15px;">₱<?= number_format($rev_total, 0) ?></span>
      </div>
    </div>
  </div>

</div>

<script>
  function updateTime() {
    document.getElementById('live-time').textContent =
      new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', hour12:true });
  }
  updateTime(); setInterval(updateTime, 1000);
</script>
</body>
</html>