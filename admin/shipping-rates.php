<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$forwardPickup = trim((string) ($_GET['forward_pickup'] ?? _cfg('SHIPROCKET_PICKUP_PINCODE', '110001')));
$forwardDelivery = trim((string) ($_GET['forward_delivery'] ?? '400001'));
$reversePickup = trim((string) ($_GET['reverse_pickup'] ?? '400001'));
$reverseDelivery = trim((string) ($_GET['reverse_delivery'] ?? _cfg('SHIPROCKET_PICKUP_PINCODE', '110001')));
$codForward = (int) ($_GET['forward_cod'] ?? 0) === 1;

$weightSlabs = [0.5, 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5];
$forwardRates = [];
$reverseRates = [];
$forwardCouriers = [];
$reverseCouriers = [];
$forwardError = '';
$reverseError = '';

function fetch_serviceability_for_weight(string $pickup, string $delivery, float $weight, bool $cod, bool $isReturn): array
{
    $tokenResp = shiprocket_get_token();
    if (empty($tokenResp['ok'])) {
        return ['ok' => false, 'error' => (string) ($tokenResp['reason'] ?? 'Authentication failed')];
    }
    $baseUrl = rtrim(_cfg('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'), '/');
    $query = [
        'pickup_postcode' => $pickup,
        'delivery_postcode' => $delivery,
        'cod' => $cod ? 1 : 0,
        'weight' => $weight,
    ];
    if ($isReturn) {
        $query['is_return'] = 1;
    } else {
        $query['declared_value'] = 1000;
    }
    $resp = shiprocket_http_json(
        'GET',
        $baseUrl . '/v1/external/courier/serviceability?' . http_build_query($query),
        ['Authorization: Bearer ' . $tokenResp['token']]
    );
    if (empty($resp['ok'])) {
        return ['ok' => false, 'error' => 'API unavailable'];
    }
    $options = (array) ($resp['body']['data']['available_courier_companies'] ?? []);
    return ['ok' => true, 'options' => $options];
}

if (preg_match('/^[1-9][0-9]{5}$/', $forwardPickup) && preg_match('/^[1-9][0-9]{5}$/', $forwardDelivery)) {
    foreach ($weightSlabs as $w) {
        $row = fetch_serviceability_for_weight($forwardPickup, $forwardDelivery, (float) $w, $codForward, false);
        if (empty($row['ok'])) {
            $forwardError = (string) ($row['error'] ?? 'Unable to fetch forward rates');
            break;
        }
        $forwardRates[(string) $w] = [];
        foreach ((array) ($row['options'] ?? []) as $opt) {
            $name = trim((string) ($opt['courier_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rate = (float) ($opt['rate'] ?? 0);
            $forwardRates[(string) $w][$name] = $rate;
            if (!in_array($name, $forwardCouriers, true)) {
                $forwardCouriers[] = $name;
            }
        }
    }
}

if (preg_match('/^[1-9][0-9]{5}$/', $reversePickup) && preg_match('/^[1-9][0-9]{5}$/', $reverseDelivery)) {
    foreach ($weightSlabs as $w) {
        $row = fetch_serviceability_for_weight($reversePickup, $reverseDelivery, (float) $w, false, true);
        if (empty($row['ok'])) {
            $reverseError = (string) ($row['error'] ?? 'Unable to fetch return rates');
            break;
        }
        $reverseRates[(string) $w] = [];
        foreach ((array) ($row['options'] ?? []) as $opt) {
            $name = trim((string) ($opt['courier_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rate = (float) ($opt['rate'] ?? 0);
            $reverseRates[(string) $w][$name] = $rate;
            if (!in_array($name, $reverseCouriers, true)) {
                $reverseCouriers[] = $name;
            }
        }
    }
}

$forwardCouriers = array_slice($forwardCouriers, 0, 8);
$reverseCouriers = array_slice($reverseCouriers, 0, 8);

$metaTitle = 'Shipping Rates | Admin';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Shipping Rates</h1>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-3">Forward Rates</h5>
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <label class="form-label">Pickup Pincode</label>
                <input class="form-control" name="forward_pickup" value="<?php echo e($forwardPickup); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Delivery Pincode</label>
                <input class="form-control" name="forward_delivery" value="<?php echo e($forwardDelivery); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label d-block">Payment Mode</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="forward_cod" id="cod0" value="0" <?php echo $codForward ? '' : 'checked'; ?>>
                    <label class="form-check-label" for="cod0">Prepaid</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="forward_cod" id="cod1" value="1" <?php echo $codForward ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="cod1">COD</label>
                </div>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-primary w-100">Fetch Forward Rates</button>
            </div>
            <input type="hidden" name="reverse_pickup" value="<?php echo e($reversePickup); ?>">
            <input type="hidden" name="reverse_delivery" value="<?php echo e($reverseDelivery); ?>">
        </form>

        <?php if ($forwardError !== ''): ?>
            <div class="alert alert-warning py-2">Forward rates unavailable: <?php echo e($forwardError); ?>. Manual fallback remains active.</div>
        <?php endif; ?>

        <?php if (!empty($forwardCouriers)): ?>
            <div class="mb-2 small text-muted">Selected Delivery Partners</div>
            <div class="d-flex gap-2 flex-wrap mb-3">
                <?php foreach ($forwardCouriers as $c): ?>
                    <span class="badge bg-light text-dark border"><?php echo e($c); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Weight Slab</th>
                            <?php foreach ($forwardCouriers as $c): ?>
                                <th><?php echo e($c); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weightSlabs as $w): ?>
                            <?php $key = (string) $w; ?>
                            <tr>
                                <td><?php echo e(number_format($w, 1)); ?> Kg</td>
                                <?php foreach ($forwardCouriers as $c): ?>
                                    <td>
                                        <?php if (isset($forwardRates[$key][$c])): ?>
                                            Rs <?php echo number_format((float) $forwardRates[$key][$c], 2); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No forward rate data yet. Enter pincodes and fetch.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-3">Return Rates</h5>
        <form method="GET" class="row g-2 mb-3">
            <input type="hidden" name="forward_pickup" value="<?php echo e($forwardPickup); ?>">
            <input type="hidden" name="forward_delivery" value="<?php echo e($forwardDelivery); ?>">
            <input type="hidden" name="forward_cod" value="<?php echo $codForward ? '1' : '0'; ?>">
            <div class="col-md-4">
                <label class="form-label">Return Pickup Pincode</label>
                <input class="form-control" name="reverse_pickup" value="<?php echo e($reversePickup); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Return Delivery Pincode</label>
                <input class="form-control" name="reverse_delivery" value="<?php echo e($reverseDelivery); ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-primary w-100">Fetch Return Rates</button>
            </div>
        </form>

        <?php if ($reverseError !== ''): ?>
            <div class="alert alert-warning py-2">Return rates unavailable: <?php echo e($reverseError); ?>. Manual return handling remains active.</div>
        <?php endif; ?>

        <?php if (!empty($reverseCouriers)): ?>
            <div class="mb-2 small text-muted">Selected Reverse Partners</div>
            <div class="d-flex gap-2 flex-wrap mb-3">
                <?php foreach ($reverseCouriers as $c): ?>
                    <span class="badge bg-light text-dark border"><?php echo e($c); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Weight Slab</th>
                            <?php foreach ($reverseCouriers as $c): ?>
                                <th><?php echo e($c); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weightSlabs as $w): ?>
                            <?php $key = (string) $w; ?>
                            <tr>
                                <td><?php echo e(number_format($w, 1)); ?> Kg</td>
                                <?php foreach ($reverseCouriers as $c): ?>
                                    <td>
                                        <?php if (isset($reverseRates[$key][$c])): ?>
                                            Rs <?php echo number_format((float) $reverseRates[$key][$c], 2); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No return rate data yet. Enter pincodes and fetch.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
