<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login_page.php'); exit;
}

$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($email) || empty($password)) {
    header('Location: login_page.php?error=Please+fill+in+all+fields'); exit;
}

$stmt = $conn->prepare("SELECT id, full_name, password, role, tier FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['tier']      = $user['tier'];

    if ($user['role'] === 'admin')       { header('Location: admin.php');           exit; }
    elseif ($user['role'] === 'coach')   { header('Location: coach_dashboard.php'); exit; }
    else                                 { header('Location: dashboard.php');        exit; }
} else {
    header('Location: login_page.php?error=Invalid+email+or+password'); exit;
}
?>