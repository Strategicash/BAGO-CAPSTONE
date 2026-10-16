<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'red iron');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// One-time: message read tracking (for unread notifications)
$__t = @$conn->query("SHOW TABLES LIKE 'messages'");
if ($__t && $__t->num_rows > 0) {
    $__t->close();
    $__c = $conn->query("SHOW COLUMNS FROM messages LIKE 'read_at'");
    if ($__c && $__c->num_rows === 0) {
        $conn->query("ALTER TABLE messages ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL");
        $conn->query("UPDATE messages SET read_at = sent_at WHERE read_at IS NULL");
    }
    if ($__c) {
        $__c->close();
    }
} elseif ($__t) {
    $__t->close();
}
?>