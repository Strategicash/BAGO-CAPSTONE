<?php
header('Content-Type: application/json');
require_once 'config.php';

$user_id = $_GET['user_id'] ?? null;

if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'user_id is required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, weight_kg, height_cm, chest_cm, right_arm_cm, left_arm_cm, waist_navel_cm, waist_below_cm, hips_cm, right_leg_cm, left_leg_cm, bmi, recorded_at FROM body_metrics WHERE user_id = ? ORDER BY recorded_at ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$metrics = [];
while ($row = $result->fetch_assoc()) {
    $metrics[] = $row;
}

echo json_encode(['success' => true, 'metrics' => $metrics]);

$stmt->close();
$conn->close();