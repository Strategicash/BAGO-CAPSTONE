<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}
$admin_name = $_SESSION['full_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Check-In Kiosk</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:       #0d0d0d;
      --surface:  #141414;
      --card:     #1a1a1a;
      --border:   #252525;
      --border2:  #303030;
      --text:     #f0f0f0;
      --sub:      #666;
      --muted:    #999;
      --red:      #dc2626;
      --red-dim:  rgba(220,38,38,0.12);
      --green:    #16a34a;
      --font:     'Inter', sans-serif;
      --nav-h:    56px;
    }
    html, body { height: 100%; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); }

    /* TOP NAV (same as rest of admin) */
    .topnav { position: sticky; top: 0; z-index: 100; background: var(--surface); border-bottom: 1px solid var(--border); height: var(--nav-h); display: flex; align-items: center; padding: 0 32px; gap: 20px; }
    .nav-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .nav-logo { width: 32px; height: 32px; border-radius: 6px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff; }
    .nav-brand-text { font-size: 14px; font-weight: 700; color: var(--text); }
    .nav-brand-text span { color: var(--red); }
    .nav-divider { width: 1px; height: 20px; background: var(--border2); flex-shrink: 0; }
    .nav-links { display: flex; align-items: center; gap: 2px; flex: 1; overflow-x: auto; }
    .nav-link { display: flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .nav-link:hover { background: var(--card); color: var(--text); }
    .nav-link.active { background: var(--red-dim); color: var(--red); font-weight: 600; }
    .nav-right { display: flex; align-items: center; gap: 10px; margin-left: auto; flex-shrink: 0; }
    .nav-time { font-size: 12px; color: var(--sub); }
    .nav-admin-chip { display: flex; align-items: center; gap: 7px; background: var(--card); border: 1px solid var(--border2); border-radius: 20px; padding: 4px 12px 4px 5px; }
    .nav-avatar { width: 24px; height: 24px; border-radius: 50%; background: var(--red); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .nav-admin-name { font-size: 12px; font-weight: 600; }
    .nav-logout { font-size: 12px; color: var(--sub); text-decoration: none; padding: 5px 9px; border-radius: 5px; border: 1px solid var(--border); transition: all 0.15s; }
    .nav-logout:hover { color: var(--text); border-color: var(--border2); }

    /* KIOSK AREA */
    .kiosk-stage {
        height: calc(100vh - var(--nav-h));
        display: flex; align-items: center; justify-content: center;
        transition: background 0.3s ease;
    }
    .kiosk-stage.granted     { background: radial-gradient(circle at center, #14301c 0%, var(--bg) 70%); }
    .kiosk-stage.denied      { background: radial-gradient(circle at center, #3a1414 0%, var(--bg) 70%); }
    .kiosk-stage.unknown     { background: radial-gradient(circle at center, #2a2a2a 0%, var(--bg) 70%); }
    .kiosk-stage.checked_out { background: radial-gradient(circle at center, #122a3a 0%, var(--bg) 70%); }
    .kiosk-stage.too_early   { background: radial-gradient(circle at center, #3a2f14 0%, var(--bg) 70%); }

    .kiosk { text-align: center; }
    .kiosk .icon { font-size: 88px; margin-bottom: 18px; }
    .kiosk h1 { font-size: 34px; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 8px; }
    .kiosk h2 { font-size: 17px; font-weight: 500; color: var(--muted); }
    .idle-text { font-size: 14px; color: var(--sub); margin-top: 36px; letter-spacing: 0.5px; text-transform: uppercase; }
    .pulse { animation: pulse 2s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }

    #hiddenInput { position: absolute; opacity: 0; pointer-events: none; }

    @media (max-width: 768px) { .nav-links { display: none; } }
  </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
  <a href="admin.php" class="nav-brand">
    <div class="nav-logo">FX</div>
    <div class="nav-brand-text">FitX <span>Admin</span></div>
  </a>
  <div class="nav-divider"></div>
  <div class="nav-links">
  <a href="admin.php"                 class="nav-link">⊞ Dashboard</a>
  <a href="all_members.php"           class="nav-link">👥 Members</a>
  <a href="payments.php"              class="nav-link">💳 Payments</a>
  <a href="admin_enroll_rfid.php"     class="nav-link">📶 Enroll RFID</a>
  <a href="admin_checkin.php"         class="nav-link">🚪 Check-In Kiosk</a>
  <a href="analytics.php"             class="nav-link">📈 Analytics</a>
  <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
  <a href="settings.php"              class="nav-link">⚙️ Settings</a>
</div>
  <div class="nav-right">
    <span class="nav-time" id="live-time"></span>
    <div class="nav-admin-chip">
      <div class="nav-avatar"><?= strtoupper(substr($admin_name, 0, 1)) ?></div>
      <span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span>
    </div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<!-- KIOSK -->
<div class="kiosk-stage" id="kioskStage">
  <div id="idleView" class="kiosk">
    <div class="icon pulse">🔓</div>
    <h1>FitX Training Club</h1>
    <h2>Tap your card to check in</h2>
    <div class="idle-text">Waiting for RFID scan…</div>
  </div>
  <div id="resultView" class="kiosk" style="display:none;">
    <div class="icon" id="resultIcon"></div>
    <h1 id="resultTitle"></h1>
    <h2 id="resultSubtitle"></h2>
  </div>
</div>

<input type="text" id="cardInput" autocomplete="off" autofocus>

<script>
  const input = document.getElementById('cardInput');
  const stage = document.getElementById('kioskStage');
  const idleView = document.getElementById('idleView');
  const resultView = document.getElementById('resultView');
  const resultIcon = document.getElementById('resultIcon');
  const resultTitle = document.getElementById('resultTitle');
  const resultSubtitle = document.getElementById('resultSubtitle');

  let resetTimer = null;

  function refocus() { input.focus(); }
  document.addEventListener('click', refocus);
  setInterval(refocus, 1000);
  refocus();

  function showResult(status, title, subtitle) {
    stage.className = 'kiosk-stage ' + status;
    idleView.style.display = 'none';
    resultView.style.display = 'block';
    resultTitle.textContent = title;
    resultSubtitle.textContent = subtitle;

    const icons = {
      granted: '✅',
      denied: '⛔',
      unknown: '❓',
      checked_out: '👋',
      too_early: '⏳'
    };
    resultIcon.textContent = icons[status] || '❓';

    clearTimeout(resetTimer);
    const duration = status === 'too_early' ? 6000 : 4000;
    resetTimer = setTimeout(resetToIdle, duration);
  }

  function resetToIdle() {
    stage.className = 'kiosk-stage';
    resultView.style.display = 'none';
    idleView.style.display = 'block';
    input.value = '';
    refocus();
  }

  input.addEventListener('keydown', async (e) => {
    if (e.key === 'Enter') {
      const cardId = input.value.trim();
      input.value = '';
      if (!cardId) return;

      try {
        const res = await fetch('api_admin_checkin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ rfid_card_id: cardId })
        });
        const data = await res.json();

        if (data.status === 'granted') {
          showResult('granted', 'Welcome, ' + (data.name || 'Member') + '!', data.message || 'Access granted');
        } else if (data.status === 'denied') {
          showResult('denied', data.name || 'Access Denied', data.message || 'Membership expired or inactive');
        } else if (data.status === 'checked_out') {
          showResult('checked_out', 'Goodbye, ' + (data.name || 'Member') + '!', data.message || 'Checked out');
        } else if (data.status === 'too_early') {
          showResult('too_early', data.name || 'Hold on', data.message || 'Too soon to check out');
        } else {
          showResult('unknown', 'Unknown Card', data.message || 'This card is not registered to any member');
        }
      } catch (err) {
        showResult('unknown', 'Connection Error', 'Could not reach the server');
      }
    }
  });

  function updateTime() {
    document.getElementById('live-time').textContent =
      new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true });
  }
  updateTime(); setInterval(updateTime, 1000);
</script>
</body>
</html>