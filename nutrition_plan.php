<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];

// Handle save nutrition plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    $member_id    = intval($_POST['member_id']    ?? 0);
    $plan_name    = trim($_POST['plan_name']       ?? '');
    $goal         = trim($_POST['goal']            ?? '');
    $calories     = intval($_POST['calories']      ?? 0);
    $protein_g    = intval($_POST['protein_g']     ?? 0);
    $carbs_g      = intval($_POST['carbs_g']       ?? 0);
    $fats_g       = intval($_POST['fats_g']        ?? 0);
    $meals_per_day = intval($_POST['meals_per_day'] ?? 3);
    $notes        = trim($_POST['notes']           ?? '');

    if ($member_id && $plan_name) {
        $s = $conn->prepare("INSERT INTO nutrition_plans (coach_id, member_id, plan_name, goal, calories, protein_g, carbs_g, fats_g, meals_per_day, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $s->bind_param("iissiiiiis", $coach_id, $member_id, $plan_name, $goal, $calories, $protein_g, $carbs_g, $fats_g, $meals_per_day, $notes);
        $s->execute();
        $s->close();
    }
    header("Location: nutrition_plan.php?client=$member_id&saved=1");
    exit;
}

// Fetch assigned clients
$r = $conn->prepare("SELECT id, full_name, fitness_level FROM users WHERE coach_id=? AND role='member' ORDER BY full_name");
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

// Fetch existing nutrition plan for selected client
$plan = null;
if ($selected_id) {
    $r = $conn->prepare("SELECT * FROM nutrition_plans WHERE member_id=? AND coach_id=? ORDER BY created_at DESC LIMIT 1");
    $r->bind_param("ii", $selected_id, $coach_id);
    $r->execute();
    $plan = $r->get_result()->fetch_assoc();
    $r->close();
}

// Fetch all nutrition plans for selected client
$all_plans = [];
if ($selected_id) {
    $r = $conn->prepare("SELECT * FROM nutrition_plans WHERE member_id=? AND coach_id=? ORDER BY created_at DESC");
    $r->bind_param("ii", $selected_id, $coach_id);
    $r->execute();
    $all_plans = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Nutrition Plans</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222; --text: #f0f0f0; --sub: #555555; --muted: #888888; --accent: #3b82f6; --accent-dim: rgba(59,130,246,0.1); --green: #22c55e; --green-dim: rgba(34,197,94,0.1); --yellow: #f59e0b; --red: #ef4444; --font: 'Plus Jakarta Sans', sans-serif; }
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
    .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    .client-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .client-tab { padding: 7px 14px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s; }
    .client-tab:hover { border-color: var(--green); color: var(--green); }
    .client-tab.active { background: var(--green-dim); border-color: var(--green); color: var(--green); }
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 4px; }
    .field input, .field select, .field textarea { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; resize: vertical; transition: border-color 0.15s; }
    .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--green); }
    .field select option { background: var(--surface); }
    .macro-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; margin-bottom: 12px; }
    .macro-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .macro-card .m-val { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .macro-card .m-lbl { font-size: 10px; color: var(--sub); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .macro-card.calories .m-val { color: var(--accent); }
    .macro-card.protein .m-val { color: var(--green); }
    .macro-card.carbs .m-val { color: var(--yellow); }
    .macro-card.fats .m-val { color: var(--red); }
    .btn-save { width: 100%; background: var(--green); color: #000; border: none; border-radius: 6px; padding: 10px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; margin-top: 4px; transition: opacity 0.15s; }
    .btn-save:hover { opacity: 0.85; }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 11px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
    .pill.cut { background: rgba(239,68,68,0.1); color: var(--red); }
    .pill.bulk { background: var(--green-dim); color: var(--green); }
    .pill.maintain { background: var(--accent-dim); color: var(--accent); }
    .success-msg { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); font-size: 12px; padding: 9px 14px; border-radius: 6px; margin-bottom: 16px; }
    .empty-state { text-align: center; padding: 40px 20px; color: var(--sub); font-size: 13px; }
    @media (max-width: 900px) { .g2 { grid-template-columns: 1fr; } .macro-grid { grid-template-columns: 1fr 1fr; } }
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
    <a href="coach_dashboard.php" class="nav-item">💬 Messages</a>
    <div class="nav-lbl">Tools</div>
    <a href="workout_plans.php" class="nav-item">📋 Workout Plans</a>
    <a href="nutrition_plan.php" class="nav-item active">🥗 Nutrition Plans</a>
    <a href="client_progress.php" class="nav-item">📊 Client Progress</a>
    <div class="nav-lbl">Account</div>
     <a href="coach_profile.php" class="nav-item">⚙️ My profile</a>
    <a href="logout.php" class="nav-item">🚪 Log Out</a>
  </nav>
</aside>
<main class="main">
  <div class="topbar">
    <div class="page-title">Nutrition Plans</div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>
  <div class="content">

    <?php if(isset($_GET['saved'])): ?>
      <div class="success-msg">✓ Nutrition plan saved and sent to client!</div>
    <?php endif; ?>

    <!-- CLIENT SELECTOR -->
    <?php if(empty($clients_list)): ?>
      <div class="empty-state">No clients assigned yet.</div>
    <?php else: ?>

    <div style="font-size:11px;color:var(--sub);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">Select Client</div>
    <div class="client-tabs">
      <?php foreach($clients_list as $c): ?>
        <a href="nutrition_plan.php?client=<?= $c['id'] ?>"
           class="client-tab <?= $c['id']===$selected_id ? 'active' : '' ?>">
          <?= htmlspecialchars($c['full_name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if($selected_client): ?>

    <div class="g2">

      <!-- PLAN FORM -->
      <div class="card">
        <div class="ch">
          <div class="ct">Create Nutrition Plan</div>
          <span style="font-size:11px;color:var(--sub)"><?= htmlspecialchars($selected_client['full_name']) ?></span>
        </div>
        <form action="nutrition_plan.php?client=<?= $selected_id ?>" method="POST">
          <input type="hidden" name="save_plan" value="1"/>
          <input type="hidden" name="member_id" value="<?= $selected_id ?>"/>

          <div class="field">
            <label>Plan Name</label>
            <input type="text" name="plan_name" placeholder="e.g. Cutting Phase — Week 1" value="<?= $plan ? htmlspecialchars($plan['plan_name']) : '' ?>" required/>
          </div>

          <div class="field">
            <label>Goal</label>
            <select name="goal">
              <?php foreach(['Lose Weight / Cut','Build Muscle / Bulk','Maintain Weight','Improve Performance','Body Recomposition'] as $g): ?>
                <option <?= ($plan && $plan['goal']===$g) ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="g2" style="margin-bottom:0;">
            <div class="field">
              <label>Daily Calories (kcal)</label>
              <input type="number" name="calories" placeholder="e.g. 2200" value="<?= $plan ? $plan['calories'] : '' ?>"/>
            </div>
            <div class="field">
              <label>Meals Per Day</label>
              <select name="meals_per_day">
                <?php foreach([3,4,5,6] as $m): ?>
                  <option <?= ($plan && $plan['meals_per_day']==$m) ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Protein (g)</label>
              <input type="number" name="protein_g" placeholder="e.g. 180" value="<?= $plan ? $plan['protein_g'] : '' ?>"/>
              <div style="font-size:10px;color:var(--sub);margin-top:3px;">~2g per kg bodyweight</div>
            </div>
            <div class="field">
              <label>Carbs (g)</label>
              <input type="number" name="carbs_g" placeholder="e.g. 250" value="<?= $plan ? $plan['carbs_g'] : '' ?>"/>
            </div>
            <div class="field">
              <label>Fats (g)</label>
              <input type="number" name="fats_g" placeholder="e.g. 70" value="<?= $plan ? $plan['fats_g'] : '' ?>"/>
            </div>
          </div>

          <div class="field">
            <label>Coach Notes & Meal Guide</label>
            <textarea name="notes" rows="5" placeholder="e.g. Breakfast: oats + eggs. Lunch: rice + chicken. Dinner: sweet potato + fish. Avoid processed foods and sugary drinks..."><?= $plan ? htmlspecialchars($plan['notes']) : '' ?></textarea>
          </div>

          <button type="submit" class="btn-save">💾 Save & Send to Client</button>
        </form>
      </div>

      <!-- CURRENT PLAN PREVIEW -->
      <div>
        <?php if($plan): ?>
        <div class="card">
          <div class="ch">
            <div class="ct">Current Plan</div>
            <span style="font-size:11px;color:var(--sub)"><?= date('M j, Y', strtotime($plan['created_at'])) ?></span>
          </div>
          <div style="margin-bottom:14px;">
            <div style="font-size:16px;font-weight:700;margin-bottom:4px;"><?= htmlspecialchars($plan['plan_name']) ?></div>
            <span class="pill <?= strpos(strtolower($plan['goal']),'lose') !== false ? 'cut' : (strpos(strtolower($plan['goal']),'build') !== false ? 'bulk' : 'maintain') ?>">
              <?= htmlspecialchars($plan['goal']) ?>
            </span>
          </div>

          <!-- MACRO CARDS -->
          <div class="macro-grid">
            <div class="macro-card calories">
              <div class="m-val"><?= $plan['calories'] ?: '—' ?></div>
              <div class="m-lbl">Calories</div>
            </div>
            <div class="macro-card protein">
              <div class="m-val"><?= $plan['protein_g'] ?: '—' ?>g</div>
              <div class="m-lbl">Protein</div>
            </div>
            <div class="macro-card carbs">
              <div class="m-val"><?= $plan['carbs_g'] ?: '—' ?>g</div>
              <div class="m-lbl">Carbs</div>
            </div>
            <div class="macro-card fats">
              <div class="m-val"><?= $plan['fats_g'] ?: '—' ?>g</div>
              <div class="m-lbl">Fats</div>
            </div>
          </div>

          <div style="font-size:12px;color:var(--sub);margin-bottom:6px;font-weight:600;">MEALS PER DAY: <?= $plan['meals_per_day'] ?></div>

          <?php if($plan['notes']): ?>
          <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:12px;font-size:12px;color:var(--muted);line-height:1.8;">
            <?= nl2br(htmlspecialchars($plan['notes'])) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
          <div class="ch"><div class="ct">Current Plan</div></div>
          <div class="empty-state">No nutrition plan yet for this client. Create one on the left.</div>
        </div>
        <?php endif; ?>

        <!-- PLAN HISTORY -->
        <?php if(count($all_plans) > 1): ?>
        <div class="card">
          <div class="ch"><div class="ct">Plan History</div></div>
          <table>
            <thead><tr><th>Plan Name</th><th>Goal</th><th>Calories</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach($all_plans as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['plan_name']) ?></td>
                <td><?= htmlspecialchars($p['goal']) ?></td>
                <td><?= $p['calories'] ? $p['calories'].' kcal' : '—' ?></td>
                <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div>
</main>
</body>
</html>