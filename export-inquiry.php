<?php
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/international-buyers.php');
}

if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/international-buyers.php');
}
if (!public_form_rate_limit_allow('export_inquiry_submit', 5, 600)) {
    flash('error', 'Too many submissions. Please wait a few minutes and try again.');
    redirect('/international-buyers.php');
}

$name = trim($_POST['name'] ?? '');
$companyName = trim($_POST['company_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$whatsapp = trim($_POST['whatsapp_number'] ?? '');
$country = trim($_POST['country'] ?? '');
$productInterested = trim($_POST['product_interested'] ?? '');
$quantity = trim($_POST['quantity'] ?? '');
$message = trim($_POST['message'] ?? '');
$fabricType = '';

$_SESSION['export_inquiry_old'] = [
    'name' => $name,
    'company_name' => $companyName,
    'email' => $email,
    'whatsapp_number' => $whatsapp,
    'country' => $country,
    'product_interested' => $productInterested,
    'quantity' => $quantity,
    'message' => $message,
];

if ($name === '' || $email === '' || $whatsapp === '' || $country === '' || $productInterested === '' || $quantity === '') {
    flash('error', 'Please fill all required fields.');
    redirect('/international-buyers.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Please enter a valid email address.');
    redirect('/international-buyers.php');
}

$stmt = $conn->prepare(
    "INSERT INTO inquiries (
        inquiry_type, name, company_name, email, whatsapp_number, country,
        product_interested, fabric_type, quantity, message
    ) VALUES ('export', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'sssssssss',
    $name,
    $companyName,
    $email,
    $whatsapp,
    $country,
    $productInterested,
    $fabricType,
    $quantity,
    $message
);
$stmt->execute();
$newInquiryId = (int) $stmt->insert_id;

log_inquiry_activity(
    $conn,
    $newInquiryId,
    'created',
    null,
    'public_export_form',
    'International/bulk inquiry submitted.'
);

send_inquiry_notification([
    'id' => $newInquiryId,
    'name' => $name,
    'email' => $email,
    'country' => $country,
    'fabric_type' => $productInterested,
    'quantity' => $quantity,
    'meters' => '',
    'incoterm' => '',
    'destination' => '',
    'pincode' => '',
    'timeline' => '',
    'message' => "Company: {$companyName}\nWhatsApp: {$whatsapp}\n" . $message,
]);

unset($_SESSION['export_inquiry_old']);
flash('success', 'Your international inquiry has been submitted.');
redirect('/thank-you.php');
