<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'];
$admin_id   = $_SESSION['user_id'];
$success=''; $error='';

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['update_profile'])) {
    $nn=trim($_POST['full_name']??''); $ne=trim($_POST['email']??'');
    if (empty($nn)||empty($ne)) { $error='Name and email cannot be empty.'; }
    elseif (!filter_var($ne,FILTER_VALIDATE_EMAIL)) { $error='Invalid email address.'; }
    else {
        $chk=$conn->prepare("SELECT id FROM users WHERE email=? AND id!=?"); $chk->bind_param("si",$ne,$admin_id); $chk->execute(); $chk->store_result();
        if ($chk->num_rows>0) { $error='That email is already used by another account.'; }
        else { $s=$conn->prepare("UPDATE users SET full_name=?,email=? WHERE id=?"); $s->bind_param("ssi",$nn,$ne,$admin_id); $s->execute(); $s->close(); $_SESSION['full_name']=$nn; $admin_name=$nn; $success='Profile updated successfully!'; }
        $chk->close();
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['change_password'])) {
    $cur=trim($_POST['current_password']??''); $np=trim($_POST['new_password']??''); $conf=trim($_POST['confirm_password']??'');
    $r=$conn->prepare("SELECT password FROM users WHERE id=?"); $r->bind_param("i",$admin_id); $r->execute(); $row=$r->get_result()->fetch_assoc(); $r->close();
    if (!password_verify($cur,$row['password'])) { $error='Current password is incorrect.'; }
    elseif ($np!==$conf) { $error='New passwords do not match.'; }
    elseif (strlen($np)<6) { $error='New password must be at least 6 characters.'; }
    else { $h=password_hash($np,PASSWORD_BCRYPT); $s=$conn->prepare("UPDATE users SET password=? WHERE id=?"); $s->bind_param("si",$h,$admin_id); $s->execute(); $s->close(); $success='Password changed successfully!'; }
}

$conn->query("CREATE TABLE IF NOT EXISTS gym_settings (id INT AUTO_INCREMENT PRIMARY KEY, skey VARCHAR(100) NOT NULL UNIQUE, sval TEXT DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['update_gym'])) {
    $fields=['gym_name'=>trim($_POST['gym_name']??''),'gym_email'=>trim($_POST['gym_email']??''),'gym_phone'=>trim($_POST['gym_phone']??''),'gym_address'=>trim($_POST['gym_address']??''),'premium_price'=>trim($_POST['premium_price']??''),'gcash_number'=>trim($_POST['gcash_number']??''),'gcash_name'=>trim($_POST['gcash_name']??''),'bank_name'=>trim($_POST['bank_name']??''),'bank_account'=>trim($_POST['bank_account']??''),'bank_account_name'=>trim($_POST['bank_account_name']??'')];
    foreach($fields as $k=>$v){$s=$conn->prepare("INSERT INTO gym_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=?"); $s->bind_param("sss",$k,$v,$v); $s->execute(); $s->close();}
    $success='Gym settings saved successfully!';
}

$r=$conn->query("SELECT skey,sval FROM gym_settings"); $rows=$r->fetch_all(MYSQLI_ASSOC); $r->close();
$gs=[]; foreach($rows as $row) $gs[$row['skey']]=$row['sval'];

// FIXED: Removed the ₱ and PHP tags from the default array value. This should be a clean number.
$gs+=['gym_name'=>'FitX Training Club Gym','gym_email'=>'','gym_phone'=>'','gym_address'=>'','premium_price'=>'1500','gcash_number'=>'0917 123 4567','gcash_name'=>'FitX Training Club Gym','bank_name'=>'BDO','bank_account'=>'1234 5678 9012','bank_account_name'=>'FitX Training Club Gym'];

$r=$conn->prepare("SELECT full_name,email FROM users WHERE id=?"); $r->bind_param("i",$admin_id); $r->execute(); $ap=$r->get_result()->fetch_assoc(); $r->close();
$r=$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_payments=(int)$r->fetch_assoc()['c']; $r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Settings</title>
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
    .nav-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}.nav-time{font-size:12px;color:var(--sub)}
    .nav-admin-chip{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border2);border-radius:20px;padding:4px 12px 4px 5px}
    .nav-avatar{width:24px;height:24px;border-radius:50%;background:var(--red);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
    .nav-admin-name{font-size:12px;font-weight:600}.nav-logout{font-size:12px;color:var(--sub);text-decoration:none;padding:5px 9px;border-radius:5px;border:1px solid var(--border);transition:all 0.15s}
    .nav-logout:hover{color:var(--text);border-color:var(--border2)}
    .banner{position:relative;height:var(--banner-h);overflow:hidden;border-bottom:1px solid var(--border)}
    .banner-fallback{position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),repeating-linear-gradient(0deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),linear-gradient(135deg,#130303 0%,#1c0505 50%,#0d0d0d 100%)}
    .banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(13,13,13,0.88) 0%,rgba(13,13,13,0.5) 55%,rgba(13,13,13,0.15) 100%)}
    .banner-content{position:absolute;inset:0;display:flex;align-items:center;padding:0 40px}
    .banner-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
    .banner-title{font-size:22px;font-weight:700;letter-spacing:-0.5px}.banner-sub{font-size:13px;color:var(--muted);margin-top:5px}
    .wrapper{max-width:900px;margin:0 auto;padding:24px 32px}
    .alert-ok{background:rgba(22,163,74,0.12);border:1px solid rgba(22,163,74,0.2);color:#4ade80;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .alert-err{background:rgba(220,38,38,0.12);border:1px solid rgba(220,38,38,0.2);color:#f87171;font-size:13px;padding:10px 16px;border-radius:7px;margin-bottom:18px;font-weight:500}
    .section{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}
    .section-head{padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.018)}
    .section-title{font-size:13px;font-weight:700}
    .section-sub{font-size:11px;color:var(--sub)}
    .section-body{padding:20px}
    .form-gap{display:flex;flex-direction:column;gap:14px}
    .g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    .field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);margin-bottom:5px}
    .field input{width:100%;background:var(--surface);border:1px solid var(--border2);border-radius:6px;padding:9px 12px;font-family:var(--font);font-size:13px;color:var(--text);outline:none;transition:border-color 0.15s}
    .field input:focus{border-color:#60a5fa}.field input[readonly]{color:var(--sub);cursor:not-allowed}
    .field-hint{font-size:10px;color:var(--sub);margin-top:4px}
    .btn-row{display:flex;justify-content:flex-end;margin-top:20px}
    .btn-save{background:#2563eb;color:#fff;border:none;border-radius:6px;padding:10px 20px;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:opacity 0.15s}
    .btn-save:hover{opacity:0.85}
    .sec-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--sub);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border)}
    .info-box{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:12px 14px;font-size:12px;color:var(--sub);line-height:1.6;margin-bottom:14px}
    .danger-zone{border:1px solid rgba(220,38,38,0.25);border-radius:8px;padding:20px;margin-bottom:14px}
    .dz-title{font-size:13px;font-weight:700;color:#f87171;margin-bottom:4px}.dz-desc{font-size:12px;color:var(--sub);margin-bottom:14px}
    .danger-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border)}
    .danger-row:last-child{border-bottom:none;padding-bottom:0}
    .dr-title{font-size:13px;font-weight:600;margin-bottom:2px}.dr-desc{font-size:11px;color:var(--sub)}
    .btn-danger{background:transparent;color:#f87171;border:1px solid rgba(220,38,38,0.3);border-radius:6px;padding:8px 16px;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all 0.15s}
    .btn-danger:hover{background:var(--red-dim);border-color:#f87171}
    @media(max-width:900px){.g2,.g3{grid-template-columns:1fr}}
    @media(max-width:768px){.nav-links{display:none}.wrapper{padding:20px 16px}.banner-content{padding:0 20px}}
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
    <div class="nav-admin-chip"><div class="nav-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div><span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span></div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<div class="banner">
  <div class="banner-fallback"></div><div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin — System</div>
      <div class="banner-title">Settings</div>
      <div class="banner-sub">System &amp; account configuration</div>
    </div>
  </div>
</div>

<div class="wrapper">
  <?php if($success): ?><div class="alert-ok">✓ <?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if($error): ?><div class="alert-err">✗ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="section">
    <div class="section-head"><span class="section-title">Admin Profile</span><span class="section-sub">Your login account</span></div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="update_profile" value="1"/>
        <div class="form-gap">
          <div class="g2">
            <div class="field"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($ap['full_name']) ?>" required/></div>
            <div class="field"><label>Email Address</label><input type="email" name="email" value="<?= htmlspecialchars($ap['email']) ?>" required/></div>
          </div>
          <div class="field"><label>Role</label><input type="text" value="Administrator" readonly/></div>
        </div>
        <div class="btn-row"><button type="submit" class="btn-save">Save Profile</button></div>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">Change Password</span><span class="section-sub">Minimum 6 characters</span></div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="change_password" value="1"/>
        <div class="form-gap">
          <div class="field"><label>Current Password</label><input type="password" name="current_password" placeholder="Enter current password" required/></div>
          <div class="g2">
            <div class="field"><label>New Password</label><input type="password" name="new_password" placeholder="New password" required/></div>
            <div class="field"><label>Confirm New Password</label><input type="password" name="confirm_password" placeholder="Repeat new password" required/></div>
          </div>
        </div>
        <div class="btn-row"><button type="submit" class="btn-save">Update Password</button></div>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">Gym Information</span><span class="section-sub">Displayed across the system</span></div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="update_gym" value="1"/>
        <div class="form-gap">
          <div class="sec-label">Basic Info</div>
          <div class="g2">
            <div class="field"><label>Gym Name</label><input type="text" name="gym_name" value="<?= htmlspecialchars($gs['gym_name']) ?>" placeholder="FitX Training Club Gym"/></div>
            <div class="field"><label>Contact Email</label><input type="email" name="gym_email" value="<?= htmlspecialchars($gs['gym_email']) ?>" placeholder="info@rediron.com"/></div>
            <div class="field"><label>Phone Number</label><input type="text" name="gym_phone" value="<?= htmlspecialchars($gs['gym_phone']) ?>" placeholder="+63 912 345 6789"/></div>
            <div class="field"><label>Address</label><input type="text" name="gym_address" value="<?= htmlspecialchars($gs['gym_address']) ?>" placeholder="123 Gym St, City"/></div>
          </div>
          <div class="sec-label" style="margin-top:6px;">Pricing</div>
          <div class="g2">
            <div class="field"><label>Premium Monthly Price (₱)</label><input type="number" name="premium_price" value="<?= htmlspecialchars($gs['premium_price']) ?>" placeholder="1500" min="0"/><div class="field-hint">Shown on billing and index page tier cards.</div></div>
          </div>
          <div class="sec-label" style="margin-top:6px;">Payment Details</div>
          <div class="info-box">These details are shown to members when choosing a payment method on billing page.</div>
          <div class="g3">
            <div class="field"><label>GCash Number</label><input type="text" name="gcash_number" value="<?= htmlspecialchars($gs['gcash_number']) ?>" placeholder="0917 123 4567"/></div>
            <div class="field"><label>GCash Account Name</label><input type="text" name="gcash_name" value="<?= htmlspecialchars($gs['gcash_name']) ?>" placeholder="FitX Training Club"/></div>
            <div class="field"><label>Platform</label><input type="text" value="GCash" readonly/></div>
            <div class="field"><label>Bank Name</label><input type="text" name="bank_name" value="<?= htmlspecialchars($gs['bank_name']) ?>" placeholder="BDO"/></div>
            <div class="field"><label>Bank Account Number</label><input type="text" name="bank_account" value="<?= htmlspecialchars($gs['bank_account']) ?>" placeholder="1234 5678 9012"/></div>
            <div class="field"><label>Bank Account Name</label><input type="text" name="bank_account_name" value="<?= htmlspecialchars($gs['bank_account_name']) ?>" placeholder="FitX Training Club"/></div>
          </div>
        </div>
        <div class="btn-row"><button type="submit" class="btn-save">Save Gym Settings</button></div>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">System Information</span><span class="section-sub">Read only</span></div>
    <div class="section-body">
      <div class="g3">
        <div class="field"><label>PHP Version</label><input type="text" value="<?= phpversion() ?>" readonly/></div>
        <div class="field"><label>Database</label><input type="text" value="red iron (MySQL)" readonly/></div>
        <div class="field"><label>Server Time</label><input type="text" value="<?= date('M j, Y g:i A') ?>" readonly/></div>
        <div class="field"><label>Session Admin</label><input type="text" value="<?= htmlspecialchars($ap['email']) ?>" readonly/></div>
        <div class="field"><label>Environment</label><input type="text" value="XAMPP / Local" readonly/></div>
        <div class="field"><label>Session ID</label><input type="text" value="<?= substr(session_id(),0,16) ?>..." readonly/></div>
      </div>
    </div>
  </div>

  <div class="danger-zone">
    <div class="dz-title">⚠ Danger Zone</div>
    <div class="dz-desc">These actions are destructive and cannot be undone.</div>
    <div class="danger-row"><div><div class="dr-title">Clear All Workout Logs</div><div class="dr-desc">Permanently deletes all workout log entries from all members.</div></div><form method="POST" onsubmit="return confirm('Delete ALL workout logs permanently?')"><input type="hidden" name="clear_workout_logs" value="1"/><button type="submit" class="btn-danger">Clear Logs</button></form></div>
    <div class="danger-row"><div><div class="dr-title">Clear All Body Metrics</div><div class="dr-desc">Permanently deletes all body measurement entries.</div></div><form method="POST" onsubmit="return confirm('Delete ALL body metrics permanently?')"><input type="hidden" name="clear_body_metrics" value="1"/><button type="submit" class="btn-danger">Clear Metrics</button></form></div>
    <div class="danger-row"><div><div class="dr-title">Clear All Messages</div><div class="dr-desc">Permanently deletes all messages between members and coaches.</div></div><form method="POST" onsubmit="return confirm('Delete ALL messages permanently?')"><input type="hidden" name="clear_messages" value="1"/><button type="submit" class="btn-danger">Clear Messages</button></form></div>
    <div class="danger-row"><div><div class="dr-title">Reset All Premium to Basic</div><div class="dr-desc">Downgrades all premium members and removes all coach assignments.</div></div><form method="POST" onsubmit="return confirm('Downgrade ALL premium members to basic?')"><input type="hidden" name="reset_premium" value="1"/><button type="submit" class="btn-danger">Reset Premium</button></form></div>
  </div>
</div>

<script>
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);
</script>
</body>
</html>

<?php
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $dc=new mysqli('localhost','root','','red iron');
    if (isset($_POST['clear_workout_logs'])){$dc->query("DELETE FROM workout_logs");$dc->close();header('Location: settings.php?success=1');exit;}
    if (isset($_POST['clear_body_metrics'])){$dc->query("DELETE FROM body_metrics");$dc->close();header('Location: settings.php?success=1');exit;}
    if (isset($_POST['clear_messages'])){$dc->query("DELETE FROM messages");$dc->close();header('Location: settings.php?success=1');exit;}
    if (isset($_POST['reset_premium'])){$dc->query("UPDATE users SET tier='basic',coach_id=NULL WHERE role='member'");$dc->close();header('Location: settings.php?success=1');exit;}
    $dc->close();
}
?>