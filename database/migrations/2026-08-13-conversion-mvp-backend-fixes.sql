-- Persist account-activation intent across asynchronous Razorpay completion.
-- The sent timestamp acts as an atomic claim, preventing duplicate messages
-- when browser verification and webhook processing happen concurrently.
ALTER TABLE orders ADD COLUMN account_activation_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER estimated_delivery_end;
ALTER TABLE orders ADD COLUMN account_activation_sent_at DATETIME NULL DEFAULT NULL AFTER account_activation_requested;
CREATE INDEX idx_orders_activation_delivery ON orders (account_activation_requested, account_activation_sent_at);
