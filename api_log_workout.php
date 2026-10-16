<?php
header('Content-Type: application/json');
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$user_id = $data['user_id'] ?? null;
$exercise = $data['exercise'] ?? '';
$muscle_group = $data['muscle_group'] ?? null;
$type = $data['type'] ?? 'strength';

if (empty($user_id) || empty($exercise) || empty($muscle_group)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if ($type === 'cardio') {
    $duration_minutes = $data['duration_minutes'] ?? null;
    if (empty($duration_minutes)) {
        echo json_encode(['success' => false, 'message' => 'Duration is required for cardio workouts']);
        exit;
    }
    $sets = null;
    $reps = null;
    $weight_kg = null;
} else {
    $sets = $data['sets'] ?? null;
    $reps = $data['reps'] ?? null;
    $weight_kg = $data['weight_kg'] ?? 0;
    $duration_minutes = null;

    if (empty($sets) || empty($reps)) {
        echo json_encode(['success' => false, 'message' => 'Sets and reps are required for strength workouts']);
        exit;
    }
}

$stmt = $conn->prepare("INSERT INTO workout_logs (user_id, exercise, sets, reps, weight_kg, muscle_group, type, duration_minutes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isiidssi", $user_id, $exercise, $sets, $reps, $weight_kg, $muscle_group, $type, $duration_minutes);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Workout logged successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to log workout']);
}

$stmt->close();
$conn->close();