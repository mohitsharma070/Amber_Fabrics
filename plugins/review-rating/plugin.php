<?php

add_action('app.init', 'review_rating_maybe_handle_submit', 20);
add_action('product.details.after', 'review_rating_render_product_block', 20);

function review_rating_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('review-rating', 'enabled', 1) === 1,
        'auto_approve' => (int) plugin_setting('review-rating', 'auto_approve', 1) === 1,
        'min_length' => max(5, (int) plugin_setting('review-rating', 'min_length', 10)),
        'max_length' => max(50, (int) plugin_setting('review-rating', 'max_length', 800)),
    ];
}

function review_rating_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'product_reviews'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[review-rating] table check failed: ' . $e->getMessage());
        return false;
    }
}

function review_rating_clean_text(string $text, int $maxLength): string
{
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function review_rating_customer_can_review(mysqli $conn, int $customerId, int $productId): bool
{
    if ($customerId <= 0 || $productId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT oi.id
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.customer_id = ?
           AND oi.product_id = ?
           AND o.order_status = 'delivered'
           AND (
                o.payment_method = 'cod'
                OR o.payment_status = 'paid'
           )
         LIMIT 1"
    );
    $stmt->bind_param('ii', $customerId, $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (bool) $row;
}

function review_rating_save(mysqli $conn, int $customerId, int $productId, int $rating, string $reviewText, bool $autoApprove): void
{
    $status = $autoApprove ? 'approved' : 'pending';
    $stmt = $conn->prepare(
        "INSERT INTO product_reviews
            (product_id, customer_id, rating, review_text, status, reviewed_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            review_text = VALUES(review_text),
            status = VALUES(status),
            reviewed_at = NOW(),
            updated_at = NOW()"
    );
    $stmt->bind_param('iiiss', $productId, $customerId, $rating, $reviewText, $status);
    $stmt->execute();
}

function review_rating_maybe_handle_submit(array $context): void
{
    $settings = review_rating_settings();
    if (!$settings['enabled']) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        return;
    }
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli || !review_rating_table_ready($conn)) {
        return;
    }

    $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $uriPath = is_string($uriPath) ? rtrim($uriPath, '/') : '';
    if ($uriPath !== '/review-rating-submit.php') {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('/catalog.php');
    }
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('/catalog.php');
    }

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    if ($customerId <= 0) {
        flash('error', 'Please login to submit review.');
        redirect('/customer/login.php');
    }

    $productId = (int) ($_POST['product_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $reviewText = review_rating_clean_text((string) ($_POST['review_text'] ?? ''), (int) $settings['max_length']);

    if ($productId <= 0) {
        flash('error', 'Invalid product selected.');
        redirect('/catalog.php');
    }
    if ($rating < 1 || $rating > 5) {
        flash('error', 'Please select rating between 1 and 5.');
        redirect('/fabric.php?id=' . $productId);
    }
    if (strlen($reviewText) < (int) $settings['min_length']) {
        flash('error', 'Review must be at least ' . (int) $settings['min_length'] . ' characters.');
        redirect('/fabric.php?id=' . $productId);
    }
    if (!review_rating_customer_can_review($conn, $customerId, $productId)) {
        flash('error', 'You can review only purchased products.');
        redirect('/fabric.php?id=' . $productId);
    }

    review_rating_save($conn, $customerId, $productId, $rating, $reviewText, (bool) $settings['auto_approve']);
    if ($settings['auto_approve']) {
        flash('success', 'Thanks! Your review has been posted.');
    } else {
        flash('success', 'Thanks! Your review has been submitted for approval.');
    }
    redirect('/fabric.php?id=' . $productId);
}

function review_rating_avg_and_count(mysqli $conn, int $productId): array
{
    $stmt = $conn->prepare(
        "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews
         FROM product_reviews
         WHERE product_id = ? AND status = 'approved'"
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    return [
        'avg' => (float) ($row['avg_rating'] ?? 0),
        'count' => (int) ($row['total_reviews'] ?? 0),
    ];
}

function review_rating_recent_reviews(mysqli $conn, int $productId, int $limit = 10): array
{
    $stmt = $conn->prepare(
        "SELECT pr.rating, pr.review_text, pr.reviewed_at, c.name AS customer_name
         FROM product_reviews pr
         JOIN customers c ON c.id = pr.customer_id
         WHERE pr.product_id = ? AND pr.status = 'approved'
         ORDER BY pr.reviewed_at DESC
         LIMIT ?"
    );
    $stmt->bind_param('ii', $productId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return is_array($rows) ? $rows : [];
}

function review_rating_star_text(int $rating): string
{
    $rating = max(0, min(5, $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}

function review_rating_render_product_block(array $context): void
{
    $settings = review_rating_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $product = $context['product'] ?? [];
    $customerId = (int) ($context['customer_id'] ?? 0);
    if (!$conn instanceof mysqli || !review_rating_table_ready($conn)) {
        return;
    }
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0) {
        return;
    }

    $stats = review_rating_avg_and_count($conn, $productId);
    $reviews = review_rating_recent_reviews($conn, $productId, 10);
    $canReview = review_rating_customer_can_review($conn, $customerId, $productId);
    ?>
    <div class="mt-4 border-top pt-4">
        <h5 class="mb-2">Customer Reviews</h5>
        <div class="text-muted small mb-3">
            Average: <strong><?php echo number_format((float) ($stats['avg'] ?? 0), 1); ?>/5</strong>
            (<?php echo (int) ($stats['count'] ?? 0); ?> review<?php echo ((int) ($stats['count'] ?? 0) !== 1) ? 's' : ''; ?>)
        </div>

        <?php if ($customerId > 0 && $canReview): ?>
            <form method="POST" action="/review-rating-submit.php" class="mb-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
                <div class="mb-2">
                    <label class="form-label">Your Rating</label>
                    <select name="rating" class="form-select" required>
                        <option value="">Select</option>
                        <option value="5">5 - Excellent</option>
                        <option value="4">4 - Very Good</option>
                        <option value="3">3 - Good</option>
                        <option value="2">2 - Fair</option>
                        <option value="1">1 - Poor</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Your Review</label>
                    <textarea name="review_text" class="form-control" rows="3" maxlength="<?php echo (int) $settings['max_length']; ?>" required></textarea>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-primary">Submit Review</button>
            </form>
        <?php elseif ($customerId <= 0): ?>
            <div class="alert alert-light border small">Login to submit a review.</div>
        <?php else: ?>
            <div class="alert alert-light border small">You can review this product after purchasing it.</div>
        <?php endif; ?>

        <?php if (empty($reviews)): ?>
            <p class="text-muted small mb-0">No reviews yet.</p>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong><?php echo e((string) ($review['customer_name'] ?? 'Customer')); ?></strong>
                        <span class="text-warning"><?php echo e(review_rating_star_text((int) ($review['rating'] ?? 0))); ?></span>
                    </div>
                    <div class="small text-muted mb-1"><?php echo e((string) ($review['reviewed_at'] ?? '')); ?></div>
                    <div><?php echo e((string) ($review['review_text'] ?? '')); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}
