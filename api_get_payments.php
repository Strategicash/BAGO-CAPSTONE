<?php
header('Content-Type: application/json');
require_once 'config.php';

$user_id = $_GET['user_id'] ?? null;
if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'user_id is required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, description, amount, status, paid_at, expires_at, proof_file, reference_number FROM payments WHERE user_id = ? ORDER BY paid_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$payments = [];
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}

echo json_encode(['success' => true, 'payments' => $payments]);

$stmt->close();
$conn->close();