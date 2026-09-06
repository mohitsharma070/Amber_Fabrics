<?php
// Exercise the real admin endpoint with isolated session/provider callbacks.
if (PHP_SAPI !== 'cli' || getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1'
    || getenv('APP_MODE') !== 'local' || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) { exit(2); }
putenv('MAIL_DRIVER=log');
putenv('MAIL_FROM=ci@example.test');
putenv('SHIPPING_COURIER_ENABLED=0');
putenv('COD_GUARD_WHATSAPP_PROVIDER=none');
ob_start();
require_once dirname(__DIR__, 2) . '/includes/init.php';
$GLOBALS['amber_hooks'] = [];
$conn->query('SET SESSION innodb_lock_wait_timeout = 15');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/admin/order-view.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'order-transition-test';
$_SESSION['admin_id'] = (int) $argv[4];
$_SESSION['admin_name'] = 'Transition Test';
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['admin_session_started_at'] = time();
$_SESSION['admin_last_seen_at'] = time();
$_SESSION['admin_session_fingerprint'] = admin_session_fingerprint();
$_SESSION['csrf_token'] = 'transition-test-csrf';
$_SESSION['flash'] = [];
$_GET['id'] = (int) $argv[1];
$_POST = ['action' => 'workflow_transition', 'target_status' => $argv[2],
    'expected_status' => $argv[3], 'csrf_token' => 'transition-test-csrf'];
$hook = null;
add_action('order.after_status_change', static function (array $context) use (&$hook): void {
    $config = $GLOBALS['_app_config'];
    $observer = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASSWORD'], $config['DB_NAME'], (int) $config['DB_PORT']);
    $id = (int) $context['order_id'];
    $row = $observer->query("SELECT order_status FROM orders WHERE id = $id")->fetch_assoc();
    $details = $observer->query("SELECT details FROM order_activity_logs WHERE order_id = $id ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $hook = ['previous' => $context['previous_status'], 'committed' =>
        $row['order_status'] === $context['target_status']
        && ($details['details'] ?? '') === 'Order: ' . $context['previous_status'] . ' -> ' . $context['target_status']];
    $observer->close();
});
register_shutdown_function(static function () use (&$hook): void {
    $flash = $_SESSION['flash'] ?? [];
    ob_end_clean();
    echo json_encode(['status' => isset($flash['success']) ? 'success' : (isset($flash['error']) ? 'error' : 'unknown'),
        'message' => $flash['error'] ?? '', 'hook' => $hook]);
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
});
fwrite(STDOUT, $conn->thread_id . "\n");
// Optional baseline allows RED against main without overwriting the worktree.
if (!empty($argv[5])) {
    $source = file_get_contents($argv[5]);
    $source = str_replace('__DIR__', var_export(dirname(__DIR__, 2) . '/admin', true), $source);
    eval('?>' . $source);
} else {
    require dirname(__DIR__, 2) . '/admin/order-view.php';
}
