<?php
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin')       { header('Location: admin.php');           exit; }
    elseif ($_SESSION['role'] === 'coach')   { header('Location: coach_dashboard.php'); exit; }
    else                                     { header('Location: dashboard.php');        exit; }
}

$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Sign In</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #666666; --accent: #f59e0b;
      --accent-dim: rgba(59,130,246,0.12); --green: #22c55e;
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }
    .page-bg {
      min-height: 100vh;
      background-image: url('equipment.jpg.jpg');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      position: relative;
      display: flex;
      flex-direction: column;
    }
    .page-bg::before {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(0,0,0,0.75);
      z-index: 0;
    }
    .page-bg > * { position: relative; z-index: 1; }

    /* NAV */
    nav {
      padding: 0 48px; height: 60px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .logo { font-size: 17px; font-weight: 700; color: var(--text); text-decoration: none; }
    .logo span { color: var(--accent); }
    .nav-link { font-size: 13px; color: rgba(255,255,255,0.5); text-decoration: none; }
    .nav-link strong { color: var(--accent); }

    /* FORM AREA */
    .form-wrap {
      flex: 1; display: flex;
      align-items: center; justify-content: center;
      padding: 40px 24px;
    }
    .auth-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 32px;
      width: 100%;
      max-width: 380px;
    }
    .auth-title { font-size: 20px; font-weight: 700; margin-bottom: 6px; }
    .auth-sub { font-size: 13px; color: var(--sub); margin-bottom: 24px; }

    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 5px; }
    .field input {
      width: 100%; background: var(--surface);
      border: 1px solid var(--border); border-radius: 6px;
      padding: 10px 12px; font-family: var(--font);
      font-size: 13px; color: var(--text); outline: none;
      transition: border-color 0.15s;
    }
    .field input:focus { border-color: var(--accent); }

    .btn-submit {
      width: 100%; background: var(--accent); color: #fff;
      border: none; border-radius: 6px; padding: 11px;
      font-family: var(--font); font-size: 14px; font-weight: 700;
      cursor: pointer; transition: opacity 0.15s; margin-top: 4px;
    }
    .btn-submit:hover { opacity: 0.85; }

    .auth-switch { text-align: center; font-size: 12px; color: var(--sub); margin-top: 16px; }
    .auth-switch a { color: var(--accent); text-decoration: none; font-weight: 600; }

    .alert-err { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); color: #ef4444; font-size: 12px; padding: 9px 12px; border-radius: 6px; margin-bottom: 16px; }
    .alert-ok  { background: rgba(34,197,94,0.1);  border: 1px solid rgba(34,197,94,0.2);  color: #22c55e; font-size: 12px; padding: 9px 12px; border-radius: 6px; margin-bottom: 16px; }

    .divider { display: flex; align-items: center; gap: 10px; margin: 16px 0; }
    .divider::before, .divider::after { content:''; flex:1; height:1px; background:var(--border); }
    .divider span { font-size: 11px; color: var(--sub); }

    @media (max-width: 480px) { nav { padding: 0 20px; } }
  </style>
</head>
<body>
<div class="page-bg">

  <nav>
    <a href="index.php" class="logo">FitX <span>Training Club</span></a>
    <span class="nav-link">No account? <a href="register_page.php"><strong>Register</strong></a></span>
  </nav>

  <div class="form-wrap">
    <div class="auth-card">
      <div class="auth-title">Welcome back</div>
      <div class="auth-sub">Sign in to your FitX account</div>

      <?php if ($error): ?>
        <div class="alert-err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert-ok"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form action="login.php" method="POST">
        <div class="field">
          <label>Email Address</label>
          <input type="email" name="email" placeholder="you@email.com" required autofocus/>
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" placeholder="••••••••" required/>
        </div>
        <button type="submit" class="btn-submit">Sign In</button>
      </form>

      <div class="divider"><span>or</span></div>

      <div class="auth-switch">
        Don't have an account? <a href="register_page.php">Create one</a>
      </div>
    </div>
  </div>

</div>
</body>
</html>