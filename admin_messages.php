<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }
$admin_name = $_SESSION['full_name'] ?? 'Admin';

// Get all coach-client pairs that have messages
$r = $conn->query("
    SELECT DISTINCT
        c.id AS coach_id, c.full_name AS coach_name,
        m2.id AS member_id, m2.full_name AS member_name, m2.fitness_level,
        (SELECT COUNT(*) FROM messages WHERE
            (sender_id=c.id AND receiver_id=m2.id) OR
            (sender_id=m2.id AND receiver_id=c.id)) AS msg_count,
        (SELECT sent_at FROM messages WHERE
            (sender_id=c.id AND receiver_id=m2.id) OR
            (sender_id=m2.id AND receiver_id=c.id)
            ORDER BY sent_at DESC LIMIT 1) AS last_msg_at,
        (SELECT body FROM messages WHERE
            (sender_id=c.id AND receiver_id=m2.id) OR
            (sender_id=m2.id AND receiver_id=c.id)
            ORDER BY sent_at DESC LIMIT 1) AS last_msg_body
    FROM users c
    JOIN users m2 ON m2.coach_id = c.id AND m2.role = 'member' AND m2.tier = 'premium'
    WHERE c.role = 'coach'
    HAVING msg_count > 0
    ORDER BY last_msg_at DESC
");
$conversations = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
if ($r) $r->close();

// Also get pairs with NO messages yet (assigned but haven't messaged)
$r = $conn->query("
    SELECT
        c.id AS coach_id, c.full_name AS coach_name,
        m2.id AS member_id, m2.full_name AS member_name, m2.fitness_level,
        0 AS msg_count, NULL AS last_msg_at, NULL AS last_msg_body
    FROM users c
    JOIN users m2 ON m2.coach_id = c.id AND m2.role = 'member' AND m2.tier = 'premium'
    WHERE c.role = 'coach'
    AND NOT EXISTS (
        SELECT 1 FROM messages WHERE
        (sender_id=c.id AND receiver_id=m2.id) OR
        (sender_id=m2.id AND receiver_id=c.id)
    )
    ORDER BY c.full_name, m2.full_name
");
$silent_pairs = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
if ($r) $r->close();

// Selected conversation
$sel_coach  = intval($_GET['coach']  ?? 0);
$sel_member = intval($_GET['member'] ?? 0);

$messages = [];
$sel_coach_name  = '';
$sel_member_name = '';

if ($sel_coach && $sel_member) {
    // Get names
    $r = $conn->prepare("SELECT full_name FROM users WHERE id=?"); $r->bind_param("i",$sel_coach); $r->execute(); $row=$r->get_result()->fetch_assoc(); $r->close();
    $sel_coach_name = $row['full_name'] ?? '';
    $r = $conn->prepare("SELECT full_name FROM users WHERE id=?"); $r->bind_param("i",$sel_member); $r->execute(); $row=$r->get_result()->fetch_assoc(); $r->close();
    $sel_member_name = $row['full_name'] ?? '';

    $r = $conn->prepare("
        SELECT m.sender_id, m.body, m.sent_at, u.full_name AS sender_name, u.role AS sender_role
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
        ORDER BY m.sent_at ASC
        LIMIT 100
    ");
    $r->bind_param("iiii", $sel_coach, $sel_member, $sel_member, $sel_coach);
    $r->execute();
    $messages = $r->get_result()->fetch_all(MYSQLI_ASSOC);
    $r->close();
}

// Stats
$r = $conn->query("SELECT COUNT(*) c FROM messages"); $total_msgs = (int)$r->fetch_assoc()['c']; $r->close();
$r = $conn->query("SELECT COUNT(DISTINCT LEAST(sender_id,receiver_id), GREATEST(sender_id,receiver_id)) c FROM messages"); $total_convos = (int)$r->fetch_assoc()['c']; $r->close();
$r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'"); $pending_payments=(int)$r->fetch_assoc()['c']; $r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Admin — Messages</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--bg:#0d0d0d;--surface:#141414;--card:#1a1a1a;--border:#252525;--border2:#303030;--text:#f0f0f0;--sub:#666;--muted:#999;--red:#dc2626;--red-dim:rgba(220,38,38,0.12);--green:#16a34a;--green-dim:rgba(22,163,74,0.12);--blue:#2563eb;--blue-dim:rgba(37,99,235,0.12);--amber:#d97706;--amber-dim:rgba(217,119,6,0.12);--font:'Inter',sans-serif;--nav-h:56px}
    body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column}
    .topnav{position:sticky;top:0;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 32px;gap:20px;flex-shrink:0}
    .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}.nav-logo{width:32px;height:32px;border-radius:6px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
    .nav-brand-text{font-size:14px;font-weight:700;color:var(--text)}.nav-brand-text span{color:var(--red)}
    .nav-divider{width:1px;height:20px;background:var(--border2);flex-shrink:0}
    .nav-links{display:flex;align-items:center;gap:2px;flex:1}
    .nav-link{display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:6px;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;transition:all 0.15s;white-space:nowrap}
    .nav-link:hover{background:var(--card);color:var(--text)}.nav-link.active{background:var(--red-dim);color:var(--red);font-weight:600}
    .nav-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:8px;margin-left:2px}
    .nav-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}.nav-time{font-size:12px;color:var(--sub)}
    .nav-admin-chip{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border2);border-radius:20px;padding:4px 12px 4px 5px}
    .nav-avatar{width:24px;height:24px;border-radius:50%;background:var(--red);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
    .nav-admin-name{font-size:12px;font-weight:600}.nav-logout{font-size:12px;color:var(--sub);text-decoration:none;padding:5px 9px;border-radius:5px;border:1px solid var(--border);transition:all 0.15s}
    .nav-logout:hover{color:var(--text);border-color:var(--border2)}

    /* BANNER */
    .banner{position:relative;height:120px;overflow:hidden;border-bottom:1px solid var(--border);flex-shrink:0}
    .banner-fallback{position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),repeating-linear-gradient(0deg,rgba(220,38,38,0.05) 0,rgba(220,38,38,0.05) 1px,transparent 1px,transparent 64px),linear-gradient(135deg,#130303 0%,#1c0505 50%,#0d0d0d 100%)}
    .banner-overlay{position:absolute;inset:0;background:linear-gradient(to right,rgba(13,13,13,0.88) 0%,rgba(13,13,13,0.5) 55%,rgba(13,13,13,0.15) 100%)}
    .banner-content{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;padding:0 40px}
    .banner-eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
    .banner-title{font-size:22px;font-weight:700;letter-spacing:-0.5px}.banner-sub{font-size:13px;color:var(--muted);margin-top:5px}
    .banner-right{text-align:right}.banner-stat-val{font-size:22px;font-weight:700;line-height:1}.banner-stat-lbl{font-size:11px;color:var(--sub);margin-top:3px}

    /* MAIN LAYOUT — full height chat */
    .chat-layout{display:flex;flex:1;overflow:hidden;height:calc(100vh - var(--nav-h) - 120px)}

    /* LEFT PANEL — conversation list */
    .conv-panel{width:300px;flex-shrink:0;border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
    .conv-panel-hd{padding:14px 16px;border-bottom:1px solid var(--border);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);flex-shrink:0;background:var(--surface)}
    .conv-list{flex:1;overflow-y:auto}
    .conv-section-lbl{padding:10px 14px 5px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);background:var(--bg);border-bottom:1px solid var(--border)}
    .conv-item{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;text-decoration:none;border-bottom:1px solid var(--border);transition:background 0.12s}
    .conv-item:hover{background:var(--card)}.conv-item.active{background:var(--card);border-left:2px solid var(--red)}
    .conv-av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
    .conv-info{flex:1;min-width:0}
    .conv-pair{font-size:12px;font-weight:700;color:var(--text);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .conv-preview{font-size:11px;color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .conv-meta{text-align:right;flex-shrink:0}
    .conv-time{font-size:10px;color:var(--sub)}
    .conv-count{font-size:10px;font-weight:700;color:var(--muted);margin-top:3px}
    .conv-silent{padding:12px 16px;font-size:12px;color:var(--sub);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}

    /* RIGHT PANEL — message thread */
    .msg-panel{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
    .msg-panel-hd{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;flex-shrink:0;background:var(--surface)}
    .msg-panel-hd-info{}
    .msg-panel-title{font-size:14px;font-weight:700;margin-bottom:2px}
    .msg-panel-sub{font-size:11px;color:var(--sub)}
    .admin-badge{background:var(--red-dim);color:#f87171;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;letter-spacing:0.5px;text-transform:uppercase;margin-left:auto;flex-shrink:0}
    .msg-thread{flex:1;padding:20px;overflow-y:auto;display:flex;flex-direction:column;gap:12px}
    .msg-row{display:flex;gap:10px;align-items:flex-end;width:100%}
    .msg-row.coach{flex-direction:row}
    .msg-row.member{flex-direction:row-reverse}
    .msg-av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0}
    .msg-content{display:flex;flex-direction:column;max-width:60%}
    .msg-row.member .msg-content{align-items:flex-end}
    .msg-sender-name{font-size:10px;color:var(--sub);margin-bottom:3px;font-weight:600}
    .msg-bubble{padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.55;word-break:break-word}
    .msg-row.coach .msg-bubble{background:var(--green-dim);border:1px solid rgba(22,163,74,0.2);border-radius:12px 12px 12px 2px;color:var(--text)}
    .msg-row.member .msg-bubble{background:var(--blue-dim);border:1px solid rgba(37,99,235,0.2);border-radius:12px 12px 2px 12px;color:var(--text)}
    .msg-time{font-size:10px;color:var(--sub);margin-top:4px}
    .msg-date-sep{text-align:center;font-size:11px;color:var(--sub);padding:8px 0;font-weight:600}

    /* Empty state */
    .empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--sub);font-size:13px;gap:8px;padding:40px}
    .empty-icon{font-size:40px;margin-bottom:8px}
    .empty-title{font-size:15px;font-weight:600;color:var(--muted)}

    @media(max-width:900px){.conv-panel{width:240px}}
    @media(max-width:768px){.nav-links{display:none}.conv-panel{display:none}.banner-right{display:none}.banner-content{padding:0 20px}}
  </style>
</head>
<body>

<nav class="topnav">
  <a href="admin.php" class="nav-brand"><div class="nav-logo">FX</div><div class="nav-brand-text">FitX <span>Admin</span></div></a>
  <div class="nav-divider"></div>
  <div class="nav-links">
    <a href="admin.php"            class="nav-link">⊞ Dashboard</a>
    <a href="all_members.php"      class="nav-link">👥 Members</a>
    <a href="coach_assignment.php" class="nav-link">🏆 Coaches</a>
    <a href="payments.php"         class="nav-link">💳 Payments <?php if($pending_payments>0): ?><span class="nav-badge"><?= $pending_payments ?></span><?php endif; ?></a>
    <a href="staff_coach.php"      class="nav-link">🧑‍🏫 Staff</a>
    <a href="analytics.php"        class="nav-link">📈 Analytics</a>
    <a href="admin_client_activity.php" class="nav-link">📋 Client Activity</a>
    <a href="admin_messages.php"   class="nav-link active">💬 Messages</a>
    <a href="settings.php"         class="nav-link">⚙️ Settings</a>
  </div>
  <div class="nav-right">
    <span class="nav-time" id="live-time"></span>
    <div class="nav-admin-chip"><div class="nav-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div><span class="nav-admin-name"><?= htmlspecialchars($admin_name) ?></span></div>
    <a href="logout.php" class="nav-logout">Log out</a>
  </div>
</nav>

<div class="banner">
  <div class="banner-fallback"></div><div class="banner-overlay"></div>
  <div class="banner-content">
    <div>
      <div class="banner-eyebrow">Admin — Monitor</div>
      <div class="banner-title">Coach–Client Messages</div>
      <div class="banner-sub">Read-only view of all coach and member conversations</div>
    </div>
    <div class="banner-right">
      <div class="banner-stat-val" style="color:#60a5fa"><?= $total_msgs ?></div>
      <div class="banner-stat-lbl">Total messages sent</div>
    </div>
  </div>
</div>

<div class="chat-layout">

  <!-- LEFT: Conversation list -->
  <div class="conv-panel">
    <div class="conv-panel-hd">Conversations (<?= count($conversations) ?>)</div>
    <div class="conv-list">

      <?php if(empty($conversations) && empty($silent_pairs)): ?>
        <div style="padding:20px;font-size:12px;color:var(--sub);text-align:center;">No coach-client pairs found.</div>

      <?php else: ?>

        <?php if(!empty($conversations)): ?>
          <div class="conv-section-lbl">Active Conversations</div>
          <?php foreach($conversations as $conv):
            $is_active = ($conv['coach_id']==$sel_coach && $conv['member_id']==$sel_member);
            $preview = $conv['last_msg_body'] ? substr($conv['last_msg_body'], 0, 35) . (strlen($conv['last_msg_body'])>35?'...':'') : '';
          ?>
          <a href="admin_messages.php?coach=<?= $conv['coach_id'] ?>&member=<?= $conv['member_id'] ?>"
             class="conv-item <?= $is_active?'active':'' ?>">
            <div class="conv-av" style="background:var(--green-dim);color:#4ade80"><?= strtoupper(substr($conv['coach_name'],0,1)) ?></div>
            <div class="conv-info">
              <div class="conv-pair">🧑‍🏫 <?= htmlspecialchars($conv['coach_name']) ?></div>
              <div class="conv-pair" style="color:var(--muted);font-weight:500;font-size:11px;">👤 <?= htmlspecialchars($conv['member_name']) ?></div>
              <div class="conv-preview"><?= htmlspecialchars($preview) ?></div>
            </div>
            <div class="conv-meta">
              <?php if($conv['last_msg_at']): ?>
                <div class="conv-time"><?= date('M j', strtotime($conv['last_msg_at'])) ?></div>
              <?php endif; ?>
              <div class="conv-count"><?= $conv['msg_count'] ?> msgs</div>
            </div>
          </a>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if(!empty($silent_pairs)): ?>
          <div class="conv-section-lbl">Assigned — No Messages Yet</div>
          <?php foreach($silent_pairs as $sp): ?>
          <div class="conv-silent">
            <div class="conv-av" style="background:var(--border);color:var(--sub);width:28px;height:28px;font-size:11px;"><?= strtoupper(substr($sp['coach_name'],0,1)) ?></div>
            <div>
              <div style="font-size:12px;color:var(--muted);">🧑‍🏫 <?= htmlspecialchars($sp['coach_name']) ?></div>
              <div style="font-size:11px;color:var(--sub);">👤 <?= htmlspecialchars($sp['member_name']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: Message thread -->
  <div class="msg-panel">
    <?php if($sel_coach && $sel_member && $sel_coach_name && $sel_member_name): ?>

      <div class="msg-panel-hd">
        <div class="conv-av" style="background:var(--green-dim);color:#4ade80;width:36px;height:36px;font-size:14px;"><?= strtoupper(substr($sel_coach_name,0,1)) ?></div>
        <div class="msg-panel-hd-info">
          <div class="msg-panel-title">🧑‍🏫 <?= htmlspecialchars($sel_coach_name) ?> &amp; 👤 <?= htmlspecialchars($sel_member_name) ?></div>
          <div class="msg-panel-sub"><?= count($messages) ?> message<?= count($messages)!==1?'s':'' ?> · Read-only admin view</div>
        </div>
        <div class="admin-badge">👁 Admin View</div>
      </div>

      <div class="msg-thread" id="msgThread">
        <?php if(empty($messages)): ?>
          <div class="empty-state">
            <div class="empty-icon">💬</div>
            <div class="empty-title">No messages yet</div>
            <div>This coach and member haven't messaged each other yet.</div>
          </div>
        <?php else:
          $last_date = '';
          foreach($messages as $msg):
            $msg_date = date('Y-m-d', strtotime($msg['sent_at']));
            $is_coach = ($msg['sender_id'] == $sel_coach);
            $row_class = $is_coach ? 'coach' : 'member';
            $av_style  = $is_coach ? 'background:var(--green-dim);color:#4ade80' : 'background:var(--blue-dim);color:#60a5fa';
            $av_letter = strtoupper(substr($msg['sender_name'],0,1));

            if ($msg_date !== $last_date):
              $last_date = $msg_date;
        ?>
          <div class="msg-date-sep"><?= date('F j, Y', strtotime($msg['sent_at'])) ?></div>
        <?php endif; ?>
          <div class="msg-row <?= $row_class ?>">
            <div class="msg-av" style="<?= $av_style ?>"><?= $av_letter ?></div>
            <div class="msg-content">
              <div class="msg-sender-name"><?= htmlspecialchars($msg['sender_name']) ?> · <?= $is_coach ? 'Coach' : 'Member' ?></div>
              <div class="msg-bubble"><?= nl2br(htmlspecialchars($msg['body'])) ?></div>
              <div class="msg-time"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Read-only notice -->
      <div style="padding:12px 20px;border-top:1px solid var(--border);background:var(--surface);display:flex;align-items:center;gap:10px;">
        <span style="font-size:18px;">👁</span>
        <span style="font-size:12px;color:var(--sub);">Admin view is <strong style="color:var(--muted)">read-only</strong>. You can monitor conversations but cannot send messages.</span>
      </div>

    <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">💬</div>
        <div class="empty-title">Select a conversation</div>
        <div>Choose a coach–client pair from the left to view their messages.</div>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
  function updateTime(){document.getElementById('live-time').textContent=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true});}
  updateTime();setInterval(updateTime,1000);
  const thread = document.getElementById('msgThread');
  if (thread) thread.scrollTop = thread.scrollHeight;
</script>
</body>
</html>