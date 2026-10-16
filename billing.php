<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }

require_once 'check_expiry.php';

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

$success = '';
$error   = '';

// ── Handle upgrade / renew ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_upgrade'])) {
    $method        = trim($_POST['payment_method'] ?? 'cash');
    $action        = ($_POST['action'] ?? 'upgrade') === 'renew' ? 'Renewal' : 'Premium Coaching';
    $method_labels = ['gcash' => 'GCash', 'cash' => 'Cash', 'bank' => 'Bank Transfer'];
    $method_label  = $method_labels[$method] ?? 'Cash';
    $desc          = $action . ' — ' . $method_label . ' — ' . date('F Y');
    $amount        = 1500.00;

    $proof_path      = null;
    $is_upload_valid = true;

    // GCash and Bank require proof upload
    if ($method === 'gcash' || $method === 'bank') {
        if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_OK) {
            $file_tmp  = $_FILES['proof_of_payment']['tmp_name'];
            $file_name = $_FILES['proof_of_payment']['name'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed   = ['jpg', 'jpeg', 'png'];

            if (in_array($file_ext, $allowed)) {
                $upload_dir = 'uploads/proofs/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $new_name = 'proof_' . $user_id . '_' . time() . '.' . $file_ext;
                $dest     = $upload_dir . $new_name;
                if (move_uploaded_file($file_tmp, $dest)) {
                    $proof_path = $new_name;
                } else {
                    $error = '❌ Failed to save the uploaded image. Check folder permissions.';
                    $is_upload_valid = false;
                }
            } else {
                $error = '❌ Invalid file type. Only JPG, JPEG, and PNG are allowed.';
                $is_upload_valid = false;
            }
        } else {
            $error = '❌ Proof of payment is required for GCash and Bank Transfer.';
            $is_upload_valid = false;
        }
    }

    if ($is_upload_valid) {
        $now     = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

        // Add proof_file column if not exists
        $conn->query("ALTER TABLE payments ADD COLUMN IF NOT EXISTS proof_file VARCHAR(255) DEFAULT NULL");

        $s = $conn->prepare("INSERT INTO payments (user_id, description, amount, status, paid_at, expires_at, notified, proof_file) VALUES (?, ?, ?, 'paid', ?, ?, 0, ?)");
        $s->bind_param("isdsss", $user_id, $desc, $amount, $now, $expires, $proof_path);
        $s->execute(); $s->close();

        // Upgrade to premium instantly
        $s = $conn->prepare("UPDATE users SET tier='premium' WHERE id=?");
        $s->bind_param("i", $user_id); $s->execute(); $s->close();
        $_SESSION['tier'] = 'premium';
        $tier = 'premium';

        $messages = [
            'gcash' => '✓ Premium activated! GCash payment recorded.',
            'cash'  => '✓ Premium activated! Cash payment recorded.',
            'bank'  => '✓ Premium activated! Bank transfer recorded.',
        ];
        header('Location: billing.php?success=' . urlencode($messages[$method] ?? '✓ Premium activated!')); exit;
    } else {
        header('Location: billing.php?error=' . urlencode($error)); exit;
    }
}

// ── Fetch subscription ────────────────────────────────────────────────────────
$subscription = null; $days_left = null; $expiry_pct = 0; $is_expired = false; $awaiting_coach = false;

$r = $conn->prepare("SELECT id, description, amount, paid_at, expires_at FROM payments WHERE user_id=? AND status='paid' AND expires_at IS NOT NULL ORDER BY expires_at DESC LIMIT 1");
$r->bind_param("i", $user_id); $r->execute();
$subscription = $r->get_result()->fetch_assoc(); $r->close();

if ($subscription && $subscription['expires_at']) {
    $now        = time();
    $expire_ts  = strtotime($subscription['expires_at']);
    $start_ts   = strtotime($subscription['paid_at']);
    $total_days = max(1, round(($expire_ts - $start_ts) / 86400));
    $days_left  = max(0, (int)ceil(($expire_ts - $now) / 86400));
    $days_used  = $total_days - $days_left;
    $expiry_pct = min(100, round(($days_used / $total_days) * 100));
    $is_expired = $days_left === 0;
} elseif ($tier === 'premium') {
    $r = $conn->prepare("SELECT id FROM payments WHERE user_id=? AND status='paid' ORDER BY paid_at DESC LIMIT 1");
    $r->bind_param("i", $user_id); $r->execute();
    if ($r->get_result()->fetch_assoc()) $awaiting_coach = true; $r->close();
}

// ── Payment history ────────────────────────────────────────────────────────────
$r = $conn->prepare("SELECT description, amount, status, paid_at, expires_at FROM payments WHERE user_id=? ORDER BY paid_at DESC");
$r->bind_param("i", $user_id); $r->execute();
$payments = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();

$conn->close();

$days_color = '#22c55e';
if ($days_left !== null) {
    if ($days_left <= 3)     $days_color = '#ef4444';
    elseif ($days_left <= 7) $days_color = '#f59e0b';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Billing</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #f59e0b; --accent-dim: rgba(245,158,11,0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --red: #ef4444; --red-dim: rgba(239,68,68,0.1);
      --yellow: #f59e0b; --yellow-dim: rgba(245,158,11,0.1);
      --blue: #3b82f6; --blue-dim: rgba(59,130,246,0.1);
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }
    .sidebar { width: 210px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; }
    .sidebar-logo { padding: 18px 18px 14px; font-size: 15px; font-weight: 700; color: var(--text); border-bottom: 1px solid var(--border); }
    .sidebar-logo span { color: var(--accent); }
    .sidebar-user { padding: 14px 18px; border-bottom: 1px solid var(--border); }
    .user-av { width: 34px; height: 34px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; margin-bottom: 8px; }
    .user-name { font-size: 13px; font-weight: 600; margin-bottom: 3px; }
    .user-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); }
    .user-badge.basic { color: var(--sub); }
    .sidebar-nav { flex: 1; padding: 10px 8px; }
    .nav-lbl { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--sub); padding: 0 10px; margin: 14px 0 5px; }
    .nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; transition: all 0.12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--card); color: var(--text); }
    .nav-item.active { background: var(--accent-dim); color: var(--accent); font-weight: 600; }
    .sidebar-foot { padding: 10px 8px; border-top: 1px solid var(--border); }
    .main { margin-left: 210px; flex: 1; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .content { padding: 24px 28px; }
    .billing-layout { display: flex; gap: 24px; align-items: flex-start; }
    .billing-main { flex: 1; min-width: 0; }
    .billing-receipt { width: 310px; flex-shrink: 0; display: none; position: sticky; top: 76px; }
    .billing-receipt.show { display: block; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 24px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .ct { font-size: 13px; font-weight: 700; }
    .alert-green { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#22c55e;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:14px; }
    .alert-red   { background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:14px; }
    .sub-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 18px 20px; margin-bottom: 14px; }
    .sub-card.expired  { border-color: rgba(239,68,68,0.4);  background: var(--red-dim); }
    .sub-card.warning  { border-color: rgba(245,158,11,0.4); background: var(--yellow-dim); }
    .sub-card.active   { border-color: rgba(34,197,94,0.3);  background: var(--green-dim); }
    .sub-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .sub-title { font-size: 13px; font-weight: 700; }
    .sub-expiry { font-size: 12px; color: var(--muted); }
    .sub-days { font-size: 28px; font-weight: 700; margin-bottom: 2px; }
    .sub-days-lbl { font-size: 11px; color: var(--sub); margin-bottom: 12px; }
    .expiry-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin-bottom: 6px; }
    .expiry-fill { height: 100%; border-radius: 3px; }
    .expiry-dates { display: flex; justify-content: space-between; font-size: 10px; color: var(--sub); }
    .tier-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .tier-opt { border: 2px solid var(--border); border-radius: 8px; padding: 20px; position: relative; }
    .tier-opt.current { border-color: var(--accent); background: var(--accent-dim); }
    .tier-opt-name { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--sub); margin-bottom: 6px; }
    .tier-opt-price { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .tier-opt-desc { font-size: 12px; color: var(--sub); margin-bottom: 14px; }
    .tier-features { list-style: none; display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
    .tier-features li { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 7px; }
    .tier-features li.on { color: var(--text); }
    .dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
    .dot.on { background: var(--green); }
    .dot.off { background: var(--border); }
    .current-badge { position: absolute; top: 12px; right: 12px; background: var(--accent); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; }
    .btn-upgrade { width: 100%; background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 9px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn-upgrade:hover { opacity: 0.85; }
    .btn-renew { width: 100%; background: var(--green); color: #000; border: none; border-radius: 6px; padding: 9px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; }
    .btn-renew:hover { opacity: 0.85; }
    .btn-current { width: 100%; background: transparent; color: var(--sub); border: 1px solid var(--border); border-radius: 6px; padding: 9px; font-family: var(--font); font-size: 13px; cursor: default; }
    .payment-options { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
    .payment-opt { display: flex; align-items: center; gap: 14px; background: var(--surface); border: 2px solid var(--border); border-radius: 8px; padding: 12px 14px; cursor: pointer; transition: all 0.15s; }
    .payment-opt:hover { border-color: var(--accent); }
    .payment-opt.selected { border-color: var(--accent); background: var(--accent-dim); }
    .payment-opt input[type="radio"] { accent-color: var(--accent); width: 16px; height: 16px; flex-shrink: 0; }
    .payment-icon { font-size: 20px; flex-shrink: 0; }
    .payment-name { font-size: 13px; font-weight: 700; margin-bottom: 2px; }
    .payment-detail { font-size: 11px; color: var(--sub); }
    .payment-amount { margin-left: auto; font-size: 14px; font-weight: 700; color: var(--accent); }

    /* ── EXACT UPLOAD STYLES FROM register_page.php ── */
    .upload-section { display: none; margin-top: 14px; margin-bottom: 4px; }
    .upload-section.show { display: block; }
    .upload-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .upload-required { font-size: 9px; font-weight: 700; background: rgba(239,68,68,0.15); color: #f87171; padding: 2px 6px; border-radius: 3px; text-transform: uppercase; letter-spacing: 0.5px; }
    .upload-box { border: 2px dashed var(--border); border-radius: 8px; padding: 20px 16px; text-align: center; cursor: pointer; transition: all 0.15s; position: relative; background: var(--surface); }
    .upload-box:hover { border-color: var(--accent); background: var(--accent-dim); }
    .upload-box.has-file { border-color: var(--green); border-style: solid; background: var(--green-dim); }
    .upload-box.error-border { border-color: var(--red); background: rgba(239,68,68,0.05); }
    .upload-box input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
    .upload-icon { font-size: 26px; margin-bottom: 6px; }
    .upload-text { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 3px; }
    .upload-sub { font-size: 11px; color: var(--sub); }
    .upload-preview { display: none; margin-top: 10px; border-radius: 7px; overflow: hidden; border: 1px solid var(--border); }
    .upload-preview img { width: 100%; max-height: 180px; object-fit: cover; display: block; }
    .upload-preview-name { font-size: 11px; color: var(--sub); padding: 7px 10px; background: var(--surface); display: flex; align-items: center; justify-content: space-between; }
    .upload-preview-remove { font-size: 11px; color: #f87171; cursor: pointer; background: none; border: none; font-family: var(--font); padding: 0; }
    .upload-notice { display: none; margin-top: 6px; font-size: 11px; color: #f87171; font-weight: 600; }
    .upload-notice.show { display: block; }

    /* RECEIPT PANEL */
    .receipt-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .receipt-header { background: linear-gradient(135deg, #1a0a00, #2a1200); padding: 16px 18px; border-bottom: 1px solid var(--border); }
    .receipt-logo { font-size: 12px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
    .receipt-logo span { color: var(--accent); }
    .receipt-title { font-size: 15px; font-weight: 700; margin-bottom: 3px; }
    .receipt-amount { font-size: 26px; font-weight: 700; color: var(--accent); letter-spacing: -0.5px; }
    .receipt-period { font-size: 11px; color: var(--sub); margin-top: 2px; }
    .receipt-body { padding: 16px 18px; }
    .gcash-number { font-size: 22px; font-weight: 700; letter-spacing: 2px; color: var(--text); text-align: center; font-family: monospace; margin-bottom: 3px; }
    .gcash-name { font-size: 12px; color: var(--sub); text-align: center; margin-bottom: 12px; }
    .step-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
    .step-row { display: flex; align-items: flex-start; gap: 9px; font-size: 12px; color: var(--muted); }
    .step-num { width: 18px; height: 18px; border-radius: 50%; background: var(--accent); color: #fff; font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
    .bank-row { display: flex; justify-content: space-between; background: var(--surface); border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; }
    .bank-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--sub); }
    .bank-val { font-size: 12px; font-weight: 600; color: var(--text); font-family: monospace; }
    .cash-box { background: var(--surface); border-radius: 7px; padding: 12px; font-size: 12px; color: var(--sub); line-height: 1.7; margin-bottom: 12px; }
    .cash-box strong { color: var(--text); display: block; margin-bottom: 5px; }
    .receipt-divider { height: 1px; background: var(--border); margin: 12px 0; }
    .tip-box { background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2); border-radius: 6px; padding: 10px 12px; font-size: 11px; color: var(--green); line-height: 1.6; }
    .tip-box strong { display: block; margin-bottom: 2px; }
    .receipt-footer { font-size: 11px; color: var(--sub); text-align: center; margin-top: 12px; }
    .btn-confirm-now { width: 100%; background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 10px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; margin-top: 14px; }
    .btn-confirm-now:hover { opacity: 0.85; }
    .btn-confirm-now:disabled { opacity: 0.4; cursor: not-allowed; }
    .btn-cancel-pay { width: 100%; background: transparent; color: var(--sub); border: 1px solid var(--border); border-radius: 6px; padding: 9px; font-family: var(--font); font-size: 13px; cursor: pointer; margin-top: 6px; }
    .btn-cancel-pay:hover { color: var(--text); }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 11px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
    .pill.paid    { background: var(--green-dim); color: var(--green); }
    .pill.pending { background: var(--yellow-dim); color: var(--yellow); }
    .pill.expired { background: var(--red-dim);   color: var(--red); }
    @media (max-width: 900px) { .billing-receipt { display: none !important; } }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } .tier-cards { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">FitX <span>Training Club</span></div>
  <div class="sidebar-user">
    <div class="user-av"><?= $avatar ?></div>
    <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
    <div class="user-badge <?= $tier === 'basic' ? 'basic' : '' ?>"><?= ucfirst($tier) ?></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-lbl">Main</div>
    <a href="dashboard.php"    class="nav-item">📊 Dashboard</a>
    <a href="workouts.php"     class="nav-item">🏋️ Workouts</a>
    <a href="progress.php"     class="nav-item">📈 Progress</a>
    <a href="body_metrics.php" class="nav-item">⚖️ Body Metrics</a>
    <?php if ($tier === 'premium'): ?>
    <div class="nav-lbl">Premium</div>
    <a href="my_coach.php" class="nav-item">👤 My Coach</a>
    <a href="my_plan.php"  class="nav-item">📋 My Plan</a>
    <a href="messages.php" class="nav-item">💬 Messages</a>
    <?php endif; ?>
    <div class="nav-lbl">Account</div>
    <a href="profile.php"  class="nav-item">⚙️ Profile</a>
    <a href="billing.php"  class="nav-item active">💳 Billing</a>
  </nav>
  <div class="sidebar-foot"><a href="logout.php" class="nav-item">🚪 Log Out</a></div>
</aside>

<main class="main">
  <div class="topbar"><div class="page-title">Billing</div></div>
  <div class="content">

    <?php if (isset($_GET['success'])): ?><div class="alert-green">✓ <?= htmlspecialchars($_GET['success']) ?></div><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="alert-red"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

    <div class="billing-layout">
      <div class="billing-main">

        <!-- SUBSCRIPTION STATUS -->
        <?php if ($tier === 'premium' && ($subscription || $awaiting_coach)): ?>
          <?php $card_class='active'; if($is_expired) $card_class='expired'; elseif($days_left!==null&&$days_left<=7) $card_class='warning'; ?>
          <?php if ($awaiting_coach && !$subscription): ?>
          <div class="sub-card active">
            <div class="sub-header"><div class="sub-title">✅ Premium Active</div><div class="sub-expiry">Awaiting coach assignment</div></div>
            <div class="sub-days" style="color:var(--green)">—</div>
            <div class="sub-days-lbl">30-day timer starts when your coach is assigned</div>
            <div class="expiry-bar"><div class="expiry-fill" style="width:0%;background:var(--green)"></div></div>
            <div style="margin-top:10px;font-size:12px;color:var(--sub);">⏳ Admin will assign a coach shortly.</div>
          </div>
          <?php elseif ($subscription): ?>
          <div class="sub-card <?= $card_class ?>">
            <div class="sub-header">
              <div class="sub-title"><?php if($is_expired): ?>❌ Premium Expired<?php elseif($days_left<=7): ?>⚠️ Expiring Soon<?php else: ?>✅ Premium Active<?php endif; ?></div>
              <div class="sub-expiry"><?= $is_expired?'Expired':'Expires' ?> <?= date('M j, Y',strtotime($subscription['expires_at'])) ?></div>
            </div>
            <div class="sub-days" style="color:<?= $days_color ?>"><?= $is_expired?'0':$days_left ?></div>
            <div class="sub-days-lbl"><?= $is_expired?'Subscription expired':'Days remaining' ?></div>
            <div class="expiry-bar"><div class="expiry-fill" style="width:<?= $expiry_pct ?>%;background:<?= $is_expired?'#ef4444':($days_left<=7?'#f59e0b':'#22c55e') ?>"></div></div>
            <div class="expiry-dates"><span>Started <?= date('M j, Y',strtotime($subscription['paid_at'])) ?></span><span>Expires <?= date('M j, Y',strtotime($subscription['expires_at'])) ?></span></div>
            <?php if ($is_expired||$days_left<=7): ?>
              <div style="margin-top:14px;"><button class="btn-renew" onclick="openPayment('renew')">🔄 Renew Premium — ₱1,500/month</button></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- MEMBERSHIP PLAN -->
        <div class="card">
          <div class="ch"><div class="ct">Membership Plan</div></div>
          <div class="tier-cards">
            <div class="tier-opt <?= $tier==='basic'?'current':'' ?>">
              <?php if($tier==='basic'): ?><div class="current-badge">Current</div><?php endif; ?>
              <div class="tier-opt-name">Basic</div>
              <div class="tier-opt-price">Free</div>
              <div class="tier-opt-desc">With gym membership</div>
              <ul class="tier-features">
                <li class="on"><span class="dot on"></span>Workout logging</li>
                <li class="on"><span class="dot on"></span>Body metrics tracking</li>
                <li class="on"><span class="dot on"></span>Progress charts</li>
                <li><span class="dot off"></span>Assigned coach</li>
                <li><span class="dot off"></span>Custom plans</li>
                <li><span class="dot off"></span>Nutrition plan</li>
                <li><span class="dot off"></span>Direct messaging</li>
              </ul>
              <button class="btn-current">Basic Plan</button>
            </div>
            <div class="tier-opt <?= $tier==='premium'?'current':'' ?>">
              <?php if($tier==='premium'): ?><div class="current-badge">Current</div><?php endif; ?>
              <div class="tier-opt-name">Premium</div>
              <div class="tier-opt-price">₱1,500 <span style="font-size:13px;color:var(--sub)">/mo</span></div>
              <div class="tier-opt-desc">Full coaching experience</div>
              <ul class="tier-features">
                <li class="on"><span class="dot on"></span>Everything in Basic</li>
                <li class="on"><span class="dot on"></span>Assigned certified coach</li>
                <li class="on"><span class="dot on"></span>Custom workout plan</li>
                <li class="on"><span class="dot on"></span>Nutrition plan</li>
                <li class="on"><span class="dot on"></span>Direct messaging</li>
              </ul>
              <?php if($tier==='premium'&&$awaiting_coach): ?>
                <button class="btn-current">✅ Active — Awaiting Coach</button>
              <?php elseif($tier==='premium'&&!$is_expired&&$days_left!==null&&$days_left>7): ?>
                <button class="btn-current">Premium Active</button>
              <?php elseif($tier==='premium'&&($is_expired||($days_left!==null&&$days_left<=7))): ?>
                <button class="btn-renew" onclick="openPayment('renew')">🔄 Renew Premium</button>
              <?php elseif($tier==='premium'): ?>
                <button class="btn-current">Premium Active</button>
              <?php else: ?>
                <button class="btn-upgrade" onclick="openPayment('upgrade')">⚡ Upgrade to Premium</button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- PAYMENT HISTORY -->
        <div class="card">
          <div class="ch"><div class="ct">Payment History</div></div>
          <table>
            <thead><tr><th>Description</th><th>Amount</th><th>Date</th><th>Expires</th><th>Status</th></tr></thead>
            <tbody>
              <?php if(empty($payments)): ?>
                <tr><td colspan="5" style="color:var(--sub);text-align:center;padding:20px 0;">No payment history yet.</td></tr>
              <?php else: foreach($payments as $p):
                $p_expired=$p['expires_at']&&strtotime($p['expires_at'])<time()&&$p['status']==='paid';
              ?>
                <tr>
                  <td><?= htmlspecialchars($p['description']) ?></td>
                  <td>₱<?= number_format($p['amount'],0) ?></td>
                  <td><?= $p['paid_at']?date('M j, Y',strtotime($p['paid_at'])):'—' ?></td>
                  <td><?= $p['expires_at']?date('M j, Y',strtotime($p['expires_at'])):'—' ?></td>
                  <td><?php if($p_expired): ?><span class="pill expired">Expired</span><?php else: ?><span class="pill <?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span><?php endif; ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </div><!-- /billing-main -->

      <!-- RECEIPT SIDE PANEL -->
      <div class="billing-receipt" id="receipt-panel">
        <div class="receipt-card">
          <div class="receipt-header">
            <div class="receipt-logo">FitX <span>Training Club</span></div>
            <div class="receipt-title" id="receipt-title">Premium Payment</div>
            <div class="receipt-amount">₱1,500.00</div>
            <div class="receipt-period">30-day Premium Coaching Access</div>
          </div>
          <div class="receipt-body">

            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:8px;">Select Payment Method</div>
            <div class="payment-options">
              <label class="payment-opt" id="opt-gcash" onclick="selPay('gcash')">
                <input type="radio" name="pay_billing" value="gcash"/>
                <div class="payment-icon">💙</div>
                <div><div class="payment-name">GCash</div><div class="payment-detail">Send to our number</div></div>
                <div class="payment-amount">₱1,500</div>
              </label>
              <label class="payment-opt" id="opt-cash" onclick="selPay('cash')">
                <input type="radio" name="pay_billing" value="cash"/>
                <div class="payment-icon">💵</div>
                <div><div class="payment-name">Cash</div><div class="payment-detail">Front desk</div></div>
                <div class="payment-amount">₱1,500</div>
              </label>
              <label class="payment-opt" id="opt-bank" onclick="selPay('bank')">
                <input type="radio" name="pay_billing" value="bank"/>
                <div class="payment-icon">🏦</div>
                <div><div class="payment-name">Bank Transfer</div><div class="payment-detail">BDO account</div></div>
                <div class="payment-amount">₱1,500</div>
              </label>
            </div>

            <!-- GCash details -->
            <div id="detail-gcash" style="display:none;">
              <div class="gcash-number">0955 946 0103</div>
              <div class="gcash-name">FitX Training Club</div>
              <div class="step-list">
                <div class="step-row"><div class="step-num">1</div><span>Open GCash → Send Money</span></div>
                <div class="step-row"><div class="step-num">2</div><span>Enter <strong>0955 946 0103</strong></span></div>
                <div class="step-row"><div class="step-num">3</div><span>Send <strong>₱1,500.00</strong></span></div>
                <div class="step-row"><div class="step-num">4</div><span>Screenshot confirmation then upload below</span></div>
              </div>
              <div class="tip-box"><strong>📸 Screenshot required!</strong>Upload your GCash confirmation below before confirming.</div>
            </div>

            <!-- Cash details -->
            <div id="detail-cash" style="display:none;">
              <div class="cash-box"><strong>💵 Pay at Front Desk</strong>Pay ₱1,500 cash and give staff your email. Get your receipt.</div>
              <div class="step-list">
                <div class="step-row"><div class="step-num">1</div><span>Pay ₱1,500 at the gym cashier</span></div>
                <div class="step-row"><div class="step-num">2</div><span>Give staff your email address</span></div>
                <div class="step-row"><div class="step-num">3</div><span>Click Confirm below — activates instantly</span></div>
              </div>
            </div>

            <!-- Bank details -->
            <div id="detail-bank" style="display:none;">
              <div class="bank-row"><span class="bank-lbl">Bank</span><span class="bank-val">BDO Savings</span></div>
              <div class="bank-row"><span class="bank-lbl">Account Name</span><span class="bank-val">FitX Training Club</span></div>
              <div class="bank-row"><span class="bank-lbl">Account No.</span><span class="bank-val">0967 639 0528</span></div>
              <div class="bank-row" style="margin-bottom:10px;"><span class="bank-lbl">Amount</span><span class="bank-val" style="color:var(--accent)">₱1,500.00</span></div>
              <div class="step-list">
                <div class="step-row"><div class="step-num">1</div><span>Open your banking app → Transfer</span></div>
                <div class="step-row"><div class="step-num">2</div><span>Transfer to <strong>0967 639 0528</strong></span></div>
                <div class="step-row"><div class="step-num">3</div><span>Amount: <strong>₱1,500.00</strong></span></div>
                <div class="step-row"><div class="step-num">4</div><span>Screenshot confirmation then upload below</span></div>
              </div>
              <div class="tip-box"><strong>📸 Screenshot required!</strong>Upload your transfer slip below before confirming.</div>
            </div>

            <div class="receipt-divider"></div>

            <!-- FORM — exact same upload structure as register_page.php -->
            <form method="POST" id="billing-form" enctype="multipart/form-data">
              <input type="hidden" name="request_upgrade" value="1"/>
              <input type="hidden" name="payment_method"  id="billing-method" value=""/>
              <input type="hidden" name="action"          id="billing-action"  value="upgrade"/>

              <!-- UPLOAD SECTION — shown only for GCash/Bank -->
              <div class="upload-section" id="upload-section">
                <div class="upload-lbl">
                  Proof of Payment
                  <span class="upload-required">Required</span>
                </div>
                <div class="upload-box" id="upload-box"
                     ondragover="event.preventDefault();this.classList.add('dragover')"
                     ondragleave="this.classList.remove('dragover')"
                     ondrop="handleDrop(event)">
                  <input type="file" name="proof_of_payment" id="proof-input"
                         accept="image/jpeg,image/jpg,image/png"
                         onchange="handleFile(this)"/>
                  <div class="upload-icon" id="upload-icon">📎</div>
                  <div class="upload-text" id="upload-text">Upload your receipt or screenshot</div>
                  <div class="upload-sub" id="upload-sub">PNG, JPG — max 5MB · Click or drag & drop</div>
                </div>
                <div class="upload-preview" id="upload-preview">
                  <img id="preview-img" src="" alt="Receipt preview"/>
                  <div class="upload-preview-name">
                    <span id="preview-filename"></span>
                    <button type="button" class="upload-preview-remove" onclick="removeFile()">✕ Remove</button>
                  </div>
                </div>
                <div class="upload-notice" id="upload-notice">
                  ⚠ Please upload your proof of payment before confirming.
                </div>
              </div>

              <button type="submit" class="btn-confirm-now" id="btn-confirm-now" disabled>Select a payment method</button>
            </form>

            <button class="btn-cancel-pay" onclick="closePayment()">Cancel</button>
            <div class="receipt-footer" id="receipt-footer">Premium activates instantly after confirming.</div>
          </div>
        </div>
      </div><!-- /receipt panel -->

    </div><!-- /billing-layout -->
  </div>
</main>

<script>
  // ── Exact same JS logic as register_page.php ──────────────────────────────
  let currentAction  = 'upgrade';
  let selectedMethod = '';
  let hasFile        = false;

  function openPayment(action) {
    currentAction = action;
    document.getElementById('billing-action').value = action;
    document.getElementById('receipt-title').textContent =
      action === 'renew' ? '🔄 Renew Premium' : '⚡ Upgrade to Premium';
    document.getElementById('receipt-panel').classList.add('show');

    // Reset everything
    selectedMethod = '';
    hasFile = false;
    document.getElementById('billing-method').value = '';
    document.querySelectorAll('.payment-opt').forEach(o => o.classList.remove('selected'));
    document.querySelectorAll('input[name="pay_billing"]').forEach(r => r.checked = false);
    hideAllDetails();
    removeFile();
    document.getElementById('upload-section').classList.remove('show');
    updateSubmitState();
  }

  function closePayment() {
    document.getElementById('receipt-panel').classList.remove('show');
    selectedMethod = '';
    hasFile = false;
    hideAllDetails();
    document.querySelectorAll('.payment-opt').forEach(o => o.classList.remove('selected'));
    removeFile();
    document.getElementById('upload-section').classList.remove('show');
  }

  function selPay(method) {
    selectedMethod = method;
    document.getElementById('billing-method').value = method;

    document.querySelectorAll('.payment-opt').forEach(o => o.classList.remove('selected'));
    document.getElementById('opt-' + method).classList.add('selected');

    hideAllDetails();
    document.getElementById('detail-' + method).style.display = 'block';

    // Show upload only for GCash and Bank — same logic as register_page.php
    if (method === 'gcash' || method === 'bank') {
      document.getElementById('upload-section').classList.add('show');
    } else {
      document.getElementById('upload-section').classList.remove('show');
      hasFile = false; // cash doesn't need file
    }

    updateSubmitState();
  }

  function hideAllDetails() {
    ['gcash', 'cash', 'bank'].forEach(m => {
      document.getElementById('detail-' + m).style.display = 'none';
    });
  }

  // ── Exact same handleFile as register_page.php ────────────────────────────
  function handleFile(input) {
    const file = input.files[0];
    if (!file) { hasFile = false; updateSubmitState(); return; }

    if (file.size > 5 * 1024 * 1024) {
      alert('File is too large. Maximum size is 5MB.');
      input.value = '';
      hasFile = false;
      updateSubmitState();
      return;
    }

    hasFile = true;
    const box = document.getElementById('upload-box');
    box.classList.add('has-file');
    box.classList.remove('error-border');
    document.getElementById('upload-notice').classList.remove('show');
    document.getElementById('preview-filename').textContent =
      file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';

    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = e => {
        document.getElementById('preview-img').src = e.target.result;
        document.getElementById('preview-img').style.display = 'block';
        document.getElementById('upload-preview').style.display = 'block';
      };
      reader.readAsDataURL(file);
    } else {
      document.getElementById('preview-img').style.display = 'none';
      document.getElementById('upload-preview').style.display = 'block';
    }

    document.getElementById('upload-icon').textContent = '✅';
    document.getElementById('upload-text').textContent  = 'Receipt uploaded!';
    document.getElementById('upload-sub').textContent   = 'Click or drag to replace';

    updateSubmitState();
  }

  // ── Exact same handleDrop as register_page.php ────────────────────────────
  function handleDrop(e) {
    e.preventDefault();
    document.getElementById('upload-box').classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) {
      const input = document.getElementById('proof-input');
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      handleFile(input);
    }
  }

  // ── Exact same removeFile as register_page.php ────────────────────────────
  function removeFile() {
    const input = document.getElementById('proof-input');
    if (input) input.value = '';
    const preview = document.getElementById('upload-preview');
    if (preview) preview.style.display = 'none';
    const img = document.getElementById('preview-img');
    if (img) img.src = '';
    const box = document.getElementById('upload-box');
    if (box) box.classList.remove('has-file');
    const icon = document.getElementById('upload-icon');
    if (icon) icon.textContent = '📎';
    const text = document.getElementById('upload-text');
    if (text) text.textContent = 'Upload your receipt or screenshot';
    const sub = document.getElementById('upload-sub');
    if (sub) sub.textContent = 'PNG, JPG — max 5MB · Click or drag & drop';
    hasFile = false;
    updateSubmitState();
  }

  // ── Exact same updateSubmitState as register_page.php ────────────────────
  function updateSubmitState() {
    const btn = document.getElementById('btn-confirm-now');
    if (!selectedMethod) {
      btn.disabled = true;
      btn.textContent = 'Select a payment method';
      return;
    }
    // Cash needs no file
    if (selectedMethod === 'cash') {
      btn.disabled = false;
      btn.textContent = '✓ Confirm — Paid at Front Desk';
      return;
    }
    // GCash / Bank need file
    if (!hasFile) {
      btn.disabled = true;
      btn.textContent = 'Upload proof of payment to continue';
    } else {
      btn.disabled = false;
      const labels = {
        gcash: '✓ Confirm — Paid via GCash',
        bank:  '✓ Confirm — Paid via Bank Transfer'
      };
      btn.textContent = labels[selectedMethod] + ' ✓';
    }
  }
</script>
</body>
</html>