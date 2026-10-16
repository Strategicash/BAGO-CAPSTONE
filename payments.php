<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'] ?? 'Admin';

// Approve a pending payment (merged in from admin_approve_payments.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id'])) {
    $payment_id = intval($_POST['payment_id']);
    $duration = intval($_POST['duration_days']);
    $stmt = $conn->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), plan_duration_days = ? WHERE id = ?");
    $stmt->bind_param("iii", $duration, $duration, $payment_id);
    $stmt->execute();
    $stmt->close();
    header('Location: payments.php?approved=1');
    exit;
}

$filter = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['all', 'paid', 'pending'], true)) $filter = 'pending';

$where = "1=1";
if ($filter === 'paid') $where = "p.status = 'paid'";
if ($filter === 'pending') $where = "p.status = 'pending'";

$r = $conn->query("
    SELECT p.id AS payment_id, p.description, p.amount, p.status, p.paid_at,
           p.proof_file, p.reference_number,
           u.id AS user_id, u.full_name, u.email, u.tier
    FROM payments p JOIN users u ON u.id = p.user_id
    WHERE $where ORDER BY (p.status='pending') DESC, p.paid_at DESC
");
$rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
if ($r) $r->close();

// Stats
$r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_count = (int)$r->fetch_assoc()['c']; $r->close();
$r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status='paid'"); $paid_count = (int)$r->fetch_assoc()['c']; $r->close();
$r = $conn->query("SELECT COUNT(*) c FROM users WHERE tier='premium'"); $premium_count = (int)$r->fetch_assoc()['c']; $r->close();
$r = $conn->query("SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())"); $revenue_month = (float)$r->fetch_assoc()['t']; $r->close();
$r = $conn->query("SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='paid'"); $revenue_all = (float)$r->fetch_assoc()['t']; $r->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Payments</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;
      --text:#f0f0f0;--sub:#666;--muted:#999;
      --red:#dc2626;--red-dim:rgba(220,38,38,0.12);
      --green:#16a34a;--green-dim:rgba(22,163,74,0.12);
      --blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);
      --amber:#d97706;--amber-dim:rgba(217,119,6,0.12);
      --font:'Inter',sans-serif;--nav-h:56px;--banner-h:140px
    }
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh}

    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px}
    .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}
    .nav-logo{width:32px;height:32px;border-radius:6px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
    .nav-brand-text{font-size:14px;font-weight:700;color:var(--text)}.nav-brand-text span{color:var(--red)}
    .nav-divider{width:1px;height:20px;background:var(--border2);flex-shrink:0}
    .nav-links{display:flex;align-items:center;gap:2px;flex:1}
    .nav-link{display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:6px;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;transition:all 0.15s;white-space:nowrap}
    .nav-link:hover{background:var(--card);color:var(--text)}.nav-link.active{background:var(--red-dim);color:var(--red);font-weight:600}
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
    .banner-title{font-size:22px;font-weight:700;letter-spacing:-0.5px}
    .banner-sub{font-size:13px;color:var(--muted);margin-top:5px}
    .banner-right{text-align:right}.banner-stat-val{font-size:22px;font-weight:700;line-height:1}
    .banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}

    .wrapper{max-width:1400px;margin:0 auto;padding:24px 32px}

    .info-bar{background:var(--blue-dim);border:1px solid rgba(37,99,235,0.25);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:12px;color:#93c5fd;display:flex;align-items:center;gap:10px}
    .info-bar strong{color:#60a5fa}

    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
    .scard{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:18px 20px;display:flex;align-items:center;gap:14px}
    .scard-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .ic-blue{background:var(--blue-dim)}.ic-green{background:var(--green-dim)}.ic-amber{background:var(--amber-dim)}.ic-red{background:var(--red-dim)}
    .scard-val{font-size:26px;font-weight:700;letter-spacing:-0.5px;line-height:1}
    .scard-lbl{font-size:12px;color:var(--sub);margin-top:3px;font-weight:500}
    .scard-note{font-size:11px;color:var(--muted);margin-top:2px}

    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-body{padding:0 20px 16px}
    .section-empty{padding:28px 20px;text-align:center;font-size:13px;color:var(--sub)}
    .filter-bar{display:flex;gap:6px;align-items:center}
    .filter-link{font-size:12px;padding:5px 11px;border-radius:6px;border:1px solid var(--border);color:var(--muted);text-decoration:none;transition:all 0.15s}
    .filter-link:hover{color:var(--text);border-color:var(--border2)}.filter-link.on{background:var(--blue-dim);border-color:#60a5fa;color:#60a5fa;font-weight:600}

    .pill{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px}
    .pill.paid{background:var(--green-dim);color:#4ade80}
    .pill.pending{background:var(--amber-dim);color:#fbbf24}
    table{width:100%;border-collapse:collapse}
    thead th{text-align:left;padding:12px 0 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);border-bottom:1px solid var(--border)}
    tbody td{padding:11px 0;font-size:13px;border-bottom:1px solid var(--border);color:var(--muted);vertical-align:middle}
    tbody tr:last-child td{border-bottom:none}
    .td-name{font-weight:600;color:var(--text)}.td-email{font-size:11px;color:var(--sub);margin-top:1px}
    .btn-proof{display:inline-block;background:var(--blue-dim);color:#60a5fa;border:1px solid rgba(96,165,250,0.3);border-radius:5px;padding:5px 10px;font-size:11px;font-weight:600;text-decoration:none;transition:all 0.15s}
    .btn-proof:hover{background:rgba(37,99,235,0.2)}
    .tier-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px}
    .tier-premium{background:var(--amber-dim);color:#fbbf24}
    .tier-basic{background:var(--border);color:var(--muted)}
    .ref{display:inline-block;font-family:monospace;font-size:11px;background:var(--surface);border:1px solid var(--border2);color:#60a5fa;padding:3px 8px;border-radius:5px}
    .approve-form{display:flex;gap:6px;align-items:center}
    .approve-form select{background:var(--surface);border:1px solid var(--border2);border-radius:5px;color:var(--text);padding:6px 8px;font-family:var(--font);font-size:12px;outline:none}
    .approve-form select:focus{border-color:#60a5fa}
    .btn-approve{background:var(--green);color:#fff;border:none;border-radius:5px;padding:6px 12px;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer}
    .btn-approve:hover{background:#128a3c}

    @media(max-width:1100px){.stats-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:768px){.nav-links{display:none}.wrapper{padding:20px 16px}.banner-content{padding:0 20px}.banner-right{display:none}}
  </style>
</head>
<body>

<nav class="topnav">
  <a href="admin.php" class="nav-brand">
    <div class="nav-logo">FX</div>
    <div class="nav-brand-text">FitX <span>Admin</span></div>
  </a>
  <div class="nav-divider"></div>
  <div class="nav-links">
    <a href="admin.php"               class="nav-link">⊞ Dashboard</a>
    <a href="all_members.php"         class="nav-link">👥 Members</a>
    <a href="payments.php"            class="nav-link active">💳 Payments</a>
    <a href="admin_enroll_rfid.php"     class="nav-link">📶 Enroll RFID</a>
    <a href="admin_checkin.php"         class="nav-link">🚪 Check-In Kiosk</a>
    <a href="analytics.php"          class="nav-link">📈 Analytics</a>
    <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
    <a href="admin_access_codes.php" class="nav-link">🔑 Access Codes</a>
    <a href="settings.php"            class="nav-link">⚙️ Settings</a>
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

<div class="banner">
  <div class="banner-fallback"></div>
  <div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin — Finance</div>
      <div class="banner-title">Payments</div>
      <div class="banner-sub">Review reference numbers, approve pending payments, and track revenue</div>
    </div>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#4ade80">₱<?= number_format($revenue_month, 0) ?></div>
      <div class="banner-stat-lbl">Revenue this month</div>
    </div>
  </div>
</div>

<div class="wrapper">

  <?php if (isset($_GET['approved'])): ?>
    <div class="info-bar" style="background:var(--green-dim);border-color:rgba(22,163,74,0.25);color:#86efac;">
      <span style="font-size:18px;">✅</span>
      <span><strong>Payment approved</strong> — membership activated.</span>
    </div>
  <?php endif; ?>

  <div class="info-bar">
    <span style="font-size:18px;">🧾</span>
    <span>
      Members submit a <strong>reference number</strong> (plus optional proof photo) when they pay.
      Review it here and approve to activate their plan.
    </span>
  </div>

  <div class="stats-grid">

    <div class="scard">
      <div class="scard-icon ic-amber">⏳</div>
      <div>
        <div class="scard-val" style="color:#fbbf24"><?= $pending_count ?></div>
        <div class="scard-lbl">Pending Approval</div>
        <div class="scard-note">Needs review</div>
      </div>
    </div>

    <div class="scard">
      <div class="scard-icon ic-green">✅</div>
      <div>
        <div class="scard-val" style="color:#4ade80"><?= $paid_count ?></div>
        <div class="scard-lbl">Total Transactions</div>
        <div class="scard-note">All time</div>
      </div>
    </div>

    <div class="scard">
      <div class="scard-icon ic-blue">📅</div>
      <div>
        <div class="scard-val" style="color:#60a5fa;font-size:20px;">₱<?= number_format($revenue_month, 0) ?></div>
        <div class="scard-lbl">Revenue this month</div>
        <div class="scard-note"><?= date('F Y') ?></div>
      </div>
    </div>

    <div class="scard">
      <div class="scard-icon ic-red">💰</div>
      <div>
        <div class="scard-val" style="font-size:20px;">₱<?= number_format($revenue_all, 0) ?></div>
        <div class="scard-lbl">Total Revenue</div>
        <div class="scard-note">All time</div>
      </div>
    </div>

  </div>

  <div class="section">

    <div class="section-head">
      <span class="section-title">Transaction Records</span>
      <div class="filter-bar">
        <span style="font-size:12px;color:var(--sub)">Filter:</span>
        <a href="payments.php?status=pending" class="filter-link <?= $filter==='pending'?'on':'' ?>">Pending</a>
        <a href="payments.php?status=paid"    class="filter-link <?= $filter==='paid'?'on':'' ?>">Paid</a>
        <a href="payments.php?status=all"     class="filter-link <?= $filter==='all'?'on':'' ?>">All</a>
      </div>
    </div>

    <?php if (empty($rows)): ?>
      <div class="section-empty">No transactions found.</div>
    <?php else: ?>

    <div class="section-body" style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>Member</th>
            <th>Description</th>
            <th>Amount</th>
            <th>Reference #</th>
            <th>Proof</th>
            <th>Status</th>
            <th>Date / Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>

            <td>
              <div class="td-name"><?= htmlspecialchars($row['full_name']) ?></div>
              <div class="td-email"><?= htmlspecialchars($row['email']) ?></div>
            </td>

            <td><?= htmlspecialchars($row['description']) ?></td>

            <td style="color:var(--text);font-weight:600;">
              ₱<?= number_format((float)$row['amount'], 0) ?>
            </td>

            <td><span class="ref">#<?= htmlspecialchars($row['reference_number'] ?? 'none') ?></span></td>

            <td>
              <?php if (!empty($row['proof_file'])):
                $raw  = $row['proof_file'];
                $base = (strpos($raw, 'uploads/') === false) ? 'uploads/proofs/' . $raw : $raw;
                $path = file_exists($base) ? $base : '../' . $base;
              ?>
                <a href="<?= htmlspecialchars($path) ?>" target="_blank" class="btn-proof">View Proof</a>
              <?php else: ?>
                <span style="font-size:11px;color:var(--sub);">No Upload</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($row['status'] === 'paid'): ?>
                <span class="pill paid">✓ Paid</span>
              <?php else: ?>
                <span class="pill pending">⏳ Pending</span>
              <?php endif; ?>
            </td>

            <td style="font-size:12px;">
              <?php if ($row['status'] === 'paid'): ?>
                <?= date('M j, Y g:i A', strtotime($row['paid_at'])) ?>
              <?php else: ?>
                <form method="POST" class="approve-form">
                  <input type="hidden" name="payment_id" value="<?= $row['payment_id'] ?>">
                  <select name="duration_days">
                    <option value="7">1 Week</option>
                    <option value="30" selected>1 Month</option>
                    <option value="90">3 Months</option>
                    <option value="365">1 Year</option>
                  </select>
                  <button type="submit" class="btn-approve">Approve</button>
                </form>
              <?php endif; ?>
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
  function updateTime() {
    document.getElementById('live-time').textContent =
      new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', hour12:true });
  }
  updateTime();
  setInterval(updateTime, 1000);
</script>

</body>
</html>