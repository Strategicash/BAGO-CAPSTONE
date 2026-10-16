<?php
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin')       { header('Location: admin.php');           exit; }
    elseif ($_SESSION['role'] === 'coach')   { header('Location: coach_dashboard.php'); exit; }
    else                                     { header('Location: dashboard.php');        exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club</title>
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
      background: linear-gradient(rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.8)),  url('Fitx.jpg');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      background-repeat: no-repeat;
      position: relative;
      display: flex;
      flex-direction: column;
    }

    /* NAV */
    nav {
      padding: 0 48px;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .logo { font-size: 17px; font-weight: 700; color: var(--text); }
    .logo span { color: var(--accent); }
    .nav-btns { display: flex; gap: 10px; }
    .btn-outline {
      padding: 8px 20px; border-radius: 6px;
      border: 1px solid rgba(255,255,255,0.15);
      color: var(--text); font-family: var(--font);
      font-size: 13px; font-weight: 600;
      background: transparent; cursor: pointer;
      text-decoration: none; display: inline-block;
      transition: all 0.15s;
    }
    .btn-outline:hover { border-color: rgba(255,255,255,0.4); }
    .btn-filled {
      padding: 8px 20px; border-radius: 6px;
      background: var(--accent); color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      border: none; cursor: pointer;
      text-decoration: none; display: inline-block;
      transition: opacity 0.15s;
    }
    .btn-filled:hover { opacity: 0.85; }

    /* HERO */
    .hero {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 60px 24px 80px;
    }
    .hero-badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(59,130,246,0.12);
      border: 1px solid rgba(59,130,246,0.25);
      color: var(--accent); font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 1px;
      padding: 5px 14px; border-radius: 20px; margin-bottom: 24px;
    }
    .hero-title {
      font-size: clamp(36px, 6vw, 64px);
      font-weight: 700;
      line-height: 1.1;
      margin-bottom: 18px;
      max-width: 700px;
    }
    .hero-title span { color: var(--accent); }
    .hero-sub {
      font-size: 16px; color: rgba(255,255,255,0.55);
      max-width: 480px; line-height: 1.7; margin-bottom: 36px;
    }
    .hero-btns { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }
    .btn-hero-primary {
      padding: 13px 32px; border-radius: 8px;
      background: var(--accent); color: #fff;
      font-family: var(--font); font-size: 15px; font-weight: 700;
      border: none; cursor: pointer; text-decoration: none;
      transition: opacity 0.15s; display: inline-block;
    }
    .btn-hero-primary:hover { opacity: 0.85; }
    .btn-hero-secondary {
      padding: 13px 32px; border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.2);
      color: var(--text); background: transparent;
      font-family: var(--font); font-size: 15px; font-weight: 600;
      cursor: pointer; text-decoration: none; display: inline-block;
      transition: all 0.15s;
    }
    .btn-hero-secondary:hover { border-color: rgba(255,255,255,0.5); }

    /* TIERS */
    .tiers-section {
      /* Removed the background overlay here so it blends perfectly with the page-bg */
      padding: 48px 24px;
    }
    .tiers-inner { max-width: 820px; margin: 0 auto; }
    .section-label {
      font-size: 11px; font-weight: 700; color: var(--sub);
      text-transform: uppercase; letter-spacing: 1px;
      text-align: center; margin-bottom: 24px;
    }
    .tiers-grid {
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 1px; background: var(--border);
      border-radius: 10px; overflow: hidden;
    }
    .tier-card { background: var(--card); padding: 28px; }
    .tier-card.premium { border-top: 2px solid var(--accent); }
    .tier-name { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--sub); margin-bottom: 6px; }
    .tier-title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
    .tier-price { font-size: 13px; color: var(--sub); margin-bottom: 20px; }
    .tier-features { list-style: none; display: flex; flex-direction: column; gap: 8px; margin-bottom: 22px; }
    .tier-features li { display: flex; align-items: center; gap: 9px; font-size: 13px; color: var(--sub); }
    .tier-features li.on { color: var(--text); }
    .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .dot.on { background: var(--green); }
    .dot.off { background: var(--border); }
    .tier-btn {
      display: block; text-align: center; padding: 9px;
      border-radius: 6px; font-size: 13px; font-weight: 600;
      text-decoration: none; transition: all 0.15s;
    }
    .tier-btn.outline { border: 1px solid var(--border); color: var(--sub); }
    .tier-btn.outline:hover { border-color: var(--text); color: var(--text); }
    .tier-btn.filled { background: var(--accent); color: #fff; }
    .tier-btn.filled:hover { opacity: 0.85; }

    @media (max-width: 600px) {
      nav { padding: 0 20px; }
      .tiers-grid { grid-template-columns: 1fr; }
      .hero-title { font-size: 32px; }
    }
  </style>
</head>
<body>
<div class="page-bg">

  <nav>
    <div class="logo">FitX <span>Training Club</span></div>
    <div class="nav-btns">
      <a href="login_page.php"    class="btn-outline">Sign In</a>
      <a href="register_page.php" class="btn-filled">Get Started</a>
    </div>
  </nav>

   <div class="hero">
    <br>
    <h1 class="hero-title">Train Smarter with a <span>Professional Coach</span></h1>
    <p class="hero-sub">
      FitX connects you with coaches who build personalized workout and nutrition plans, track your progress, and guide you every step of the way.
    </p>
    <div class="hero-btns">
      <a href="register_page.php" class="btn-hero-primary">Start for Free</a>
      <a href="login_page.php"    class="btn-hero-secondary">Sign In</a>
    </div>
  </div>

  <div class="tiers-section">
    <div class="tiers-inner">
      <div class="section-label">Membership Plans</div>
      <div class="tiers-grid">

        <div class="tier-card">
          <div class="tier-name">Basic</div>
          <div class="tier-title">Standard</div>
          <div class="tier-price">Free with membership</div>
          <ul class="tier-features">
            <li class="on"><span class="dot on"></span>Workout logging</li>
            <li class="on"><span class="dot on"></span>Body metrics tracking</li>
            <li class="on"><span class="dot on"></span>Progress charts</li>
            <li class="on"><span class="dot on"></span>Fitness level profile</li>
            <li><span class="dot off"></span>Assigned coach</li>
            <li><span class="dot off"></span>Custom workout plans</li>
            <li><span class="dot off"></span>Direct messaging</li>
            <li><span class="dot off"></span>Nutrition plans</li>
          </ul>
          <a href="register_page.php" class="tier-btn outline">Get Basic Access →</a>
        </div>

        <div class="tier-card premium">
          <div class="tier-name">Premium</div>
          <div class="tier-title">Coaching</div>
          <div class="tier-price">Paid upgrade — ₱1,500/month</div>
          <ul class="tier-features">
            <li class="on"><span class="dot on"></span>Everything in Basic</li>
            <li class="on"><span class="dot on"></span>Assigned coach</li>
            <li class="on"><span class="dot on"></span>Custom workout program</li>
            <li class="on"><span class="dot on"></span>Personalized nutrition plan</li>
            <li class="on"><span class="dot on"></span>Direct coach messaging</li>
            <li class="on"><span class="dot on"></span>Form feedback & reviews</li>
            <li class="on"><span class="dot on"></span>Advanced periodization</li>
            <li class="on"><span class="dot on"></span>Progress analysis</li>
          </ul>
          <a href="register_page.php?tier=premium" class="tier-btn filled">Upgrade to Premium →</a>
        </div>

      </div>
    </div>
  </div>

</div>
</body>
</html>