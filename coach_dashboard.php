<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];

// Handle send message POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $body        = trim($_POST['body'] ?? '');
    if ($receiver_id && $body) {
        $s = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)");
        $s->bind_param("iis", $coach_id, $receiver_id, $body);
        $s->execute();
        $s->close();
    }
    header("Location: coach_dashboard.php?client=$receiver_id");
    exit;
}

// ── Fetch assigned clients with stats ────────────────────────────────────────
$r = $conn->prepare("
    SELECT
        u.id, u.full_name, u.fitness_level,
        (SELECT COUNT(*) FROM workout_logs wl
            WHERE wl.user_id = u.id
            AND wl.logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS workouts_month,
        (SELECT COUNT(*) FROM workout_logs wl
            WHERE wl.user_id = u.id
            AND DATE(wl.logged_at) = CURDATE()) AS workouts_today,
        (SELECT plan_name FROM workout_plans wp
            WHERE wp.member_id = u.id ORDER BY wp.created_at DESC LIMIT 1) AS current_plan,
        (SELECT phase FROM workout_plans wp
            WHERE wp.member_id = u.id ORDER BY wp.created_at DESC LIMIT 1) AS current_phase,
        (SELECT COUNT(*) FROM messages m
            WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS unread_msgs
    FROM users u
    WHERE u.coach_id = ? AND u.role = 'member'
    ORDER BY u.full_name
");
$r->bind_param("ii", $coach_id, $coach_id);
$r->execute();
$clients_list = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// ── Selected client ───────────────────────────────────────────────────────────
$selected_id     = intval($_GET['client'] ?? ($clients_list[0]['id'] ?? 0));
$selected_client = null;
foreach ($clients_list as $c) {
    if ($c['id'] === $selected_id) { $selected_client = $c; break; }
}

// ── Messages for selected client ─────────────────────────────────────────────
$messages = [];
if ($selected_id) {
    $r = $conn->prepare("
        SELECT sender_id, body, sent_at FROM messages
        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
        ORDER BY sent_at ASC LIMIT 40
    ");
    $r->bind_param("iiii", $coach_id, $selected_id, $selected_id, $coach_id);
    $r->execute();
    $messages = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

// ── Active plan for selected client ──────────────────────────────────────────
$plan = null;
if ($selected_id) {
    $r = $conn->prepare("SELECT * FROM workout_plans WHERE member_id=? ORDER BY created_at DESC LIMIT 1");
    $r->bind_param("i", $selected_id);
    $r->execute();
    $plan = $r->get_result()->fetch_assoc();
    $r->close();
}

// ── Recent workout logs for selected client ───────────────────────────────────
$recent_logs = [];
if ($selected_id) {
    $r = $conn->prepare("
        SELECT exercise, muscle_group, sets, reps, weight_kg, logged_at
        FROM workout_logs
        WHERE user_id = ?
        ORDER BY logged_at DESC
        LIMIT 8
    ");
    $r->bind_param("i", $selected_id);
    $r->execute();
    $recent_logs = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

// ── Overall coach stats ───────────────────────────────────────────────────────
$total_clients   = count($clients_list);
$active_plans    = 0;
$active_today    = 0;
foreach ($clients_list as $c) {
    if ($c['current_plan'])    $active_plans++;
    if ($c['workouts_today'])  $active_today++;
}

// ── Plan progress ─────────────────────────────────────────────────────────────
$weeks_done  = 0;
$week_pct    = 0;
$current_week = 1;
if ($plan) {
    $start      = new DateTime($plan['created_at']);
    $now        = new DateTime();
    $diff       = $start->diff($now);
    $weeks_done = min((int)floor($diff->days / 7), (int)$plan['duration_weeks']);
    $week_pct   = $plan['duration_weeks'] > 0
                  ? round(($weeks_done / $plan['duration_weeks']) * 100)
                  : 0;
    $current_week = min($plan['duration_weeks'], $weeks_done + 1);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Coach Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #3b82f6; --accent-dim: rgba(59,130,246,0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --yellow: #f59e0b; --yellow-dim: rgba(245,158,11,0.1);
      --red: #ef4444; --red-dim: rgba(239,68,68,0.1);
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

    /* ── SIDEBAR ── */
    .sidebar { width: 210px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; }
    .sidebar-logo { padding: 18px 18px 14px; font-size: 15px; font-weight: 700; color: var(--text); border-bottom: 1px solid var(--border); }
    .sidebar-logo span { color: var(--green); }
    .coach-tag { padding: 9px 18px; border-bottom: 1px solid var(--border); font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--sub); }
    .sidebar-nav { flex: 1; padding: 10px 8px; }
    .nav-lbl { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--sub); padding: 0 10px; margin: 14px 0 5px; }
    .nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; transition: all 0.12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--card); color: var(--text); }
    .nav-item.active { background: var(--green-dim); color: var(--green); font-weight: 600; }
    .sidebar-foot { padding: 10px 8px; border-top: 1px solid var(--border); }

    /* ── MAIN ── */
    .main { margin-left: 210px; flex: 1; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .coach-id { font-size: 12px; color: var(--sub); }
    .coach-id strong { color: var(--green); }
    .content { padding: 24px 28px 60px; }

    /* ── OVERVIEW STATS ── */
    .stats-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 20px; }
    .stat-box { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 16px 18px; }
    .stat-val { font-size: 26px; font-weight: 700; margin-bottom: 3px; }
    .stat-lbl { font-size: 11px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
    .stat-box.green .stat-val { color: var(--green); }
    .stat-box.blue  .stat-val { color: var(--accent); }
    .stat-box.yellow .stat-val { color: var(--yellow); }

    /* ── CLIENTS GRID ── */
    .clients-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; margin-bottom: 20px; }
    .client-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 15px; cursor: pointer; transition: border-color 0.15s; text-decoration: none; display: block; position: relative; }
    .client-card:hover { border-color: var(--muted); }
    .client-card.selected { border-color: var(--green); background: rgba(34,197,94,0.03); }
    .c-av { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; margin-bottom: 9px; }
    .c-name { font-size: 13px; font-weight: 600; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .c-level { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
    .c-level.beginner { color: var(--green); }
    .c-level.intermediate { color: var(--yellow); }
    .c-level.advanced { color: var(--red); }
    .c-stats { display: flex; gap: 10px; }
    .c-stat { font-size: 10px; color: var(--sub); }
    .c-stat strong { display: block; font-size: 13px; font-weight: 700; color: var(--text); }
    .c-unread { position: absolute; top: 10px; right: 10px; width: 7px; height: 7px; background: var(--accent); border-radius: 50%; }
    .c-plan-tag { font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 3px; background: var(--green-dim); color: var(--green); display: inline-block; margin-top: 6px; }
    .c-no-plan  { font-size: 9px; color: var(--sub); margin-top: 6px; }

    /* ── DETAIL GRID ── */
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .cs { font-size: 11px; color: var(--sub); }

    /* ── PLAN OVERVIEW ── */
    .plan-overview-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
    .plan-overview-meta { font-size: 12px; color: var(--sub); margin-bottom: 10px; }
    .progress-bar { height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; margin-bottom: 5px; }
    .progress-fill { height: 100%; border-radius: 3px; background: var(--green); }
    .progress-lbl { font-size: 11px; color: var(--sub); margin-bottom: 12px; }
    .plan-action-btn { display: inline-flex; align-items: center; gap: 6px; background: var(--green); color: #000; border: none; border-radius: 6px; padding: 8px 16px; font-family: var(--font); font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; transition: opacity 0.15s; }
    .plan-action-btn:hover { opacity: 0.85; }
    .plan-action-btn.outline { background: transparent; color: var(--green); border: 1px solid var(--green); }
    .phase-pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-bottom: 10px; }
    .phase-pill.Hypertrophy { background: var(--accent-dim); color: var(--accent); }
    .phase-pill.Strength    { background: var(--yellow-dim); color: var(--yellow); }
    .phase-pill.Cut         { background: var(--red-dim); color: var(--red); }
    .phase-pill.Endurance   { background: var(--green-dim); color: var(--green); }
    .phase-pill.Deload      { background: var(--border); color: var(--sub); }

    /* ── RECENT LOGS ── */
    .log-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--surface); font-size: 12px; }
    .log-item:last-child { border-bottom: none; }
    .log-ex { font-weight: 600; color: var(--text); flex: 1; }
    .log-detail { color: var(--sub); font-size: 11px; }
    .log-when { font-size: 10px; color: var(--sub); }

    /* ── MESSAGES ── */
    .msg-list { display: flex; flex-direction: column; gap: 10px; max-height: 300px; overflow-y: auto; padding-right: 2px; margin-bottom: 12px; }
    .msg-row { display: flex; gap: 8px; align-items: flex-start; }
    .msg-row.me { flex-direction: row-reverse; }
    .m-av { width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; }
    .m-body { max-width: 78%; padding: 9px 12px; border-radius: 8px; font-size: 12px; line-height: 1.6; }
    .msg-row.them .m-body { background: var(--surface); border: 1px solid var(--border); }
    .msg-row.me   .m-body { background: var(--accent-dim); border: 1px solid rgba(59,130,246,0.2); }
    .m-time { font-size: 10px; color: var(--sub); margin-top: 3px; }
    .msg-input-row { display: flex; gap: 8px; }
    .msg-input-row input { flex: 1; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; }
    .msg-input-row input:focus { border-color: var(--accent); }
    .btn-send { background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; }

    /* ── EMPTY ── */
    .empty { text-align: center; padding: 30px 20px; color: var(--sub); font-size: 13px; }
    .no-client { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 40px; text-align: center; color: var(--sub); font-size: 13px; }

    @media (max-width: 1100px) { .clients-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 900px)  { .g2 { grid-template-columns: 1fr; } .stats-row { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } }
  </style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">FitX <span>Training Club</span></div>
  <div class="coach-tag">Coach Panel</div>
  <nav class="sidebar-nav">
    <div class="nav-lbl">Clients</div>
    <a href="coach_dashboard.php" class="nav-item active">🏠 Dashboard</a>
    <a href="all_clients.php"     class="nav-item">👥 All Clients</a>
    <a href="coach_messages.php"  class="nav-item">💬 Messages</a>
    <div class="nav-lbl">Tools</div>
    <a href="workout_plans.php"   class="nav-item">📋 Workout Plans</a>
    <a href="nutrition_plan.php"  class="nav-item">🥗 Nutrition Plans</a>
    <a href="client_progress.php" class="nav-item">📊 Client Progress</a>
    <div class="nav-lbl">Account</div>
     <a href="coach_profile.php" class="nav-item">⚙️ My profile</a>
    <a href="logout.php" class="nav-item">🚪 Log Out</a>
  </nav>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">Coach Dashboard</div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>

  <div class="content">

    <!-- ── OVERVIEW STATS ── -->
    <div class="stats-row">
      <div class="stat-box green">
        <div class="stat-val"><?= $total_clients ?></div>
        <div class="stat-lbl">Total Clients</div>
      </div>
      <div class="stat-box blue">
        <div class="stat-val"><?= $active_plans ?></div>
        <div class="stat-lbl">Active Plans</div>
      </div>
      <div class="stat-box yellow">
        <div class="stat-val"><?= $active_today ?></div>
        <div class="stat-lbl">Trained Today</div>
      </div>
    </div>

    <!-- ── CLIENTS ── -->
    <div style="font-size:11px;color:var(--sub);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">
      My Clients — <?= $total_clients ?>
    </div>

    <?php if (empty($clients_list)): ?>
      <div class="no-client">No clients assigned yet. Contact your admin to get clients assigned.</div>
    <?php else: ?>

    <div class="clients-grid">
      <?php foreach ($clients_list as $c): ?>
      <a href="coach_dashboard.php?client=<?= $c['id'] ?>" class="client-card <?= $c['id'] === $selected_id ? 'selected' : '' ?>">
        <?php if ($c['unread_msgs']): ?>
          <div class="c-unread" title="New messages"></div>
        <?php endif; ?>
        <div class="c-av" style="background:var(--green-dim);color:var(--green)">
          <?= strtoupper(substr($c['full_name'], 0, 1)) ?>
        </div>
        <div class="c-name"><?= htmlspecialchars($c['full_name']) ?></div>
        <div class="c-level <?= $c['fitness_level'] ?>"><?= ucfirst($c['fitness_level']) ?></div>
        <div class="c-stats">
          <div class="c-stat"><strong><?= $c['workouts_month'] ?></strong>This Month</div>
          <div class="c-stat"><strong><?= $c['workouts_today'] ? '✓' : '—' ?></strong>Today</div>
        </div>
        <?php if ($c['current_plan']): ?>
          <div class="c-plan-tag">📋 <?= htmlspecialchars(substr($c['current_plan'], 0, 18)) ?><?= strlen($c['current_plan']) > 18 ? '…' : '' ?></div>
        <?php else: ?>
          <div class="c-no-plan">No plan assigned</div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- ── CLIENT DETAIL ── -->
    <?php if ($selected_client): ?>
    <div style="font-size:11px;color:var(--sub);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">
      Viewing — <?= htmlspecialchars($selected_client['full_name']) ?>
    </div>

    <div class="g2">

      <!-- LEFT: Plan Overview + Recent Activity -->
      <div>

        <!-- PLAN OVERVIEW -->
        <div class="card">
          <div class="ch">
            <div class="ct">Active Workout Plan</div>
            <a href="workout_plans.php?client=<?= $selected_id ?>" class="plan-action-btn">
              <?= $plan ? '✏️ Edit Plan' : '+ Create Plan' ?>
            </a>
          </div>

          <?php if ($plan): ?>
            <?php
              $phase_key = explode(' ', $plan['phase'] ?? '')[0];
              $phase_key = str_replace(['/', ' '], '', $phase_key);
            ?>
            <div class="plan-overview-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
            <div class="plan-overview-meta">
              <?= (int)$plan['duration_weeks'] ?> weeks
              · Started <?= date('M j, Y', strtotime($plan['created_at'])) ?>
              · Coach: <?= htmlspecialchars($coach_name) ?>
            </div>
            <?php if ($plan['phase']): ?>
              <span class="phase-pill <?= htmlspecialchars($phase_key) ?>"><?= htmlspecialchars($plan['phase']) ?></span>
            <?php endif; ?>
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?= $week_pct ?>%"></div>
            </div>
            <div class="progress-lbl">
              Week <?= $current_week ?> of <?= (int)$plan['duration_weeks'] ?> · <?= $week_pct ?>% complete
            </div>
            <?php if ($plan['notes']): ?>
              <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:12px;font-size:12px;color:var(--muted);line-height:1.8;margin-top:8px;">
                <?= nl2br(htmlspecialchars(mb_substr($plan['notes'], 0, 300))) ?><?= strlen($plan['notes']) > 300 ? '…' : '' ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="empty">
              <div style="font-size:24px;margin-bottom:8px;">📋</div>
              No plan assigned yet.
              <a href="workout_plans.php?client=<?= $selected_id ?>" style="color:var(--green);font-weight:700;text-decoration:none;display:block;margin-top:8px;">Create one now →</a>
            </div>
          <?php endif; ?>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="card">
          <div class="ch">
            <div class="ct">Recent Workouts</div>
            <a href="client_progress.php?client=<?= $selected_id ?>" class="cs" style="color:var(--accent);text-decoration:none;font-weight:600;">View Progress →</a>
          </div>
          <?php if (empty($recent_logs)): ?>
            <div class="empty">No workouts logged yet.</div>
          <?php else: ?>
            <?php foreach ($recent_logs as $log): ?>
            <div class="log-item">
              <div class="log-ex"><?= htmlspecialchars($log['exercise']) ?></div>
              <div class="log-detail">
                <?= $log['sets'] ?>×<?= $log['reps'] ?>
                <?php if ($log['weight_kg'] > 0): ?>@ <?= $log['weight_kg'] ?>kg<?php endif; ?>
                <?php if ($log['muscle_group']): ?>&nbsp;· <?= htmlspecialchars($log['muscle_group']) ?><?php endif; ?>
              </div>
              <div class="log-when"><?= date('M j', strtotime($log['logged_at'])) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>

      <!-- RIGHT: Messages -->
      <div>
        <div class="card" style="height:fit-content;">
          <div class="ch">
            <div class="ct">Messages</div>
            <span class="cs"><?= htmlspecialchars($selected_client['full_name']) ?></span>
          </div>
          <div class="msg-list" id="msg-list">
            <?php if (empty($messages)): ?>
              <div style="color:var(--sub);font-size:12px;text-align:center;padding:20px 0;">No messages yet.</div>
            <?php else: foreach ($messages as $msg):
              $is_me = $msg['sender_id'] == $coach_id; ?>
              <div class="msg-row <?= $is_me ? 'me' : 'them' ?>">
                <div class="m-av" style="background:<?= $is_me ? 'var(--green-dim);color:var(--green)' : 'var(--accent-dim);color:var(--accent)' ?>">
                  <?= $is_me ? strtoupper(substr($coach_name,0,1)) : strtoupper(substr($selected_client['full_name'],0,1)) ?>
                </div>
                <div>
                  <div class="m-body"><?= htmlspecialchars($msg['body']) ?></div>
                  <div class="m-time"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <form class="msg-input-row" action="coach_dashboard.php?client=<?= $selected_id ?>" method="POST">
            <input type="hidden" name="send_message" value="1"/>
            <input type="hidden" name="receiver_id" value="<?= $selected_id ?>"/>
            <input type="text" name="body" placeholder="Write a message..." required/>
            <button type="submit" class="btn-send">Send</button>
          </form>
        </div>
      </div>

    </div>
    <?php endif; // selected_client ?>
    <?php endif; // clients_list not empty ?>

  </div>
</main>

<script>
  const msgList = document.getElementById('msg-list');
  if (msgList) msgList.scrollTop = msgList.scrollHeight;
</script>
</body>
</html>