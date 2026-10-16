<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];
$success = '';
$error   = '';

// ── Auto-create schedule table ────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS coach_schedule (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        coach_id   INT NOT NULL,
        day_name   VARCHAR(10) NOT NULL,
        start_time TIME NOT NULL,
        end_time   TIME NOT NULL,
        is_available TINYINT(1) DEFAULT 1,
        UNIQUE KEY unique_coach_day (coach_id, day_name)
    )
");

// ── Fetch coach data ──────────────────────────────────────────────────────────
$r = $conn->prepare("SELECT full_name, email, specialties, bio FROM users WHERE id=?");
$r->bind_param("i", $coach_id); $r->execute();
$coach = $r->get_result()->fetch_assoc(); $r->close();

// ── Fetch existing schedule ───────────────────────────────────────────────────
$r = $conn->prepare("SELECT day_name, start_time, end_time, is_available FROM coach_schedule WHERE coach_id=?");
$r->bind_param("i", $coach_id); $r->execute();
$sched_rows = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();
$schedule = [];
foreach ($sched_rows as $s) $schedule[$s['day_name']] = $s;

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// ── Handle profile update ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name        = trim($_POST['full_name']   ?? '');
    $new_email       = trim($_POST['email']       ?? '');
    $new_specialties = trim($_POST['specialties'] ?? '');
    $new_bio         = trim($_POST['bio']         ?? '');

    if (empty($new_name) || empty($new_email)) {
        $error = 'Name and email cannot be empty.';
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $chk->bind_param("si", $new_email, $coach_id); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) { $error = 'That email is already taken.'; }
        else {
            $u = $conn->prepare("UPDATE users SET full_name=?, email=?, specialties=?, bio=? WHERE id=?");
            $u->bind_param("ssssi", $new_name, $new_email, $new_specialties, $new_bio, $coach_id);
            $u->execute(); $u->close();
            $_SESSION['full_name'] = $new_name;
            $coach_name = $new_name;
            $coach['full_name']   = $new_name;
            $coach['email']       = $new_email;
            $coach['specialties'] = $new_specialties;
            $coach['bio']         = $new_bio;
            $success = 'Profile updated successfully!';
        }
        $chk->close();
    }
}

// ── Handle schedule update ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    $active_days = $_POST['active_days'] ?? [];
    foreach ($days as $day) {
        $is_avail  = in_array($day, $active_days) ? 1 : 0;
        $start     = $_POST['start'][$day] ?? '06:00';
        $end       = $_POST['end'][$day]   ?? '18:00';

        $s = $conn->prepare("
            INSERT INTO coach_schedule (coach_id, day_name, start_time, end_time, is_available)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE start_time=?, end_time=?, is_available=?
        ");
        $s->bind_param("isssissi", $coach_id, $day, $start, $end, $is_avail, $start, $end, $is_avail);
        $s->execute(); $s->close();
    }
    // Refresh schedule
    $r = $conn->prepare("SELECT day_name, start_time, end_time, is_available FROM coach_schedule WHERE coach_id=?");
    $r->bind_param("i", $coach_id); $r->execute();
    $sched_rows = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();
    $schedule = [];
    foreach ($sched_rows as $s) $schedule[$s['day_name']] = $s;
    $success = 'Schedule updated! Your members can now see your availability.';
}

// ── Handle password change ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = trim($_POST['current_password'] ?? '');
    $new_pass = trim($_POST['new_password']      ?? '');
    $confirm  = trim($_POST['confirm_password']  ?? '');
    $r = $conn->prepare("SELECT password FROM users WHERE id=?");
    $r->bind_param("i", $coach_id); $r->execute();
    $row = $r->get_result()->fetch_assoc(); $r->close();
    if (!password_verify($current, $row['password'])) { $error = 'Current password is incorrect.'; }
    elseif ($new_pass !== $confirm) { $error = 'New passwords do not match.'; }
    elseif (strlen($new_pass) < 6)  { $error = 'Password must be at least 6 characters.'; }
    else {
        $hash = password_hash($new_pass, PASSWORD_BCRYPT);
        $u = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $u->bind_param("si", $hash, $coach_id); $u->execute(); $u->close();
        $success = 'Password changed successfully!';
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$r = $conn->prepare("SELECT COUNT(*) c FROM users WHERE coach_id=? AND role='member'");
$r->bind_param("i", $coach_id); $r->execute();
$total_clients = $r->get_result()->fetch_assoc()['c']; $r->close();

$r = $conn->prepare("SELECT COUNT(*) c FROM workout_plans WHERE coach_id=?");
$r->bind_param("i", $coach_id); $r->execute();
$total_plans = $r->get_result()->fetch_assoc()['c']; $r->close();

$conn->close();
$avatar = strtoupper(substr($coach_name, 0, 1));

// Day short labels for display
$day_short = ['Monday'=>'Mon','Tuesday'=>'Tue','Wednesday'=>'Wed','Thursday'=>'Thu','Friday'=>'Fri','Saturday'=>'Sat','Sunday'=>'Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Coach Profile</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --bg:#0a0a0a; --surface:#111; --card:#161616; --border:#222; --text:#f0f0f0; --sub:#555; --muted:#888; --accent:#3b82f6; --accent-dim:rgba(59,130,246,0.1); --green:#22c55e; --green-dim:rgba(34,197,94,0.1); --red:#ef4444; --font:'Plus Jakarta Sans',sans-serif; }
    body { font-family:var(--font); background:var(--bg); color:var(--text); display:flex; min-height:100vh; }
    .sidebar { width:210px; flex-shrink:0; background:var(--surface); border-right:1px solid var(--border); display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; }
    .sidebar-logo { padding:18px 18px 14px; font-size:15px; font-weight:700; border-bottom:1px solid var(--border); }
    .sidebar-logo span { color:var(--green); }
    .coach-tag { padding:9px 18px; border-bottom:1px solid var(--border); font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--sub); }
    .sidebar-nav { flex:1; padding:10px 8px; }
    .nav-lbl { font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--sub); padding:0 10px; margin:14px 0 5px; }
    .nav-item { display:flex; align-items:center; gap:9px; padding:8px 10px; border-radius:6px; font-size:13px; font-weight:500; color:var(--muted); text-decoration:none; transition:all 0.12s; margin-bottom:1px; }
    .nav-item:hover { background:var(--card); color:var(--text); }
    .nav-item.active { background:var(--green-dim); color:var(--green); font-weight:600; }
    .sidebar-foot { padding:10px 8px; border-top:1px solid var(--border); }
    .main { margin-left:210px; flex:1; }
    .topbar { border-bottom:1px solid var(--border); padding:0 28px; height:52px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:40; background:var(--bg); }
    .page-title { font-size:14px; font-weight:700; }
    .coach-id { font-size:12px; color:var(--sub); }
    .coach-id strong { color:var(--green); }
    .content { padding:24px 28px; max-width:820px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:8px; padding:24px; margin-bottom:14px; }
    .ch { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .ct { font-size:13px; font-weight:700; }
    .cs { font-size:11px; color:var(--sub); }

    /* PROFILE TOP */
    .profile-top { display:flex; align-items:center; gap:18px; padding-bottom:20px; border-bottom:1px solid var(--border); margin-bottom:20px; }
    .profile-av { width:64px; height:64px; border-radius:50%; background:var(--green-dim); color:var(--green); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:24px; flex-shrink:0; }
    .profile-name { font-size:18px; font-weight:700; margin-bottom:3px; }
    .profile-email { font-size:13px; color:var(--sub); margin-bottom:6px; }
    .coach-badge-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--green); }
    .stats-mini { display:flex; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
    .stat-mini { background:var(--surface); border:1px solid var(--border); border-radius:7px; padding:12px 18px; text-align:center; }
    .stat-mini-val { font-size:20px; font-weight:700; color:var(--green); }
    .stat-mini-lbl { font-size:10px; color:var(--sub); font-weight:600; text-transform:uppercase; letter-spacing:0.4px; margin-top:2px; }

    /* FORM */
    .g2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .field { margin-bottom:13px; }
    .field label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--sub); margin-bottom:5px; }
    .field input, .field textarea { width:100%; background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:9px 12px; font-family:var(--font); font-size:13px; color:var(--text); outline:none; transition:border-color 0.15s; resize:vertical; }
    .field input:focus, .field textarea:focus { border-color:var(--green); }
    .field .hint { font-size:10px; color:var(--sub); margin-top:4px; }
    .btn-save { background:var(--green); color:#000; border:none; border-radius:6px; padding:9px 20px; font-family:var(--font); font-size:13px; font-weight:700; cursor:pointer; transition:opacity 0.15s; }
    .btn-save:hover { opacity:0.85; }
    .btn-save.blue { background:var(--accent); color:#fff; }
    .spec-preview { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; min-height:20px; }
    .spec-tag { font-size:10px; font-weight:700; padding:3px 9px; border-radius:20px; background:var(--accent-dim); color:var(--accent); }

    /* SCHEDULE EDITOR */
    .schedule-grid { display:flex; flex-direction:column; gap:8px; }
    .schedule-row {
      display:grid;
      grid-template-columns: 100px 1fr 1fr 1fr;
      align-items:center;
      gap:10px;
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:8px;
      padding:12px 14px;
      transition:all 0.15s;
    }
    .schedule-row.active-row { border-color:rgba(34,197,94,0.4); background:rgba(34,197,94,0.04); }
    .schedule-row.inactive-row { opacity:0.45; }
    .day-toggle { display:flex; align-items:center; gap:8px; cursor:pointer; }
    .day-toggle input[type="checkbox"] { width:16px; height:16px; accent-color:var(--green); cursor:pointer; flex-shrink:0; }
    .day-name-lbl { font-size:13px; font-weight:700; color:var(--text); }
    .day-badge { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:2px 6px; border-radius:3px; }
    .day-badge.weekend { background:rgba(245,158,11,0.15); color:#f59e0b; }
    .day-badge.weekday { background:rgba(59,130,246,0.12); color:#60a5fa; }
    .time-field { display:flex; flex-direction:column; gap:3px; }
    .time-field label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--sub); }
    .time-field input[type="time"] { background:var(--card); border:1px solid var(--border2,#2a2a2a); border-radius:5px; padding:6px 9px; font-family:var(--font); font-size:12px; color:var(--text); outline:none; width:100%; }
    .time-field input[type="time"]:focus { border-color:var(--green); }
    .time-field input[type="time"]:disabled { opacity:0.3; cursor:not-allowed; }
    .avail-badge { font-size:11px; font-weight:600; text-align:right; }
    .avail-badge.on { color:var(--green); }
    .avail-badge.off { color:var(--sub); }

    /* SCHEDULE PREVIEW (read-only week view) */
    .week-preview { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; margin-top:16px; }
    .week-day { background:var(--surface); border:1px solid var(--border); border-radius:7px; padding:10px 6px; text-align:center; }
    .week-day.avail { border-color:rgba(34,197,94,0.35); background:rgba(34,197,94,0.06); }
    .week-day-name { font-size:10px; font-weight:700; text-transform:uppercase; color:var(--sub); margin-bottom:5px; }
    .week-day.avail .week-day-name { color:var(--green); }
    .week-day-time { font-size:10px; color:var(--muted); line-height:1.5; }
    .week-day.avail .week-day-time { color:var(--text); }
    .week-day-closed { font-size:10px; color:var(--sub); margin-top:4px; }

    .alert-green { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#22c55e;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:16px; }
    .alert-red   { background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:16px; }

    @media (max-width:900px) { .schedule-row { grid-template-columns:1fr 1fr; } .week-preview { grid-template-columns:repeat(4,1fr); } }
    @media (max-width:768px) { .sidebar { display:none; } .main { margin-left:0; } .g2 { grid-template-columns:1fr; } }
  </style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">FitX <span>Training Club</span></div>
  <div class="coach-tag">Coach Panel</div>
  <nav class="sidebar-nav">
    <div class="nav-lbl">Clients</div>
    <a href="coach_dashboard.php" class="nav-item">🏠 Dashboard</a>
    <a href="all_clients.php"     class="nav-item">👥 All Clients</a>
    <a href="coach_messages.php"  class="nav-item">💬 Messages</a>
    <div class="nav-lbl">Tools</div>
    <a href="workout_plans.php"   class="nav-item">📋 Workout Plans</a>
    <a href="nutrition_plan.php"  class="nav-item">🥗 Nutrition Plans</a>
    <a href="client_progress.php" class="nav-item">📊 Client Progress</a>
    <div class="nav-lbl">Account</div>
    <a href="coach_profile.php"   class="nav-item active">⚙️ My Profile</a>
    <a href="logout.php"          class="nav-item">🚪 Log Out</a>
  </nav>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">My Profile</div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>

  <div class="content">
    <?php if ($success): ?><div class="alert-green">✓ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert-red">✗ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- PROFILE OVERVIEW -->
    <div class="card">
      <div class="profile-top">
        <div class="profile-av"><?= $avatar ?></div>
        <div>
          <div class="profile-name"><?= htmlspecialchars($coach['full_name']) ?></div>
          <div class="profile-email"><?= htmlspecialchars($coach['email']) ?></div>
          <div class="coach-badge-lbl">Coach</div>
        </div>
      </div>

      <div class="stats-mini">
        <div class="stat-mini"><div class="stat-mini-val"><?= $total_clients ?></div><div class="stat-mini-lbl">Clients</div></div>
        <div class="stat-mini"><div class="stat-mini-val"><?= $total_plans ?></div><div class="stat-mini-lbl">Plans</div></div>
        <div class="stat-mini">
          <div class="stat-mini-val"><?= count(array_filter($schedule, fn($s) => $s['is_available'])) ?></div>
          <div class="stat-mini-lbl">Days Available</div>
        </div>
      </div>

      <div class="ch"><div class="ct">Edit Profile</div></div>
      <form method="POST">
        <input type="hidden" name="update_profile" value="1"/>
        <div class="g2">
          <div class="field"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($coach['full_name']) ?>" required/></div>
          <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($coach['email']) ?>" required/></div>
        </div>
        <div class="field">
          <label>Specialties <span style="font-weight:400;color:var(--sub)">(comma separated)</span></label>
          <input type="text" name="specialties" id="spec-input" value="<?= htmlspecialchars($coach['specialties'] ?? '') ?>" placeholder="e.g. Strength Training, Weight Loss, Nutrition" oninput="updateSpecPreview(this.value)"/>
          <div class="hint">These appear as tags on your client's My Coach page.</div>
          <div class="spec-preview" id="spec-preview">
            <?php foreach (array_filter(array_map('trim', explode(',', $coach['specialties'] ?? ''))) as $s): ?>
              <span class="spec-tag"><?= htmlspecialchars($s) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field">
          <label>Bio <span style="font-weight:400;color:var(--sub)">(shown to your clients)</span></label>
          <textarea name="bio" rows="4" placeholder="Tell your clients about your background and training philosophy..."><?= htmlspecialchars($coach['bio'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn-save">💾 Save Profile</button>
      </form>
    </div>

    <!-- AVAILABILITY SCHEDULE -->
    <div class="card">
      <div class="ch">
        <div class="ct">Weekly Availability Schedule</div>
        <span class="cs">Your clients can see when you're available</span>
      </div>

      <form method="POST" id="schedule-form">
        <input type="hidden" name="update_schedule" value="1"/>
        <div class="schedule-grid">
          <?php foreach ($days as $day):
            $s         = $schedule[$day] ?? null;
            $is_avail  = $s ? (bool)$s['is_available'] : false;
            $start     = $s ? substr($s['start_time'], 0, 5) : '08:00';
            $end       = $s ? substr($s['end_time'],   0, 5) : '17:00';
            $is_weekend= in_array($day, ['Saturday','Sunday']);
            $row_class = $is_avail ? 'active-row' : 'inactive-row';
          ?>
          <div class="schedule-row <?= $row_class ?>" id="row-<?= $day ?>">
            <label class="day-toggle">
              <input type="checkbox" name="active_days[]" value="<?= $day ?>"
                     <?= $is_avail ? 'checked' : '' ?>
                     onchange="toggleDay('<?= $day ?>', this.checked)"/>
              <div>
                <div class="day-name-lbl"><?= $day_short[$day] ?></div>
                <div class="day-badge <?= $is_weekend ? 'weekend' : 'weekday' ?>"><?= $is_weekend ? 'Weekend' : 'Weekday' ?></div>
              </div>
            </label>

            <div class="time-field">
              <label>From</label>
              <input type="time" name="start[<?= $day ?>]" value="<?= $start ?>" <?= !$is_avail ? 'disabled' : '' ?> id="start-<?= $day ?>"/>
            </div>

            <div class="time-field">
              <label>Until</label>
              <input type="time" name="end[<?= $day ?>]" value="<?= $end ?>" <?= !$is_avail ? 'disabled' : '' ?> id="end-<?= $day ?>"/>
            </div>

            <div class="avail-badge <?= $is_avail ? 'on' : 'off' ?>" id="badge-<?= $day ?>">
              <?= $is_avail ? '● Available' : '○ Closed' ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <button type="submit" class="btn-save">📅 Save Schedule</button>
          <span style="font-size:12px;color:var(--sub);">Check a day to mark yourself available. Set your start and end time.</span>
        </div>
      </form>

      <!-- WEEK PREVIEW -->
      <?php
      $has_any = !empty(array_filter($schedule, fn($s) => $s['is_available']));
      ?>
      <?php if ($has_any): ?>
      <div style="margin-top:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:10px;">This Week Preview</div>
      <div class="week-preview">
        <?php foreach ($days as $day):
          $s        = $schedule[$day] ?? null;
          $is_avail = $s && $s['is_available'];
          $start    = $is_avail ? date('g:i A', strtotime($s['start_time'])) : '';
          $end      = $is_avail ? date('g:i A', strtotime($s['end_time']))   : '';
        ?>
        <div class="week-day <?= $is_avail ? 'avail' : '' ?>">
          <div class="week-day-name"><?= $day_short[$day] ?></div>
          <?php if ($is_avail): ?>
            <div class="week-day-time"><?= $start ?><br>–<br><?= $end ?></div>
          <?php else: ?>
            <div class="week-day-closed">Closed</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- CHANGE PASSWORD -->
    <div class="card">
      <div class="ch"><div class="ct">Change Password</div></div>
      <form method="POST">
        <input type="hidden" name="change_password" value="1"/>
        <div class="field"><label>Current Password</label><input type="password" name="current_password" placeholder="••••••••" required/></div>
        <div class="g2">
          <div class="field"><label>New Password</label><input type="password" name="new_password" placeholder="Min 6 characters" required/></div>
          <div class="field"><label>Confirm New Password</label><input type="password" name="confirm_password" placeholder="Repeat new password" required/></div>
        </div>
        <button type="submit" class="btn-save blue">Update Password</button>
      </form>
    </div>

  </div>
</main>

<script>
function updateSpecPreview(val) {
  const container = document.getElementById('spec-preview');
  container.innerHTML = '';
  val.split(',').map(s => s.trim()).filter(Boolean).forEach(s => {
    const tag = document.createElement('span');
    tag.className = 'spec-tag';
    tag.textContent = s;
    container.appendChild(tag);
  });
}

function toggleDay(day, checked) {
  const row   = document.getElementById('row-' + day);
  const start = document.getElementById('start-' + day);
  const end   = document.getElementById('end-'   + day);
  const badge = document.getElementById('badge-' + day);

  start.disabled = !checked;
  end.disabled   = !checked;

  if (checked) {
    row.classList.remove('inactive-row');
    row.classList.add('active-row');
    badge.className = 'avail-badge on';
    badge.textContent = '● Available';
  } else {
    row.classList.remove('active-row');
    row.classList.add('inactive-row');
    badge.className = 'avail-badge off';
    badge.textContent = '○ Closed';
  }
}
</script>
</body>
</html>