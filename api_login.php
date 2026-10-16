<?php
header('Content-Type: application/json');
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, full_name, email, password, role FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    exit;
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    exit;
}

unset($user['password']);


$payStmt = $conn->prepare("SELECT expires_at FROM payments WHERE user_id = ? AND status = 'paid' ORDER BY expires_at DESC LIMIT 1");
$payStmt->bind_param("i", $user['id']);
$payStmt->execute();
$payResult = $payStmt->get_result();
$payRow = $payResult->fetch_assoc();
$payStmt->close();

if ($payRow && $payRow['expires_at']) {
    $user['expires_at'] = $payRow['expires_at'];
    $today = new DateTime();
    $expiry = new DateTime($payRow['expires_at']);
    $diff = $today->diff($expiry);
    $daysUntil = (int)$diff->format('%r%a'); // signed: negative if expiry is in the past
    $user['days_until_payment'] = $daysUntil;
    $user['membership_status'] = $daysUntil < 0 ? 'Expired' : 'Active';
}

echo json_encode(['success' => true, 'user' => $user]);

$stmt->close();
$conn->close();