<?php
header('Content-Type: application/json');
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$fullName   = $data['full_name'] ?? '';
$email      = $data['email'] ?? '';
$password   = $data['password'] ?? '';
$role       = $data['role'] ?? 'member';
$accessCode = trim($data['access_code'] ?? '');

if (empty($fullName) || empty($email) || empty($password) || empty($accessCode)) {
    echo json_encode(['success' => false, 'message' => 'All fields, including the gym access code, are required']);
    exit;
}

// 0. Verify the access code exists and is still active (not yet used)
$codeStmt = $conn->prepare("SELECT id, status FROM access_codes WHERE code = ?");
$codeStmt->bind_param("s", $accessCode);
$codeStmt->execute();
$codeResult = $codeStmt->get_result();
$codeRow = $codeResult->fetch_assoc();
$codeStmt->close();

if (!$codeRow) {
    echo json_encode(['success' => false, 'message' => 'Invalid access code. Please check with FitX staff.']);
    $conn->close();
    exit;
}

if ($codeRow['status'] !== 'active') {
    echo json_encode(['success' => false, 'message' => 'This access code has already been used. Please request a new one from FitX staff.']);
    $conn->close();
    exit;
}

$accessCodeId = $codeRow['id'];

// 1. Check if the email is already registered
$checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$checkStmt->bind_param("s", $email);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email is already registered']);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// 2. Hash the password for secure storage
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// 3. Insert the new user
$stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $fullName, $email, $hashedPassword, $role);

if ($stmt->execute()) {
    $newUserId = $conn->insert_id;
    $stmt->close();

    // 4. Mark the access code as used, tied to this new user
    $markUsed = $conn->prepare("UPDATE access_codes SET status = 'used', used_at = NOW(), used_by_user_id = ? WHERE id = ?");
    $markUsed->bind_param("ii", $newUserId, $accessCodeId);
    $markUsed->execute();
    $markUsed->close();

    // 5. Fetch the newly created user so the app can log them in immediately
    $userStmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE id = ?");
    $userStmt->bind_param("i", $newUserId);
    $userStmt->execute();
    $newUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    echo json_encode(['success' => true, 'message' => 'Account created successfully', 'user' => $newUser]);
} else {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to create account']);
}

$conn->close();