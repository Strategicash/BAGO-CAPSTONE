<?php
header('Content-Type: application/json');
require_once 'config.php';

$user_id = $_GET['user_id'] ?? null;
$limit = intval($_GET['limit'] ?? 30);

if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'user_id is required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, checked_in_at, method FROM access_logs WHERE user_id = ? ORDER BY checked_in_at DESC LIMIT ?");
$stmt->bind_param("ii", $user_id, $limit);
$stmt->execute();
$result = $stmt->get_result();

$checkins = [];
while ($row = $result->fetch_assoc()) {
    $checkins[] = $row;
}

echo json_encode(['success' => true, 'checkins' => $checkins]);

$stmt->close();
$conn->close();