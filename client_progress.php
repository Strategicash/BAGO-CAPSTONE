<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];

// Fetch assigned clients
$r = $conn->prepare("
    SELECT id, full_name, fitness_level,
    (SELECT COUNT(*) FROM workout_logs WHERE user_id = u.id AND MONTH(logged_at) = MONTH(NOW())) AS workouts_month,
    (SELECT plan_name FROM workout_plans WHERE member_id = u.id ORDER BY created_at DESC LIMIT 1) AS current_plan
    FROM users u WHERE coach_id = ? AND role = 'member' ORDER BY full_name
");
$r->bind_param("i", $coach_id);
$r->execute();
$clients_list = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// Selected client
$selected_id = intval($_GET['client'] ?? ($clients_list[0]['id'] ?? 0));
$selected_client = null;
foreach ($clients_list as $c) {
    if ($c['id'] === $selected_id) { $selected_client = $c; break; }
}

$total_workouts = $workouts_this_month = $total_volume = $best_streak = 0;
$avg_per_week = 0;
$recent_workouts = $lift_progress = $months_data = $weight_data = [];
$latest_metrics = $prev_metrics = null;
$max_count = 1;
$min_weight = $max_weight = $weight_range = 0;
$heatmap_data = [];

if ($selected_id) {

    // Total workouts (all time)
    $r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=?");
    $r->bind_param("i", $selected_id); $r->execute();
    $total_workouts = $r->get_result()->fetch_assoc()['c'];
    $r->close();

    // This month
    $r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=? AND MONTH(logged_at)=MONTH(NOW()) AND YEAR(logged_at)=YEAR(NOW())");
    $r->bind_param("i", $selected_id); $r->execute();
    $workouts_this_month = $r->get_result()->fetch_assoc()['c'];
    $r->close();

    // ── FIX 1: Total volume TODAY only (resets daily) ────────────────────────
    $r = $conn->prepare("SELECT SUM(sets * reps * weight_kg) as total FROM workout_logs WHERE user_id=? AND DATE(logged_at)=CURDATE()");
    $r->bind_param("i", $selected_id); $r->execute();
    $total_volume = $r->get_result()->fetch_assoc()['total'] ?? 0;
    $r->close();

    // ── FIX 2: Avg/week — workouts last 6 months ÷ distinct active weeks ────
    // Step A: count distinct weeks with at least one workout (last 6 months)
    $r = $conn->prepare("SELECT COUNT(DISTINCT YEARWEEK(logged_at, 1)) as weeks FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)");
    $r->bind_param("i", $selected_id); $r->execute();
    $active_weeks = (int)($r->get_result()->fetch_assoc()['weeks'] ?? 0);
    $r->close();

    // Step B: count total workouts in last 6 months
    $r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)");
    $r->bind_param("i", $selected_id); $r->execute();
    $workouts_6months = (int)($r->get_result()->fetch_assoc()['c'] ?? 0);
    $r->close();

    $avg_per_week = $active_weeks > 0 ? round($workouts_6months / $active_weeks, 1) : 0;

    // Best streak
    $r = $conn->prepare("SELECT DISTINCT DATE(logged_at) as d FROM workout_logs WHERE user_id=? ORDER BY d ASC");
    $r->bind_param("i", $selected_id); $r->execute();
    $dates = array_column($r->get_result()->fetch_all(MYSQLI_ASSOC), 'd');
    $r->close();
    $best_streak = 0; $cur = 1;
    for ($i = 1; $i < count($dates); $i++) {
        $diff = (strtotime($dates[$i]) - strtotime($dates[$i-1])) / 86400;
        if ($diff == 1) { $cur++; $best_streak = max($best_streak, $cur); }
        else { $cur = 1; }
    }
    $best_streak = max($best_streak, $cur, count($dates) > 0 ? 1 : 0);

    // Workouts per month (last 6)
    $r = $conn->prepare("
        SELECT DATE_FORMAT(logged_at,'%b') as month_name,
               DATE_FORMAT(logged_at,'%Y-%m') as month_key,
               COUNT(*) as count
        FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_name ORDER BY month_key ASC
    ");
    $r->bind_param("i", $selected_id); $r->execute();
    $monthly_raw = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();

    for ($i = 5; $i >= 0; $i--) {
        $key  = date('Y-m', strtotime("-$i months"));
        $name = date('M',   strtotime("-$i months"));
        $months_data[$key] = ['month_name' => $name, 'count' => 0];
    }
    foreach ($monthly_raw as $m) {
        if (isset($months_data[$m['month_key']])) $months_data[$m['month_key']]['count'] = $m['count'];
    }
    $max_count = max(array_column($months_data, 'count') ?: [1]);

    // Weight history (last 6 months)
    $r = $conn->prepare("
        SELECT DATE_FORMAT(recorded_at,'%b') as month_name,
               DATE_FORMAT(recorded_at,'%Y-%m') as month_key,
               AVG(weight_kg) as avg_weight
        FROM body_metrics WHERE user_id=? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_name ORDER BY month_key ASC
    ");
    $r->bind_param("i", $selected_id); $r->execute();
    $weight_raw = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();

    for ($i = 5; $i >= 0; $i--) {
        $key  = date('Y-m', strtotime("-$i months"));
        $name = date('M',   strtotime("-$i months"));
        $weight_data[$key] = ['month_name' => $name, 'avg_weight' => null];
    }
    foreach ($weight_raw as $w) {
        if (isset($weight_data[$w['month_key']])) $weight_data[$w['month_key']]['avg_weight'] = round($w['avg_weight'], 1);
    }
    $wvals = array_filter(array_column($weight_data, 'avg_weight'));
    $max_weight = $wvals ? max($wvals) : 100;
    $min_weight = $wvals ? min($wvals) : 0;
    $weight_range = ($max_weight - $min_weight) ?: 1;

    // Recent workouts (last 8)
    $r = $conn->prepare("SELECT exercise, muscle_group, sets, reps, weight_kg, logged_at FROM workout_logs WHERE user_id=? ORDER BY logged_at DESC LIMIT 8");
    $r->bind_param("i", $selected_id); $r->execute();
    $recent_workouts = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();

    // Lift progress (top 5 by best weight)
    $r = $conn->prepare("
        SELECT exercise, MIN(weight_kg) as `starting`, MAX(weight_kg) as `best`
        FROM workout_logs WHERE user_id=? AND weight_kg > 0
        GROUP BY exercise ORDER BY MAX(weight_kg) DESC LIMIT 5
    ");
    $r->bind_param("i", $selected_id); $r->execute();
    $exs = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
    foreach ($exs as $ex) {
        $r2 = $conn->prepare("SELECT weight_kg FROM workout_logs WHERE user_id=? AND exercise=? AND weight_kg>0 ORDER BY logged_at DESC LIMIT 1");
        $r2->bind_param("is", $selected_id, $ex['exercise']);
        $r2->execute();
        $row = $r2->get_result()->fetch_assoc();
        $r2->close();
        $ex['current'] = $row ? $row['weight_kg'] : $ex['best'];
        $lift_progress[] = $ex;
    }

    // Latest body metrics
    $r = $conn->prepare("SELECT * FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1");
    $r->bind_param("i", $selected_id); $r->execute();
    $latest_metrics = $r->get_result()->fetch_assoc();
    $r->close();

    $r = $conn->prepare("SELECT * FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1 OFFSET 1");
    $r->bind_param("i", $selected_id); $r->execute();
    $prev_metrics = $r->get_result()->fetch_assoc();
    $r->close();

    // Heatmap
    $r = $conn->prepare("SELECT DATE(logged_at) as log_date, COUNT(*) as count FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY log_date");
    $r->bind_param("i", $selected_id); $r->execute();
    $hmap = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
    foreach ($hmap as $h) $heatmap_data[$h['log_date']] = $h['count'];
}

$conn->close();

function chg($new, $old, $unit = 'cm') {
    if (!$new || !$old) return '<span style="color:var(--sub);font-size:11px;">—</span>';
    $d = round($new - $old, 1);
    if ($d > 0) return '<span style="color:#ef4444;font-size:11px;">↑ '.$d.' '.$unit.'</span>';
    if ($d < 0) return '<span style="color:#22c55e;font-size:11px;">↓ '.abs($d).' '.$unit.'</span>';
    return '<span style="color:var(--sub);font-size:11px;">No change</span>';
}
function mtag($m) {
    $map = ['legs'=>'legs','back'=>'back','chest'=>'chest','shoulders'=>'shoulders','arms'=>'arms'];
    return $map[strtolower($m ?? '')] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Client Progress</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #3b82f6; --accent-dim: rgba(59,130,246,0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --yellow: #f59e0b; --red: #ef4444;
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

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

    .main { margin-left: 210px; flex: 1; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .coach-id { font-size: 12px; color: var(--sub); }
    .coach-id strong { color: var(--green); }
    .content { padding: 24px 28px; }

    .client-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .client-tab { padding: 7px 14px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s; }
    .client-tab:hover { border-color: var(--green); color: var(--green); }
    .client-tab.active { background: var(--green-dim); border-color: var(--green); color: var(--green); }

    .stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
    .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 16px 18px; }
    .stat-card .lbl { font-size: 11px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    .stat-card .val { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; margin-bottom: 4px; }
    .stat-card .note { font-size: 11px; color: var(--sub); }
    .note.up { color: var(--green); }
    .note.dn { color: var(--red); }

    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .cs { font-size: 11px; color: var(--sub); }

    .bar-chart { display: flex; align-items: flex-end; gap: 8px; height: 100px; }
    .bar-col { display: flex; flex-direction: column; align-items: center; gap: 4px; flex: 1; height: 100%; justify-content: flex-end; }
    .bar-fill { width: 100%; border-radius: 3px 3px 0 0; background: var(--border); min-height: 4px; transition: height 0.3s; }
    .bar-fill.green { background: var(--green); }
    .bar-fill.accent { background: var(--accent); }
    .bar-lbl { font-size: 10px; color: var(--sub); font-weight: 600; }

    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 10px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .tag { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 4px; background: var(--accent-dim); color: var(--accent); }
    .tag.legs { background: rgba(245,158,11,0.1); color: var(--yellow); }
    .tag.back { background: var(--green-dim); color: var(--green); }
    .tag.chest { background: rgba(239,68,68,0.1); color: var(--red); }
    .trend { font-size: 11px; font-weight: 700; }
    .trend.up { color: var(--green); }
    .trend.dn { color: var(--red); }

    .metric-row { display: flex; align-items: center; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--surface); }
    .metric-row:last-child { border-bottom: none; }
    .metric-name { font-size: 13px; font-weight: 600; }
    .metric-val { font-size: 13px; color: var(--muted); }

    /* HEATMAP */
.heatmap-wrap { overflow-x: auto; }
.heatmap-inner { display: flex; gap: 6px; align-items: flex-start; min-width: fit-content; }
.heatmap-day-labels { display: flex; flex-direction: column; gap: 3px; padding-top: 20px; }
.hm-day-lbl { height: 12px; font-size: 9px; color: var(--sub); font-weight: 600; line-height: 12px; width: 24px; flex-shrink: 0; }
.heatmap-grid-wrap { display: flex; flex-direction: column; }
.heatmap-months { display: flex; margin-bottom: 4px; }
.hm-month-lbl { font-size: 9px; color: var(--sub); font-weight: 600; }
.heatmap-grid { display: flex; gap: 3px; }
.hm-week { display: flex; flex-direction: column; gap: 3px; }
.hm-cell { width: 12px; height: 12px; border-radius: 2px; background: var(--border); cursor: pointer; transition: background 0.15s; position: relative; flex-shrink: 0; }
.hm-cell:hover { outline: 1px solid rgba(255,255,255,0.3); }
.hm-cell.l1 { background: rgba(34,197,94,0.25); }
.hm-cell.l2 { background: rgba(34,197,94,0.5); }
.hm-cell.l3 { background: rgba(34,197,94,0.75); }
.hm-cell.l4 { background: #22c55e; }
.hm-legend { display: flex; align-items: center; gap: 5px; margin-top: 10px; }
.hm-legend span { font-size: 10px; color: var(--sub); }
.hm-legend-cell { width: 12px; height: 12px; border-radius: 2px; }
/* Tooltip */
.hm-tooltip { position: fixed; background: #1e1e1e; border: 1px solid var(--border); color: var(--text); font-size: 11px; padding: 5px 9px; border-radius: 5px; pointer-events: none; z-index: 999; display: none; white-space: nowrap; }

    .empty { text-align: center; padding: 30px 0; color: var(--sub); font-size: 13px; }

    @media (max-width: 1100px) { .stats-row { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 900px) { .g2 { grid-template-columns: 1fr; } }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } }
  </style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">FitX <span>Training Club</span></div>
  <div class="coach-tag">Coach Panel</div>
  <nav class="sidebar-nav">
    <div class="nav-lbl">Clients</div>
    <a href="coach_dashboard.php" class="nav-item">🏠 Dashboard</a>
    <a href="all_clients.php" class="nav-item">👥 All Clients</a>
    <a href="coach_messages.php" class="nav-item">💬 Messages</a>
    <div class="nav-lbl">Tools</div>
    <a href="workout_plans.php" class="nav-item">📋 Workout Plans</a>
    <a href="nutrition_plan.php" class="nav-item">🥗 Nutrition Plans</a>
    <a href="client_progress.php" class="nav-item active">📊 Client Progress</a>
    <div class="nav-lbl">Account</div>
     <a href="coach_profile.php" class="nav-item">⚙️ My profile</a>
    <a href="logout.php" class="nav-item">🚪 Log Out</a>
  </nav>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">Client Progress</div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>

  <div class="content">

    <?php if(empty($clients_list)): ?>
      <div class="empty">No clients assigned yet.</div>
    <?php else: ?>

    <div style="font-size:11px;color:var(--sub);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">Select Client</div>
    <div class="client-tabs">
      <?php foreach($clients_list as $c): ?>
        <a href="client_progress.php?client=<?= $c['id'] ?>"
           class="client-tab <?= $c['id']===$selected_id ? 'active' : '' ?>">
          <?= htmlspecialchars($c['full_name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if($selected_client): ?>

    <!-- CLIENT HEADER -->
    <div style="display:flex;align-items:center;gap:14px;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:16px 20px;margin-bottom:20px;">
      <div style="width:42px;height:42px;border-radius:50%;background:var(--accent-dim);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0;">
        <?= strtoupper(substr($selected_client['full_name'],0,1)) ?>
      </div>
      <div>
        <div style="font-size:15px;font-weight:700;margin-bottom:3px;"><?= htmlspecialchars($selected_client['full_name']) ?></div>
        <div style="font-size:12px;color:var(--sub);"><?= ucfirst($selected_client['fitness_level']) ?> · <?= $selected_client['workouts_month'] ?> workouts this month · Plan: <?= $selected_client['current_plan'] ? htmlspecialchars($selected_client['current_plan']) : 'None' ?></div>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;">
        <a href="workout_plans.php?client=<?= $selected_id ?>" style="font-size:12px;font-weight:600;color:var(--accent);text-decoration:none;border:1px solid var(--border);padding:6px 12px;border-radius:6px;">Edit Plan</a>
        <a href="coach_messages.php?client=<?= $selected_id ?>" style="font-size:12px;font-weight:600;color:var(--green);text-decoration:none;border:1px solid var(--border);padding:6px 12px;border-radius:6px;">Message</a>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="lbl">Total Workouts</div>
        <div class="val"><?= $total_workouts ?></div>
        <div class="note up">↑ <?= $workouts_this_month ?> this month</div>
      </div>
      <div class="stat-card">
        <div class="lbl">Total Volume</div>
        <div class="val" style="font-size:18px;"><?= number_format($total_volume) ?> <span style="font-size:13px;color:var(--sub)">kg</span></div>
        <div class="note">Today · resets daily</div>
      </div>
      <div class="stat-card">
        <div class="lbl">Best Streak</div>
        <div class="val"><?= $best_streak ?> <span style="font-size:14px;color:var(--sub)">days</span></div>
        <div class="note">Personal best</div>
      </div>
      <div class="stat-card">
        <div class="lbl">Avg / Week</div>
        <div class="val"><?= $avg_per_week ?></div>
        <div class="note">Last 6 months</div>
      </div>
    </div>

    <!-- CHARTS -->
    <div class="g2">
      <div class="card">
        <div class="ch"><div class="ct">Workouts per Month</div></div>
        <div class="bar-chart">
          <?php foreach($months_data as $m): ?>
          <div class="bar-col">
            <div class="bar-fill accent" style="height:<?= $max_count > 0 ? round(($m['count']/$max_count)*100) : 4 ?>%" title="<?= $m['count'] ?> workouts"></div>
            <div class="bar-lbl"><?= $m['month_name'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card">
        <div class="ch"><div class="ct">Weight Over Time</div></div>
        <div class="bar-chart">
          <?php foreach($weight_data as $w): ?>
          <div class="bar-col">
            <?php if($w['avg_weight']): ?>
              <?php $h = round((($w['avg_weight']-$min_weight)/$weight_range)*80)+15; ?>
              <div class="bar-fill green" style="height:<?= $h ?>%" title="<?= $w['avg_weight'] ?> kg"></div>
            <?php else: ?>
              <div class="bar-fill" style="height:4%;opacity:0.3;"></div>
            <?php endif; ?>
            <div class="bar-lbl"><?= $w['month_name'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- LIFT PROGRESS + RECENT WORKOUTS -->
    <div class="g2">
      <div class="card">
        <div class="ch"><div class="ct">Lift Progress</div><span class="cs">Top lifts by weight</span></div>
        <?php if(empty($lift_progress)): ?>
          <div class="empty">No workout data yet.</div>
        <?php else: ?>
        <table>
          <thead><tr><th>Exercise</th><th>Start</th><th>Current</th><th>Best</th><th>Change</th></tr></thead>
          <tbody>
            <?php foreach($lift_progress as $l):
              $change = round($l['best'] - $l['starting'], 1);
            ?>
            <tr>
              <td><?= htmlspecialchars($l['exercise']) ?></td>
              <td><?= $l['starting'] ?> kg</td>
              <td><?= $l['current'] ?> kg</td>
              <td><?= $l['best'] ?> kg</td>
              <td><span class="trend <?= $change >= 0 ? 'up' : 'dn' ?>"><?= $change >= 0 ? '↑' : '↓' ?> <?= abs($change) ?> kg</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="ch"><div class="ct">Recent Workouts</div><span class="cs">Last 8 sessions</span></div>
        <?php if(empty($recent_workouts)): ?>
          <div class="empty">No workouts logged yet.</div>
        <?php else: ?>
        <table>
          <thead><tr><th>Exercise</th><th>Sets × Reps</th><th>Weight</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach($recent_workouts as $w): ?>
            <tr>
              <td>
                <?= htmlspecialchars($w['exercise']) ?>
                <?php if($w['muscle_group']): ?>
                  <span class="tag <?= mtag($w['muscle_group']) ?>"><?= htmlspecialchars($w['muscle_group']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= $w['sets'] ?> × <?= $w['reps'] ?></td>
              <td><?= $w['weight_kg'] > 0 ? $w['weight_kg'].' kg' : 'BW' ?></td>
              <td><?= date('M j', strtotime($w['logged_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- BODY METRICS -->
    <div class="g2">
      <div class="card">
        <div class="ch">
          <div class="ct">Body Measurements</div>
          <span class="cs"><?= $latest_metrics ? date('M j, Y', strtotime($latest_metrics['recorded_at'])) : 'No data' ?></span>
        </div>
        <?php if($latest_metrics): ?>
        <div class="metric-row"><div class="metric-name">Weight</div><div class="metric-val"><?= $latest_metrics['weight_kg'] ? $latest_metrics['weight_kg'].' kg' : '—' ?></div><div><?= chg($latest_metrics['weight_kg']??null, $prev_metrics['weight_kg']??null,'kg') ?></div></div>
        <div class="metric-row"><div class="metric-name">BMI</div><div class="metric-val"><?= $latest_metrics['bmi'] ?? '—' ?></div><div><?= chg($latest_metrics['bmi']??null, $prev_metrics['bmi']??null,'') ?></div></div>
        <div class="metric-row"><div class="metric-name">Chest</div><div class="metric-val"><?= $latest_metrics['chest_cm'] ? $latest_metrics['chest_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['chest_cm']??null, $prev_metrics['chest_cm']??null) ?></div></div>
        <div class="metric-row"><div class="metric-name">Waist</div><div class="metric-val"><?= $latest_metrics['waist_navel_cm'] ? $latest_metrics['waist_navel_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['waist_navel_cm']??null, $prev_metrics['waist_navel_cm']??null) ?></div></div>
        <div class="metric-row"><div class="metric-name">Hips</div><div class="metric-val"><?= $latest_metrics['hips_cm'] ? $latest_metrics['hips_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['hips_cm']??null, $prev_metrics['hips_cm']??null) ?></div></div>
        <div class="metric-row"><div class="metric-name">Right Arm</div><div class="metric-val"><?= $latest_metrics['right_arm_cm'] ? $latest_metrics['right_arm_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['right_arm_cm']??null, $prev_metrics['right_arm_cm']??null) ?></div></div>
        <div class="metric-row"><div class="metric-name">Left Arm</div><div class="metric-val"><?= $latest_metrics['left_arm_cm'] ? $latest_metrics['left_arm_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['left_arm_cm']??null, $prev_metrics['left_arm_cm']??null) ?></div></div>
        <div class="metric-row"><div class="metric-name">Right Leg</div><div class="metric-val"><?= $latest_metrics['right_leg_cm'] ? $latest_metrics['right_leg_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['right_leg_cm']??null, $prev_metrics['right_leg_cm']??null) ?></div></div>
        <div class="metric-row"><div class="metric-name">Left Leg</div><div class="metric-val"><?= $latest_metrics['left_leg_cm'] ? $latest_metrics['left_leg_cm'].' cm' : '—' ?></div><div><?= chg($latest_metrics['left_leg_cm']??null, $prev_metrics['left_leg_cm']??null) ?></div></div>
        <?php else: ?>
          <div class="empty">Client has not logged any body measurements yet.</div>
        <?php endif; ?>
      </div>

     <!-- ACTIVITY HEATMAP -->
<div class="card">
  <div class="ch">
    <div class="ct">Activity Heatmap</div>
    <span style="font-size:11px;color:var(--sub)">Past 6 months · hover a cell to see details</span>
  </div>
  <div class="heatmap-wrap">
    <div class="heatmap-inner">
      <div class="heatmap-day-labels">
        <div class="hm-day-lbl">Mon</div>
        <div class="hm-day-lbl"></div>
        <div class="hm-day-lbl">Wed</div>
        <div class="hm-day-lbl"></div>
        <div class="hm-day-lbl">Fri</div>
        <div class="hm-day-lbl"></div>
        <div class="hm-day-lbl">Sun</div>
      </div>
      <div class="heatmap-grid-wrap">
        <div class="heatmap-months" id="hm-months"></div>
        <div class="heatmap-grid" id="heatmap"></div>
      </div>
    </div>
  </div>
  <div class="hm-legend">
    <span>Less</span>
    <div class="hm-legend-cell" style="background:var(--border)"></div>
    <div class="hm-legend-cell" style="background:rgba(34,197,94,0.25)"></div>
    <div class="hm-legend-cell" style="background:rgba(34,197,94,0.5)"></div>
    <div class="hm-legend-cell" style="background:rgba(34,197,94,0.75)"></div>
    <div class="hm-legend-cell" style="background:#22c55e"></div>
    <span>More</span>
  </div>
</div>
<div class="hm-tooltip" id="hm-tooltip"></div>
    </div>

    <?php endif; ?>
    <?php endif; ?>

  </div>
</main>

<script>
 const heatmapData = <?= json_encode($heatmap_data) ?>;
const hmGrid      = document.getElementById('heatmap');
const hmMonths    = document.getElementById('hm-months');
const tooltip     = document.getElementById('hm-tooltip');

if (hmGrid) {
  const WEEKS = 26;
  const today = new Date();

  // Find the Sunday of the week 26 weeks ago (start of grid)
  const startDate = new Date(today);
  startDate.setDate(today.getDate() - (WEEKS * 7) + 1);
  // Align to Monday
  const startDay = startDate.getDay(); // 0=Sun
  const offset   = startDay === 0 ? 6 : startDay - 1;
  startDate.setDate(startDate.getDate() - offset);

  // Build week columns
  let monthLabels = {}; // week index → month label
  let currentMonth = -1;

  for (let w = 0; w < WEEKS; w++) {
    const weekCol = document.createElement('div');
    weekCol.className = 'hm-week';

    for (let d = 0; d < 7; d++) {
      const cellDate = new Date(startDate);
      cellDate.setDate(startDate.getDate() + w * 7 + d);

      // Track month label position
      if (cellDate.getMonth() !== currentMonth && d === 0) {
        currentMonth = cellDate.getMonth();
        monthLabels[w] = cellDate.toLocaleDateString('en-US', { month: 'short' });
      }

      const key   = cellDate.toISOString().split('T')[0];
      const count = heatmapData[key] || 0;
      const isFuture = cellDate > today;

      const cell = document.createElement('div');
      let level  = '';
      if (!isFuture && count === 1) level = 'l1';
      else if (!isFuture && count === 2) level = 'l2';
      else if (!isFuture && count === 3) level = 'l3';
      else if (!isFuture && count >= 4) level = 'l4';

      cell.className = 'hm-cell ' + level;
      if (isFuture) cell.style.opacity = '0.2';

      // Tooltip
      const label = cellDate.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' });
      cell.addEventListener('mousemove', e => {
        tooltip.style.display = 'block';
        tooltip.style.left    = (e.clientX + 12) + 'px';
        tooltip.style.top     = (e.clientY - 28) + 'px';
        tooltip.textContent   = count
          ? `${label} — ${count} workout${count > 1 ? 's' : ''}`
          : `${label} — No workout`;
      });
      cell.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });

      weekCol.appendChild(cell);
    }
    hmGrid.appendChild(weekCol);
  }

  // Build month labels aligned to week positions
  if (hmMonths) {
    const cellW = 15; // 12px cell + 3px gap
    let lastW   = -99;
    for (let w = 0; w < WEEKS; w++) {
      const span = document.createElement('div');
      span.className = 'hm-month-lbl';
      span.style.width = cellW + 'px';
      span.style.flexShrink = '0';
      if (monthLabels[w] && w - lastW > 2) {
        span.textContent = monthLabels[w];
        lastW = w;
      }
      hmMonths.appendChild(span);
    }
  }
}
</script>
</body>
</html>