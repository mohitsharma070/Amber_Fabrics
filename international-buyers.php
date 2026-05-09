<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

$old = [
    'name' => '',
    'company_name' => '',
    'email' => '',
    'whatsapp_number' => '',
    'country' => '',
    'product_interested' => '',
    'quantity' => '',
    'message' => '',
];

// Optional prefill from checkout route query params.
$checkoutPrefill = [
    'name' => trim((string) ($_GET['name'] ?? '')),
    'email' => trim((string) ($_GET['email'] ?? '')),
    'whatsapp_number' => trim((string) ($_GET['phone'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'message' => trim((string) ($_GET['notes'] ?? '')),
];
foreach ($checkoutPrefill as $field => $value) {
    if ($value !== '' && $old[$field] === '') {
        $old[$field] = $value;
    }
}

// Prefill with logged-in customer profile when available.
$customerId = current_customer_id();
if ($customerId !== null) {
    $custStmt = $conn->prepare("SELECT name, email, phone, country FROM customers WHERE id = ? LIMIT 1");
    if ($custStmt) {
        $custStmt->bind_param('i', $customerId);
        $custStmt->execute();
        $customer = $custStmt->get_result()->fetch_assoc() ?: [];
        if ($old['name'] === '' && !empty($customer['name'])) {
            $old['name'] = (string) $customer['name'];
        }
        if ($old['email'] === '' && !empty($customer['email'])) {
            $old['email'] = (string) $customer['email'];
        }
        if ($old['whatsapp_number'] === '' && !empty($customer['phone'])) {
            $old['whatsapp_number'] = (string) $customer['phone'];
        }
        if ($old['country'] === '' && !empty($customer['country'])) {
            $old['country'] = (string) $customer['country'];
        }
    }
}

// Prefill product/quantity/message from current cart, if any.
$cartSummaryLines = [];
$cartProductNames = [];
$cart = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
if (!empty($cart)) {
    $ids = array_map('intval', array_keys($cart));
    $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT id, name, unit_type FROM fabrics WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $pid = (int) $row['id'];
                $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                    ? (string) $row['unit_type']
                    : 'meter';
                $qty = normalize_quantity_by_unit($cart[$pid] ?? 1, $unitType);
                $qtyText = format_quantity_by_unit($qty, $unitType);
                $unitLabel = ($unitType === 'piece') ? (((float) $qty === 1.0) ? 'piece' : 'pieces') : (($unitType === 'set') ? (((float) $qty === 1.0) ? 'set' : 'sets') : 'meters');
                $line = (string) ($row['name'] ?? 'Item') . ': ' . $qtyText . ' ' . $unitLabel;
                if ($unitType === 'piece' && !empty($cartSizes[$pid])) {
                    $line .= ' (Size: ' . (string) $cartSizes[$pid] . ')';
                }
                $cartSummaryLines[] = $line;
                $cartProductNames[] = (string) ($row['name'] ?? '');
            }
        }
    }
}

if ($old['product_interested'] === '' && !empty($cartProductNames)) {
    $old['product_interested'] = implode(', ', array_values(array_filter($cartProductNames)));
}
if ($old['quantity'] === '' && !empty($cartSummaryLines)) {
    $old['quantity'] = implode('; ', $cartSummaryLines);
}
if ($old['message'] === '' && !empty($cartSummaryLines)) {
    $old['message'] = "Interested in the following items:\n" . implode("\n", array_map(static fn($line) => '- ' . $line, $cartSummaryLines));
}

if (!empty($_SESSION['export_inquiry_old']) && is_array($_SESSION['export_inquiry_old'])) {
    $old = array_merge($old, $_SESSION['export_inquiry_old']);
    unset($_SESSION['export_inquiry_old']);
}

$metaTitle = 'International Buyers | Amber Fabrics';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>International / Bulk Inquiry</h1>
        <p class="mb-0">Tell us your requirement and our export team will respond with pricing and lead time.</p>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="row g-4 justify-content-center">
            <div class="col-lg-8">
                <div class="surface-panel">
                    <form method="POST" action="/export-inquiry.php" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name *</label>
                                <input class="form-control" name="name" required value="<?php echo e($old['name']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Company Name</label>
                                <input class="form-control" name="company_name" value="<?php echo e($old['company_name']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input class="form-control" type="email" name="email" required value="<?php echo e($old['email']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">WhatsApp Number *</label>
                                <input class="form-control" name="whatsapp_number" required value="<?php echo e($old['whatsapp_number']); ?>" placeholder="+91...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Country *</label>
                                <input class="form-control" name="country" required value="<?php echo e($old['country']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Product *</label>
                                <input class="form-control" name="product_interested" required value="<?php echo e($old['product_interested']); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Quantity *</label>
                                <input class="form-control" name="quantity" required value="<?php echo e($old['quantity']); ?>" placeholder="e.g. 500 pcs / 2000 meters">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="4"><?php echo e($old['message']); ?></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary w-100" type="submit">Request International Quote</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
