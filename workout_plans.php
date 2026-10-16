<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];

// ── Handle save plan POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    $member_id = intval($_POST['member_id'] ?? 0);
    $plan_name = trim($_POST['plan_name']   ?? '');
    $phase     = trim($_POST['phase']       ?? '');
    $duration  = intval($_POST['duration']  ?? 4);
    $plan_id   = intval($_POST['plan_id']   ?? 0); // 0 = new plan

    // Build notes from day fields
    // Format: Day 1: [Day Name] | [Exercise1, Exercise2, ...]
    $notes_lines = [];
    for ($d = 1; $d <= 7; $d++) {
        $day_name = trim($_POST["day{$d}_name"] ?? '');
        $day_exs  = trim($_POST["day{$d}_exercises"] ?? '');
        $is_rest  = isset($_POST["day{$d}_rest"]);
        if ($is_rest || strtolower($day_name) === 'rest') {
            $notes_lines[] = "Day $d: Rest";
        } elseif ($day_name) {
            if ($day_exs) {
                $notes_lines[] = "Day $d: $day_name | $day_exs";
            } else {
                $notes_lines[] = "Day $d: $day_name";
            }
        }
    }
    $coach_notes = trim($_POST['coach_notes'] ?? '');
    $structured  = implode("\n", $notes_lines);
    $notes       = $structured . ($coach_notes ? "\n\n" . $coach_notes : '');

    if ($member_id && $plan_name && $duration > 0) {
        // Security: only allow coach's own clients
        $check = $conn->prepare("SELECT id FROM users WHERE id=? AND coach_id=? AND role='member' LIMIT 1");
        $check->bind_param("ii", $member_id, $coach_id);
        $check->execute();
        $ok = $check->get_result()->fetch_assoc();
        $check->close();

        if ($ok) {
            if ($plan_id > 0) {
                // UPDATE existing plan
                $s = $conn->prepare("UPDATE workout_plans SET plan_name=?, phase=?, duration_weeks=?, notes=? WHERE id=? AND coach_id=?");
                $s->bind_param("ssisii", $plan_name, $phase, $duration, $notes, $plan_id, $coach_id);
                $s->execute();
                $s->close();
            } else {
                // INSERT new plan
                $s = $conn->prepare("INSERT INTO workout_plans (coach_id, member_id, plan_name, phase, duration_weeks, notes) VALUES (?,?,?,?,?,?)");
                $s->bind_param("iissis", $coach_id, $member_id, $plan_name, $phase, $duration, $notes);
                $s->execute();
                $s->close();
            }
        }
    }

    header("Location: workout_plans.php?client=$member_id&saved=1");
    exit;
}

// ── Handle delete plan POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_plan'])) {
    $plan_id   = intval($_POST['plan_id']   ?? 0);
    $member_id = intval($_POST['member_id'] ?? 0);
    if ($plan_id) {
        $s = $conn->prepare("DELETE FROM workout_plans WHERE id=? AND coach_id=?");
        $s->bind_param("ii", $plan_id, $coach_id);
        $s->execute();
        $s->close();
    }
    header("Location: workout_plans.php?client=$member_id&deleted=1");
    exit;
}

// ── Fetch clients ─────────────────────────────────────────────────────────────
$r = $conn->prepare("SELECT id, full_name, fitness_level FROM users WHERE coach_id=? AND role='member' ORDER BY full_name");
$r->bind_param("i", $coach_id);
$r->execute();
$clients_list = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

$selected_id     = intval($_GET['client'] ?? ($clients_list[0]['id'] ?? 0));
$selected_client = null;
foreach ($clients_list as $c) {
    if ($c['id'] === $selected_id) { $selected_client = $c; break; }
}

// ── Fetch plans ───────────────────────────────────────────────────────────────
$plan      = null;
$all_plans = [];
if ($selected_id) {
    // Check if a specific plan_id is requested for editing
    $edit_plan_id = intval($_GET['edit'] ?? 0);

    if ($edit_plan_id) {
        $r = $conn->prepare("SELECT * FROM workout_plans WHERE id=? AND coach_id=? LIMIT 1");
        $r->bind_param("ii", $edit_plan_id, $coach_id);
        $r->execute();
        $plan = $r->get_result()->fetch_assoc();
        $r->close();
    }
    if (!$plan) {
        $r = $conn->prepare("SELECT * FROM workout_plans WHERE member_id=? AND coach_id=? ORDER BY created_at DESC LIMIT 1");
        $r->bind_param("ii", $selected_id, $coach_id);
        $r->execute();
        $plan = $r->get_result()->fetch_assoc();
        $r->close();
    }

    $r = $conn->prepare("SELECT id, plan_name, phase, duration_weeks, notes, created_at FROM workout_plans WHERE member_id=? AND coach_id=? ORDER BY created_at DESC");
    $r->bind_param("ii", $selected_id, $coach_id);
    $r->execute();
    $all_plans = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

$conn->close();

// ── Parse existing plan notes into per-day fields ─────────────────────────────
// Format stored: "Day 1: Push - Chest, Shoulders | Bench Press, OHP"
function parse_day_from_notes($notes, $day_num) {
    if (!$notes) return ['name' => '', 'exercises' => '', 'rest' => false];
    $lines = preg_split('/\r?\n/', $notes);
    foreach ($lines as $line) {
        if (preg_match('/^Day\s*' . $day_num . '\s*:\s*(.*)/i', $line, $m)) {
            $content = trim($m[1]);
            if (strtolower($content) === 'rest') {
                return ['name' => 'Rest / Recovery', 'exercises' => '', 'rest' => true];
            }
            if (str_contains($content, '|')) {
                [$name, $exs] = explode('|', $content, 2);
                return ['name' => trim($name), 'exercises' => trim($exs), 'rest' => false];
            }
            return ['name' => $content, 'exercises' => '', 'rest' => false];
        }
    }
    return ['name' => '', 'exercises' => '', 'rest' => false];
}

// Extract coach notes (lines NOT starting with "Day N:")
function extract_coach_notes($notes) {
    if (!$notes) return '';
    $lines = preg_split('/\r?\n/', $notes);
    $out   = [];
    $skip  = true; // skip day lines
    foreach ($lines as $line) {
        if (preg_match('/^Day\s*\d+\s*:/i', $line)) continue;
        $out[] = $line;
    }
    return trim(implode("\n", $out));
}

// ── Plan progress calc ────────────────────────────────────────────────────────
$weeks_done   = 0;
$week_pct     = 0;
$current_week = 1;
if ($plan) {
    $start      = new DateTime($plan['created_at']);
    $now        = new DateTime();
    $diff       = $start->diff($now);
    $weeks_done = min((int)floor($diff->days / 7), (int)$plan['duration_weeks']);
    $week_pct   = $plan['duration_weeks'] > 0 ? round(($weeks_done / $plan['duration_weeks']) * 100) : 0;
    $current_week = min($plan['duration_weeks'], $weeks_done + 1);
}

// Default day templates per phase (used when creating new plan)
$day_defaults = [
    'Hypertrophy' => [
        ['Push — Chest, Shoulders, Triceps', 'Bench Press, Incline DB Press, OHP, Lateral Raise, Tricep Pushdown, Cable Fly'],
        ['Pull — Back, Biceps',              'Deadlift, Pull-ups, Cable Row, Face Pull, Barbell Curl, Hammer Curl'],
        ['Legs — Quads, Hamstrings, Glutes', 'Back Squat, Romanian Deadlift, Leg Press, Leg Curl, Calf Raise'],
        ['Rest / Recovery',                  '', true],
        ['Upper Body Strength',              'Bench Press, Weighted Pull-ups, DB Shoulder Press, Barbell Row, Dips'],
        ['Full Body',                        'Deadlift, Bench Press, Goblet Squat, OHP, Pull-ups'],
        ['Full Rest',                        '', true],
    ],
    'Strength' => [
        ['Squat Day',           'Back Squat, Front Squat, Box Squat, Leg Press, Calf Raise'],
        ['Bench Press Day',     'Bench Press, Close Grip Bench, DB Press, Tricep Pushdown, Face Pull'],
        ['Rest',                '', true],
        ['Deadlift Day',        'Deadlift, Romanian Deadlift, Good Morning, Back Extension, Barbell Row'],
        ['Overhead Press Day',  'OHP, Push Press, Lateral Raise, Face Pull, Barbell Curl'],
        ['Accessory Work',      'Barbell Curl, Tricep Pushdown, Lateral Raise, Calf Raise, Ab Work'],
        ['Full Rest',           '', true],
    ],
    'Cut / Fat Loss' => [
        ['Upper Body + Cardio', 'Bench Press, Barbell Row, OHP, Lateral Raise, Tricep Pushdown'],
        ['LISS Cardio',         '30-45 min treadmill or bike'],
        ['Lower Body + Cardio', 'Squat, Romanian Deadlift, Leg Press, Leg Curl, Calf Raise'],
        ['HIIT Cardio',         '20 min intervals'],
        ['Full Body Circuit',   'Deadlift, Bench Press, Pull-ups, Squat, OHP'],
        ['Active Recovery',     'Walk, light stretch'],
        ['Full Rest',           '', true],
    ],
    'Endurance' => [
        ['Upper Body Endurance', 'Push-ups, Pull-ups, Dips, Shoulder Press, Face Pull'],
        ['Long Cardio',          '45-60 min run or bike'],
        ['Lower Body Endurance', 'Squat, Lunges, Step-ups, Calf Raise, Glute Bridge'],
        ['Tempo Run',            '30 min tempo run'],
        ['Full Body Circuit',    'Kettlebell Swing, Box Jump, Push-up, Pull-up, Plank'],
        ['Easy Cardio',          '30 min easy jog'],
        ['Full Rest',            '', true],
    ],
    'Deload' => [
        ['Upper Body — Light', 'Bench Press (50%), Barbell Row (50%), OHP (50%)'],
        ['Lower Body — Light', 'Squat (50%), Romanian Deadlift (50%), Leg Press (50%)'],
        ['Rest',               '', true],
        ['Full Body — Light',  'Deadlift (50%), Bench Press (50%), Pull-ups'],
        ['Mobility Work',      'Foam rolling, stretching, yoga'],
        ['Rest',               '', true],
        ['Full Rest',          '', true],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Workout Plans</title>
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
    .sidebar-logo { padding: 18px 18px 14px; font-size: 15px; font-weight: 700; border-bottom: 1px solid var(--border); }
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
    .content { padding: 24px 28px 80px; }

    /* ── CLIENT TABS ── */
    .client-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .client-tab { padding: 7px 14px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s; }
    .client-tab:hover { border-color: var(--green); color: var(--green); }
    .client-tab.active { background: var(--green-dim); border-color: var(--green); color: var(--green); }

    /* ── LAYOUT ── */
    .layout { display: grid; grid-template-columns: 1fr 380px; gap: 16px; align-items: start; }

    /* ── FORM CARD ── */
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .cs { font-size: 11px; color: var(--sub); }
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 5px; }
    .field input, .field select, .field textarea { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 11px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; transition: border-color 0.15s; resize: vertical; }
    .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--green); }
    .field select option { background: var(--surface); }
    .r2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

    /* ── DAY SCHEDULE EDITOR ── */
    .schedule-editor { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
    .day-editor {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      transition: border-color 0.15s;
    }
    .day-editor:focus-within { border-color: var(--green); }
    .day-editor.is-rest { opacity: 0.6; }

    .day-header {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 14px;
      background: rgba(255,255,255,0.02);
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      user-select: none;
    }
    .day-num-badge { font-size: 10px; font-weight: 700; color: var(--green); background: var(--green-dim); padding: 2px 8px; border-radius: 4px; flex-shrink: 0; }
    .day-header-name { flex: 1; font-size: 12px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .day-header-name.placeholder { color: var(--sub); font-weight: 400; }
    .rest-toggle { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--sub); cursor: pointer; flex-shrink: 0; }
    .rest-toggle input[type="checkbox"] { accent-color: var(--green); width: 14px; height: 14px; }
    .day-chevron { font-size: 10px; color: var(--sub); transition: transform 0.2s; flex-shrink: 0; }
    .day-editor.open .day-chevron { transform: rotate(180deg); }

    .day-body { display: none; padding: 12px 14px; }
    .day-editor.open .day-body { display: block; }

    .day-body .field { margin-bottom: 8px; }
    .day-body .field:last-child { margin-bottom: 0; }
    .day-body .field label { font-size: 9px; }
    .day-body .field input, .day-body .field textarea {
      font-size: 12px; padding: 7px 10px;
    }

    /* Exercise tags preview */
    .ex-preview { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px; min-height: 0; }
    .ex-tag { font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: var(--green-dim); color: var(--green); }

    /* ── BUTTONS ── */
    .btn-row { display: flex; gap: 8px; margin-top: 4px; }
    .btn-save { flex: 1; background: var(--green); color: #000; border: none; border-radius: 6px; padding: 10px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity 0.15s; }
    .btn-save:hover { opacity: 0.85; }
    .btn-new { background: transparent; color: var(--green); border: 1px solid var(--green); border-radius: 6px; padding: 10px 16px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .btn-new:hover { background: var(--green-dim); }
    .btn-del { background: transparent; color: var(--red); border: 1px solid rgba(239,68,68,0.3); border-radius: 6px; padding: 8px 14px; font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-del:hover { background: var(--red-dim); }

    /* ── RIGHT PANEL ── */
    .plan-preview-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
    .plan-preview-meta { font-size: 12px; color: var(--sub); margin-bottom: 10px; }
    .progress-bar { height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; margin-bottom: 5px; }
    .progress-fill { height: 100%; border-radius: 3px; background: var(--green); }
    .progress-lbl { font-size: 11px; color: var(--sub); margin-bottom: 12px; }

    /* Live preview rows */
    .preview-day-list { display: flex; flex-direction: column; gap: 6px; }
    .preview-day-row { display: flex; align-items: flex-start; gap: 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 10px 12px; }
    .preview-day-row.rest-row { opacity: 0.5; }
    .preview-day-num { font-size: 10px; font-weight: 700; color: var(--green); width: 22px; flex-shrink: 0; padding-top: 1px; }
    .preview-day-info { flex: 1; min-width: 0; }
    .preview-day-name { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
    .preview-day-exs { font-size: 10px; color: var(--sub); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* History table */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 10px 0; font-size: 12px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); font-size: 13px; }
    tbody tr:last-child td { border-bottom: none; }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
    .pill.active { background: var(--green-dim); color: var(--green); }
    .pill.old    { background: var(--border); color: var(--sub); }
    .edit-link { color: var(--accent); text-decoration: none; font-size: 11px; font-weight: 600; }
    .edit-link:hover { text-decoration: underline; }

    /* Alerts */
    .alert-green { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); font-size: 12px; padding: 9px 14px; border-radius: 6px; margin-bottom: 14px; }
    .alert-red   { background: var(--red-dim); border: 1px solid rgba(239,68,68,0.2); color: var(--red); font-size: 12px; padding: 9px 14px; border-radius: 6px; margin-bottom: 14px; }
    .empty { text-align: center; padding: 30px; color: var(--sub); font-size: 13px; }

    @media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }
    @media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } }
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
    <a href="workout_plans.php"   class="nav-item active">📋 Workout Plans</a>
    <a href="nutrition_plan.php"  class="nav-item">🥗 Nutrition Plans</a>
    <a href="client_progress.php" class="nav-item">📊 Client Progress</a>
    <div class="nav-lbl">Account</div>
     <a href="coach_profile.php" class="nav-item">⚙️ My profile</a>
    <a href="logout.php" class="nav-item">🚪 Log Out</a>
  </nav>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">Workout Plans</div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>

  <div class="content">

    <?php if (isset($_GET['saved'])): ?>
      <div class="alert-green">✓ Workout plan saved and sent to client!</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <div class="alert-red">Plan deleted.</div>
    <?php endif; ?>

    <?php if (empty($clients_list)): ?>
      <div class="empty">No clients assigned yet.</div>
    <?php else: ?>

    <!-- Client selector -->
    <div style="font-size:11px;color:var(--sub);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">Select Client</div>
    <div class="client-tabs">
      <?php foreach ($clients_list as $c): ?>
        <a href="workout_plans.php?client=<?= $c['id'] ?>" class="client-tab <?= $c['id'] === $selected_id ? 'active' : '' ?>">
          <?= htmlspecialchars($c['full_name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($selected_client): ?>

    <?php
    // Pre-fill day values: from existing plan or defaults
    $is_new_plan = !$plan;
    $default_phase = $plan['phase'] ?? 'Hypertrophy';
    $default_template = $day_defaults[$default_phase] ?? $day_defaults['Hypertrophy'];

    $day_vals = [];
    for ($d = 1; $d <= 7; $d++) {
        if ($plan) {
            $parsed = parse_day_from_notes($plan['notes'] ?? '', $d);
            // If no day data found in notes, use template
            if (!$parsed['name'] && !$parsed['rest']) {
                $tpl = $default_template[$d-1] ?? ['', ''];
                $day_vals[$d] = ['name' => $tpl[0], 'exercises' => $tpl[1], 'rest' => $tpl[2] ?? false];
            } else {
                $day_vals[$d] = $parsed;
            }
        } else {
            $tpl = $default_template[$d-1] ?? ['', ''];
            $day_vals[$d] = ['name' => $tpl[0], 'exercises' => $tpl[1], 'rest' => $tpl[2] ?? false];
        }
    }
    $existing_coach_notes = $plan ? extract_coach_notes($plan['notes'] ?? '') : '';
    ?>

    <div class="layout">

      <!-- ── LEFT: EDITOR ── -->
      <div>
        <div class="card">
          <div class="ch">
            <div class="ct"><?= $plan ? 'Edit Workout Plan' : 'Create Workout Plan' ?></div>
            <span class="cs"><?= htmlspecialchars($selected_client['full_name']) ?></span>
          </div>

          <form action="workout_plans.php?client=<?= $selected_id ?>" method="POST" id="plan-form">
            <input type="hidden" name="save_plan"  value="1"/>
            <input type="hidden" name="member_id"  value="<?= $selected_id ?>"/>
            <input type="hidden" name="plan_id"    value="<?= $plan ? $plan['id'] : 0 ?>"/>

            <!-- Plan meta -->
            <div class="field">
              <label>Plan Name</label>
              <input type="text" name="plan_name" id="inp-plan-name"
                     value="<?= $plan ? htmlspecialchars($plan['plan_name']) : '' ?>"
                     placeholder="e.g. Hypertrophy Block — Phase 1" required/>
            </div>
            <div class="r2">
              <div class="field">
                <label>Phase</label>
                <select name="phase" id="inp-phase" onchange="applyPhaseTemplate(this.value)">
                  <?php foreach (['Hypertrophy','Strength','Cut / Fat Loss','Endurance','Deload'] as $ph): ?>
                    <option <?= ($plan && $plan['phase'] === $ph) ? 'selected' : (!$plan && $ph==='Hypertrophy' ? 'selected' : '') ?>><?= $ph ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Duration (weeks)</label>
                <input type="number" name="duration" id="inp-duration" min="1" max="52"
                       value="<?= $plan ? (int)$plan['duration_weeks'] : 4 ?>"/>
              </div>
            </div>

            <!-- ── 7-DAY SCHEDULE EDITOR ── -->
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--sub);margin-bottom:10px;">
              Weekly Schedule — Edit Each Day
            </div>
            <div class="schedule-editor" id="schedule-editor">
              <?php
              $dow = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
              for ($d = 1; $d <= 7; $d++):
                $v = $day_vals[$d];
                $is_rest = $v['rest'];
              ?>
              <div class="day-editor <?= $is_rest ? 'is-rest' : '' ?>" id="day-editor-<?= $d ?>" data-day="<?= $d ?>">
                <div class="day-header" onclick="toggleDay(<?= $d ?>)">
                  <span class="day-num-badge"><?= $dow[$d-1] ?></span>
                  <span class="day-header-name <?= $v['name'] ? '' : 'placeholder' ?>" id="day-header-name-<?= $d ?>">
                    <?= $v['name'] ? htmlspecialchars($v['name']) : 'Click to set day name...' ?>
                  </span>
                  <label class="rest-toggle" onclick="event.stopPropagation()">
                    <input type="checkbox" name="day<?= $d ?>_rest" id="rest-<?= $d ?>"
                           <?= $is_rest ? 'checked' : '' ?>
                           onchange="toggleRest(<?= $d ?>, this.checked)"/>
                    Rest
                  </label>
                  <span class="day-chevron" id="chevron-<?= $d ?>">▼</span>
                </div>
                <div class="day-body" id="day-body-<?= $d ?>">
                  <div class="field">
                    <label>Day Name / Focus</label>
                    <input type="text"
                           name="day<?= $d ?>_name"
                           id="day-name-<?= $d ?>"
                           value="<?= htmlspecialchars($v['name']) ?>"
                           placeholder="e.g. Push — Chest, Shoulders, Triceps"
                           oninput="updateHeader(<?= $d ?>, this.value)"
                           <?= $is_rest ? 'disabled' : '' ?>/>
                  </div>
                  <div class="field">
                    <label>Exercises (comma separated)</label>
                    <textarea name="day<?= $d ?>_exercises"
                              id="day-exs-<?= $d ?>"
                              rows="2"
                              placeholder="Bench Press, OHP, Incline DB Press, Lateral Raise, Tricep Pushdown"
                              oninput="updateExPreview(<?= $d ?>, this.value)"
                              <?= $is_rest ? 'disabled' : '' ?>><?= htmlspecialchars($v['exercises']) ?></textarea>
                    <div class="ex-preview" id="ex-preview-<?= $d ?>">
                      <?php if ($v['exercises']): ?>
                        <?php foreach (array_filter(array_map('trim', explode(',', $v['exercises']))) as $ex): ?>
                          <span class="ex-tag"><?= htmlspecialchars($ex) ?></span>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              <?php endfor; ?>
            </div>

            <!-- Coach notes (free text, separate from structured days) -->
            <div class="field">
              <label>Additional Coach Notes (optional)</label>
              <textarea name="coach_notes" rows="3"
                        placeholder="General notes, tips, or instructions for your client..."><?= htmlspecialchars($existing_coach_notes) ?></textarea>
            </div>

            <div class="btn-row">
              <button type="submit" class="btn-save">
                <?= $plan ? '💾 Update Plan' : '✓ Save & Send Plan' ?>
              </button>
              <?php if ($plan): ?>
              <button type="button" class="btn-new" onclick="newPlan()">+ New Plan</button>
              <?php endif; ?>
            </div>
          </form>

          <?php if ($plan): ?>
          <form method="POST" action="workout_plans.php?client=<?= $selected_id ?>" style="margin-top:10px;"
                onsubmit="return confirm('Delete this plan? This cannot be undone.')">
            <input type="hidden" name="delete_plan" value="1"/>
            <input type="hidden" name="plan_id"     value="<?= $plan['id'] ?>"/>
            <input type="hidden" name="member_id"   value="<?= $selected_id ?>"/>
            <button type="submit" class="btn-del">🗑 Delete This Plan</button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── RIGHT: PREVIEW + HISTORY ── -->
      <div>

        <!-- Live Preview -->
        <div class="card">
          <div class="ch">
            <div class="ct">Live Preview</div>
            <span class="cs" id="preview-client"><?= htmlspecialchars($selected_client['full_name']) ?></span>
          </div>

          <?php if ($plan): ?>
          <div class="plan-preview-name" id="preview-plan-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
          <div class="plan-preview-meta">
            <span id="preview-duration"><?= (int)$plan['duration_weeks'] ?></span> weeks ·
            <span id="preview-phase"><?= htmlspecialchars($plan['phase']) ?></span> ·
            Started <?= date('M j, Y', strtotime($plan['created_at'])) ?>
          </div>
          <div class="progress-bar">
            <div class="progress-fill" style="width:<?= $week_pct ?>%"></div>
          </div>
          <div class="progress-lbl">Week <?= $current_week ?> of <?= (int)$plan['duration_weeks'] ?> · <?= $week_pct ?>% complete</div>
          <?php else: ?>
          <div class="plan-preview-name" id="preview-plan-name" style="color:var(--sub)">New Plan</div>
          <div class="plan-preview-meta"><span id="preview-duration">4</span> weeks · <span id="preview-phase">Hypertrophy</span></div>
          <?php endif; ?>

          <div class="preview-day-list" id="preview-days">
            <?php
            $dow = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
            for ($d = 1; $d <= 7; $d++):
              $v = $day_vals[$d];
            ?>
            <div class="preview-day-row <?= $v['rest'] ? 'rest-row' : '' ?>" id="preview-row-<?= $d ?>">
              <div class="preview-day-num"><?= $dow[$d-1] ?></div>
              <div class="preview-day-info">
                <div class="preview-day-name" id="preview-name-<?= $d ?>">
                  <?= $v['name'] ? htmlspecialchars($v['name']) : '<span style="color:var(--sub)">—</span>' ?>
                </div>
                <div class="preview-day-exs" id="preview-exs-<?= $d ?>">
                  <?= $v['exercises'] ? htmlspecialchars(mb_substr($v['exercises'], 0, 60)) . (strlen($v['exercises']) > 60 ? '…' : '') : '' ?>
                </div>
              </div>
            </div>
            <?php endfor; ?>
          </div>
        </div>

        <!-- Plan History -->
        <?php if (!empty($all_plans)): ?>
        <div class="card">
          <div class="ch">
            <div class="ct">Plan History</div>
            <span class="cs"><?= count($all_plans) ?> plan<?= count($all_plans) > 1 ? 's' : '' ?></span>
          </div>
          <table>
            <thead><tr><th>Plan</th><th>Phase</th><th>Dur.</th><th>Date</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($all_plans as $i => $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['plan_name']) ?></td>
                <td><?= htmlspecialchars($p['phase'] ?? '—') ?></td>
                <td><?= (int)$p['duration_weeks'] ?>w</td>
                <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                <td><span class="pill <?= $i === 0 ? 'active' : 'old' ?>"><?= $i === 0 ? 'Active' : 'Past' ?></span></td>
                <td>
                  <a href="workout_plans.php?client=<?= $selected_id ?>&edit=<?= $p['id'] ?>" class="edit-link">Edit</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <?php endif; // selected_client ?>
    <?php endif; // clients_list not empty ?>

  </div>
</main>

<script>
// ── Phase templates (mirrors PHP $day_defaults) ──────────────────────────────
const PHASE_TEMPLATES = <?php echo json_encode($day_defaults); ?>;

const DOW = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function toggleDay(d) {
    const el = document.getElementById('day-editor-' + d);
    el.classList.toggle('open');
}

function toggleRest(d, isRest) {
    const editor = document.getElementById('day-editor-' + d);
    const nameEl = document.getElementById('day-name-' + d);
    const exsEl  = document.getElementById('day-exs-'  + d);

    editor.classList.toggle('is-rest', isRest);
    if (nameEl) nameEl.disabled = isRest;
    if (exsEl)  exsEl.disabled  = isRest;

    if (isRest) {
        updateHeader(d, 'Rest / Recovery');
        updatePreviewRow(d, 'Rest / Recovery', '', true);
        document.getElementById('day-name-' + d).value = 'Rest / Recovery';
    } else {
        const cur = document.getElementById('day-name-' + d).value;
        updateHeader(d, cur || '');
        updatePreviewRow(d, cur, document.getElementById('day-exs-' + d).value, false);
    }
}

function updateHeader(d, val) {
    const hdr = document.getElementById('day-header-name-' + d);
    if (val && val.trim()) {
        hdr.textContent = val;
        hdr.classList.remove('placeholder');
    } else {
        hdr.textContent = 'Click to set day name...';
        hdr.classList.add('placeholder');
    }
    updatePreviewRow(d, val,
        document.getElementById('day-exs-' + d)?.value || '',
        document.getElementById('rest-' + d)?.checked || false
    );
    // Also update live preview plan name
    const pn = document.getElementById('inp-plan-name');
    if (pn) document.getElementById('preview-plan-name').textContent = pn.value || 'New Plan';
}

function updateExPreview(d, val) {
    const container = document.getElementById('ex-preview-' + d);
    container.innerHTML = '';
    const parts = val.split(',').map(s => s.trim()).filter(Boolean);
    parts.forEach(ex => {
        const tag = document.createElement('span');
        tag.className = 'ex-tag';
        tag.textContent = ex;
        container.appendChild(tag);
    });
    updatePreviewRow(d,
        document.getElementById('day-name-' + d)?.value || '',
        val,
        document.getElementById('rest-' + d)?.checked || false
    );
}

function updatePreviewRow(d, name, exs, isRest) {
    const row  = document.getElementById('preview-row-' + d);
    const pName = document.getElementById('preview-name-' + d);
    const pExs  = document.getElementById('preview-exs-'  + d);
    if (row)   row.classList.toggle('rest-row', isRest);
    if (pName) pName.innerHTML = name || '<span style="color:var(--sub)">—</span>';
    if (pExs)  pExs.textContent = exs ? (exs.length > 60 ? exs.substring(0, 60) + '…' : exs) : '';
}

function applyPhaseTemplate(phase) {
    const tpl = PHASE_TEMPLATES[phase];
    if (!tpl) return;
    if (!confirm('Apply "' + phase + '" template? This will overwrite the current schedule.')) return;

    for (let d = 1; d <= 7; d++) {
        const t    = tpl[d - 1] || ['', ''];
        const name = t[0] || '';
        const exs  = t[1] || '';
        const rest = t[2] || false;

        const nameEl = document.getElementById('day-name-' + d);
        const exsEl  = document.getElementById('day-exs-'  + d);
        const restEl = document.getElementById('rest-' + d);

        if (nameEl) nameEl.value = name;
        if (exsEl)  exsEl.value  = exs;
        if (restEl) {
            restEl.checked = rest;
            toggleRest(d, rest);
        }
        updateHeader(d, name);
        updateExPreview(d, exs);
    }

    document.getElementById('preview-phase').textContent = phase;
}

function newPlan() {
    // Reset plan_id to 0 (new) and clear form
    if (!confirm('Start a new plan? The current plan will remain in history.')) return;
    document.querySelector('input[name="plan_id"]').value = '0';
    document.querySelector('input[name="plan_name"]').value = '';
    document.querySelector('input[name="duration"]').value = '4';
    document.querySelector('button[type="submit"]').textContent = '✓ Save & Send Plan';
    document.getElementById('preview-plan-name').textContent = 'New Plan';
}

// Live plan-name preview
document.getElementById('inp-plan-name')?.addEventListener('input', function() {
    document.getElementById('preview-plan-name').textContent = this.value || 'New Plan';
});
document.getElementById('inp-duration')?.addEventListener('input', function() {
    document.getElementById('preview-duration').textContent = this.value;
});
document.getElementById('inp-phase')?.addEventListener('change', function() {
    document.getElementById('preview-phase').textContent = this.value;
});

// Open today's day by default (or first non-rest day)
<?php
$today_idx = (int)date('N'); // 1=Mon...7=Sun
echo "toggleDay($today_idx);";
?>
</script>
</body>
</html>