<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }

$member_id = intval($_POST['member_id'] ?? 0);
$coach_id  = intval($_POST['coach_id']  ?? 0);

if ($member_id && $coach_id) {
    // Assign coach and ensure tier is premium
    $s = $conn->prepare("UPDATE users SET coach_id=?, tier='premium' WHERE id=?");
    $s->bind_param("ii", $coach_id, $member_id);
    $s->execute();
    $s->close();

    // ── Start the 30-day timer NOW (coach assignment = subscription start) ──
    // Only set expires_at if not already set (don't reset on reassignment)
    $s = $conn->prepare("
        UPDATE payments
        SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
        WHERE user_id = ?
          AND status = 'paid'
          AND expires_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $s->bind_param("i", $member_id);
    $s->execute();
    $s->close();
}

$conn->close();
header('Location: admin.php?assigned=1');
exit;
?>