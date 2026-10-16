<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}
$admin_name = $_SESSION['full_name'] ?? 'Admin';

$message = '';
$messageType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? null;
    $card_id = trim($_POST['rfid_card_id'] ?? '');

    if (empty($user_id) || empty($card_id)) {
        $message = "Select a member and scan a card first.";
        $messageType = 'error';
    } else {
        $check = $conn->prepare("SELECT id, full_name FROM users WHERE rfid_card_id = ? AND id != ?");
        $check->bind_param("si", $card_id, $user_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $message = "This card is already linked to " . htmlspecialchars($existing['full_name']) . ".";
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE users SET rfid_card_id = ? WHERE id = ?");
            $stmt->bind_param("si", $card_id, $user_id);
            $stmt->execute();
            $stmt->close();
            $message = "Card linked successfully.";
            $messageType = 'success';
        }
    }
}

$members = $conn->query("SELECT id, full_name, email, rfid_card_id FROM users WHERE role = 'member' ORDER BY full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Enroll RFID</title>
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
      --red-mid:  #b91c1c;
      --green:    #16a34a;
      --green-dim:rgba(22,163,74,0.12);
      --blue:     #2563eb;
      --blue-dim: rgba(37,99,235,0.12);
      --amber:    #d97706;
      --amber-dim:rgba(217,119,6,0.12);
      --font:     'Inter', sans-serif;
      --nav-h:    56px;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }

    /* TOP NAV */
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

    /* HEADER STRIP */
    .pagehead { border-bottom: 1px solid var(--border); padding: 22px 32px; background: linear-gradient(135deg, #130303 0%, #1c0505 50%, #0d0d0d 100%); }
    .pagehead-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--red); margin-bottom: 6px; }
    .pagehead-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
    .pagehead-sub { font-size: 13px; color: var(--muted); margin-top: 5px; }

    /* MAIN */
    .wrapper { max-width: 900px; margin: 0 auto; padding: 28px 32px; }
    .alert { font-size: 13px; padding: 10px 16px; border-radius: 7px; margin-bottom: 20px; font-weight: 500; }
    .alert.success { background: var(--green-dim); border: 1px solid rgba(22,163,74,0.2); color: #4ade80; }
    .alert.error   { background: var(--red-dim);   border: 1px solid rgba(220,38,38,0.25); color: #f87171; }

    /* Section */
    .section { background: var(--card); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
    .section-head { padding: 13px 20px; border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.018); }
    .section-title { font-size: 13px; font-weight: 700; }
    .section-body { padding: 20px; }

    label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--sub); margin-bottom: 6px; margin-top: 16px; }
    label:first-of-type { margin-top: 0; }
    select, input[type="text"] {
        width: 100%; background: var(--surface); border: 1px solid var(--border2); border-radius: 6px;
        padding: 11px 12px; font-family: var(--font); font-size: 14px; color: var(--text); outline: none;
    }
    select:focus, input[type="text"]:focus { border-color: #60a5fa; }
    #cardInput { border: 2px solid var(--red); background: var(--red-dim); font-weight: 700; letter-spacing: 1px; }
    #cardInput:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(220,38,38,0.15); }
    .hint { font-size: 11px; color: var(--sub); margin-top: 6px; }

    .btn-primary {
        margin-top: 20px; width: 100%; background: var(--red); color: #fff; border: none; border-radius: 6px;
        padding: 12px; font-family: var(--font); font-size: 14px; font-weight: 700; cursor: pointer; transition: background 0.15s;
    }
    .btn-primary:hover { background: var(--red-mid); }

    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; padding: 12px 20px 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--sub); border-bottom: 1px solid var(--border); }
    tbody td { padding: 11px 20px; font-size: 13px; border-bottom: 1px solid var(--border); color: var(--muted); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }
    .td-name { font-weight: 600; color: var(--text); }
    .pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
    .pill.linked    { background: var(--green-dim); color: #4ade80; }
    .pill.unlinked  { background: var(--red-dim);   color: #f87171; }

    @media (max-width: 768px) { .nav-links { display: none; } .wrapper { padding: 20px 16px; } }
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

<!-- PAGE HEAD -->
<div class="pagehead">
  <div class="pagehead-eyebrow">RFID Access Control</div>
  <div class="pagehead-title">Enroll RFID Card</div>
  <div class="pagehead-sub">Link a member's physical access card to their account.</div>
</div>

<div class="wrapper">
  <?php if ($message): ?>
    <div class="alert <?= $messageType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="section">
    <div class="section-head"><span class="section-title">Link a Card</span></div>
    <div class="section-body">
      <form method="POST">
        <label>Member</label>
        <select name="user_id" required>
          <option value="" disabled selected>Choose a member</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?> (<?= htmlspecialchars($m['email']) ?>)</option>
          <?php endforeach; $members->data_seek(0); ?>
        </select>

        <label>Scan Card Now</label>
        <input type="text" name="rfid_card_id" id="cardInput" autocomplete="off" autofocus placeholder="Waiting for scan...">
        <div class="hint">This field stays focused — just tap the card on the USB reader.</div>

        <button type="submit" class="btn-primary">Link Card</button>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><span class="section-title">Currently Enrolled</span></div>
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Card Status</th></tr></thead>
      <tbody>
        <?php while ($m = $members->fetch_assoc()): ?>
          <tr>
            <td class="td-name"><?= htmlspecialchars($m['full_name']) ?></td>
            <td><?= htmlspecialchars($m['email']) ?></td>
            <td>
              <?php if ($m['rfid_card_id']): ?>
                <span class="pill linked"><?= htmlspecialchars($m['rfid_card_id']) ?></span>
              <?php else: ?>
                <span class="pill unlinked">Not linked</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  document.getElementById('cardInput').focus();
  document.addEventListener('click', function (e) {
    if (e.target.tagName !== 'SELECT' && e.target.tagName !== 'BUTTON') {
      document.getElementById('cardInput').focus();
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