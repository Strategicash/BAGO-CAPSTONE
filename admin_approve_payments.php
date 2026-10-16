<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_id = $_POST['payment_id'];
    $duration = $_POST['duration_days'];

    $stmt = $conn->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), plan_duration_days = ? WHERE id = ?");
    $stmt->bind_param("iii", $duration, $duration, $payment_id);
    $stmt->execute();
    $stmt->close();
    header('Location: admin_approve_payments.php?approved=1');
    exit;
}

$pending = $conn->query("SELECT p.id, p.description, p.amount, p.reference_number, p.proof_file, p.paid_at, u.full_name, u.email FROM payments p JOIN users u ON p.user_id = u.id WHERE p.status = 'pending' ORDER BY p.paid_at ASC");
?>
<!DOCTYPE html>
<html>
<head>
  <title>Approve Payments</title>
  <style>
    body { font-family: sans-serif; max-width: 800px; margin: 40px auto; }
    .card { border: 1px solid #ccc; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
    .card img { max-width: 200px; display: block; margin: 10px 0; }
    select, button { padding: 8px; font-size: 14px; }
    button { background: #2e7d32; color: white; border: none; cursor: pointer; border-radius: 4px; }
    .ref { font-family: monospace; background: #f0f0f0; padding: 4px 8px; border-radius: 4px; }
  </style>
</head>
<body>
  <h2>Pending Payments</h2>
  <?php if (isset($_GET['approved'])): ?><p style="color:green">Payment approved.</p><?php endif; ?>

  <?php if ($pending->num_rows === 0): ?>
    <p>No pending payments.</p>
  <?php endif; ?>

  <?php while ($p = $pending->fetch_assoc()): ?>
    <div class="card">
      <strong><?= htmlspecialchars($p['full_name']) ?></strong> (<?= htmlspecialchars($p['email']) ?>)<br>
      <?= htmlspecialchars($p['description']) ?> — ₱<?= number_format($p['amount'], 2) ?><br>
      Reference #: <span class="ref"><?= htmlspecialchars($p['reference_number'] ?? 'none') ?></span><br>
      <?php if ($p['proof_file']): ?>
        <a href="<?= htmlspecialchars($p['proof_file']) ?>" target="_blank">
          <img src="<?= htmlspecialchars($p['proof_file']) ?>" alt="Proof">
        </a>
      <?php endif; ?>

      <form method="POST" style="margin-top:10px;">
        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
        <select name="duration_days">
          <option value="7">1 Week</option>
          <option value="30" selected>1 Month</option>
          <option value="90">3 Months</option>
          <option value="365">1 Year</option>
        </select>
        <button type="submit">Approve</button>
      </form>
    </div>
  <?php endwhile; ?>
</body>
</html>