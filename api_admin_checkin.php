<?php
header('Content-Type: application/json');
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$card_id = trim($data['rfid_card_id'] ?? '');

if (empty($card_id)) {
    echo json_encode(['success' => false, 'message' => 'No card scanned']);
    exit;
}

$stmt = $conn->prepare("SELECT id, full_name FROM users WHERE rfid_card_id = ?");
$stmt->bind_param("s", $card_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Card not recognized', 'status' => 'unknown_card']);
    exit;
}

// Check if this member has an open check-in (tapped in, hasn't tapped out yet)
$openStmt = $conn->prepare("SELECT id, checked_in_at, TIMESTAMPDIFF(MINUTE, checked_in_at, NOW()) AS minutes_inside FROM access_logs WHERE user_id = ? AND access_granted = 1 AND checked_out_at IS NULL ORDER BY checked_in_at DESC LIMIT 1");
$openStmt->bind_param("i", $user['id']);
$openStmt->execute();
$openLog = $openStmt->get_result()->fetch_assoc();
$openStmt->close();

if ($openLog) {
    // Check membership validity FIRST — expired members must not get "too_early" or "Goodbye"
    $payStmt = $conn->prepare("SELECT expires_at FROM payments WHERE user_id = ? AND status = 'paid' AND expires_at >= NOW() ORDER BY expires_at DESC LIMIT 1");
    $payStmt->bind_param("i", $user['id']);
    $payStmt->execute();
    $openMembership = $payStmt->get_result()->fetch_assoc();
    $payStmt->close();

    if (!$openMembership) {
        // Close the open row so "Active Now" counts stay accurate
        $outStmt = $conn->prepare("UPDATE access_logs SET checked_out_at = NOW() WHERE id = ?");
        $outStmt->bind_param("i", $openLog['id']);
        $outStmt->execute();
        $outStmt->close();

        echo json_encode([
            'success' => true,
            'status' => 'denied',
            'name' => $user['full_name'],
            'message' => $user['full_name'] . ' — membership expired',
        ]);
        $conn->close();
        exit;
    }

    // Membership valid — keep existing behavior
    // This is a potential check-out tap
    $minutesInside = (int)$openLog['minutes_inside'];

    if ($minutesInside < 60) {
        $remaining = 60 - $minutesInside;
        echo json_encode([
            'success' => true,
            'status' => 'too_early',
            'name' => $user['full_name'],
            'message' => $user['full_name'] . ' — checked in ' . $minutesInside . ' min ago. Wait ' . $remaining . ' more min to check out.',
        ]);
        exit;
    }

    // 60+ minutes have passed — process check-out
    $outStmt = $conn->prepare("UPDATE access_logs SET checked_out_at = NOW() WHERE id = ?");
    $outStmt->bind_param("i", $openLog['id']);
    $outStmt->execute();
    $outStmt->close();

    echo json_encode([
        'success' => true,
        'status' => 'checked_out',
        'name' => $user['full_name'],
        'message' => 'Goodbye, ' . $user['full_name'] . '! See you next time.',
    ]);
    $conn->close();
    exit;
}

// No open check-in — this is a normal check-in attempt
$payStmt = $conn->prepare("SELECT expires_at FROM payments WHERE user_id = ? AND status = 'paid' AND expires_at >= NOW() ORDER BY expires_at DESC LIMIT 1");
$payStmt->bind_param("i", $user['id']);
$payStmt->execute();
$membership = $payStmt->get_result()->fetch_assoc();
$payStmt->close();

$granted = $membership ? 1 : 0;

$logStmt = $conn->prepare("INSERT INTO access_logs (user_id, method, access_granted) VALUES (?, 'rfid', ?)");
$logStmt->bind_param("ii", $user['id'], $granted);
$logStmt->execute();
$logStmt->close();

if ($granted) {
    echo json_encode([
        'success' => true,
        'status' => 'granted',
        'name' => $user['full_name'],
        'message' => 'Welcome, ' . $user['full_name'] . '!',
        'expires_at' => $membership['expires_at'],
    ]);
} else {
    echo json_encode([
        'success' => true,
        'status' => 'denied',
        'name' => $user['full_name'],
        'message' => $user['full_name'] . ' — membership expired',
    ]);
}

$conn->close();