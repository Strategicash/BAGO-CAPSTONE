<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin')     { header('Location: admin.php');           exit; }
    elseif ($_SESSION['role'] === 'coach') { header('Location: coach_dashboard.php'); exit; }
    else                                   { header('Location: dashboard.php');        exit; }
}

$error          = $_GET['error'] ?? '';
$preselect_tier = $_GET['tier']  ?? 'basic';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FitX Training Club — Register</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0a0a; --surface: #111111; --card: #161616; --border: #222222;
      --text: #f0f0f0; --sub: #666; --accent: #f59e0b;
      --accent-dim: rgba(245,158,11,0.1); --green: #22c55e;
      --green-dim: rgba(34,197,94,0.1); --blue: #3b82f6;
      --blue-dim: rgba(59,130,246,0.1); --red: #ef4444;
      --font: 'Plus Jakarta Sans', sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }
    .page-bg { min-height: 100vh; background-image: url('back.jpg'); background-size: cover; background-position: center; background-attachment: fixed; position: relative; display: flex; flex-direction: column; }
    .page-bg::before { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,0.78); z-index: 0; }
    .page-bg > * { position: relative; z-index: 1; }
    nav { padding: 0 48px; height: 60px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .logo { font-size: 17px; font-weight: 700; color: var(--text); text-decoration: none; }
    .logo span { color: var(--accent); }
    .form-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 36px 24px; gap: 24px; flex-wrap: wrap; }

    /* AUTH CARD */
    .auth-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 30px; width: 100%; max-width: 420px; flex-shrink: 0; }
    .auth-title { font-size: 20px; font-weight: 700; margin-bottom: 5px; }
    .auth-sub { font-size: 13px; color: var(--sub); margin-bottom: 22px; }
    .alert-err { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); color: #ef4444; font-size: 12px; padding: 9px 12px; border-radius: 6px; margin-bottom: 14px; }

    /* TIER TOGGLE */
    .tier-toggle { display: flex; background: var(--surface); border-radius: 8px; padding: 3px; margin-bottom: 18px; gap: 3px; }
    .tier-btn { flex: 1; padding: 8px; border: none; background: transparent; border-radius: 6px; font-family: var(--font); font-size: 12px; font-weight: 600; color: var(--sub); cursor: pointer; transition: all 0.15s; }
    .tier-btn.active  { background: var(--border); color: var(--text); }
    .tier-btn.premium { background: var(--accent); color: #fff; }

    /* FIELDS */
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 5px; }
    .field input, .field select { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 10px 12px; font-family: var(--font); font-size: 13px; color: var(--text); outline: none; transition: border-color 0.15s; }
    .field input:focus, .field select:focus { border-color: var(--accent); }
    .field select option { background: var(--surface); }

    /* PAYMENT METHOD SELECTION */
    .pay-section { display: none; margin-bottom: 14px; }
    .pay-section.show { display: block; }
    .pay-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 8px; }
    .pay-opts { display: flex; flex-direction: column; gap: 7px; }
    .pay-opt { display: flex; align-items: center; gap: 12px; background: var(--surface); border: 2px solid var(--border); border-radius: 7px; padding: 11px 14px; cursor: pointer; transition: all 0.15s; }
    .pay-opt:hover { border-color: var(--accent); }
    .pay-opt.selected { border-color: var(--accent); background: var(--accent-dim); }
    .pay-opt input[type="radio"] { accent-color: var(--accent); width: 15px; height: 15px; flex-shrink: 0; }
    .pay-name { font-size: 13px; font-weight: 600; }
    .pay-detail { font-size: 11px; color: var(--sub); }
    .pay-amount { margin-left: auto; font-size: 14px; font-weight: 700; color: var(--accent); }

    /* UPLOAD PROOF SECTION */
    .upload-section { display: none; margin-bottom: 14px; }
    .upload-section.show { display: block; }
    .upload-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .upload-required { font-size: 9px; font-weight: 700; background: rgba(239,68,68,0.15); color: #f87171; padding: 2px 6px; border-radius: 3px; text-transform: uppercase; letter-spacing: 0.5px; }
    .upload-box { border: 2px dashed var(--border); border-radius: 8px; padding: 20px 16px; text-align: center; cursor: pointer; transition: all 0.15s; position: relative; background: var(--surface); }
    .upload-box:hover { border-color: var(--accent); background: var(--accent-dim); }
    .upload-box.has-file { border-color: var(--green); border-style: solid; background: var(--green-dim); }
    .upload-box.error-border { border-color: var(--red); background: rgba(239,68,68,0.05); }
    .upload-box input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
    .upload-icon { font-size: 26px; margin-bottom: 6px; }
    .upload-text { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 3px; }
    .upload-sub { font-size: 11px; color: var(--sub); }

    /* PREVIEW */
    .upload-preview { display: none; margin-top: 10px; border-radius: 7px; overflow: hidden; border: 1px solid var(--border); }
    .upload-preview img { width: 100%; max-height: 180px; object-fit: cover; display: block; }
    .upload-preview-name { font-size: 11px; color: var(--sub); padding: 7px 10px; background: var(--surface); display: flex; align-items: center; justify-content: space-between; }
    .upload-preview-remove { font-size: 11px; color: #f87171; cursor: pointer; background: none; border: none; font-family: var(--font); padding: 0; }

    /* UPLOAD NOTICE */
    .upload-notice { display: none; margin-top: 6px; font-size: 11px; color: #f87171; font-weight: 600; }
    .upload-notice.show { display: block; }

    /* SUBMIT */
    .btn-submit { width: 100%; background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 11px; font-family: var(--font); font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.15s; margin-top: 6px; }
    .btn-submit:hover { opacity: 0.85; }
    .btn-submit:disabled { opacity: 0.4; cursor: not-allowed; }
    .auth-switch { text-align: center; font-size: 12px; color: var(--sub); margin-top: 14px; }
    .auth-switch a { color: var(--accent); text-decoration: none; font-weight: 600; }

    /* RECEIPT PANEL */
    .receipt-panel { width: 320px; flex-shrink: 0; display: none; }
    .receipt-panel.show { display: block; }
    .receipt-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; position: sticky; top: 36px; }
    .receipt-header { background: linear-gradient(135deg, #1a0a00, #2a1200); padding: 18px 20px; border-bottom: 1px solid var(--border); }
    .receipt-header-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .receipt-logo { font-size: 13px; font-weight: 700; color: var(--text); }
    .receipt-logo span { color: var(--accent); }
    .receipt-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; background: var(--accent); color: #fff; padding: 2px 8px; border-radius: 4px; }
    .receipt-title { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
    .receipt-amount { font-size: 28px; font-weight: 700; color: var(--accent); letter-spacing: -0.5px; }
    .receipt-period { font-size: 11px; color: var(--sub); margin-top: 2px; }
    .receipt-body { padding: 18px 20px; }
    .receipt-method-name { font-size: 14px; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
    .gcash-number { font-size: 24px; font-weight: 700; letter-spacing: 2px; color: var(--text); text-align: center; font-family: monospace; margin-bottom: 4px; }
    .gcash-name { font-size: 13px; color: var(--sub); text-align: center; margin-bottom: 14px; }
    .step-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
    .step-row { display: flex; align-items: flex-start; gap: 9px; font-size: 12px; color: var(--muted, #999); }
    .step-num { width: 20px; height: 20px; border-radius: 50%; background: var(--accent); color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
    .bank-row { display: flex; justify-content: space-between; background: var(--surface); border-radius: 6px; padding: 8px 12px; margin-bottom: 6px; }
    .bank-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--sub); }
    .bank-value { font-size: 13px; font-weight: 600; color: var(--text); font-family: monospace; }
    .cash-box { background: var(--surface); border-radius: 8px; padding: 14px; font-size: 13px; color: var(--sub); line-height: 1.7; margin-bottom: 14px; }
    .cash-box strong { color: var(--text); display: block; margin-bottom: 6px; font-size: 14px; }
    .receipt-divider { height: 1px; background: var(--border); margin: 14px 0; }
    .screenshot-tip { background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2); border-radius: 7px; padding: 10px 14px; font-size: 12px; color: var(--green); line-height: 1.6; }
    .screenshot-tip strong { display: block; margin-bottom: 3px; }
    .receipt-footer { font-size: 11px; color: var(--sub); text-align: center; margin-top: 12px; }

    @media (max-width: 820px) { .form-wrap { flex-direction: column; align-items: center; } .receipt-panel { width: 100%; max-width: 420px; } .receipt-card { position: static; } }
    @media (max-width: 480px) { nav { padding: 0 20px; } }
  </style>
</head>
<body>
<div class="page-bg">
  <nav>
    <a href="index.php" class="logo">FitX <span>Training Club</span></a>
  </nav>

  <div class="form-wrap">

    <!-- REGISTRATION FORM -->
    <div class="auth-card">
      <div class="auth-title">Create your account</div>
      <div class="auth-sub">Join FitX Training Club today</div>

      <?php if ($error): ?>
        <div class="alert-err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- TIER TOGGLE -->
      <div class="tier-toggle">
        <button type="button" class="tier-btn <?= $preselect_tier !== 'premium' ? 'active' : '' ?>" onclick="setTier('basic')">🆓 Basic — Free</button>
        <button type="button" class="tier-btn <?= $preselect_tier === 'premium' ? 'premium' : '' ?>" onclick="setTier('premium')">⭐ Premium — ₱1,500/mo</button>
      </div>

      <form action="register.php" method="POST" enctype="multipart/form-data" id="reg-form">
        <input type="hidden" name="selected_tier"  id="hidden-tier" value="<?= $preselect_tier ?>"/>
        <input type="hidden" name="payment_method" id="hidden-pay"  value=""/>

        <div class="field"><label>Full Name</label><input type="text" name="full_name" placeholder="Juan dela Cruz" required/></div>
        <div class="field"><label>Email Address</label><input type="email" name="email" placeholder="you@email.com" required/></div>
        <div class="field">
          <label>Fitness Level</label>
          <select name="fitness_level" required>
            <option value="" disabled selected>Select level</option>
            <option value="beginner">Beginner</option>
            <option value="intermediate">Intermediate</option>
            <option value="advanced">Advanced</option>
          </select>
        </div>
        <div class="field"><label>Password</label><input type="password" name="password" placeholder="Create a password" required/></div>
        <div class="field"><label>Confirm Password</label><input type="password" name="confirm_password" placeholder="Repeat password" required/></div>

        <!-- PAYMENT METHOD (premium only) -->
        <div class="pay-section <?= $preselect_tier === 'premium' ? 'show' : '' ?>" id="pay-section">
          <div class="pay-lbl">Select Payment Method</div>
          <div class="pay-opts">
            <label class="pay-opt" id="opt-gcash" onclick="selPay('gcash')">
              <input type="radio" name="pay_display" value="gcash"/>
              <div><div class="pay-name">💙 GCash</div><div class="pay-detail">Send to our GCash number</div></div>
              <div class="pay-amount">₱1,500</div>
            </label>
            <label class="pay-opt" id="opt-cash" onclick="selPay('cash')">
              <input type="radio" name="pay_display" value="cash"/>
              <div><div class="pay-name">💵 Cash — Front Desk</div><div class="pay-detail">Pay in person at the gym</div></div>
              <div class="pay-amount">₱1,500</div>
            </label>
            <label class="pay-opt" id="opt-bank" onclick="selPay('bank')">
              <input type="radio" name="pay_display" value="bank"/>
              <div><div class="pay-name">🏦 Bank Transfer</div><div class="pay-detail">BDO savings account</div></div>
              <div class="pay-amount">₱1,500</div>
            </label>
          </div>
        </div>

        <!-- PROOF OF PAYMENT UPLOAD (appears after payment method selected) -->
        <div class="upload-section" id="upload-section">
          <div class="upload-lbl">
            Proof of Payment
            <span class="upload-required">Required</span>
          </div>
          <div class="upload-box" id="upload-box"
               ondragover="event.preventDefault();this.classList.add('hover')"
               ondragleave="this.classList.remove('hover')"
               ondrop="handleDrop(event)">
            <input type="file" name="proof_of_payment" id="proof-input"
                   accept="image/*,.pdf"
                   onchange="handleFile(this)"/>
            <div class="upload-icon" id="upload-icon">📎</div>
            <div class="upload-text" id="upload-text">Upload your receipt or screenshot</div>
            <div class="upload-sub" id="upload-sub">PNG, JPG, PDF — max 5MB · Click or drag & drop</div>
          </div>
          <div class="upload-preview" id="upload-preview">
            <img id="preview-img" src="" alt="Receipt preview"/>
            <div class="upload-preview-name">
              <span id="preview-filename"></span>
              <button type="button" class="upload-preview-remove" onclick="removeFile()">✕ Remove</button>
            </div>
          </div>
          <div class="upload-notice" id="upload-notice">
            ⚠ Please upload your proof of payment before registering.
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn" onclick="return validateForm()">
          <?= $preselect_tier === 'premium' ? 'Register as Premium' : 'Create Account' ?>
        </button>
      </form>

      <div class="auth-switch">Already have an account? <a href="login_page.php">Sign in</a></div>
    </div>

    <!-- RECEIPT PANEL -->
    <div class="receipt-panel" id="receipt-panel">

      <!-- GCash -->
      <div class="receipt-card" id="receipt-gcash" style="display:none;">
        <div class="receipt-header">
          <div class="receipt-header-top"><div class="receipt-logo">FitX <span>Training Club</span></div><div class="receipt-badge">Premium</div></div>
          <div class="receipt-title">GCash Payment</div>
          <div class="receipt-amount">₱1,500.00</div>
          <div class="receipt-period">30-day Premium Coaching Access</div>
        </div>
        <div class="receipt-body">
          <div class="receipt-method-name">💙 Send via GCash</div>
          <div class="gcash-number">0955 946 0103</div>
          <div class="gcash-name">FitX Training Club</div>
          <div class="step-list">
            <div class="step-row"><div class="step-num">1</div><span>Open GCash → tap <strong>Send Money</strong></span></div>
            <div class="step-row"><div class="step-num">2</div><span>Enter <strong>0955 946 0103</strong> — FitX Training Club</span></div>
            <div class="step-row"><div class="step-num">3</div><span>Send <strong>₱1,500.00</strong> and complete</span></div>
            <div class="step-row"><div class="step-num">4</div><span>Screenshot your GCash confirmation</span></div>
            <div class="step-row"><div class="step-num">5</div><span>Upload the screenshot below and register</span></div>
          </div>
          <div class="receipt-divider"></div>
          <div class="screenshot-tip"><strong>📸 Screenshot required!</strong>Take a screenshot of your GCash confirmation and upload it in the form on the left before registering.</div>
          <div class="receipt-footer">Premium activates instantly after registration.</div>
        </div>
      </div>

      <!-- Cash -->
      <div class="receipt-card" id="receipt-cash" style="display:none;">
        <div class="receipt-header">
          <div class="receipt-header-top"><div class="receipt-logo">FitX <span>Training Club</span></div><div class="receipt-badge">Premium</div></div>
          <div class="receipt-title">Cash Payment</div>
          <div class="receipt-amount">₱1,500.00</div>
          <div class="receipt-period">30-day Premium Coaching Access</div>
        </div>
        <div class="receipt-body">
          <div class="receipt-method-name">💵 Pay at Front Desk</div>
          <div class="cash-box"><strong>Visit the Gym Reception</strong>Pay ₱1,500 cash and give staff your registered email. Ask for an official receipt.</div>
          <div class="step-list">
            <div class="step-row"><div class="step-num">1</div><span>Visit the gym and pay <strong>₱1,500</strong> cash</span></div>
            <div class="step-row"><div class="step-num">2</div><span>Give staff your <strong>email address</strong></span></div>
            <div class="step-row"><div class="step-num">3</div><span>Get your official receipt from cashier</span></div>
            <div class="step-row"><div class="step-num">4</div><span>Take a <strong>photo</strong> of the receipt</span></div>
            <div class="step-row"><div class="step-num">5</div><span>Upload the photo below and register</span></div>
          </div>
          <div class="receipt-divider"></div>
          <div class="screenshot-tip"><strong>📷 Photo required!</strong>Take a clear photo of your cash receipt and upload it in the form before registering.</div>
          <div class="receipt-footer">Premium activates instantly after registration.</div>
        </div>
      </div>

      <!-- Bank -->
      <div class="receipt-card" id="receipt-bank" style="display:none;">
        <div class="receipt-header">
          <div class="receipt-header-top"><div class="receipt-logo">FitX <span>Training Club</span></div><div class="receipt-badge">Premium</div></div>
          <div class="receipt-title">Bank Transfer</div>
          <div class="receipt-amount">₱1,500.00</div>
          <div class="receipt-period">30-day Premium Coaching Access</div>
        </div>
        <div class="receipt-body">
          <div class="receipt-method-name">🏦 BDO Bank Transfer</div>
          <div class="bank-row"><span class="bank-label">Bank</span><span class="bank-value">BDO Savings</span></div>
          <div class="bank-row"><span class="bank-label">Account Name</span><span class="bank-value">FitX Training Club</span></div>
          <div class="bank-row"><span class="bank-label">Account No.</span><span class="bank-value">0967 639 0528</span></div>
          <div class="bank-row"><span class="bank-label">Amount</span><span class="bank-value" style="color:var(--accent)">₱1,500.00</span></div>
          <div class="step-list" style="margin-top:12px;">
            <div class="step-row"><div class="step-num">1</div><span>Open banking app → Transfer</span></div>
            <div class="step-row"><div class="step-num">2</div><span>Enter <strong>0967 639 0528</strong></span></div>
            <div class="step-row"><div class="step-num">3</div><span>Transfer exactly <strong>₱1,500.00</strong></span></div>
            <div class="step-row"><div class="step-num">4</div><span>Screenshot transfer confirmation</span></div>
            <div class="step-row"><div class="step-num">5</div><span>Upload the screenshot and register</span></div>
          </div>
          <div class="receipt-divider"></div>
          <div class="screenshot-tip"><strong>📸 Screenshot required!</strong>Screenshot your bank transfer confirmation and upload it in the form before registering.</div>
          <div class="receipt-footer">Premium activates instantly after registration.</div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
  let currentTier  = '<?= $preselect_tier ?>';
  let selectedPay  = '';
  let hasFile      = false;

  function setTier(tier) {
    currentTier = tier;
    document.getElementById('hidden-tier').value = tier;
    document.getElementById('hidden-pay').value  = '';
    selectedPay = '';

    const btns = document.querySelectorAll('.tier-btn');
    btns[0].className = 'tier-btn' + (tier === 'basic'   ? ' active'   : '');
    btns[1].className = 'tier-btn' + (tier === 'premium' ? ' premium'  : '');

    document.getElementById('pay-section').classList.toggle('show', tier === 'premium');

    if (tier === 'basic') {
      document.getElementById('receipt-panel').classList.remove('show');
      document.getElementById('upload-section').classList.remove('show');
      hideAllReceipts();
      document.querySelectorAll('.pay-opt').forEach(o => o.classList.remove('selected'));
      document.getElementById('submit-btn').textContent = 'Create Account';
      document.getElementById('submit-btn').disabled = false;
    } else {
      document.getElementById('submit-btn').textContent = 'Register as Premium';
      updateSubmitState();
    }
  }

  function selPay(method) {
    selectedPay = method;
    document.getElementById('hidden-pay').value = method;

    document.querySelectorAll('.pay-opt').forEach(o => o.classList.remove('selected'));
    document.getElementById('opt-' + method).classList.add('selected');

    hideAllReceipts();
    document.getElementById('receipt-' + method).style.display = 'block';
    document.getElementById('receipt-panel').classList.add('show');

    // Show upload section
    document.getElementById('upload-section').classList.add('show');

    updateSubmitState();
  }

  function hideAllReceipts() {
    ['gcash','cash','bank'].forEach(m => {
      document.getElementById('receipt-' + m).style.display = 'none';
    });
  }

  function handleFile(input) {
    const file = input.files[0];
    if (!file) { hasFile = false; updateSubmitState(); return; }

    // Validate size
    if (file.size > 5 * 1024 * 1024) {
      alert('File is too large. Maximum size is 5MB.');
      input.value = '';
      hasFile = false;
      updateSubmitState();
      return;
    }

    hasFile = true;
    const box = document.getElementById('upload-box');
    box.classList.add('has-file');
    box.classList.remove('error-border');
    document.getElementById('upload-notice').classList.remove('show');

    // Show filename
    document.getElementById('preview-filename').textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';

    // Show image preview if image
    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = e => {
        document.getElementById('preview-img').src = e.target.result;
        document.getElementById('preview-img').style.display = 'block';
        document.getElementById('upload-preview').style.display = 'block';
      };
      reader.readAsDataURL(file);
    } else {
      // PDF — show name only
      document.getElementById('preview-img').style.display = 'none';
      document.getElementById('upload-preview').style.display = 'block';
    }

    // Update upload box text
    document.getElementById('upload-icon').textContent = '✅';
    document.getElementById('upload-text').textContent  = 'Receipt uploaded!';
    document.getElementById('upload-sub').textContent   = 'Click or drag to replace';

    updateSubmitState();
  }

  function handleDrop(e) {
    e.preventDefault();
    document.getElementById('upload-box').classList.remove('hover');
    const file = e.dataTransfer.files[0];
    if (file) {
      const input = document.getElementById('proof-input');
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      handleFile(input);
    }
  }

  function removeFile() {
    document.getElementById('proof-input').value = '';
    document.getElementById('upload-preview').style.display = 'none';
    document.getElementById('preview-img').src = '';
    document.getElementById('upload-box').classList.remove('has-file');
    document.getElementById('upload-icon').textContent = '📎';
    document.getElementById('upload-text').textContent  = 'Upload your receipt or screenshot';
    document.getElementById('upload-sub').textContent   = 'PNG, JPG, PDF — max 5MB · Click or drag & drop';
    hasFile = false;
    updateSubmitState();
  }

  function updateSubmitState() {
    const btn = document.getElementById('submit-btn');
    if (currentTier !== 'premium') {
      btn.disabled = false;
      btn.textContent = 'Create Account';
      return;
    }
    // Premium: need method + file
    const ready = selectedPay !== '' && hasFile;
    btn.disabled = !ready;
    if (!selectedPay) {
      btn.textContent = 'Select a payment method';
    } else if (!hasFile) {
      btn.textContent = 'Upload proof of payment to continue';
    } else {
      btn.textContent = 'Register as Premium ✓';
    }
  }

  function validateForm() {
    if (currentTier !== 'premium') return true;

    if (!selectedPay) {
      alert('Please select a payment method.');
      return false;
    }
    if (!hasFile) {
      document.getElementById('upload-box').classList.add('error-border');
      document.getElementById('upload-notice').classList.add('show');
      document.getElementById('upload-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    return true;
  }

  // Init
  <?php if ($preselect_tier === 'premium'): ?>
  updateSubmitState();
  <?php endif; ?>
</script>
</body>
</html>