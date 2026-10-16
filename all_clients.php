<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];

// Fetch all assigned clients with their stats
$r = $conn->prepare("
    SELECT u.id, u.full_name, u.email, u.fitness_level, u.created_at,
    (SELECT COUNT(*) FROM workout_logs WHERE user_id = u.id) AS total_workouts,
    (SELECT COUNT(*) FROM workout_logs WHERE user_id = u.id AND MONTH(logged_at) = MONTH(NOW())) AS workouts_month,
    (SELECT plan_name FROM workout_plans WHERE member_id = u.id ORDER BY created_at DESC LIMIT 1) AS current_plan,
    (SELECT recorded_at FROM body_metrics WHERE user_id = u.id ORDER BY recorded_at DESC LIMIT 1) AS last_metric
    FROM users u WHERE u.coach_id = ? AND u.role = 'member'
    ORDER BY u.full_name ASC
");
$r->bind_param("i", $coach_id);
$r->execute();
$clients = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — All Clients</title>
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
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 14px; }
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .ct { font-size: 13px; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); padding: 0 0 10px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 12px 0; font-size: 13px; border-bottom: 1px solid var(--surface); color: var(--muted); vertical-align: middle; }
    tbody td:first-child { color: var(--text); }
    tbody tr:last-child td { border-bottom: none; }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
    .pill.beginner { background: var(--green-dim); color: var(--green); }
    .pill.intermediate { background: rgba(245,158,11,0.1); color: var(--yellow); }
    .pill.advanced { background: rgba(239,68,68,0.1); color: var(--red); }
    .pill.active { background: var(--green-dim); color: var(--green); }
    .pill.none { background: var(--border); color: var(--sub); }
    .btn-view { border: 1px solid var(--border); background: transparent; color: var(--muted); border-radius: 5px; padding: 4px 10px; font-family: var(--font); font-size: 11px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s; }
    .btn-view:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-dim); }
    .av { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; }
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
    <a href="all_clients.php" class="nav-item active">👥 All Clients</a>
    <a href="coach_messages.php" class="nav-item">💬 Messages</a>
    <div class="nav-lbl">Tools</div>
    <a href="workout_plans.php" class="nav-item">📋 Workout Plans</a>
    <a href="nutrition_plan.php" class="nav-item">🥗 Nutrition Plans</a>
     <a href="client_progress.php" class="nav-item">📊 Client Progress</a>                      
    <div class="nav-lbl">Account</div>
    <a href="coach_profile.php" class="nav-item">⚙️ My profile</a>
    <a href="logout.php" class="nav-item">🚪 Log Out</a>
  </nav>
</aside>
<main class="main">
  <div class="topbar">
    <div class="page-title">All Clients</div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>
  <div class="content">
    <div class="card">
      <div class="ch">
        <div class="ct">My Clients — <?= count($clients) ?></div>
      </div>
      <?php if(empty($clients)): ?>
        <p style="font-size:13px;color:var(--sub);text-align:center;padding:20px 0;">No clients assigned yet.</p>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Client</th>
            <th>Level</th>
            <th>Total Workouts</th>
            <th>This Month</th>
            <th>Current Plan</th>
            <th>Last Metric</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($clients as $c): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="av"><?= strtoupper(substr($c['full_name'],0,1)) ?></div>
                <div>
                  <div style="font-weight:600;"><?= htmlspecialchars($c['full_name']) ?></div>
                  <div style="font-size:11px;color:var(--sub)"><?= htmlspecialchars($c['email']) ?></div>
                </div>
              </div>
            </td>
            <td><span class="pill <?= $c['fitness_level'] ?>"><?= ucfirst($c['fitness_level']) ?></span></td>
            <td><?= $c['total_workouts'] ?> workouts</td>
            <td><?= $c['workouts_month'] ?> this month</td>
            <td>
              <?php if($c['current_plan']): ?>
                <span class="pill active"><?= htmlspecialchars($c['current_plan']) ?></span>
              <?php else: ?>
                <span class="pill none">No plan</span>
              <?php endif; ?>
            </td>
            <td><?= $c['last_metric'] ? date('M j, Y', strtotime($c['last_metric'])) : '—' ?></td>
            <td>
              <a href="coach_dashboard.php?client=<?= $c['id'] ?>" class="btn-view">View →</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>