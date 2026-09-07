<?php
define("AMBER_TESTING", true);
$root = dirname(__DIR__, 2);
require $root . "/config/db.php";
require_once $root . "/includes/functions.php";

$appRequestId = "test-req";
require_once $root . "/includes/security/customer-auth.php";

// Mock required variables for admin/returns.php
session_start();
$conn->query("INSERT INTO admins (id, name, email, is_active, role) VALUES (1, 'Tester', 'tester@amber.test', 1, 'super_admin') ON DUPLICATE KEY UPDATE is_active = 1, role = 'super_admin'");
$_SESSION["admin_id"] = 1;
$_SESSION["admin_name"] = "Tester";
$_SESSION["admin_role"] = "super_admin";
$_SESSION["admin_session_started_at"] = time();
$_SESSION["admin_last_seen_at"] = time();
$_SESSION["admin_session_fingerprint"] = hash('sha256', 'v2|');
$_SESSION["csrf_token"] = "testtoken";
$_POST["csrf_token"] = "testtoken";

$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["SCRIPT_NAME"] = "/admin/returns.php";
$_POST["action"] = "";
$_POST["return_id"] = (int)($argv[1] ?? 0);
$_POST["status"] = $argv[2] ?? "";
$_POST["refund_amount"] = $argv[3] ?? "0";
$_POST["admin_note"] = "Test return";
$_SESSION["csrf_token"] = "testtoken";
$_POST["csrf_token"] = "testtoken";

// We need to bypass `redirect` so it doesnt exit our worker entirely if we want to return something.
// Actually, `exit` is fine, we just want the DB to be updated.
try {
    require $root . "/admin/returns.php";
} catch (Throwable $e) {
}


