<?php
header('Content-Type: application/json');
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function generateUniqueCode($conn, $length = 8) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no confusing 0/O, 1/I
    do {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check = $conn->prepare("SELECT id FROM access_codes WHERE code = ?");
        $check->bind_param("s", $code);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
    } while ($exists);
    return $code;
}

$code = generateUniqueCode($conn);

$stmt = $conn->prepare("INSERT INTO access_codes (code) VALUES (?)");
$stmt->bind_param("s", $code);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'code' => $code]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to generate code']);
}

$stmt->close();
$conn->close();