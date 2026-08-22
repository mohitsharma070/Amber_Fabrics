<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', 'Invalid logout request.');
    redirect(is_customer_logged_in() ? '/customer/profile' : '/customer/login');
}

// Persistence is best-effort: a database error must never prevent logout.
try {
    if (!empty($_SESSION['customer_id']) && !empty($_SESSION['cart'])) {
        CartService::cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart']);
    }
    if (!empty($_SESSION['customer_id']) && !empty($_SESSION['wishlist'])) {
        wishlist_save_to_db(
            $conn,
            (int) $_SESSION['customer_id'],
            (array) $_SESSION['wishlist'],
            isset($_SESSION['wishlist_meter_length']) && is_array($_SESSION['wishlist_meter_length']) ? $_SESSION['wishlist_meter_length'] : [],
            isset($_SESSION['wishlist_size']) && is_array($_SESSION['wishlist_size']) ? $_SESSION['wishlist_size'] : []
        );
    }
} catch (Throwable $e) {
    error_log('[customer-logout] Could not persist cart/wishlist: ' . $e->getMessage());
}

app_destroy_session(true);
flash('success', 'You have been logged out.');
redirect('/customer/login');
