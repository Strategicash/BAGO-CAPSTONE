<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }
if ($_SESSION['tier'] !== 'premium') { header('Location: dashboard.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// Fetch assigned coach
$r = $conn->prepare("
    SELECT u.id, u.full_name, u.email, u.created_at, u.specialties, u.bio,
           (SELECT COUNT(*) FROM users WHERE coach_id=u.id AND role='member') AS client_count,
           (SELECT COUNT(*) FROM workout_plans WHERE coach_id=u.id) AS plans_created
    FROM users u JOIN users m ON m.coach_id=u.id
    WHERE m.id=? AND u.role='coach' LIMIT 1
");
$r->bind_param("i", $user_id); $r->execute();
$coach = $r->get_result()->fetch_assoc(); $r->close();

// Fetch coach schedule
$schedule = [];
$days_order = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
if ($coach) {
    $r = $conn->prepare("SELECT day_name, start_time, end_time, is_available FROM coach_schedule WHERE coach_id=? AND is_available=1");
    $r->bind_param("i", $coach['id']); $r->execute();
    $sched_rows = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();
    foreach ($sched_rows as $s) $schedule[$s['day_name']] = $s;
}

// Assigned since
$assigned_since = null;
if ($coach) {
    $r = $conn->prepare("SELECT MIN(created_at) since FROM workout_plans WHERE coach_id=? AND member_id=?");
    $r->bind_param("ii", $coach['id'], $user_id); $r->execute();
    $assigned_since = $r->get_result()->fetch_assoc()['since'] ?? null; $r->close();
}

// Active workout plan
$active_plan = null;
if ($coach) {
    $r = $conn->prepare("SELECT plan_name, phase, duration_weeks, created_at FROM workout_plans WHERE coach_id=? AND member_id=? ORDER BY created_at DESC LIMIT 1");
    $r->bind_param("ii", $coach['id'], $user_id); $r->execute();
    $active_plan = $r->get_result()->fetch_assoc(); $r->close();
}

// Active nutrition plan
$active_nutrition = null;
if ($coach) {
    $r = $conn->prepare("SELECT plan_name, goal, calories FROM nutrition_plans WHERE coach_id=? AND member_id=? ORDER BY id DESC LIMIT 1");
    $r->bind_param("ii", $coach['id'], $user_id); $r->execute();
    $active_nutrition = $r->get_result()->fetch_assoc(); $r->close();
}

// Coach notes
$coach_note = null; $plan_updated = null;
if ($coach) {
    $r = $conn->prepare("SELECT notes, created_at FROM workout_plans WHERE coach_id=? AND member_id=? AND notes!='' ORDER BY created_at DESC LIMIT 1");
    $r->bind_param("ii", $coach['id'], $user_id); $r->execute();
    $note_row = $r->get_result()->fetch_assoc(); $r->close();
    if ($note_row) { $coach_note = $note_row['notes']; $plan_updated = $note_row['created_at']; }
}

// Last message
$last_message = null;
if ($coach) {
    $r = $conn->prepare("SELECT body, sent_at FROM messages WHERE sender_id=? AND receiver_id=? ORDER BY sent_at DESC LIMIT 1");
    $r->bind_param("ii", $coach['id'], $user_id); $r->execute();
    $last_message = $r->get_result()->fetch_assoc(); $r->close();
}

$conn->close();

$coach_avatar    = $coach ? strtoupper(substr($coach['full_name'], 0, 1)) : '?';
$specialties_list = [];
if ($coach && $coach['specialties']) {
    $specialties_list = array_filter(array_map('trim', explode(',', $coach['specialties'])));
}

// Today's day — to highlight if coach is available today
$today = date('l'); // e.g. "Monday"

$day_short = ['Monday'=>'Mon','Tuesday'=>'Tue','Wednesday'=>'Wed','Thursday'=>'Thu','Friday'=>'Fri','Saturday'=>'Sat','Sunday'=>'Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — My Coach</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    :root { --bg:#0a0a0a; --surface:#111; --card:#161616; --border:#222; --text:#f0f0f0; --sub:#555; --muted:#888; --accent:#f59e0b; --accent-dim:rgba(245,158,11,0.1); --green:#22c55e; --green-dim:rgba(34,197,94,0.1); --red:#ef4444; --font:'Plus Jakarta Sans',sans-serif; }
    body { font-family:var(--font); background:var(--bg); color:var(--text); display:flex; min-height:100vh; }
    .sidebar { width:210px; flex-shrink:0; background:var(--surface); border-right:1px solid var(--border); display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; }
    .sidebar-logo { padding:18px 18px 14px; font-size:15px; font-weight:700; color:var(--text); border-bottom:1px solid var(--border); }
    .sidebar-logo span { color:var(--accent); }
    .sidebar-user { padding:14px 18px; border-bottom:1px solid var(--border); }
    .user-av { width:34px; height:34px; border-radius:50%; background:var(--accent-dim); color:var(--accent); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; margin-bottom:8px; }
    .user-name { font-size:13px; font-weight:600; margin-bottom:3px; }
    .user-badge { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent); }
    .sidebar-nav { flex:1; padding:10px 8px; }
    .nav-lbl { font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--sub); padding:0 10px; margin:14px 0 5px; }
    .nav-item { display:flex; align-items:center; gap:9px; padding:8px 10px; border-radius:6px; font-size:13px; font-weight:500; color:var(--muted); text-decoration:none; transition:all 0.12s; margin-bottom:1px; }
    .nav-item:hover { background:var(--card); color:var(--text); }
    .nav-item.active { background:var(--accent-dim); color:var(--accent); font-weight:600; }
    .sidebar-foot { padding:10px 8px; border-top:1px solid var(--border); }
    .main { margin-left:210px; flex:1; }
    .topbar { border-bottom:1px solid var(--border); padding:0 28px; height:52px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:40; background:var(--bg); }
    .page-title { font-size:14px; font-weight:700; }
    .btn-sm { background:var(--accent); color:#fff; border:none; border-radius:6px; padding:6px 14px; font-family:var(--font); font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
    .content { padding:24px 28px; }
    .g2 { display:grid; grid-template-columns:1fr 2fr; gap:14px; margin-bottom:14px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:8px; padding:20px; margin-bottom:14px; }
    .ch { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .ct { font-size:13px; font-weight:700; }

    /* COACH PROFILE */
    .coach-profile { text-align:center; padding:10px 0; }
    .coach-big-av { width:72px; height:72px; border-radius:50%; background:var(--green-dim); color:var(--green); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:26px; margin:0 auto 12px; }
    .coach-big-name { font-size:16px; font-weight:700; margin-bottom:4px; }
    .coach-email { font-size:12px; color:var(--sub); margin-bottom:8px; }
    .online-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; color:var(--green); font-weight:600; }
    .online-badge::before { content:''; width:6px; height:6px; background:var(--green); border-radius:50%; }
    .spec-tags { display:flex; flex-wrap:wrap; gap:5px; justify-content:center; margin:10px 0; }
    .spec-tag { font-size:10px; font-weight:700; padding:3px 9px; border-radius:20px; background:var(--accent-dim); color:var(--accent); }
    .coach-bio { font-size:12px; color:var(--muted); line-height:1.7; text-align:left; background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:11px 13px; margin-top:12px; }
    .stat-list { display:flex; flex-direction:column; gap:0; margin-top:14px; }
    .stat-row { display:flex; justify-content:space-between; font-size:12px; padding:9px 0; border-bottom:1px solid var(--border); }
    .stat-row:last-child { border-bottom:none; }
    .stat-row span:first-child { color:var(--sub); }
    .stat-row span:last-child { font-weight:600; color:var(--text); }

    /* SCHEDULE DISPLAY */
    .schedule-week { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
    .sday { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:10px 6px; text-align:center; position:relative; }
    .sday.avail { border-color:rgba(34,197,94,0.4); background:rgba(34,197,94,0.06); }
    .sday.today-avail { border-color:var(--green); box-shadow:0 0 0 2px rgba(34,197,94,0.2); }
    .sday.today-closed { border-color:rgba(239,68,68,0.3); }
    .sday-name { font-size:10px; font-weight:700; text-transform:uppercase; color:var(--sub); margin-bottom:5px; }
    .sday.avail .sday-name { color:var(--green); }
    .sday.today-avail .sday-name, .sday.today-closed .sday-name { color:var(--text); }
    .sday-time { font-size:10px; color:var(--text); line-height:1.6; font-weight:500; }
    .sday-closed { font-size:10px; color:var(--sub); margin-top:4px; }
    .today-dot { position:absolute; top:5px; right:5px; width:5px; height:5px; border-radius:50%; background:var(--accent); }

    /* TODAY AVAILABILITY BANNER */
    .today-banner { border-radius:8px; padding:14px 16px; margin-bottom:14px; display:flex; align-items:center; gap:12px; font-size:13px; }
    .today-banner.avail { background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.25); color:var(--green); }
    .today-banner.closed { background:rgba(239,68,68,0.07); border:1px solid rgba(239,68,68,0.2); color:#f87171; }
    .today-banner.no-sched { background:var(--surface); border:1px solid var(--border); color:var(--sub); }
    .today-icon { font-size:22px; flex-shrink:0; }

    /* PLAN CARDS */
    .plan-mini { background:var(--surface); border:1px solid var(--border); border-radius:7px; padding:12px 14px; margin-bottom:10px; }
    .plan-mini-title { font-size:13px; font-weight:600; margin-bottom:3px; }
    .plan-mini-sub { font-size:11px; color:var(--sub); }

    .msg-preview { background:var(--surface); border:1px solid var(--border); border-radius:7px; padding:12px 14px; }
    .msg-preview-body { font-size:13px; color:var(--muted); line-height:1.6; margin-bottom:6px; }
    .msg-preview-time { font-size:11px; color:var(--sub); }

    .empty { text-align:center; padding:50px 20px; color:var(--sub); font-size:13px; }
    .no-sched-note { font-size:12px; color:var(--sub); text-align:center; padding:16px 0; }

    @media (max-width:900px) { .g2 { grid-template-columns:1fr; } .schedule-week { grid-template-columns:repeat(4,1fr); } }
    @media (max-width:768px) { .sidebar { display:none; } .main { margin-left:0; } }
  </style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">FitX <span>Training Club</span></div>
  <div class="sidebar-user">
    <div class="user-av"><?= $avatar ?></div>
    <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
    <div class="user-badge"><?= ucfirst($tier) ?></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-lbl">Main</div>
    <a href="dashboard.php"    class="nav-item">📊 Dashboard</a>
    <a href="workouts.php"     class="nav-item">🏋️ Workouts</a>
    <a href="progress.php"     class="nav-item">📈 Progress</a>
    <a href="body_metrics.php" class="nav-item">⚖️ Body Metrics</a>
    <div class="nav-lbl">Premium</div>
    <a href="my_coach.php" class="nav-item active">👤 My Coach</a>
    <a href="my_plan.php"  class="nav-item">📋 My Plan</a>
    <a href="messages.php" class="nav-item">💬 Messages</a>
    <div class="nav-lbl">Account</div>
    <a href="profile.php"  class="nav-item">⚙️ Profile</a>
    <a href="billing.php"  class="nav-item">💳 Billing</a>
  </nav>
  <div class="sidebar-foot"><a href="logout.php" class="nav-item">🚪 Log Out</a></div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">My Coach</div>
    <?php if ($coach): ?>
      <a href="messages.php" class="btn-sm">💬 Message Coach</a>
    <?php endif; ?>
  </div>

  <div class="content">

    <?php if (!$coach): ?>
    <div class="empty">
      <div style="font-size:40px;margin-bottom:14px;">👤</div>
      <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:8px;">No coach assigned yet</div>
      <div style="margin-bottom:16px;color:var(--muted);">The admin will assign you a coach shortly. Check back soon!</div>
      <a href="dashboard.php" style="color:var(--accent);font-weight:600;text-decoration:none;">← Back to Dashboard</a>
    </div>

    <?php else: ?>

    <!-- TODAY AVAILABILITY BANNER -->
    <?php
      $today_sched = $schedule[$today] ?? null;
      $coach_avail_today = $today_sched && $today_sched['is_available'];
    ?>
    <?php if (!empty($schedule)): ?>
      <?php if ($coach_avail_today): ?>
      <div class="today-banner avail">
        <span class="today-icon">🟢</span>
        <div>
          <strong>Your coach is available today (<?= $today ?>)</strong><br>
          <span style="font-size:12px;">Hours: <?= date('g:i A', strtotime($today_sched['start_time'])) ?> – <?= date('g:i A', strtotime($today_sched['end_time'])) ?></span>
        </div>
      </div>
      <?php else: ?>
      <div class="today-banner closed">
        <span class="today-icon">🔴</span>
        <div>
          <strong>Your coach is not available today (<?= $today ?>)</strong><br>
          <span style="font-size:12px;">Check the schedule below for available days.</span>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="g2">

      <!-- LEFT: COACH PROFILE CARD -->
      <div>
        <div class="card">
          <div class="coach-profile">
            <div class="coach-big-av"><?= $coach_avatar ?></div>
            <div class="coach-big-name"><?= htmlspecialchars($coach['full_name']) ?></div>
            <div class="coach-email"><?= htmlspecialchars($coach['email']) ?></div>
            <div class="online-badge">Active Coach</div>

            <?php if (!empty($specialties_list)): ?>
              <div class="spec-tags">
                <?php foreach ($specialties_list as $spec): ?>
                  <span class="spec-tag"><?= htmlspecialchars($spec) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="spec-tags"><span class="spec-tag">Personal Training</span></div>
            <?php endif; ?>

            <?php if ($coach['bio']): ?>
              <div class="coach-bio"><?= nl2br(htmlspecialchars($coach['bio'])) ?></div>
            <?php endif; ?>

            <div class="stat-list">
              <div class="stat-row"><span>Total Clients</span><span><?= $coach['client_count'] ?></span></div>
              <div class="stat-row"><span>Plans Created</span><span><?= $coach['plans_created'] ?></span></div>
              <div class="stat-row"><span>Assigned Since</span><span><?= $assigned_since ? date('M j, Y', strtotime($assigned_since)) : 'Recently' ?></span></div>
              <div class="stat-row"><span>Workout Plan</span><span><?= $active_plan ? '✓ Active' : 'None yet' ?></span></div>
              <div class="stat-row"><span>Nutrition Plan</span><span><?= $active_nutrition ? '✓ Active' : 'None yet' ?></span></div>
              <div class="stat-row">
                <span>Available Days</span>
                <span style="color:var(--green)"><?= count($schedule) ?> days/week</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div>

        <!-- WEEKLY SCHEDULE -->
        <div class="card">
          <div class="ch">
            <div class="ct">📅 Coach Availability</div>
            <span style="font-size:11px;color:var(--sub)">Weekly schedule</span>
          </div>

          <?php if (empty($schedule)): ?>
            <div class="no-sched-note">Your coach hasn't set their schedule yet. Message them to ask about availability.</div>
          <?php else: ?>
          <div class="schedule-week">
            <?php foreach ($days_order as $day):
              $s         = $schedule[$day] ?? null;
              $is_avail  = (bool)($s['is_available'] ?? false);
              $is_today  = ($day === $today);
              $cls       = 'sday';
              if ($is_avail && $is_today) $cls .= ' avail today-avail';
              elseif ($is_avail)          $cls .= ' avail';
              elseif ($is_today)          $cls .= ' today-closed';
            ?>
            <div class="<?= $cls ?>">
              <?php if ($is_today): ?><div class="today-dot"></div><?php endif; ?>
              <div class="sday-name"><?= $day_short[$day] ?></div>
              <?php if ($is_avail && $s): ?>
                <div class="sday-time">
                  <?= date('g:i A', strtotime($s['start_time'])) ?><br>
                  <span style="color:var(--sub)">to</span><br>
                  <?= date('g:i A', strtotime($s['end_time'])) ?>
                </div>
              <?php else: ?>
                <div class="sday-closed">Closed</div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:10px;font-size:11px;color:var(--sub);display:flex;align-items:center;gap:6px;">
            <span style="width:8px;height:8px;border-radius:50%;background:var(--accent);display:inline-block;"></span>
            Orange dot = today
            &nbsp;·&nbsp;
            <span style="width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block;"></span>
            Green = available
          </div>
          <?php endif; ?>
        </div>

        <!-- ACTIVE PLANS -->
        <div class="card">
          <div class="ch">
            <div class="ct">Active Plans</div>
            <a href="my_plan.php" style="font-size:11px;color:var(--accent);text-decoration:none;font-weight:600;">View full plan →</a>
          </div>
          <?php if ($active_plan): ?>
          <div class="plan-mini">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--sub);margin-bottom:5px;">💪 Workout Plan</div>
            <div class="plan-mini-title"><?= htmlspecialchars($active_plan['plan_name']) ?></div>
            <div class="plan-mini-sub"><?= $active_plan['phase'] ? htmlspecialchars($active_plan['phase']).' · ' : '' ?><?= $active_plan['duration_weeks'] ?> weeks</div>
          </div>
          <?php else: ?>
            <p style="font-size:13px;color:var(--sub);margin-bottom:10px;">No workout plan assigned yet.</p>
          <?php endif; ?>
          <?php if ($active_nutrition): ?>
          <div class="plan-mini">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--sub);margin-bottom:5px;">🥗 Nutrition Plan</div>
            <div class="plan-mini-title"><?= htmlspecialchars($active_nutrition['plan_name']) ?></div>
            <div class="plan-mini-sub"><?= $active_nutrition['goal'] ? htmlspecialchars($active_nutrition['goal']).' · ' : '' ?><?= $active_nutrition['calories'] ? $active_nutrition['calories'].' kcal/day' : '' ?></div>
          </div>
          <?php else: ?>
            <p style="font-size:13px;color:var(--sub);">No nutrition plan assigned yet.</p>
          <?php endif; ?>
        </div>

        <!-- COACH NOTES -->
        <div class="card">
          <div class="ch">
            <div class="ct">Coach Notes</div>
            <span style="font-size:11px;color:var(--sub)"><?= $plan_updated ? 'Updated '.date('M j, Y', strtotime($plan_updated)) : '' ?></span>
          </div>
          <?php if ($coach_note): ?>
            <p style="font-size:13px;color:var(--muted);line-height:1.8;"><?= nl2br(htmlspecialchars($coach_note)) ?></p>
          <?php else: ?>
            <p style="font-size:13px;color:var(--sub);">No notes from your coach yet.</p>
          <?php endif; ?>
        </div>

        <!-- LAST MESSAGE -->
        <div class="card">
          <div class="ch">
            <div class="ct">Last Message from Coach</div>
            <a href="messages.php" style="font-size:11px;color:var(--accent);text-decoration:none;font-weight:600;">Open chat →</a>
          </div>
          <?php if ($last_message): ?>
          <div class="msg-preview">
            <div class="msg-preview-body">"<?= htmlspecialchars($last_message['body']) ?>"</div>
            <div class="msg-preview-time"><?= date('M j, Y g:i A', strtotime($last_message['sent_at'])) ?></div>
          </div>
          <?php else: ?>
            <p style="font-size:13px;color:var(--sub);">No messages yet. <a href="messages.php" style="color:var(--accent);text-decoration:none;font-weight:600;">Start the conversation →</a></p>
          <?php endif; ?>
        </div>

      </div>  
    </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>