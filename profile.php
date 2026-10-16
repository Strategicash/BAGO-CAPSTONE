<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

$success = '';
$error   = '';

// Fetch current user data
$r = $conn->prepare("SELECT full_name, email, fitness_level FROM users WHERE id=?");
$r->bind_param("i", $user_id); $r->execute();
$user = $r->get_result()->fetch_assoc();
$r->close();

// Handle personal info update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {
    $new_name  = trim($_POST['full_name']     ?? '');
    $new_email = trim($_POST['email']         ?? '');
    $new_level = trim($_POST['fitness_level'] ?? '');

    if (empty($new_name) || empty($new_email)) {
        $error = 'Name and email cannot be empty.';
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $chk->bind_param("si", $new_email, $user_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error = 'That email is already used by another account.';
        } else {
            $u = $conn->prepare("UPDATE users SET full_name=?, email=?, fitness_level=? WHERE id=?");
            $u->bind_param("sssi", $new_name, $new_email, $new_level, $user_id);
            $u->execute();
            $u->close();
            $_SESSION['full_name'] = $new_name;
            $full_name = $new_name;
            $avatar    = strtoupper(substr($full_name, 0, 1));
            $user['full_name']     = $new_name;
            $user['email']         = $new_email;
            $user['fitness_level'] = $new_level;
            $success = 'Profile updated successfully!';
        }
        $chk->close();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = trim($_POST['current_password'] ?? '');
    $new_pass = trim($_POST['new_password']      ?? '');
    $confirm  = trim($_POST['confirm_password']  ?? '');

    $r = $conn->prepare("SELECT password FROM users WHERE id=?");
    $r->bind_param("i", $user_id); $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();

    if (!password_verify($current, $row['password'])) {
        $error = 'Current password is incorrect.';
    } elseif ($new_pass !== $confirm) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_pass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $hash = password_hash($new_pass, PASSWORD_BCRYPT);
        $u = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $u->bind_param("si", $hash, $user_id);
        $u->execute();
        $u->close();
        $success = 'Password changed successfully!';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Profile</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {  --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #f59e0b; --accent-dim: rgba(245, 158, 11, 0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --red: #ef4444; --yellow: #f59e0b;
      --font: 'Plus Jakarta Sans', sans-serif; }
      
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
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .content { padding: 24px 28px; max-width: 700px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 24px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .ct { font-size: 13px; font-weight: 700; }
    .profile-top { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
    .profile-av-big { width: 60px; height: 60px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; flex-shrink: 0; }
    .profile-name { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
    .profile-email { font-size: 13px; color: var(--sub); margin-bottom: 3px; }
    .profile-tier { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); }
    .profile-tier.basic { color: var(--sub); }
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 5px; }
    .field input, .field select { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 9px 12px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; transition: border-color 0.15s; }
    .field input:focus, .field select:focus { border-color: var(--accent); }
    .field select option { background: var(--surface); }
    .btn-save { background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 9px 20px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; }
    .danger-zone { border-color: rgba(239,68,68,0.3); }
    .btn-danger { background: transparent; color: var(--red); border: 1px solid rgba(239,68,68,0.4); border-radius: 6px; padding: 8px 16px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; }
    .alert-green { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#22c55e;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:16px; }
    .alert-red   { background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:16px; }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } .g2 { grid-template-columns: 1fr; } }
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
    <a href="profile.php" class="nav-item active">⚙️ Profile</a>
    <a href="billing.php" class="nav-item">💳 Billing</a>
  </nav>
  <div class="sidebar-foot"><a href="logout.php" class="nav-item">🚪 Log Out</a></div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">Profile</div>
  </div>
  <div class="content">

    <div class="card">
      <!-- PROFILE TOP — real name + real email, no hardcode -->
      <div class="profile-top">
        <div class="profile-av-big"><?= $avatar ?></div>
        <div>
          <div class="profile-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="profile-email"><?= htmlspecialchars($user['email']) ?></div>
          <div class="profile-tier <?= $tier === 'basic' ? 'basic' : '' ?>"><?= ucfirst($tier) ?> Member</div>
        </div>
      </div>

      <div class="ch"><div class="ct">Personal Information</div></div>

      <?php if ($success): ?><div class="alert-green">✓ <?= $success ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="alert-red">✗ <?= $error ?></div><?php endif; ?>

      <form method="POST">
        <input type="hidden" name="update_info" value="1"/>
        <div class="g2">
          <div class="field">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required/>
          </div>
          <div class="field">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required/>
          </div>
        </div>
        <div class="field">
          <label>Fitness Level</label>
          <select name="fitness_level">
            <option value="beginner"     <?= $user['fitness_level']==='beginner'     ? 'selected':'' ?>>Beginner</option>
            <option value="intermediate" <?= $user['fitness_level']==='intermediate' ? 'selected':'' ?>>Intermediate</option>
            <option value="advanced"     <?= $user['fitness_level']==='advanced'     ? 'selected':'' ?>>Advanced</option>
          </select>
        </div>
        <button type="submit" class="btn-save">Save Changes</button>
      </form>
    </div>

    <div class="card">
      <div class="ch"><div class="ct">Change Password</div></div>
      <form method="POST">
        <input type="hidden" name="change_password" value="1"/>
        <div class="field">
          <label>Current Password</label>
          <input type="password" name="current_password" placeholder="••••••••" required/>
        </div>
        <div class="g2">
          <div class="field">
            <label>New Password</label>
            <input type="password" name="new_password" placeholder="Min 6 characters" required/>
          </div>
          <div class="field">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" placeholder="Repeat new password" required/>
          </div>
        </div>
        <button type="submit" class="btn-save">Update Password</button>
      </form>
    </div>

    <div class="card danger-zone">
      <div class="ch"><div class="ct">Danger Zone</div></div>
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">Once you delete your account all data will be permanently removed.</p>
      <button class="btn-danger" onclick="return confirm('Are you sure? This cannot be undone.')">Delete Account</button>
    </div>

  </div>
</main>
</body>
</html>