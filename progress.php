<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// ── Total workouts all time ──────────────────────────────
$r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=?");
$r->bind_param("i", $user_id); $r->execute();
$row = $r->get_result()->fetch_assoc();
$total_workouts = isset($row['c']) ? (int)$row['c'] : 0;
$r->close();

// ── Workouts logged today ────────────────────────────────
$r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=? AND DATE(logged_at)=CURDATE()");
$r->bind_param("i", $user_id); $r->execute();
$row = $r->get_result()->fetch_assoc();
$workouts_today = isset($row['c']) ? (int)$row['c'] : 0;
$r->close();

// ── Total volume TODAY only (resets daily) ───────────────
$r = $conn->prepare("SELECT SUM(sets * reps * weight_kg) as total FROM workout_logs WHERE user_id=? AND DATE(logged_at)=CURDATE()");
$r->bind_param("i", $user_id); $r->execute();
$total_volume = $r->get_result()->fetch_assoc()['total'] ?? 0;
$r->close();

$vol = (float)($total_volume ?: 0);
if ($vol <= 0) {
    $vol_class = 'none'; $vol_tag = ''; $vol_line = 'Log a workout to see your level.';
} elseif ($vol < 8000) {
    $vol_class = 'low'; $vol_tag = 'Low volume'; $vol_line = 'Lighter day — fewer reps or lighter loads.';
} elseif ($vol < 15000) {
    $vol_class = 'medium'; $vol_tag = 'Medium volume'; $vol_line = 'Moderate total work for today.';
} else {
    $vol_class = 'high'; $vol_tag = 'High volume'; $vol_line = 'Heavy total load — rest and fuel matter.';
}

// ── Workouts per month for bar chart (last 6 months) ────
$r = $conn->prepare("
    SELECT DATE_FORMAT(logged_at, '%b') as month_name,
          DATE_FORMAT(logged_at, '%Y-%m') as month_key,
          COUNT(*) as count
    FROM workout_logs
    WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_name
    ORDER BY month_key ASC
");
$r->bind_param("i", $user_id); $r->execute();
$monthly_counts = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$months_data = [];
for ($i = 5; $i >= 0; $i--) {
    $key  = date('Y-m', strtotime("-$i months"));
    $name = date('M',   strtotime("-$i months"));
    $months_data[$key] = ['month_name' => $name, 'count' => 0];
}
foreach ($monthly_counts as $m) {
    if (isset($months_data[$m['month_key']])) $months_data[$m['month_key']]['count'] = $m['count'];
}
$max_count = max(array_column($months_data, 'count') ?: [1]);

// ── Weight history for bar chart (last 6 months) ─────────
$r = $conn->prepare("
    SELECT DATE_FORMAT(recorded_at, '%b') as month_name,
          DATE_FORMAT(recorded_at, '%Y-%m') as month_key,
          AVG(weight_kg) as avg_weight
    FROM body_metrics
    WHERE user_id=? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_name
    ORDER BY month_key ASC
");
$r->bind_param("i", $user_id); $r->execute();
$weight_history = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$weight_data = [];
for ($i = 5; $i >= 0; $i--) {
    $key  = date('Y-m', strtotime("-$i months"));
    $name = date('M',   strtotime("-$i months"));
    $weight_data[$key] = ['month_name' => $name, 'avg_weight' => null];
}
foreach ($weight_history as $w) {
    if (isset($weight_data[$w['month_key']])) $weight_data[$w['month_key']]['avg_weight'] = round($w['avg_weight'], 1);
}
$weight_values = array_filter(array_column($weight_data, 'avg_weight'));
$max_weight   = $weight_values ? max($weight_values) : 100;
$min_weight   = $weight_values ? min($weight_values) : 0;
$weight_range = ($max_weight - $min_weight) ?: 1;

// ── Lift progress (best weight per exercise) ─────────────
$r = $conn->prepare("
    SELECT exercise,
          MIN(weight_kg) as `starting`,
          MAX(weight_kg) as `best`
    FROM workout_logs
    WHERE user_id=? AND weight_kg > 0
    GROUP BY exercise
    ORDER BY MAX(weight_kg) DESC
    LIMIT 5
");
$r->bind_param("i", $user_id); $r->execute();
$exercises_raw = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$lift_progress = [];
foreach ($exercises_raw as $ex) {
    $r2 = $conn->prepare("SELECT weight_kg FROM workout_logs WHERE user_id=? AND exercise=? AND weight_kg > 0 ORDER BY logged_at DESC LIMIT 1");
    $r2->bind_param("is", $user_id, $ex['exercise']);
    $r2->execute();
    $row = $r2->get_result()->fetch_assoc();
    $r2->close();
    $ex['current_weight'] = $row ? $row['weight_kg'] : $ex['best'];
    $lift_progress[] = $ex;
}

// ── Activity heatmap (last 6 months daily) ───────────────
$r = $conn->prepare("
    SELECT DATE(logged_at) as log_date, COUNT(*) as count
    FROM workout_logs
    WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY log_date
");
$r->bind_param("i", $user_id); $r->execute();
$heatmap_raw = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$heatmap_data = [];
foreach ($heatmap_raw as $h) {
    $heatmap_data[$h['log_date']] = (int)$h['count'];
}

// ── Pass today's date from PHP so JS uses server local date ──
// This avoids any UTC offset issue on the client side entirely
$php_today = date('Y-m-d'); // server local date, matches CURDATE() in MySQL

// ── Average per week ─────────────────────────────────────
$r = $conn->prepare("SELECT COUNT(DISTINCT YEARWEEK(logged_at, 1)) as weeks FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)");
$r->bind_param("i", $user_id); $r->execute();
$row = $r->get_result()->fetch_assoc();
$active_weeks = isset($row['weeks']) ? (int)$row['weeks'] : 0;
$r->close();

$r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=? AND logged_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)");
$r->bind_param("i", $user_id); $r->execute();
$row = $r->get_result()->fetch_assoc();
$workouts_6months = isset($row['c']) ? (int)$row['c'] : 0;
$r->close();

$avg_per_week = $active_weeks > 0 ? round($workouts_6months / $active_weeks, 1) : 0;

// ── Best streak ──────────────────────────────────────────
$r = $conn->prepare("SELECT DISTINCT DATE(logged_at) as d FROM workout_logs WHERE user_id=? ORDER BY d ASC");
$r->bind_param("i", $user_id); $r->execute();
$dates = array_column($r->get_result()->fetch_all(MYSQLI_ASSOC), 'd');
$r->close();

$best_streak = 0; $current_streak = 1;
for ($i = 1; $i < count($dates); $i++) {
    $diff = (strtotime($dates[$i]) - strtotime($dates[$i-1])) / 86400;
    if ($diff == 1) { $current_streak++; $best_streak = max($best_streak, $current_streak); }
    else { $current_streak = 1; }
}
$best_streak = max($best_streak, $current_streak, count($dates) > 0 ? 1 : 0);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Progress</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #f59e0b; --accent-dim: rgba(245,158,11,0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --red: #ef4444;
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

    /* SIDEBAR */
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

    /* MAIN */
    .main { margin-left: 210px; flex: 1; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .content { padding: 24px 28px; }

    /* STATS */
    .stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
    .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 16px 18px; }
    .stat-card .lbl { font-size: 11px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
    .stat-card .val { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; margin-bottom: 4px; }
    .stat-card .note { font-size: 11px; color: var(--sub); }
    .vol-reset { font-size: 9px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase; color: var(--accent); border: 1px solid rgba(245,158,11,0.35); padding: 2px 7px; border-radius: 4px; }
    .vol-tier { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-top: 6px; }
    .vol-tier.low    { background: rgba(59,130,246,0.12); color: #93c5fd; }
    .vol-tier.medium { background: rgba(245,158,11,0.12); color: var(--accent); }
    .vol-tier.high   { background: rgba(34,197,94,0.12);  color: var(--green); }
    .vol-tier.none   { display: none; }
    .vol-mean { font-size: 11px; color: var(--sub); margin-top: 4px; line-height: 1.35; }
    .note.up { color: var(--green) !important; }
    .note.dn { color: var(--red)   !important; }

    /* CHARTS */
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .bar-chart { display: flex; align-items: flex-end; gap: 8px; height: 140px; padding-bottom: 24px; position: relative; }
    .bar-chart::after { content: ''; position: absolute; bottom: 24px; left: 0; right: 0; height: 1px; background: var(--border); }
    .bar-col { display: flex; flex-direction: column; align-items: center; gap: 4px; flex: 1; }
    .bar-fill { width: 100%; border-radius: 4px 4px 0 0; background: var(--accent); opacity: 0.7; transition: opacity 0.15s; cursor: pointer; min-height: 4px; }
    .bar-fill:hover { opacity: 1; }
    .bar-fill.green { background: var(--green); }
    .bar-lbl { font-size: 10px; color: var(--sub); font-weight: 600; }

    /* LIFT TABLE */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 11px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .trend { font-size: 11px; font-weight: 700; }
    .trend.up { color: var(--green); }
    .trend.dn { color: var(--red); }

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
    .hm-cell.l2 { background: rgba(34,197,94,0.5);  }
    .hm-cell.l3 { background: rgba(34,197,94,0.75); }
    .hm-cell.l4 { background: #22c55e; }
    .hm-legend { display: flex; align-items: center; gap: 5px; margin-top: 10px; }
    .hm-legend span { font-size: 10px; color: var(--sub); }
    .hm-legend-cell { width: 12px; height: 12px; border-radius: 2px; }
    .hm-tooltip { position: fixed; background: #1e1e1e; border: 1px solid var(--border); color: var(--text); font-size: 11px; padding: 5px 9px; border-radius: 5px; pointer-events: none; z-index: 999; display: none; white-space: nowrap; }

    @media (max-width: 1100px) { .stats-row { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } .g2 { grid-template-columns: 1fr; } }
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
    <a href="progress.php"     class="nav-item active">📈 Progress</a>
    <a href="body_metrics.php" class="nav-item">⚖️ Body Metrics</a>
    <?php if ($tier === 'premium'): ?>
    <div class="nav-lbl">Premium</div>
    <a href="my_coach.php" class="nav-item">👤 My Coach</a>
    <a href="my_plan.php"  class="nav-item">📋 My Plan</a>
    <a href="messages.php" class="nav-item">💬 Messages</a>
    <?php endif; ?>
    <div class="nav-lbl">Account</div>
    <a href="profile.php" class="nav-item">⚙️ Profile</a>
    <a href="billing.php" class="nav-item">💳 Billing</a>
  </nav>
  <div class="sidebar-foot"><a href="logout.php" class="nav-item">🚪 Log Out</a></div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">Progress</div>
    <span style="font-size:12px;color:var(--sub)">Last 6 months</span>
  </div>

  <div class="content">

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="lbl">Total Workouts</div>
        <div class="val"><?= $total_workouts ?></div>
        <div class="note up">↑ <?= $workouts_today ?> Today</div>
      </div>
      <div class="stat-card">
        <div class="lbl">Total Volume <span class="vol-reset" title="Only today's sets count; back to 0 each new day.">Resets daily</span></div>
        <div class="val" style="font-size:18px;"><?= number_format($vol) ?> <span style="font-size:13px;color:var(--sub)">kg · today</span></div>
        <?php if ($vol_tag): ?><span class="vol-tier <?= htmlspecialchars($vol_class) ?>"><?= htmlspecialchars($vol_tag) ?></span><?php endif; ?>
        <div class="vol-mean"><?= htmlspecialchars($vol_line) ?></div>
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
          <?php foreach ($months_data as $m): ?>
          <div class="bar-col">
            <div class="bar-fill <?= $m['count'] === $max_count ? 'green' : '' ?>"
                 style="height:<?= $max_count > 0 ? round(($m['count']/$max_count)*100) : 4 ?>%"
                 title="<?= $m['count'] ?> workouts">
            </div>
            <div class="bar-lbl"><?= $m['month_name'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="ch"><div class="ct">Weight Over Time</div></div>
        <div class="bar-chart">
          <?php foreach ($weight_data as $w): ?>
          <div class="bar-col">
            <?php if ($w['avg_weight']): ?>
              <?php $h = round((($w['avg_weight']-$min_weight)/$weight_range)*80)+15; ?>
              <div class="bar-fill green" style="height:<?= $h ?>%" title="<?= $w['avg_weight'] ?> kg"></div>
            <?php else: ?>
              <div class="bar-fill" style="height:4%;opacity:0.2;"></div>
            <?php endif; ?>
            <div class="bar-lbl"><?= $w['month_name'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- LIFT PROGRESS -->
    <div class="card">
      <div class="ch">
        <div class="ct">Lift Progress</div>
        <span style="font-size:11px;color:var(--sub)">Personal Records</span>
      </div>
      <table>
        <thead><tr><th>Exercise</th><th>Starting</th><th>Current</th><th>Best</th><th>Change</th></tr></thead>
        <tbody>
          <?php if (empty($lift_progress)): ?>
            <tr><td colspan="5" style="color:var(--sub);text-align:center;padding:20px 0;">No workout data yet.</td></tr>
          <?php else: foreach ($lift_progress as $l):
            $change = round($l['best'] - $l['starting'], 1); ?>
            <tr>
              <td><?= htmlspecialchars($l['exercise']) ?></td>
              <td><?= $l['starting'] ?> kg</td>
              <td><?= $l['current_weight'] ?> kg</td>
              <td><?= $l['best'] ?> kg</td>
              <td><span class="trend <?= $change >= 0 ? 'up' : 'dn' ?>"><?= $change >= 0 ? '↑' : '↓' ?> <?= abs($change) ?> kg</span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
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
            <div class="heatmap-grid"   id="heatmap"></div>
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
</main>
   
<script>
const heatmapData = <?= json_encode($heatmap_data) ?>;
const hmGrid      = document.getElementById('heatmap');
const hmMonths    = document.getElementById('hm-months');
const tooltip     = document.getElementById('hm-tooltip');

// KEY FIX: use PHP server date as "today" anchor — avoids all JS timezone issues
// PHP date('Y-m-d') matches exactly what MySQL DATE(logged_at) returns
const PHP_TODAY = '<?= $php_today ?>';

// Parse YYYY-MM-DD string into a plain date object at midnight local time
function parseLocalDate(str) {
  const [y, m, d] = str.split('-').map(Number);
  return new Date(y, m - 1, d); // month is 0-indexed; no UTC involved
}

// Format a local Date object back to YYYY-MM-DD for heatmap key lookup
function localDateStr(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

if (hmGrid) {
  const WEEKS = 26;

  // Anchor everything to PHP's today — no JS Date() timezone ambiguity
  const today = parseLocalDate(PHP_TODAY);

  // Find the Monday of the week 26 weeks ago
  const startDate = new Date(today);
  startDate.setDate(today.getDate() - WEEKS * 7 + 1);
  const dow    = startDate.getDay();         // 0=Sun, 1=Mon ...
  const offset = dow === 0 ? 6 : dow - 1;   // how many days back to Monday
  startDate.setDate(startDate.getDate() - offset);

  let monthLabels   = {};
  let currentMonth  = -1;
  let lastLabelWeek = -99;

  for (let w = 0; w < WEEKS; w++) {
    const weekCol = document.createElement('div');
    weekCol.className = 'hm-week';

    for (let d = 0; d < 7; d++) {
      const cellDate = new Date(
        startDate.getFullYear(),
        startDate.getMonth(),
        startDate.getDate() + w * 7 + d
      );

      // Month label on Monday of each new month, spaced at least 3 weeks apart
      if (d === 0 && cellDate.getMonth() !== currentMonth && w - lastLabelWeek > 2) {
        currentMonth   = cellDate.getMonth();
        monthLabels[w] = cellDate.toLocaleDateString('en-US', { month: 'short' });
        lastLabelWeek  = w;
      }

      const key      = localDateStr(cellDate);
      const count    = heatmapData[key] || 0;
      const isFuture = localDateStr(cellDate) > PHP_TODAY; // string compare is safe for YYYY-MM-DD

      const cell = document.createElement('div');
      let level = '';
      if (!isFuture) {
        if      (count === 1) level = 'l1';
        else if (count === 2) level = 'l2';
        else if (count === 3) level = 'l3';
        else if (count >= 4)  level = 'l4';
      }
      cell.className = 'hm-cell ' + level;
      if (isFuture) cell.style.opacity = '0.15';

      const label = cellDate.toLocaleDateString('en-US', {
        weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
      });
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

  // Month label row
  if (hmMonths) {
    const cellW = 15;
    for (let w = 0; w < WEEKS; w++) {
      const span = document.createElement('div');
      span.className        = 'hm-month-lbl';
      span.style.width      = cellW + 'px';
      span.style.flexShrink = '0';
      if (monthLabels[w]) span.textContent = monthLabels[w];
      hmMonths.appendChild(span);
    }
  }
}
</script>
</body>
</html>