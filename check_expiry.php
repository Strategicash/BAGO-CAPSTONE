<?php
/**
 * check_expiry.php
 * Checks if the current premium member's subscription has expired
 * and downgrades them to basic automatically.
 * 
 * Only runs if expires_at is set (timer started = coach assigned).
 * Safe against pending renewal payments.
 */

// Block unauthenticated access or improper execution context
if (!isset($conn) || !isset($_SESSION['user_id'])) {
    return;
}

// Only process users currently flagged as premium in the session
if (($_SESSION['tier'] ?? '') !== 'premium') {
    return;
}

$uid = $_SESSION['user_id'];

// ── 1. RENEWAL PROTECTION ─────────────────────────────────────────────────
// Check if the user has already paid for a renewal that is awaiting coach setup.
// If a pending paid invoice exists, do not downgrade them.
$pending_query = $conn->prepare("
    SELECT id 
    FROM payments 
    WHERE user_id = ? AND status = 'paid' AND expires_at IS NULL 
    LIMIT 1
");
$pending_query->bind_param("i", $uid);
$pending_query->execute();
$has_pending_renewal = $pending_query->get_result()->fetch_assoc();
$pending_query->close();

if ($has_pending_renewal) {
    return; // Freeze expiration; user has secured their next period
}

// ── 2. EXPIRY EVALUATION ──────────────────────────────────────────────────
// Find the most recent active subscription period
$r = $conn->prepare("
    SELECT id, expires_at
    FROM payments
    WHERE user_id = ? AND status = 'paid' AND expires_at IS NOT NULL
    ORDER BY expires_at DESC
    LIMIT 1
");
$r->bind_param("i", $uid);
$r->execute();
$pay = $r->get_result()->fetch_assoc();
$r->close();

// If no active timer exists, safely bail
if (!$pay || !$pay['expires_at']) {
    return;
}

// ── 3. DOWNGRADE EXECUTION ────────────────────────────────────────────────
// Evaluate if the expiration timestamp is behind the current server time
if (strtotime($pay['expires_at']) < time()) {
    
    // Use an atomic database transaction to prevent partial data updates
    $conn->begin_transaction();
    
    try {
        // Revert user to basic tier and dissolve coach mapping
        $s = $conn->prepare("UPDATE users SET tier = 'basic', coach_id = NULL WHERE id = ?");
        $s->bind_param("i", $uid);
        $s->execute();
        $s->close();
        
        $conn->commit();
        
        // Immediately sync local session context
        $_SESSION['tier'] = 'basic';
        
        // Route the user out of premium interfaces with an clear context notice
        $current_page = basename($_SERVER['PHP_SELF']);
        if ($current_page !== 'billing.php' && $current_page !== 'logout.php') {
            header('Location: billing.php?expired=1');
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        // Fail silently in production environment to avoid breaking layout execution
    }
}
?>