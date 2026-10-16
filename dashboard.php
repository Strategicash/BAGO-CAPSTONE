<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// ── Workouts this month ──────────────────────────────────
$r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=? AND MONTH(logged_at)=MONTH(NOW()) AND YEAR(logged_at)=YEAR(NOW())");
$r->bind_param("i", $user_id); $r->execute();
$workouts_month = $r->get_result()->fetch_assoc()['c'];
$r->close();

// ── Workouts last month ──────────────────────────────────
$r = $conn->prepare("SELECT COUNT(*) as c FROM workout_logs WHERE user_id=? AND MONTH(logged_at)=MONTH(NOW()-INTERVAL 1 MONTH) AND YEAR(logged_at)=YEAR(NOW()-INTERVAL 1 MONTH)");
$r->bind_param("i", $user_id); $r->execute();
$workouts_last = $r->get_result()->fetch_assoc()['c'];
$r->close();
$workout_diff = $workouts_month - $workouts_last;

// ── Latest weight ────────────────────────────────────────
$r = $conn->prepare("SELECT weight_kg FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1");
$r->bind_param("i", $user_id); $r->execute();
$wrow = $r->get_result()->fetch_assoc();
$r->close();
$current_weight = $wrow ? $wrow['weight_kg'] : null;

// ── Previous weight ──────────────────────────────────────
$r = $conn->prepare("SELECT weight_kg FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1 OFFSET 1");
$r->bind_param("i", $user_id); $r->execute();
$prev_wrow = $r->get_result()->fetch_assoc();
$r->close();
$weight_diff = ($current_weight && $prev_wrow) ? round($current_weight - $prev_wrow['weight_kg'], 1) : null;

// ── Latest body metrics ──────────────────────────────────
$r = $conn->prepare("SELECT weight_kg, height_cm, waist_navel_cm, hips_cm, bmi, recorded_at FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1");
$r->bind_param("i", $user_id); $r->execute();
$metrics = $r->get_result()->fetch_assoc();
$r->close();

// ── Recent workouts (last 5) ─────────────────────────────
$r = $conn->prepare("SELECT exercise, sets, reps, weight_kg, muscle_group, logged_at FROM workout_logs WHERE user_id=? ORDER BY logged_at DESC LIMIT 5");
$r->bind_param("i", $user_id); $r->execute();
$recent_workouts = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// ── Assigned coach (premium only) ───────────────────────
$coach = null;
if ($tier === 'premium') {
    $r = $conn->prepare("SELECT u.full_name FROM users u JOIN users m ON m.coach_id=u.id WHERE m.id=?");
    $r->bind_param("i", $user_id); $r->execute();
    $coach = $r->get_result()->fetch_assoc();
    $r->close();
}

// ── Active plan ──────────────────────────────────────────
$plan = null;
if ($tier === 'premium') {
    $r = $conn->prepare("SELECT plan_name FROM workout_plans WHERE member_id=? ORDER BY created_at DESC LIMIT 1");
    $r->bind_param("i", $user_id); $r->execute();
    $plan = $r->get_result()->fetch_assoc();
    $r->close();
}


// ── Premium approval notification ────────────────────────
$show_premium_notif = false;
if ($tier === 'premium') {
    $r = $conn->prepare("SELECT id FROM payments WHERE user_id=? AND status='paid' AND notified=0 LIMIT 1");
    $r->bind_param("i", $user_id);
    $r->execute();
    $notif_row = $r->get_result()->fetch_assoc();
    $r->close();
    if ($notif_row) {
        $show_premium_notif = true;
        $s = $conn->prepare("UPDATE payments SET notified=1 WHERE user_id=? AND status='paid' AND notified=0");
        $s->bind_param("i", $user_id);
        $s->execute();
        $s->close();
        $_SESSION['tier'] = 'premium';
    }
}

$conn->close();

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
  <title>FitX Training Club — Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #f59e0b; --accent-dim: rgba(245, 158, 11, 0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --red: #ef4444; --yellow: #f59e0b;
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
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .btn-sm { background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity 0.15s; text-decoration: none; display: inline-block; }
    .btn-sm:hover { opacity: 0.85; }
    .content { padding: 24px 28px; }
    .stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
    .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 16px 18px; }
    .stat-card .lbl { font-size: 11px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    .stat-card .val { font-size: 22px; font-weight: 700; letter-spacing: -0.3px; margin-bottom: 4px; }
    .stat-card .note { font-size: 11px; color: var(--sub); }
    .stat-card .note.up { color: var(--green); }
    .stat-card .note.dn { color: var(--red); }
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    .g32 { display: grid; grid-template-columns: 3fr 2fr; gap: 14px; margin-bottom: 14px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .ca { font-size: 12px; color: var(--accent); text-decoration: none; font-weight: 600; }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 10px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .tag { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 4px; background: var(--accent-dim); color: var(--accent); }
    .tag.legs { background: rgba(245,158,11,0.1); color: var(--yellow); }
    .tag.back { background: var(--green-dim); color: var(--green); }
    .tag.chest { background: rgba(239,68,68,0.1); color: var(--red); }
    .tag.shoulders { background: rgba(139,92,246,0.1); color: #8b5cf6; }
    .tag.arms { background: rgba(236,72,153,0.1); color: #ec4899; }

    /* LOG WORKOUT CTA — replaces the form */
    .log-cta { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 28px 20px; text-align: center; }
    .log-cta-icon { font-size: 32px; }
    .log-cta-text { font-size: 13px; color: var(--muted); line-height: 1.6; }
    .btn-log-big { background: var(--accent); color: #fff; border: none; border-radius: 8px; padding: 11px 28px; font-family: var(--font); font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; transition: opacity 0.15s; }
    .btn-log-big:hover { opacity: 0.85; }

    /* COACH */
    .coach-row { display: flex; align-items: center; gap: 12px; background: var(--surface); border-radius: 7px; padding: 12px; border: 1px solid var(--border); }
    .coach-av { width: 36px; height: 36px; border-radius: 50%; background: var(--green-dim); color: var(--green); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0; }
    .coach-name { font-size: 13px; font-weight: 600; margin-bottom: 1px; }
    .coach-spec { font-size: 11px; color: var(--sub); }
    .online { margin-left: auto; font-size: 11px; color: var(--green); display: flex; align-items: center; gap: 5px; }
    .online::before { content:''; width:6px; height:6px; background:var(--green); border-radius:50%; }

    /* METRICS */
    .metric-list { display: flex; flex-direction: column; gap: 13px; }
    .metric-row { display: flex; flex-direction: column; gap: 5px; }
    .metric-top { display: flex; justify-content: space-between; font-size: 12px; }
    .metric-top span:last-child { color: var(--sub); }
    .bar { height: 4px; background: var(--border); border-radius: 2px; }
    .bar-fill { height: 100%; border-radius: 2px; background: var(--accent); }

    @media (max-width: 1100px) { .stats-row { grid-template-columns: 1fr 1fr; } .g32 { grid-template-columns: 1fr; } }
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
    <a href="dashboard.php"    class="nav-item active">📊 Dashboard</a>
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
    <a href="profile.php" class="nav-item">⚙️ Profile</a>
    <a href="billing.php" class="nav-item">💳 Billing</a>
  </nav>
  <div class="sidebar-foot">
    <a href="logout.php" class="nav-item">🚪 Log Out</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">Dashboard</div>
    <a href="billing.php" class="btn-sm">Manage Premium</a>
  </div>

  <div class="content">

    <?php if ($show_premium_notif): ?>
    <div style="background:linear-gradient(135deg,rgba(34,197,94,0.12),rgba(59,130,246,0.08));border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
      <div style="font-size:28px;flex-shrink:0;">🎉</div>
      <div style="flex:1;">
        <div style="font-size:14px;font-weight:700;color:#22c55e;margin-bottom:3px;">Your Premium membership has been activated!</div>
        <div style="font-size:12px;color:var(--muted);">Welcome to Premium. A coach will be assigned to you shortly. Check <a href="my_coach.php" style="color:var(--accent);font-weight:600;text-decoration:none;">My Coach</a> and <a href="my_plan.php" style="color:var(--accent);font-weight:600;text-decoration:none;">My Plan</a> for your personalized program.</div>
      </div>
      <button onclick="this.parentElement.style.display='none'" style="background:none;border:none;color:var(--sub);font-size:18px;cursor:pointer;flex-shrink:0;padding:0 4px;">&times;</button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['logged'])): ?>
    <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#22c55e;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:16px;">
      ✓ Workout logged successfully!
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
    <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:16px;">
      <?= htmlspecialchars($_GET['error']) ?>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS — clickable -->
    <div class="stats-row">
      <a href="workouts.php" class="stat-card" style="text-decoration:none;display:block;transition:border-color 0.15s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
        <div class="lbl">Workouts / Month</div>
        <div class="val"><?= $workouts_month ?></div>
        <div class="note <?= $workout_diff >= 0 ? 'up' : 'dn' ?>">
          <?= $workout_diff >= 0 ? '↑' : '↓' ?> <?= abs($workout_diff) ?> from last month
        </div>
        <div style="font-size:10px;color:var(--sub);margin-top:4px;">View history →</div>
      </a>
      <a href="body_metrics.php" class="stat-card" style="text-decoration:none;display:block;transition:border-color 0.15s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
        <div class="lbl">Current Weight</div>
        <div class="val">
          <?= $current_weight ? $current_weight.'<span style="font-size:14px;font-weight:500;color:var(--sub)"> kg</span>' : '—' ?>
        </div>
        <div class="note <?= ($weight_diff !== null && $weight_diff < 0) ? 'up' : 'dn' ?>">
          <?php if ($weight_diff !== null): ?>
            <?= $weight_diff > 0 ? '↑' : '↓' ?> <?= abs($weight_diff) ?> kg
          <?php else: ?>
            No data yet
          <?php endif; ?>
        </div>
        <div style="font-size:10px;color:var(--sub);margin-top:4px;">Update metrics →</div>
      </a>
      <a href="billing.php" class="stat-card" style="text-decoration:none;display:block;transition:border-color 0.15s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
        <div class="lbl">Tier</div>
        <div class="val" style="font-size:16px;color:var(--<?= $tier==='premium' ? 'accent' : 'sub' ?>)"><?= ucfirst($tier) ?></div>
        <div class="note"><?= $tier==='premium' ? 'Coaching active' : 'Basic access' ?></div>
        <div style="font-size:10px;color:var(--sub);margin-top:4px;">Manage billing →</div>
      </a>
      <a href="<?= $tier==='premium' ? 'my_plan.php' : 'billing.php' ?>" class="stat-card" style="text-decoration:none;display:block;transition:border-color 0.15s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
        <div class="lbl">Coach Plan</div>
        <div class="val" style="font-size:16px;color:var(--<?= $plan ? 'green' : 'sub' ?>)"><?= $plan ? 'Active' : 'None' ?></div>
        <div class="note"><?= $plan ? htmlspecialchars($plan['plan_name']) : ($tier==='basic' ? 'Upgrade for coaching' : 'Not assigned yet') ?></div>
        <div style="font-size:10px;color:var(--sub);margin-top:4px;"><?= $plan ? 'View plan →' : 'Learn more →' ?></div>
      </a>
    </div>

    <div class="g32">
      <!-- RECENT WORKOUTS -->
      <div class="card">
        <div class="ch">
          <div class="ct">Recent Workouts</div>
          <a href="workouts.php" class="ca">View all & Log →</a>
        </div>
        <table>
          <thead><tr><th>Exercise</th><th>Sets × Reps</th><th>Weight</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($recent_workouts)): ?>
              <tr><td colspan="4" style="color:var(--sub);text-align:center;padding:20px 0;">
                No workouts logged yet. <a href="workouts.php" style="color:var(--accent);">Log your first workout →</a>
              </td></tr>
            <?php else: foreach ($recent_workouts as $w): ?>
              <tr>
                <td>
                  <?= htmlspecialchars($w['exercise']) ?>
                  <?php if ($w['muscle_group']): ?>
                    <span class="tag <?= mtag($w['muscle_group']) ?>"><?= htmlspecialchars($w['muscle_group']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= $w['sets'] ?> × <?= $w['reps'] ?></td>
                <td><?= $w['weight_kg'] > 0 ? $w['weight_kg'].' kg' : '—' ?></td>
                <td><?= date('M j', strtotime($w['logged_at'])) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- LOG CTA + COACH -->
      <div style="display:flex;flex-direction:column;gap:14px;">

        <!-- LOG WORKOUT CTA — links to workouts.php -->
        <div class="card">
          <div class="ch"><div class="ct">Log Workout</div></div>
          <div class="log-cta">
            <div class="log-cta-icon">🏋️</div>
            <div class="log-cta-text">Track your sets, reps, and weights to monitor your progress over time.</div>
            <a href="workouts.php#log" class="btn-log-big">+ Log Today's Workout</a>
          </div>
        </div>

        <!-- MY COACH -->
        <div class="card">
          <div class="ch">
            <div class="ct">My Coach</div>
            <?php if ($tier === 'premium' && $coach): ?>
              <a href="messages.php" class="ca">Message →</a>
            <?php endif; ?>
          </div>
          <?php if ($tier === 'premium' && $coach): ?>
          <div class="coach-row">
            <div class="coach-av"><?= strtoupper(substr($coach['full_name'],0,1)) ?></div>
            <div>
              <div class="coach-name"><?= htmlspecialchars($coach['full_name']) ?></div>
              <div class="coach-spec">Assigned Coach</div>
            </div>
            <div class="online">Active</div>
          </div>
          <?php elseif ($tier === 'premium'): ?>
            <p style="font-size:12px;color:var(--sub);">Coach will be assigned to you soon.</p>
          <?php else: ?>
            <p style="font-size:12px;color:var(--sub);">Upgrade to <a href="billing.php" style="color:var(--accent);font-weight:600;">Premium</a> to get an assigned coach.</p>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- BODY METRICS SNAPSHOT -->
    <?php
    // BMI category helper
    function bmi_category($bmi) {
        if (!$bmi) return ['—', 'var(--sub)'];
        if ($bmi < 18.5) return ['Underweight', '#f59e0b'];
        if ($bmi < 25.0) return ['Normal',      '#22c55e'];
        if ($bmi < 30.0) return ['Overweight',  '#f59e0b'];
        return              ['Obese',         '#ef4444'];
    }
    [$bmi_label, $bmi_color] = bmi_category($metrics['bmi'] ?? null);
    ?>
    <div class="card">
      <div class="ch">
        <div class="ct">Body Metrics Snapshot</div>
        <a href="body_metrics.php" class="ca">
          <?= $metrics ? 'Update →' : 'Add Metrics →' ?>
        </a>
      </div>
      <?php if ($metrics): ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">

        <!-- BMI -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:6px;">BMI</div>
          <div style="font-size:20px;font-weight:700;color:var(--text);margin-bottom:3px;">
            <?= $metrics['bmi'] ?: '—' ?>
          </div>
          <div style="font-size:11px;font-weight:700;color:<?= $bmi_color ?>"><?= $bmi_label ?></div>
        </div>

        <!-- HEIGHT & WEIGHT -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:8px;">Height &amp; Weight</div>
          <div style="font-size:18px;font-weight:700;color:var(--text);margin-bottom:6px;line-height:1.3;">
            <?= $metrics['height_cm'] ? $metrics['height_cm'].' <span style="font-size:12px;color:var(--sub)">cm</span>' : '—' ?>
          </div>
          <div style="font-size:18px;font-weight:700;color:var(--text);margin-bottom:3px;line-height:1.3;">
            <?= $metrics['weight_kg'] ? $metrics['weight_kg'].' <span style="font-size:12px;color:var(--sub)">kg</span>' : '—' ?>
          </div>
          <div style="font-size:11px;color:var(--sub);">Latest entry</div>
        </div>

        <!-- LAST UPDATED -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:6px;">Last Updated</div>
          <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:3px;">
            <?= date('M j', strtotime($metrics['recorded_at'])) ?>
          </div>
          <div style="font-size:11px;color:var(--sub);"><?= date('Y', strtotime($metrics['recorded_at'])) ?> · <a href="body_metrics.php" style="color:var(--accent);font-weight:600;text-decoration:none;">Full details →</a></div>
        </div>

      </div>
      <?php else: ?>
      <div style="display:flex;align-items:center;gap:16px;background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:16px 20px;">
        <div style="font-size:28px;">⚖️</div>
        <div>
          <div style="font-size:13px;font-weight:600;margin-bottom:3px;">No body metrics recorded yet</div>
          <div style="font-size:12px;color:var(--sub);">Track your weight, BMI, and measurements to monitor your body composition over time.</div>
        </div>
        <a href="body_metrics.php" style="margin-left:auto;background:var(--accent);color:#fff;text-decoration:none;border-radius:6px;padding:8px 16px;font-size:12px;font-weight:700;white-space:nowrap;">Add Metrics</a>
      </div>
      <?php endif; ?>
    </div>

  </div>
</main>
</body>
</html>