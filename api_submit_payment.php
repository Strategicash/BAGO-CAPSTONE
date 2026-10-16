<?php
header('Content-Type: application/json');
require_once 'config.php';

$user_id = $_POST['user_id'] ?? null;
$description = $_POST['description'] ?? '';
$amount = $_POST['amount'] ?? null;
$reference_number = $_POST['reference_number'] ?? '';

if (empty($user_id) || empty($description) || empty($amount) || empty($reference_number)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields, including reference number']);
    exit;
}

$proof_path = null;
if (isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = 'uploads/payment_proofs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $ext = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION);
    $filename = 'payment_' . $user_id . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;
    if (move_uploaded_file($_FILES['proof']['tmp_name'], $destination)) {
        $proof_path = $destination;
    }
}

$stmt = $conn->prepare("INSERT INTO payments (user_id, description, amount, status, proof_file, reference_number) VALUES (?, ?, ?, 'pending', ?, ?)");
$stmt->bind_param("isdss", $user_id, $description, $amount, $proof_path, $reference_number);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Payment submitted, pending confirmation']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to submit payment']);
}

$stmt->close();
$conn->close();