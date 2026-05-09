<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

flash('error', 'Stripe payments are temporarily unavailable. Please use Cash on Delivery or Razorpay.');
redirect('/checkout.php');
