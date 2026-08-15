-- Customer session revocation, guest-safe coupon reservations, and
-- concurrency-safe Razorpay order initialization.
ALTER TABLE customers
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash;

ALTER TABLE coupon_usages
    MODIFY COLUMN customer_id INT NULL;

DELETE duplicate_usage
FROM coupon_usages duplicate_usage
JOIN coupon_usages kept_usage
  ON kept_usage.order_id = duplicate_usage.order_id
 AND kept_usage.id < duplicate_usage.id;

ALTER TABLE coupon_usages
    DROP INDEX idx_coupon_usages_order_id,
    ADD UNIQUE KEY uq_coupon_usages_order_id (order_id);

INSERT IGNORE INTO coupon_usages (coupon_id, customer_id, order_id, used_at)
SELECT o.coupon_id, o.customer_id, o.id, o.created_at
FROM orders o
WHERE o.coupon_id IS NOT NULL
  AND o.coupon_id > 0
  AND o.payment_status IN ('pending', 'paid')
  AND o.order_status NOT IN ('cancelled', 'refunded');

UPDATE coupons c
LEFT JOIN (
    SELECT coupon_id, COUNT(*) AS reservation_count
    FROM coupon_usages
    GROUP BY coupon_id
) usage_totals ON usage_totals.coupon_id = c.id
SET c.used_count = COALESCE(usage_totals.reservation_count, 0);

ALTER TABLE payments
    ADD COLUMN razorpay_create_claim_token CHAR(32) NULL AFTER razorpay_order_id,
    ADD COLUMN razorpay_create_claimed_at DATETIME NULL AFTER razorpay_create_claim_token;
