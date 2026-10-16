<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register_page.php');
    exit;
}

$full_name        = trim($_POST['full_name'] ?? '');
$email            = trim($_POST['email'] ?? '');
$fitness_level    = trim($_POST['fitness_level'] ?? '');
$password         = trim($_POST['password'] ?? '');
$confirm_password = trim($_POST['confirm_password'] ?? '');
$tier             = $_POST['selected_tier'] ?? 'basic';
$pay_method       = $_POST['payment_method'] ?? '';

// ── Validation ────────────────────────────────────────────────────────────────
if (empty($full_name) || empty($email) || empty($password)) {
    header('Location: register_page.php?error=Please+fill+in+all+fields');
    exit;
}

if ($password !== $confirm_password) {
    header('Location: register_page.php?error=Passwords+do+not+match');
    exit;
}

if (strlen($password) < 6) {
    header('Location: register_page.php?error=Password+must+be+at+least+6+characters');
    exit;
}

if ($tier === 'premium' && empty($pay_method)) {
    header('Location: register_page.php?error=Please+select+a+payment+method&tier=premium');
    exit;
}

// ── Check duplicate email ─────────────────────────────────────────────────────
$chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
$chk->bind_param("s", $email);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    header('Location: register_page.php?error=Email+already+registered');
    exit;
}

$chk->close();

// ── Insert user ───────────────────────────────────────────────────────────────
$hash = password_hash($password, PASSWORD_BCRYPT);

$actual_tier = $tier === 'premium'
    ? 'premium'
    : 'basic';

$ins = $conn->prepare("
    INSERT INTO users
    (full_name, email, password, fitness_level, tier)
    VALUES (?, ?, ?, ?, ?)
");

$ins->bind_param(
    "sssss",
    $full_name,
    $email,
    $hash,
    $fitness_level,
    $actual_tier
);

$ins->execute();

$new_id = $conn->insert_id;

$ins->close();

// ── If premium: record payment as PAID immediately (auto-approved) ────────────
if ($tier === 'premium' && $pay_method !== '') {

    // ── Upload proof of payment ───────────────────────────────────────────────
    $proof_path = '';

    if (
        isset($_FILES['proof_of_payment']) &&
        $_FILES['proof_of_payment']['error'] === 0
    ) {

        $upload_dir = 'uploads/proofs/';

        // Create uploads folder automatically if missing
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $tmp_name = $_FILES['proof_of_payment']['tmp_name'];

        // Safe filename
        $original_name = basename($_FILES['proof_of_payment']['name']);

        $safe_name = preg_replace(
            '/[^A-Za-z0-9_\.-]/',
            '_',
            $original_name
        );

        $file_name = time() . '_' . $safe_name;

        $target_file = $upload_dir . $file_name;

        // Move uploaded file
        if (move_uploaded_file($tmp_name, $target_file)) {
            $proof_path = $target_file;
        }
    }

    $method_labels = [
        'gcash' => 'GCash',
        'cash'  => 'Cash — Front Desk',
        'bank'  => 'Bank Transfer',
    ];

    $method_label = $method_labels[$pay_method]
        ?? ucfirst($pay_method);

    $desc   = 'Premium Membership — ' . $method_label . ' — ' . date('F Y');
    $amount = 1500.00;
    $now    = date('Y-m-d H:i:s');

    // ── Insert payment WITH proof file ───────────────────────────────────────
    $ps = $conn->prepare("
        INSERT INTO payments
        (
            user_id,
            description,
            amount,
            status,
            proof_file,
            paid_at,
            notified
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'paid',
            ?,
            ?,
            0
        )
    ");

    $ps->bind_param(
        "isdss",
        $new_id,
        $desc,
        $amount,
        $proof_path,
        $now
    );

    $ps->execute();

    $ps->close();
}

// ── Set session ───────────────────────────────────────────────────────────────
$_SESSION['user_id']   = $new_id;
$_SESSION['full_name'] = $full_name;
$_SESSION['role']      = 'member';
$_SESSION['tier']      = $actual_tier;

// ── Redirect ──────────────────────────────────────────────────────────────────
if ($actual_tier === 'premium') {
    header('Location: dashboard.php?welcome=premium');
    exit;
}

header('Location: dashboard.php');
exit;
?>