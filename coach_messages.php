<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'coach') { header('Location: index.php'); exit; }

$coach_id   = $_SESSION['user_id'];
$coach_name = $_SESSION['full_name'];

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['body'])) {
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $body        = trim($_POST['body'] ?? '');
    if ($receiver_id && $body) {
        $s = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)");
        $s->bind_param("iis", $coach_id, $receiver_id, $body);
        $s->execute();
        $s->close();
    }
    header("Location: coach_messages.php?client=$receiver_id");
    exit;
}

// Get all assigned premium clients
$r = $conn->prepare("
    SELECT id, full_name, fitness_level
    FROM users
    WHERE coach_id=? AND role='member' AND tier='premium'
    ORDER BY full_name
");
$r->bind_param("i", $coach_id);
$r->execute();
$clients = $r->get_result()->fetch_all(MYSQLI_ASSOC);
$r->close();

// Selected client
$selected_id = intval($_GET['client'] ?? ($clients[0]['id'] ?? 0));
$selected_client = null;
foreach ($clients as $c) {
    if ($c['id'] === $selected_id) { $selected_client = $c; break; }
}

$unread_by_member = [];
$ru = $conn->prepare("SELECT sender_id, COUNT(*) AS c FROM messages WHERE receiver_id = ? AND read_at IS NULL GROUP BY sender_id");
$ru->bind_param("i", $coach_id);
$ru->execute();
$ur = $ru->get_result();
while ($row = $ur->fetch_assoc()) {
    $unread_by_member[(int) $row['sender_id']] = (int) $row['c'];
}
$ru->close();

$total_unread_inbox = array_sum($unread_by_member);
$new_from_client = ($selected_id && isset($unread_by_member[$selected_id])) ? $unread_by_member[$selected_id] : 0;

if ($selected_id && $new_from_client > 0) {
    $s = $conn->prepare("UPDATE messages SET read_at = NOW() WHERE receiver_id = ? AND sender_id = ? AND read_at IS NULL");
    $s->bind_param("ii", $coach_id, $selected_id);
    $s->execute();
    $s->close();
    $unread_by_member[$selected_id] = 0;
}

$total_unread_remaining = array_sum($unread_by_member);

// Fetch messages
$messages = [];
if ($selected_id) {
    $r = $conn->prepare("
        SELECT sender_id, body, sent_at
        FROM messages
        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
        ORDER BY sent_at ASC LIMIT 60
    ");
    $r->bind_param("iiii", $coach_id, $selected_id, $selected_id, $coach_id);
    $r->execute();
    $messages = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Coach Messages</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222; --text: #f0f0f0; --sub: #555555; --muted: #888888; --accent: #3b82f6; --accent-dim: rgba(59,130,246,0.1); --green: #22c55e; --green-dim: rgba(34,197,94,0.1); --font: 'Plus Jakarta Sans', sans-serif; }
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
    .main { margin-left: 210px; flex: 1; display: flex; flex-direction: column; height: 100vh; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .coach-id { font-size: 12px; color: var(--sub); }
    .coach-id strong { color: var(--green); }
    .chat-wrap { display: flex; flex: 1; overflow: hidden; }
    .chat-sidebar { width: 240px; border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow-y: auto; }
    .chat-sidebar-hd { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); }
    .convo-item { display: flex; align-items: center; gap: 10px; padding: 12px 16px; cursor: pointer; transition: background 0.12s; border-bottom: 1px solid var(--border); text-decoration: none; position: relative; }
    .convo-item:hover { background: var(--card); }
    .convo-item.active { background: var(--card); }
    .convo-av { width: 36px; height: 36px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0; }
    .convo-info { flex: 1; min-width: 0; }
    .convo-name { font-size: 13px; font-weight: 600; margin-bottom: 2px; color: var(--text); }
    .convo-level { font-size: 11px; color: var(--sub); }
    .chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .chat-header { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
    .chat-av { width: 36px; height: 36px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
    .chat-name { font-size: 13px; font-weight: 700; }
    .chat-level { font-size: 11px; color: var(--sub); }
    .messages-list { flex: 1; padding: 20px; overflow-y: auto; overflow-x: hidden; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
    .msg-row { display: flex; gap: 10px; align-items: flex-end; width: 100%; min-width: 0; }
    .msg-row.me { flex-direction: row-reverse; }
    .msg-av { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; flex-shrink: 0; }
    .msg-content { min-width: 0; flex: 1; max-width: calc(100% - 38px); display: flex; flex-direction: column; align-items: flex-start; }
    .msg-row.me .msg-content { align-items: flex-end; }
    .msg-bubble {
      box-sizing: border-box;
      width: fit-content;
      max-width: min(100%, 42rem);
      padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.55;
      overflow-wrap: anywhere; word-break: break-word;
    }
    .msg-row.them .msg-bubble { background: var(--surface); border: 1px solid var(--border); border-radius: 12px 12px 12px 2px; }
    .msg-row.me .msg-bubble { background: var(--green-dim); border: 1px solid rgba(34,197,94,0.2); border-radius: 12px 12px 2px 12px; }
    .msg-time { font-size: 10px; color: var(--sub); margin-top: 4px; }
    .chat-input { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 10px; }
    .chat-input input { flex: 1; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; }
    .chat-input input:focus { border-color: var(--green); }
    .btn-send { background: var(--green); color: #000; border: none; border-radius: 8px; padding: 10px 18px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; }
    .empty-state { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--sub); font-size: 13px; }
    .msg-notice { background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(34,197,94,0.08)); border: 1px solid rgba(59,130,246,0.25); border-radius: 8px; padding: 10px 14px; margin: 0 20px 12px; font-size: 12px; color: var(--text); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .msg-notice strong { color: var(--accent); }
    .unread-badge { margin-left: auto; font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #ef4444; color: #fff; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .top-unread { font-size: 11px; font-weight: 700; color: #fca5a5; background: rgba(239,68,68,0.15); padding: 4px 10px; border-radius: 999px; }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } .chat-sidebar { display: none; } .msg-notice { margin: 0 14px 12px; } }
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
    <a href="coach_messages.php" class="nav-item active">💬 Messages</a>
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
    <div class="page-title" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      Messages
      <?php if ($total_unread_remaining > 0): ?>
        <span class="top-unread" title="Unread messages in other threads"><?= $total_unread_remaining ?> unread</span>
      <?php endif; ?>
    </div>
    <div class="coach-id">Logged in as <strong><?= htmlspecialchars($coach_name) ?></strong></div>
  </div>
  <div class="chat-wrap">

    <!-- CLIENT LIST -->
    <div class="chat-sidebar">
      <div class="chat-sidebar-hd">Premium Clients</div>
      <?php if(empty($clients)): ?>
        <div style="padding:16px;font-size:12px;color:var(--sub);">No premium clients assigned.</div>
      <?php else: foreach($clients as $c): ?>
        <a href="coach_messages.php?client=<?= $c['id'] ?>"
           class="convo-item <?= $c['id']===$selected_id ? 'active' : '' ?>">
          <div class="convo-av"><?= strtoupper(substr($c['full_name'],0,1)) ?></div>
          <div class="convo-info">
            <div class="convo-name"><?= htmlspecialchars($c['full_name']) ?></div>
            <div class="convo-level"><?= ucfirst($c['fitness_level']) ?></div>
          </div>
          <?php $ub = $unread_by_member[$c['id']] ?? 0; if ($ub > 0): ?>
            <span class="unread-badge" title="Unread messages"><?= $ub > 99 ? '99+' : $ub ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <!-- CHAT MAIN -->
    <div class="chat-main">
      <?php if($selected_client): ?>

      <div class="chat-header">
        <div class="chat-av"><?= strtoupper(substr($selected_client['full_name'],0,1)) ?></div>
        <div>
          <div class="chat-name"><?= htmlspecialchars($selected_client['full_name']) ?></div>
          <div class="chat-level"><?= ucfirst($selected_client['fitness_level']) ?> · Premium</div>
        </div>
      </div>
      <?php if ($new_from_client > 0): ?>
      <div class="msg-notice" role="status">
        <span style="font-size:18px;">🔔</span>
        <div>
          <strong><?= $new_from_client === 1 ? 'New message' : $new_from_client . ' new messages' ?></strong> from this client
          <span style="color:var(--sub);"> — marked as read</span>
        </div>
      </div>
      <?php endif; ?>

      <div class="messages-list" id="msgList">
        <?php if(empty($messages)): ?>
          <div style="text-align:center;color:var(--sub);font-size:13px;margin:auto;">No messages yet. Start the conversation!</div>
        <?php else: foreach($messages as $msg):
          $is_me = $msg['sender_id'] == $coach_id;
        ?>
          <div class="msg-row <?= $is_me ? 'me' : 'them' ?>">
            <div class="msg-av" style="background:<?= $is_me ? 'var(--green-dim);color:var(--green)' : 'var(--accent-dim);color:var(--accent)' ?>">
              <?= $is_me ? strtoupper(substr($coach_name,0,1)) : strtoupper(substr($selected_client['full_name'],0,1)) ?>
            </div>
            <div class="msg-content">
              <div class="msg-bubble"><?= nl2br(htmlspecialchars($msg['body'])) ?></div>
              <div class="msg-time"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <form class="chat-input" action="coach_messages.php?client=<?= $selected_id ?>" method="POST">
        <input type="hidden" name="receiver_id" value="<?= $selected_id ?>"/>
        <input type="text" name="body" placeholder="Write a message..." required/>
        <button type="submit" class="btn-send">Send</button>
      </form>

      <?php else: ?>
        <div class="empty-state">Select a client to start messaging</div>
      <?php endif; ?>
    </div>

  </div>
</main>
<script>
  const list = document.getElementById('msgList');
  if (list) list.scrollTop = list.scrollHeight;
</script>
</body>
</html>