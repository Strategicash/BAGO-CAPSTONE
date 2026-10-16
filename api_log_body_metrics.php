<?php
header('Content-Type: application/json');
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$user_id = $data['user_id'] ?? null;
$weight_kg = $data['weight_kg'] ?? null;
$height_cm = $data['height_cm'] ?? null;
$chest_cm = $data['chest_cm'] ?? null;
$right_arm_cm = $data['right_arm_cm'] ?? null;
$left_arm_cm = $data['left_arm_cm'] ?? null;
$waist_navel_cm = $data['waist_navel_cm'] ?? null;
$waist_below_cm = $data['waist_below_cm'] ?? null;
$hips_cm = $data['hips_cm'] ?? null;
$right_leg_cm = $data['right_leg_cm'] ?? null;
$left_leg_cm = $data['left_leg_cm'] ?? null;

if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'user_id is required']);
    exit;
}

// Auto-calculate BMI if both weight and height are provided
$bmi = null;
if ($weight_kg && $height_cm) {
    $height_m = $height_cm / 100;
    $bmi = round($weight_kg / ($height_m * $height_m), 2);
}

$stmt = $conn->prepare("INSERT INTO body_metrics (user_id, weight_kg, height_cm, chest_cm, right_arm_cm, left_arm_cm, waist_navel_cm, waist_below_cm, hips_cm, right_leg_cm, left_leg_cm, bmi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param(
    "iddddddddddd",
    $user_id, $weight_kg, $height_cm, $chest_cm, $right_arm_cm, $left_arm_cm,
    $waist_navel_cm, $waist_below_cm, $hips_cm, $right_leg_cm, $left_leg_cm, $bmi
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Body metrics logged', 'bmi' => $bmi]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to log body metrics']);
}

$stmt->close();
$conn->close();