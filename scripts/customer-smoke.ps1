$ErrorActionPreference = 'Stop'

Write-Host "Running customer flow smoke checks..." -ForegroundColor Cyan

$phpFiles = @(
    "includes/init.php",
    "includes/functions.php",
    "includes/customer-auth.php",
    "index.php",
    "catalog.php",
    "fabric.php",
    "add-to-cart.php",
    "update-cart.php",
    "remove-cart.php",
    "cart.php",
    "checkout.php",
    "place-order.php",
    "order-success.php",
    "customer/login.php",
    "customer/register.php",
    "customer/forgot-password.php",
    "customer/reset-password.php",
    "customer/orders.php",
    "customer/order-view.php",
    "customer/profile.php",
    "customer/logout.php",
    "contact.php",
    "international-buyers.php",
    "export-inquiry.php",
    "payment/razorpay-create.php",
    "payment/razorpay-verify.php"
)

foreach ($file in $phpFiles) {
    & php -l $file | Out-Host
}

Write-Host "Verifying critical routes exist..." -ForegroundColor Cyan
$routeFiles = @(
    "customer/login.php",
    "customer/register.php",
    "cart.php",
    "checkout.php",
    "place-order.php",
    "payment/razorpay-create.php",
    "payment/razorpay-verify.php",
    "order-success.php"
)

foreach ($file in $routeFiles) {
    if (-not (Test-Path $file)) {
        throw "Route target file missing: $file"
    }
}

Write-Host "Smoke checks passed." -ForegroundColor Green
