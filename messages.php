<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'member') { header('Location: index.php'); exit; }
if ($_SESSION['tier'] !== 'premium') { header('Location: dashboard.php'); exit; }

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$tier      = $_SESSION['tier'];
$avatar    = strtoupper(substr($full_name, 0, 1));

// Get assigned coach
$r = $conn->prepare("
    SELECT u.id, u.full_name
    FROM users u
    JOIN users m ON m.coach_id = u.id
    WHERE m.id = ?
");
$r->bind_param("i", $user_id);
$r->execute();
$coach = $r->get_result()->fetch_assoc();
$r->close();

$coach_id   = $coach ? $coach['id']        : null;
$coach_name = $coach ? $coach['full_name'] : null;

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['body']) && $coach_id) {
    $body = trim($_POST['body'] ?? '');
    if ($body) {
        $s = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)");
        $s->bind_param("iis", $user_id, $coach_id, $body);
        $s->execute();
        $s->close();
    }
    header('Location: messages.php');
    exit;
}

$new_from_coach = 0;
if ($coach_id) {
    $r = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE receiver_id = ? AND sender_id = ? AND read_at IS NULL");
    $r->bind_param("ii", $user_id, $coach_id);
    $r->execute();
    $new_from_coach = (int) ($r->get_result()->fetch_assoc()['c'] ?? 0);
    $r->close();
    if ($new_from_coach > 0) {
        $s = $conn->prepare("UPDATE messages SET read_at = NOW() WHERE receiver_id = ? AND sender_id = ? AND read_at IS NULL");
        $s->bind_param("ii", $user_id, $coach_id);
        $s->execute();
        $s->close();
    }
}

// Fetch messages between member and coach
$messages = [];
if ($coach_id) {
    $r = $conn->prepare("
        SELECT sender_id, body, sent_at
        FROM messages
        WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
        ORDER BY sent_at ASC
        LIMIT 60
    ");
    $r->bind_param("iiii", $user_id, $coach_id, $coach_id, $user_id);
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
  <title>FitX Training Club — Messages</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {  --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
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
    .main { margin-left: 210px; flex: 1; display: flex; flex-direction: column; height: 100vh; }
    .topbar { border-bottom: 1px solid var(--border); padding: 0 28px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: var(--bg); }
    .page-title { font-size: 14px; font-weight: 700; }
    .chat-wrap { display: flex; flex: 1; overflow: hidden; }
    .chat-sidebar { width: 240px; border-right: 1px solid var(--border); display: flex; flex-direction: column; }
    .chat-sidebar-hd { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); }
    .convo-item { display: flex; align-items: center; gap: 10px; padding: 12px 16px; cursor: pointer; transition: background 0.12s; border-bottom: 1px solid var(--border); }
    .convo-item:hover, .convo-item.active { background: var(--card); }
    .convo-av { width: 36px; height: 36px; border-radius: 50%; background: var(--green-dim); color: var(--green); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0; }
    .convo-info { flex: 1; min-width: 0; }
    .convo-name { font-size: 13px; font-weight: 600; margin-bottom: 2px; }
    .convo-preview { font-size: 11px; color: var(--sub); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .convo-time { font-size: 10px; color: var(--sub); flex-shrink: 0; }
    .chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .chat-header { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
    .chat-av { width: 36px; height: 36px; border-radius: 50%; background: var(--green-dim); color: var(--green); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
    .chat-name { font-size: 13px; font-weight: 700; }
    .chat-status { font-size: 11px; color: var(--green); }
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
    .msg-row.me .msg-bubble { background: var(--accent-dim); border: 1px solid rgba(59,130,246,0.2); border-radius: 12px 12px 2px 12px; }
    .msg-time { font-size: 10px; color: var(--sub); margin-top: 4px; }
    .chat-input { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 10px; }
    .chat-input input { flex: 1; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; }
    .chat-input input:focus { border-color: var(--accent); }
    .btn-send { background: var(--accent); color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; }
    .msg-notice { background: linear-gradient(135deg, rgba(34,197,94,0.12), rgba(245,158,11,0.08)); border: 1px solid rgba(34,197,94,0.25); border-radius: 8px; padding: 10px 14px; margin: 0 20px 12px; font-size: 12px; color: var(--text); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .msg-notice strong { color: var(--green); }
    @media (max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; } .chat-sidebar { display: none; } .msg-notice { margin: 0 14px 12px; } }
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
    <a href="body_metrics.php" class="nav-item">⚖️ Body Metrics</a>
    <?php if ($tier === 'premium'): ?>
    <div class="nav-lbl">Premium</div>
    <a href="my_coach.php" class="nav-item">👤 My Coach</a>
    <a href="my_plan.php" class="nav-item">📋 My Plan</a>
    <a href="messages.php" class="nav-item active">💬 Messages</a>
    <?php endif; ?>
    <div class="nav-lbl">Account</div>
    <a href="profile.php" class="nav-item">⚙️ Profile</a>
    <a href="billing.php" class="nav-item">💳 Billing</a>
  </nav>
  <div class="sidebar-foot"><a href="logout.php" class="nav-item">🚪 Log Out</a></div>
</aside>
<main class="main">
  <div class="topbar">
    <div class="page-title" style="display:flex;align-items:center;gap:10px;">Messages
      <?php if ($coach_id && $new_from_coach > 0): ?>
        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;background:var(--green-dim);color:var(--green);padding:3px 8px;border-radius:999px;">New</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="chat-wrap">
    <div class="chat-sidebar">
      <div class="chat-sidebar-hd">Conversations</div>
      <?php if($coach): ?>
      <div class="convo-item active">
        <div class="convo-av"><?= strtoupper(substr($coach_name,0,1)) ?></div>
        <div class="convo-info">
          <div class="convo-name"><?= htmlspecialchars($coach_name) ?></div>
          <div class="convo-preview"><?= !empty($messages) ? htmlspecialchars(substr(end($messages)['body'],0,30)).'...' : 'No messages yet' ?></div>
        </div>
      </div>
      <?php else: ?>
      <div class="convo-item">
        <div class="convo-info">
          <div class="convo-name" style="color:var(--sub)">No coach assigned</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="chat-main">
      <div class="chat-header">
        <div class="chat-av"><?= $coach_name ? strtoupper(substr($coach_name,0,1)) : '?' ?></div>
        <div>
          <div class="chat-name"><?= $coach_name ? htmlspecialchars($coach_name) : 'No Coach Assigned' ?></div>
          <div class="chat-status"><?= $coach_name ? '● Active' : '' ?></div>
        </div>
      </div>
      <?php if ($coach_id && $new_from_coach > 0): ?>
      <div class="msg-notice" role="status">
        <span style="font-size:18px;">🔔</span>
        <div>
          <strong><?= $new_from_coach === 1 ? 'New message' : $new_from_coach . ' new messages' ?></strong> from your coach
          <span style="color:var(--sub);"> — marked as read</span>
        </div>
      </div>
      <?php endif; ?>
      <div class="messages-list" id="msgList">
        <?php if(!$coach_id): ?>
          <div style="text-align:center;color:var(--sub);font-size:13px;padding:40px 20px;">
            You have not been assigned a coach yet. Please wait for admin to assign one.
          </div>
        <?php elseif(empty($messages)): ?>
          <div style="text-align:center;color:var(--sub);font-size:13px;padding:40px 20px;">
            No messages yet. Send your first message to your coach!
          </div>
        <?php else: foreach($messages as $msg):
          $is_me = $msg['sender_id'] == $user_id;
        ?>
          <div class="msg-row <?= $is_me ? 'me' : 'them' ?>">
            <div class="msg-av" style="background:<?= $is_me ? 'var(--accent-dim);color:var(--accent)' : 'var(--green-dim);color:var(--green)' ?>">
              <?= $is_me ? $avatar : strtoupper(substr($coach_name,0,1)) ?>
            </div>
            <div class="msg-content">
              <div class="msg-bubble"><?= nl2br(htmlspecialchars($msg['body'])) ?></div>
              <div class="msg-time"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <form class="chat-input" action="messages.php" method="POST">
        <input type="text" name="body" id="msgInput" placeholder="<?= $coach_id ? 'Write a message...' : 'No coach assigned yet' ?>" <?= !$coach_id ? 'disabled' : '' ?> required/>
        <button type="submit" class="btn-send" <?= !$coach_id ? 'disabled' : '' ?>>Send</button>
      </form>
    </div>
  </div>
</main>
<script>
  const list = document.getElementById('msgList');
  if (list) list.scrollTop = list.scrollHeight;
</script>
</body>
</html>