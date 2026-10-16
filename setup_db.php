<?php
$conn = new mysqli('localhost', 'root', '', '');
if ($conn->connect_error) {
    die("<h2 style='color:red'>Connection failed: " . $conn->connect_error . "</h2>");
}

$queries = [

// ── DATABASE ─────────────────────────────────────────────────────────────────
"CREATE DATABASE IF NOT EXISTS `red iron`",
"USE `red iron`",

// ── USERS ─────────────────────────────────────────────────────────────────────
// specialties + bio added for coach profile (my_coach.php)
"CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(100)  NOT NULL,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    password      VARCHAR(255)  NOT NULL,
    role          ENUM('member','coach','admin') DEFAULT 'member',
    tier          ENUM('basic','premium')        DEFAULT 'basic',
    fitness_level ENUM('beginner','intermediate','advanced') DEFAULT 'beginner',
    coach_id      INT           DEFAULT NULL,
    specialties   VARCHAR(255)  DEFAULT NULL,
    bio           TEXT          DEFAULT NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
)",

// ── WORKOUT LOGS ──────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS workout_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL,
    exercise     VARCHAR(100) NOT NULL,
    sets         INT          NOT NULL,
    reps         INT          NOT NULL,
    weight_kg    DECIMAL(6,2) DEFAULT 0,
    muscle_group VARCHAR(50)  DEFAULT NULL,
    logged_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)",

// ── BODY METRICS ──────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS body_metrics (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT          NOT NULL,
    weight_kg      DECIMAL(5,2) DEFAULT NULL,
    height_cm      DECIMAL(5,2) DEFAULT NULL,
    chest_cm       DECIMAL(5,2) DEFAULT NULL,
    right_arm_cm   DECIMAL(5,2) DEFAULT NULL,
    left_arm_cm    DECIMAL(5,2) DEFAULT NULL,
    waist_navel_cm DECIMAL(5,2) DEFAULT NULL,
    waist_below_cm DECIMAL(5,2) DEFAULT NULL,
    hips_cm        DECIMAL(5,2) DEFAULT NULL,
    right_leg_cm   DECIMAL(5,2) DEFAULT NULL,
    left_leg_cm    DECIMAL(5,2) DEFAULT NULL,
    bmi            DECIMAL(4,2) DEFAULT NULL,
    recorded_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)",

// ── WORKOUT PLANS ─────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS workout_plans (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    coach_id       INT          NOT NULL,
    member_id      INT          NOT NULL,
    plan_name      VARCHAR(150) NOT NULL,
    phase          VARCHAR(50)  DEFAULT NULL,
    duration_weeks INT          DEFAULT 4,
    notes          TEXT         DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coach_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
)",

// ── NUTRITION PLANS ───────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS nutrition_plans (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    coach_id     INT          NOT NULL,
    member_id    INT          NOT NULL,
    plan_name    VARCHAR(150) NOT NULL,
    goal         VARCHAR(100) DEFAULT NULL,
    calories     INT          DEFAULT NULL,
    protein_g    INT          DEFAULT NULL,
    carbs_g      INT          DEFAULT NULL,
    fats_g       INT          DEFAULT NULL,
    meals_per_day INT         DEFAULT 3,
    notes        TEXT         DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coach_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
)",

// ── MESSAGES ──────────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sender_id   INT       NOT NULL,
    receiver_id INT       NOT NULL,
    body        TEXT      NOT NULL,
    sent_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at     TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
)",

// ── PAYMENTS ──────────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS payments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT             NOT NULL,
    description VARCHAR(200)    NOT NULL,
    amount      DECIMAL(10,2)   NOT NULL,
    status      ENUM('paid','pending') DEFAULT 'pending',
    paid_at     TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)",

// ── ADD specialties + bio IF TABLE ALREADY EXISTS (safe for existing DBs) ────
// These will silently fail if columns already exist — that's fine
"ALTER TABLE users ADD COLUMN IF NOT EXISTS specialties VARCHAR(255) DEFAULT NULL",
"ALTER TABLE users ADD COLUMN IF NOT EXISTS bio TEXT DEFAULT NULL",

];

// ── Run all queries ───────────────────────────────────────────────────────────
$errors   = 0;
$successes = 0;
foreach ($queries as $q) {
    if ($conn->query($q) === FALSE) {
        // Ignore "duplicate column" errors from ALTER TABLE on fresh installs
        if (strpos($conn->error, 'Duplicate column') === false) {
            echo "<p style='color:red;font-family:sans-serif;'>✗ Error: " . $conn->error . "</p>";
            $errors++;
        }
    } else {
        $successes++;
    }
}

// ── Insert default admin + coach accounts ─────────────────────────────────────
$conn->select_db('red iron');
$hash = password_hash('admin123', PASSWORD_BCRYPT);

$accounts = [
    ['Admin',             'admin@rediron.com',  'admin', 'basic'],
    ['Coach Mark Santos', 'coach@rediron.com',  'coach', 'basic'],
];

foreach ($accounts as $acc) {
    $stmt = $conn->prepare("INSERT IGNORE INTO users (full_name, email, password, role, tier) VALUES (?,?,?,?,?)");
    $stmt->bind_param("sssss", $acc[0], $acc[1], $hash, $acc[2], $acc[3]);
    $stmt->execute();
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>FitX — Database Setup</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background: #0a0a0a; color: #f0f0f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #161616; border: 1px solid #222; border-radius: 10px; padding: 36px 40px; max-width: 480px; width: 100%; }
    h2 { font-size: 20px; margin-bottom: 6px; }
    h2.ok  { color: #22c55e; }
    h2.err { color: #ef4444; }
    .sub { font-size: 13px; color: #888; margin-bottom: 24px; }
    .account { background: #111; border: 1px solid #222; border-radius: 7px; padding: 12px 16px; margin-bottom: 10px; font-size: 13px; }
    .account .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #555; margin-bottom: 4px; }
    .account .val { color: #f0f0f0; font-weight: 600; }
    .account .pass { color: #22c55e; }
    .tables { margin: 16px 0; }
    .table-item { font-size: 12px; color: #22c55e; padding: 3px 0; }
    .btn { display: block; text-align: center; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 6px; padding: 10px; font-size: 13px; font-weight: 700; margin-top: 20px; }
    .warn { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); color: #f59e0b; font-size: 12px; padding: 10px 14px; border-radius: 6px; margin-top: 14px; }
  </style>
</head>
<body>
<div class="box">
  <?php if ($errors === 0): ?>
    <h2 class="ok">✓ Database Ready</h2>
    <div class="sub">All tables created successfully for FitX Training Club.</div>
  <?php else: ?>
    <h2 class="err">⚠ Setup completed with <?= $errors ?> error(s)</h2>
    <div class="sub">Check the errors above. Some tables may not have been created.</div>
  <?php endif; ?>

  <div class="tables">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#555;margin-bottom:8px;">Tables Created</div>
    <div class="table-item">✓ users (with specialties + bio)</div>
    <div class="table-item">✓ workout_logs</div>
    <div class="table-item">✓ body_metrics</div>
    <div class="table-item">✓ workout_plans</div>
    <div class="table-item">✓ nutrition_plans</div>
    <div class="table-item">✓ messages</div>
    <div class="table-item">✓ payments</div>
    <div class="table-item" style="color:var(--muted)">○ gym_settings — auto-created by settings.php on first visit</div>
  </div>

  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#555;margin-bottom:8px;">Default Accounts</div>

  <div class="account">
    <div class="label">Admin</div>
    <div class="val">admin@rediron.com</div>
    <div class="pass">admin123</div>
  </div>
  <div class="account">
    <div class="label">Coach</div>
    <div class="val">coach@rediron.com</div>
    <div class="pass">admin123</div>
  </div>

  <div class="warn">
    ⚠ Delete or restrict access to this file after setup. Anyone who runs it can reset your database.
  </div>

  <a href="index.php" class="btn">Go to Login →</a>
</div>
</body>
</html>