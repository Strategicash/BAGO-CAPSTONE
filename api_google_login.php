<?php
header('Content-Type: application/json');
require_once 'config.php';

// Google Sign-In exchange: Flutter sends the verified Google email + name,
// we return the matching `users` row (backend user_id). Auto-creates a
// member row on first sign-in so dashboard api_get_* endpoints work.
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$email = trim($data['email'] ?? '');
$fullName = trim($data['full_name'] ?? $data['name'] ?? $data['display_name'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Valid email required']);
    exit;
}

if ($fullName === '') {
    $atPos = strpos($email, '@');
    $fullName = ($atPos !== false && $atPos > 0) ? substr($email, 0, $atPos) : $email;
}

// 1. Existing user? Return the canonical row (id = backend user_id).
$stmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo json_encode(['success' => true, 'user' => $user, 'is_new' => false]);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

// 2. First-time Google user: INSERT a member row. `password` is NOT NULL in
// this schema, so store a random unusable hash (login stays Google-only).
$randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
$role = 'member';

$insert = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
$insert->bind_param("ssss", $fullName, $email, $randomPassword, $role);

if (!$insert->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to create account']);
    $insert->close();
    $conn->close();
    exit;
}
$newId = $conn->insert_id;
$insert->close();

// 3. Re-select to return the exact stored row.
$refetch = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE id = ?");
$refetch->bind_param("i", $newId);
$refetch->execute();
$user = $refetch->get_result()->fetch_assoc();

echo json_encode(['success' => true, 'user' => $user, 'is_new' => true]);

$refetch->close();
$conn->close();
