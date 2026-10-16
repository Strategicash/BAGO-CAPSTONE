<?php
header('Content-Type: application/json');
require_once 'config.php';

$user_id = $_GET['user_id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'user_id is required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, exercise, sets, reps, weight_kg, muscle_group, type, duration_minutes, logged_at FROM workout_logs WHERE user_id = ? ORDER BY logged_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$workouts = [];
while ($row = $result->fetch_assoc()) {
    $workouts[] = $row;
}

echo json_encode(['success' => true, 'workouts' => $workouts]);

$stmt->close();
$conn->close();