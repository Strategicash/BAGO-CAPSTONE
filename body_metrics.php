<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = $conn->prepare("SELECT * FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1");
    $r->bind_param("i", $user_id);
    $r->execute();
    $last_entry = $r->get_result()->fetch_assoc();
    $r->close();

    // Blank field = carry forward last snapshot (avoid storing 0 and wiping measurements).
    $merge = function (string $key) use ($last_entry) {
        $raw = $_POST[$key] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            if ($last_entry && isset($last_entry[$key]) && $last_entry[$key] !== null && $last_entry[$key] !== '') {
                return (float) $last_entry[$key];
            }
            return 0.0;
        }
        return floatval($raw);
    };

    $weight_kg      = floatval($_POST['weight_kg'] ?? 0);
    $height_cm      = $merge('height_cm');
    $chest_cm       = $merge('chest_cm');
    $right_arm_cm   = $merge('right_arm_cm');
    $left_arm_cm    = $merge('left_arm_cm');
    $waist_navel_cm = $merge('waist_navel_cm');
    $waist_below_cm = $merge('waist_below_cm');
    $hips_cm        = $merge('hips_cm');
    $right_leg_cm   = $merge('right_leg_cm');
    $left_leg_cm    = $merge('left_leg_cm');

    $bmi = null;
    if ($weight_kg > 0 && $height_cm > 0) {
        $h = $height_cm / 100;
        $bmi = round($weight_kg / ($h * $h), 1);
    }

    $s = $conn->prepare("INSERT INTO body_metrics (user_id, weight_kg, height_cm, chest_cm, right_arm_cm, left_arm_cm, waist_navel_cm, waist_below_cm, hips_cm, right_leg_cm, left_leg_cm, bmi) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->bind_param("iddddddddddd", $user_id, $weight_kg, $height_cm, $chest_cm, $right_arm_cm, $left_arm_cm, $waist_navel_cm, $waist_below_cm, $hips_cm, $right_leg_cm, $left_leg_cm, $bmi);
    $s->execute();
    $s->close();
    header('Location: body_metrics.php?saved=1');
    exit;
}

// Latest entry
$r = $conn->prepare("SELECT * FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1");
$r->bind_param("i", $user_id); $r->execute();
$latest = $r->get_result()->fetch_assoc();
$r->close();

// Previous entry
$r = $conn->prepare("SELECT * FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 1 OFFSET 1");
$r->bind_param("i", $user_id); $r->execute();
$prev = $r->get_result()->fetch_assoc();
$r->close();

// History
$r = $conn->prepare("SELECT * FROM body_metrics WHERE user_id=? ORDER BY recorded_at DESC LIMIT 10");
$r->bind_param("i", $user_id); $r->execute();
$history = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$conn->close();

function bmi_label($bmi) {
    if (!$bmi) return '—';
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 25)   return 'Normal';
    if ($bmi < 30)   return 'Overweight';
    return 'Obese';
}
function bmi_color($bmi) {
    if (!$bmi) return 'var(--sub)';
    if ($bmi < 18.5) return '#3b82f6';
    if ($bmi < 25)   return '#22c55e';
    if ($bmi < 30)   return '#f59e0b';
    return '#ef4444';
}
function chg($new, $old, $unit='cm') {
    if (!$new || !$old) return '<span style="color:var(--sub);font-size:11px;">—</span>';
    $d = round($new - $old, 1);
    if ($d > 0) return '<span style="color:#ef4444;font-size:11px;">↑ '.$d.' '.$unit.'</span>';
    if ($d < 0) return '<span style="color:#22c55e;font-size:11px;">↓ '.abs($d).' '.$unit.'</span>';
    return '<span style="color:var(--sub);font-size:11px;">No change</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Body Metrics</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {  --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #555555; --muted: #888888;
      --accent: #f59e0b; --accent-dim: rgba(245, 158, 11, 0.1);
      --green: #22c55e; --green-dim: rgba(34,197,94,0.1);
      --red: #ef4444; --yellow: #f59e0b;
      --font: 'Plus Jakarta Sans', sans-serif;}
      
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
    .content { padding: 24px 28px; }
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    .g3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 20px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .stat-top { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; }
    .metric-big { text-align: center; padding: 18px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
    .metric-big .val { font-size: 26px; font-weight: 700; letter-spacing: -1px; margin-bottom: 4px; }
    .metric-big .lbl { font-size: 11px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    .metric-big .change { font-size: 11px; min-height: 16px; }
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 4px; }
    .field input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; }
    .field input:focus { border-color: var(--accent); }
    .field-hint { font-size: 10px; color: var(--sub); margin-top: 3px; }
    .btn-save { width: 100%; background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 10px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; margin-top: 8px; }
    .btn-save:hover { opacity: 0.85; }
    .guide-box { background: var(--surface); border: 1px solid var(--border); border-radius: 7px; padding: 14px; margin-bottom: 16px; }
    .guide-row { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; border-bottom: 1px solid var(--border); }
    .guide-row:last-child { border-bottom: none; }
    .guide-num { width: 20px; height: 20px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
    .guide-title { font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
    .guide-desc { font-size: 11px; color: var(--sub); line-height: 1.5; }
    .measure-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--surface); }
    .measure-row:last-child { border-bottom: none; }
    .measure-name { font-size: 13px; font-weight: 600; }
    .measure-val { font-size: 13px; color: var(--muted); }
    .measure-change { font-size: 11px; min-width: 80px; text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 10px 0; font-size: 12px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .success-msg { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); font-size: 12px; padding: 9px 14px; border-radius: 6px; margin-bottom: 16px; }
    @media (max-width: 1100px) { .stat-top { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } .g2,.g3 { grid-template-columns: 1fr; } .stat-top { grid-template-columns: 1fr 1fr; } }
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
    <a href="dashboard.php" class="nav-item">📊 Dashboard</a>
    <a href="workouts.php" class="nav-item">🏋️ Workouts</a>
    <a href="progress.php" class="nav-item">📈 Progress</a>
    <a href="body_metrics.php" class="nav-item active">⚖️ Body Metrics</a>
    <?php if ($tier === 'premium'): ?>
    <div class="nav-lbl">Premium</div>
    <a href="my_coach.php" class="nav-item">👤 My Coach</a>
    <a href="my_plan.php" class="nav-item">📋 My Plan</a>
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
    <div class="page-title">Body Metrics</div>
    <span style="font-size:12px;color:var(--sub)">Track your measurements over time</span>
  </div>
  <div class="content">

    <?php if(isset($_GET['saved'])): ?>
      <div class="success-msg">✓ Measurements saved successfully!</div>
    <?php endif; ?>

    <!-- TOP STATS -->
    <div class="stat-top">
      <div class="metric-big">
        <div class="lbl">Weight</div>
        <div class="val"><?= $latest ? $latest['weight_kg'] : '—' ?> <span style="font-size:14px;color:var(--sub)">kg</span></div>
        <div class="change"><?= chg($latest['weight_kg'] ?? null, $prev['weight_kg'] ?? null, 'kg') ?></div>
      </div>
      <div class="metric-big">
        <div class="lbl">Height</div>
        <div class="val"><?= $latest ? $latest['height_cm'] : '—' ?> <span style="font-size:14px;color:var(--sub)">cm</span></div>
        <div class="change" style="color:var(--sub);font-size:11px;">Measured once</div>
      </div>
      <div class="metric-big">
        <div class="lbl">BMI</div>
        <div class="val" style="color:<?= bmi_color($latest['bmi'] ?? null) ?>"><?= $latest ? ($latest['bmi'] ?? '—') : '—' ?></div>
        <div class="change">
          <?php if($latest && $latest['bmi']): ?>
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:<?= bmi_color($latest['bmi']) ?>22;color:<?= bmi_color($latest['bmi']) ?>"><?= bmi_label($latest['bmi']) ?></span>
          <?php else: ?>
            <span style="font-size:11px;color:var(--sub);">Log weight + height</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="metric-big">
        <div class="lbl">Waist</div>
        <div class="val"><?= $latest ? ($latest['waist_navel_cm'] ?? '—') : '—' ?> <span style="font-size:14px;color:var(--sub)">cm</span></div>
        <div class="change"><?= chg($latest['waist_navel_cm'] ?? null, $prev['waist_navel_cm'] ?? null) ?></div>
      </div>
    </div>

    <div class="g2">

      <!-- CURRENT MEASUREMENTS -->
      <div class="card">
        <div class="ch">
          <div class="ct">Current Measurements</div>
          <?php if($latest): ?>
            <span style="font-size:11px;color:var(--sub)"><?= date('M j, Y', strtotime($latest['recorded_at'])) ?></span>
          <?php endif; ?>
        </div>
        <?php if($latest): ?>
          <div class="measure-row"><div class="measure-name">Chest</div><div class="measure-val"><?= $latest['chest_cm'] ? $latest['chest_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['chest_cm'] ?? null, $prev['chest_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Right Arm</div><div class="measure-val"><?= $latest['right_arm_cm'] ? $latest['right_arm_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['right_arm_cm'] ?? null, $prev['right_arm_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Left Arm</div><div class="measure-val"><?= $latest['left_arm_cm'] ? $latest['left_arm_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['left_arm_cm'] ?? null, $prev['left_arm_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Waist at Navel</div><div class="measure-val"><?= $latest['waist_navel_cm'] ? $latest['waist_navel_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['waist_navel_cm'] ?? null, $prev['waist_navel_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Waist 2" Below</div><div class="measure-val"><?= $latest['waist_below_cm'] ? $latest['waist_below_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['waist_below_cm'] ?? null, $prev['waist_below_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Hips</div><div class="measure-val"><?= $latest['hips_cm'] ? $latest['hips_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['hips_cm'] ?? null, $prev['hips_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Right Leg</div><div class="measure-val"><?= $latest['right_leg_cm'] ? $latest['right_leg_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['right_leg_cm'] ?? null, $prev['right_leg_cm'] ?? null) ?></div></div>
          <div class="measure-row"><div class="measure-name">Left Leg</div><div class="measure-val"><?= $latest['left_leg_cm'] ? $latest['left_leg_cm'].' cm' : '—' ?></div><div class="measure-change"><?= chg($latest['left_leg_cm'] ?? null, $prev['left_leg_cm'] ?? null) ?></div></div>
        <?php else: ?>
          <p style="font-size:13px;color:var(--sub);padding:10px 0;">No measurements yet. Log your first entry!</p>
        <?php endif; ?>
      </div>

      <!-- LOG FORM -->
      <div class="card">
        <div class="ch">
          <div class="ct">Log New Entry</div>
          <span style="font-size:11px;color:var(--sub)">Use a measuring tape</span>
        </div>

        <!-- HOW TO MEASURE GUIDE -->
        <div class="guide-box">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:8px;">📏 How to Measure (optional)</div>
          <div class="guide-row"><div class="guide-num">1</div><div><div class="guide-title">Chest</div><div class="guide-desc">Wrap tape around the fullest part of your chest, arms relaxed</div></div></div>
          <div class="guide-row"><div class="guide-num">2</div><div><div class="guide-title">Arms</div><div class="guide-desc">Flex your arm, measure around the biggest part of your bicep</div></div></div>
          <div class="guide-row"><div class="guide-num">3</div><div><div class="guide-title">Waist at Navel</div><div class="guide-desc">Wrap tape around your belly button, breathe out naturally</div></div></div>
          <div class="guide-row"><div class="guide-num">4</div><div><div class="guide-title">Waist 2" Below</div><div class="guide-desc">Measure 2 inches below your belly button</div></div></div>
          <div class="guide-row"><div class="guide-num">5</div><div><div class="guide-title">Hips</div><div class="guide-desc">Wrap tape around the widest part of your hips and glutes</div></div></div>
          <div class="guide-row"><div class="guide-num">6</div><div><div class="guide-title">Legs</div><div class="guide-desc">Measure around the widest part of your upper thigh</div></div></div>
        </div>

        <form action="body_metrics.php" method="POST">
          <div class="g2" style="margin-bottom:0;">
            <div class="field">
              <label>Weight (kg) *</label>
              <input type="number" name="weight_kg" placeholder="e.g. 70.5" step="0.1" required value="<?= $latest && $latest['weight_kg'] !== null && $latest['weight_kg'] !== '' ? htmlspecialchars((string)$latest['weight_kg']) : '' ?>"/>
              <div class="field-hint">Step on a scale</div>
            </div>
            <div class="field">
              <label>Height (cm)</label>
              <input type="number" name="height_cm" placeholder="e.g. 170" step="0.1" value="<?= $latest && $latest['height_cm'] !== null && $latest['height_cm'] !== '' ? htmlspecialchars((string)$latest['height_cm']) : '' ?>"/>
              <div class="field-hint">For BMI — leave blank to keep last height</div>
            </div>
            <div class="field">
              <label>① Chest (cm)</label>
              <input type="number" name="chest_cm" placeholder="e.g. 95" step="0.1" value="<?= $latest && $latest['chest_cm'] !== null && $latest['chest_cm'] !== '' ? htmlspecialchars((string)$latest['chest_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>② Right Arm (cm)</label>
              <input type="number" name="right_arm_cm" placeholder="e.g. 35" step="0.1" value="<?= $latest && $latest['right_arm_cm'] !== null && $latest['right_arm_cm'] !== '' ? htmlspecialchars((string)$latest['right_arm_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>③ Left Arm (cm)</label>
              <input type="number" name="left_arm_cm" placeholder="e.g. 35" step="0.1" value="<?= $latest && $latest['left_arm_cm'] !== null && $latest['left_arm_cm'] !== '' ? htmlspecialchars((string)$latest['left_arm_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>④ Waist at Navel (cm)</label>
              <input type="number" name="waist_navel_cm" placeholder="e.g. 80" step="0.1" value="<?= $latest && $latest['waist_navel_cm'] !== null && $latest['waist_navel_cm'] !== '' ? htmlspecialchars((string)$latest['waist_navel_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>⑤ Waist 2" Below (cm)</label>
              <input type="number" name="waist_below_cm" placeholder="e.g. 82" step="0.1" value="<?= $latest && $latest['waist_below_cm'] !== null && $latest['waist_below_cm'] !== '' ? htmlspecialchars((string)$latest['waist_below_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>⑥ Hips (cm)</label>
              <input type="number" name="hips_cm" placeholder="e.g. 90" step="0.1" value="<?= $latest && $latest['hips_cm'] !== null && $latest['hips_cm'] !== '' ? htmlspecialchars((string)$latest['hips_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>⑦ Right Leg (cm)</label>
              <input type="number" name="right_leg_cm" placeholder="e.g. 55" step="0.1" value="<?= $latest && $latest['right_leg_cm'] !== null && $latest['right_leg_cm'] !== '' ? htmlspecialchars((string)$latest['right_leg_cm']) : '' ?>"/>
            </div>
            <div class="field">
              <label>⑧ Left Leg (cm)</label>
              <input type="number" name="left_leg_cm" placeholder="e.g. 55" step="0.1" value="<?= $latest && $latest['left_leg_cm'] !== null && $latest['left_leg_cm'] !== '' ? htmlspecialchars((string)$latest['left_leg_cm']) : '' ?>"/>
            </div>
          </div>
          <button type="submit" class="btn-save">Save Measurements</button>
        </form>
      </div>
    </div>

    <!-- HISTORY TABLE -->
    <div class="card">
      <div class="ch"><div class="ct">Measurement History</div></div>
      <?php if(empty($history)): ?>
        <p style="font-size:13px;color:var(--sub);text-align:center;padding:20px 0;">No entries yet. Start tracking your measurements above!</p>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Weight</th>
              <th>BMI</th>
              <th>Chest</th>
              <th>R.Arm</th>
              <th>L.Arm</th>
              <th>Waist</th>
              <th>Hips</th>
              <th>R.Leg</th>
              <th>L.Leg</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($history as $h): ?>
            <tr>
              <td><?= date('M j, Y', strtotime($h['recorded_at'])) ?></td>
              <td><?= $h['weight_kg'] ? $h['weight_kg'].' kg' : '—' ?></td>
              <td style="color:<?= bmi_color($h['bmi'] ?? null) ?>"><?= $h['bmi'] ?? '—' ?></td>
              <td><?= $h['chest_cm'] ? $h['chest_cm'] : '—' ?></td>
              <td><?= $h['right_arm_cm'] ? $h['right_arm_cm'] : '—' ?></td>
              <td><?= $h['left_arm_cm'] ? $h['left_arm_cm'] : '—' ?></td>
              <td><?= $h['waist_navel_cm'] ? $h['waist_navel_cm'] : '—' ?></td>
              <td><?= $h['hips_cm'] ? $h['hips_cm'] : '—' ?></td>
              <td><?= $h['right_leg_cm'] ? $h['right_leg_cm'] : '—' ?></td>
              <td><?= $h['left_leg_cm'] ? $h['left_leg_cm'] : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</main>
</body>
</html>