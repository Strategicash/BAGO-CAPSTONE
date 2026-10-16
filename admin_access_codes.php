<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}
$admin_name = $_SESSION['full_name'] ?? 'Admin';

$codes = $conn->query("SELECT ac.id, ac.code, ac.status, ac.generated_at, ac.used_at, u.full_name AS used_by_name
                        FROM access_codes ac
                        LEFT JOIN users u ON u.id = ac.used_by_user_id
                        ORDER BY ac.generated_at DESC LIMIT 50");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Access Codes</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0d0d0d; --surface: #141414; --card: #1a1a1a; --border: #252525; --border2: #303030;
      --text: #f0f0f0; --sub: #666; --muted: #999;
      --red: #dc2626; --red-dim: rgba(220,38,38,0.12);
      --green: #16a34a; --green-dim: rgba(22,163,74,0.12);
      --amber: #d97706; --amber-dim: rgba(217,119,6,0.12);
      --font: 'Inter', sans-serif; --nav-h: 56px;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); }
    .topnav { position: sticky; top: 0; z-index: 100; background: var(--surface); border-bottom: 1px solid var(--border); height: var(--nav-h); display: flex; align-items: center; padding: 0 32px; gap: 20px; }
    .nav-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .nav-logo { width: 32px; height: 32px; border-radius: 6px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff; }
    .nav-brand-text { font-size: 14px; font-weight: 700; color: var(--text); }
    .nav-brand-text span { color: var(--red); }
    .nav-divider { width: 1px; height: 20px; background: var(--border2); flex-shrink: 0; }
    .nav-links { display: flex; align-items: center; gap: 2px; flex: 1; overflow-x: auto; }
    .nav-link { display: flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; white-space: nowrap; }
    .nav-link:hover { background: var(--card); color: var(--text); }
    .nav-link.active { background: var(--red-dim); color: var(--red); font-weight: 600; }
    .nav-right { display: flex; align-items: center; gap: 10px; margin-left: auto; flex-shrink: 0; }
    .nav-admin-chip { display: flex; align-items: center; gap: 7px; background: var(--card); border: 1px solid var(--border2); border-radius: 20px; padding: 4px 12px 4px 5px; }
    .nav-avatar { width: 24px; height: 24px; border-radius: 50%; background: var(--red); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .nav-admin-name { font-size: 12px; font-weight: 600; }
    .nav-logout { font-size: 12px; color: var(--sub); text-decoration: none; padding: 5px 9px; border-radius: 5px; border: 1px solid var(--border); }
    .nav-logout:hover { color: var(--text); border-color: var(--border2); }

    .page { max-width: 900px; margin: 0 auto; padding: 32px 24px; }
    .page h1 { font-size: 22px; font-weight: 700; margin-bottom: 20px; }

    .generate-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 28px; text-align: center; }
    .generate-btn { background: var(--red); color: #fff; border: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; font-family: var(--font); }
    .generate-btn:hover { background: #ef4444; }
    .generated-code { margin-top: 20px; display: none; }
    .generated-code .code-display { font-size: 42px; font-weight: 700; letter-spacing: 6px; color: var(--green); font-family: monospace; }
    .generated-code .code-hint { font-size: 13px; color: var(--muted); margin-top: 8px; }

    table { width: 100%; border-collapse: collapse; background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
    th { text-align: left; padding: 12px 16px; font-size: 12px; color: var(--muted); text-transform: uppercase; border-bottom: 1px solid var(--border); }
    td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    .code-mono { font-family: monospace; font-weight: 600; letter-spacing: 1px; }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .pill-active { background: var(--amber-dim); color: var(--amber); }
    .pill-used { background: var(--green-dim); color: var(--green); }

    @media (max-width: 768px) { .nav-links { display: none; } }
  </style>
</head>
<body>

<nav class="topnav">
  <a href="admin.php" class="nav-brand">
    <div class="nav-logo">FX</div>
    <div class="nav-brand-text">FitX <span>Admin</span></div>
  </a>
  <div class="nav-divider"></div>
  <div class="nav-links">
    <a href="admin.php" class="nav-link">⊞ Dashboard</a>
    <a href="all_members.php" class="nav-link">👥 Members</a>
    <a href="payments.php" class="nav-link">💳 Payments</a>
    <a href="admin_enroll_rfid.php" class="nav-link">📶 Enroll RFID</a>
    <a href="admin_checkin.php" class="nav-link">🚪 Check-In Kiosk</a>
    <a href="admin_access_codes.php" class="nav-link active">🔑 Access Codes</a>
    <a href="analytics.php" class="nav-link">📈 Analytics</a>
    <a href="settings.php" class="nav-link">⚙️ Settings</a>
  </div>
  <div class="nav-right">
    <div class="nav-admin-chip">
      <div class="nav-avatar"><?= strtoupper(substr($admin_name, 0, 1)) ?></div>
      <span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span>
    </div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<div class="page">
  <h1>Access Codes</h1>

  <div class="generate-card">
    <button class="generate-btn" onclick="generateCode()">+ Generate New Access Code</button>
    <div class="generated-code" id="generatedCode">
      <div class="code-display" id="codeText"></div>
      <div class="code-hint">Tell this code to the member — one-time use only</div>
    </div>
  </div>

  <table>
    <thead>
      <tr><th>Code</th><th>Status</th><th>Generated</th><th>Used By</th></tr>
    </thead>
    <tbody>
      <?php while ($row = $codes->fetch_assoc()): ?>
      <tr>
        <td class="code-mono"><?= htmlspecialchars($row['code']) ?></td>
        <td>
          <?php if ($row['status'] === 'used'): ?>
            <span class="pill pill-used">Used</span>
          <?php else: ?>
            <span class="pill pill-active">Active</span>
          <?php endif; ?>
        </td>
        <td><?= date('M j, Y g:i A', strtotime($row['generated_at'])) ?></td>
        <td><?= $row['used_by_name'] ? htmlspecialchars($row['used_by_name']) : '—' ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<script>
async function generateCode() {
  const res = await fetch('api_generate_access_code.php', { method: 'POST' });
  const data = await res.json();
  if (data.success) {
    document.getElementById('codeText').textContent = data.code;
    document.getElementById('generatedCode').style.display = 'block';
  } else {
    alert(data.message || 'Failed to generate code');
  }
}
</script>
</body>
</html>