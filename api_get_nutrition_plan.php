<?php
header('Content-Type: application/json');
require_once 'config.php';

$member_id = $_GET['member_id'] ?? null;

if (empty($member_id)) {
    echo json_encode(['success' => false, 'message' => 'member_id is required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, plan_name, goal, goal_weight_kg, calories, protein_g, carbs_g, fats_g, meals_per_day, notes, created_at FROM nutrition_plans WHERE member_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$result = $stmt->get_result();
$plan = $result->fetch_assoc();

echo json_encode(['success' => true, 'plan' => $plan]);

$stmt->close();
$conn->close();