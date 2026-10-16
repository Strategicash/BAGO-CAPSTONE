<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// Get filter
$filter = trim($_GET['filter'] ?? 'All');

// Fetch workout history
if ($filter === 'All') {
    $r = $conn->prepare("SELECT exercise, muscle_group, sets, reps, weight_kg, logged_at FROM workout_logs WHERE user_id=? ORDER BY logged_at DESC LIMIT 50");
    $r->bind_param("i", $user_id);
} else {
    $r = $conn->prepare("SELECT exercise, muscle_group, sets, reps, weight_kg, logged_at FROM workout_logs WHERE user_id=? AND muscle_group=? ORDER BY logged_at DESC LIMIT 50");
    $r->bind_param("is", $user_id, $filter);
}
$r->execute();
$workout_history = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$conn->close();

function wtag($m) {
    $map = ['legs'=>'legs','back'=>'back','chest'=>'chest','shoulders'=>'shoulders','arms'=>'arms'];
    return $map[strtolower($m ?? '')] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Workouts</title>
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
    .content { padding: 24px 28px; }

    .layout { display: grid; grid-template-columns: 1fr 320px; gap: 16px; align-items: start; }

    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }

    /* FILTERS */
    .filter-row { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .filter-btn { padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s; text-decoration: none; display: inline-block; }
    .filter-btn:hover, .filter-btn.active { background: var(--accent-dim); border-color: var(--accent); color: var(--accent); }

    /* TABLE */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 11px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .tag { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 4px; background: var(--accent-dim); color: var(--accent); }
    .tag.legs     { background: rgba(245,158,11,0.1); color: var(--yellow); }
    .tag.back     { background: var(--green-dim); color: var(--green); }
    .tag.chest    { background: rgba(239,68,68,0.1); color: var(--red); }
    .tag.shoulders{ background: rgba(139,92,246,0.1); color: #8b5cf6; }
    .tag.arms     { background: rgba(236,72,153,0.1); color: #ec4899; }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
    .pill.done { background: var(--green-dim); color: var(--green); }

    /* LOG FORM */
    .log-form { display: flex; flex-direction: column; gap: 10px; }
    .r2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 4px; }
    .field input, .field select { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; transition: border-color 0.15s; }
    .field input:focus, .field select:focus { border-color: var(--accent); }
    .field select option { background: var(--surface); }
    .btn-log { background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 10px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; transition: opacity 0.15s; width: 100%; }
    .btn-log:hover { opacity: 0.85; }

    .alert-green { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#22c55e;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:14px; }
    .alert-red   { background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#ef4444;font-size:12px;padding:9px 14px;border-radius:6px;margin-bottom:14px; }

    @media (max-width: 1000px) { .layout { grid-template-columns: 1fr; } }
    @media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } }
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
    <a href="workouts.php"     class="nav-item active">🏋️ Workouts</a>
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
    <div class="page-title">Workouts</div>
    <span style="font-size:12px;color:var(--sub)">Log & track your sessions</span>
  </div>

  <div class="content">

    <?php if (isset($_GET['logged'])): ?>
      <div class="alert-green">✓ Workout logged successfully!</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
      <div class="alert-red"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <div class="layout">

      <div>
        <div class="filter-row">
          <?php foreach (['All','Chest','Back','Legs','Shoulders','Arms','Core'] as $f): ?>
            <a href="workouts.php?filter=<?= urlencode($f) ?>" class="filter-btn <?= $filter===$f ? 'active' : '' ?>"><?= $f ?></a>
          <?php endforeach; ?>
        </div>

        <div class="card">
          <div class="ch">
            <div class="ct">Workout History</div>
            <span style="font-size:11px;color:var(--sub)">Last 50 sessions</span>
          </div>
          <table>
            <thead>
              <tr><th>Exercise</th><th>Muscle Group</th><th>Sets × Reps</th><th>Weight</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php if (empty($workout_history)): ?>
                <tr><td colspan="6" style="color:var(--sub);text-align:center;padding:24px 0;">
                  No workouts logged yet. Use the form on the right to log your first session.
                </td></tr>
              <?php else: foreach ($workout_history as $w): ?>
                <tr>
                  <td><?= htmlspecialchars($w['exercise']) ?></td>
                  <td>
                    <?php if ($w['muscle_group']): ?>
                      <span class="tag <?= wtag($w['muscle_group']) ?>"><?= htmlspecialchars($w['muscle_group']) ?></span>
                    <?php else: ?>
                      <span style="color:var(--sub)">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= $w['sets'] ?> × <?= $w['reps'] ?></td>
                  <td><?= $w['weight_kg'] > 0 ? $w['weight_kg'].' kg' : 'BW' ?></td>
                  <td><?= date('M j', strtotime($w['logged_at'])) ?></td>
                  <td><span class="pill done">Done</span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="log">
        <div class="card" style="position:sticky;top:66px;">
          <div class="ch"><div class="ct">Log Workout</div></div>
          <form class="log-form" action="log_workout.php" method="POST">

            <div class="field">
              <label>Exercise & Muscle Group</label>
              <select name="exercise" required>
                <option value="" disabled selected>Select exercise</option>
                <optgroup label="Chest">
                  <option value="Bench Press|Chest">Bench Press</option>
                  <option value="Incline DB Press|Chest">Incline DB Press</option>
                  <option value="Decline Bench Press|Chest">Decline Bench Press</option>
                  <option value="Cable Chest Flyes|Chest">Cable Chest Flyes</option>
                  <option value="Chest Flyes|Chest">Chest Flyes</option>
                  <option value="Push-ups|Chest">Push-ups</option>
                </optgroup>
                <optgroup label="Back">
                  <option value="Deadlift|Back">Deadlift</option>
                  <option value="Pull-ups|Back">Pull-ups</option>
                  <option value="Bent Over Row|Back">Bent Over Row</option>
                  <option value="Lat Pulldown|Back">Lat Pulldown</option>
                  <option value="Cable Pull Down|Back">Cable Pull Down</option>
                  <option value="Seated Cable Row|Back">Seated Cable Row</option>
                  <option value="T-bar Cable Pull|Back">T-bar Cable Pull</option>
                  <option value="Pendlay Row|Back">Pendlay Row</option>
                </optgroup>
                <optgroup label="Legs">
                  <option value="Squat|Legs">Squat</option>
                  <option value="Back Squat|Legs">Back Squat</option>
                  <option value="Front Squat|Legs">Front Squat</option>
                  <option value="Leg Press|Legs">Leg Press</option>
                  <option value="Romanian Deadlift|Legs">Romanian Deadlift</option>
                  <option value="Lunges|Legs">Lunges</option>
                  <option value="Leg Curl|Legs">Leg Curl</option>
                  <option value="Calf Raise|Legs">Calf Raise</option>
                  <option value="Hip Thrust|Legs">Hip Thrust</option>
                  <option value="Leg Extension|Legs">Leg Extension</option>
                  <option value="Step-ups|Legs">Step-ups</option>
                  <option value="Glute Bridge|Legs">Glute Bridge</option>
                </optgroup>
                <optgroup label="Shoulders">
                  <option value="Overhead Press|Shoulders">Overhead Press</option>
                  <option value="OHP|Shoulders">OHP</option>
                  <option value="DB Shoulder Press|Shoulders">DB Shoulder Press</option>
                  <option value="Lateral Raise|Shoulders">Lateral Raise</option>
                  <option value="Front Raise|Shoulders">Front Raise</option>
                  <option value="Face Pull|Shoulders">Face Pull</option>
                  <option value="Front Delts|Shoulders">Front Delts</option>
                  <option value="Arnold Press|Shoulders">Arnold Press</option>
                </optgroup>
                <optgroup label="Arms">
                  <option value="Barbell Curl|Arms">Barbell Curl</option>
                  <option value="Hammer Curl|Arms">Hammer Curl</option>
                  <option value="Seated Curl|Arms">Seated Curl</option>
                  <option value="Overhead DB Crusher|Arms">Overhead DB Crusher</option>
                  <option value="Tricep Pushdown|Arms">Tricep Pushdown</option>
                  <option value="Cable Overhead Pulldown|Arms">Cable Overhead Pulldown</option>
                  <option value="Skull Crushers|Arms">Skull Crushers</option>
                  <option value="Dips|Arms">Dips</option>
                  <option value="Outward Curls|Arms">Outward Curls</option>
                </optgroup>
                <optgroup label="Core">
                  <option value="Plank|Core">Plank</option>
                  <option value="Crunches|Core">Crunches</option>
                  <option value="Leg Raises|Core">Leg Raises</option>
                  <option value="Russian Twists|Core">Russian Twists</option>
                  <option value="Cable Crunch|Core">Cable Crunch</option>
                </optgroup>
                <optgroup label="Other">
                  <option value="other">Other — Type it below</option>
                </optgroup>
              </select>
            </div>

            <div class="field" id="other-field" style="display:none;">
              <label>Exercise Name</label>
              <input type="text" name="exercise_other" id="exercise_other" placeholder="e.g. Smith Machine Squat..."/>
            </div>
            <div class="field" id="other-muscle-field" style="display:none;">
              <label>Muscle Group</label>
              <select name="muscle_group_other" id="muscle_group_other">
                <option value="" disabled selected>Select muscle group</option>
                <option value="Chest">Chest</option>
                <option value="Back">Back</option>
                <option value="Legs">Legs</option>
                <option value="Shoulders">Shoulders</option>
                <option value="Arms">Arms</option>
                <option value="Core">Core</option>
              </select>
            </div>

            <div class="r2">
              <div class="field"><label>Sets</label><input type="number" name="sets" placeholder="3" min="1" required/></div>
              <div class="field"><label>Reps</label><input type="number" name="reps" placeholder="10" min="1" required/></div>
            </div>
            <div class="field">
              <label>Weight (kg) — leave 0 for bodyweight</label>
              <input type="number" name="weight" placeholder="60" step="0.5" min="0" value="0"/>
            </div>

            <button type="submit" class="btn-log">+ Log Workout</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</main>

<script>
  document.querySelector('select[name="exercise"]').addEventListener('change', function() {
    const isOther = this.value === 'other';
    document.getElementById('other-field').style.display       = isOther ? 'block' : 'none';
    document.getElementById('other-muscle-field').style.display = isOther ? 'block' : 'none';
    document.getElementById('exercise_other').required         = isOther;
    document.getElementById('muscle_group_other').required     = isOther;
  });

  // Auto-scroll to log form if #log in URL
  if (window.location.hash === '#log') {
    document.getElementById('log')?.scrollIntoView({ behavior: 'smooth' });
  }
</script>
</body>
</html>