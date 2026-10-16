<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}

$user_id = $_SESSION['user_id'];

// Handle both dropdown and "Other" option
$raw = explode('|', $_POST['exercise'] ?? '');

if ($raw[0] === 'other') {
    $exercise     = trim($_POST['exercise_other']     ?? '');
    $muscle_group = trim($_POST['muscle_group_other'] ?? '');
} else {
    $exercise     = $raw[0] ?? '';
    $muscle_group = $raw[1] ?? '';
}

$sets   = intval($_POST['sets']   ?? 0);
$reps   = intval($_POST['reps']   ?? 0);
$weight = floatval($_POST['weight'] ?? 0);

// Validate
if (empty($exercise) || $sets < 1 || $reps < 1) {
    header('Location: dashboard.php?error=Please+fill+in+all+fields');
    exit;
}

// Save to database
$stmt = $conn->prepare(
    "INSERT INTO workout_logs (user_id, exercise, sets, reps, weight_kg, muscle_group)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param("isiids", $user_id, $exercise, $sets, $reps, $weight, $muscle_group);
$stmt->execute();
$stmt->close();
$conn->close();

header('Location: dashboard.php?logged=1');
exit;
?>