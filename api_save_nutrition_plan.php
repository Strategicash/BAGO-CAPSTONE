<?php
header('Content-Type: application/json');
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$member_id = $data['member_id'] ?? null;
$plan_name = $data['plan_name'] ?? 'My Nutrition Plan';
$goal = $data['goal'] ?? null;
$goal_weight_kg = $data['goal_weight_kg'] ?? null;
$calories = $data['calories'] ?? null;
$protein_g = $data['protein_g'] ?? null;
$carbs_g = $data['carbs_g'] ?? null;
$fats_g = $data['fats_g'] ?? null;
$meals_per_day = $data['meals_per_day'] ?? 3;
$notes = $data['notes'] ?? null;

if (empty($member_id)) {
    echo json_encode(['success' => false, 'message' => 'member_id is required']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO nutrition_plans (member_id, plan_name, goal, goal_weight_kg, calories, protein_g, carbs_g, fats_g, meals_per_day, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("issdiiiiis", $member_id, $plan_name, $goal, $goal_weight_kg, $calories, $protein_g, $carbs_g, $fats_g, $meals_per_day, $notes);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Nutrition plan saved']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save nutrition plan']);
}

$stmt->close();
$conn->close();