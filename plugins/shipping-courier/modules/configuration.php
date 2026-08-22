<?php

function shipping_courier_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('shipping-courier', 'enabled', 0),
        'provider' => strtolower(trim((string) plugin_setting('shipping-courier', 'provider', ''))),
        'test_mode' => (int) plugin_setting('shipping-courier', 'test_mode', 1),
        'auto_create' => (int) plugin_setting('shipping-courier', 'auto_create', 0),
        'tracking_sync' => (int) plugin_setting('shipping-courier', 'tracking_sync', 1),
        'webhook_secret' => trim((string) plugin_setting('shipping-courier', 'webhook_secret', '')),
        'webhook_signature_mode' => strtolower(trim((string) plugin_setting('shipping-courier', 'webhook_signature_mode', 'hmac_sha256'))),
        'api_base_url' => rtrim(trim((string) plugin_setting('shipping-courier', 'api_base_url', '')), '/'),
        'bigship_username' => trim((string) plugin_setting('shipping-courier', 'bigship_username', '')),
        'bigship_password' => trim((string) plugin_setting('shipping-courier', 'bigship_password', '')),
        'bigship_access_key' => trim((string) plugin_setting('shipping-courier', 'bigship_access_key', '')),
        'bigship_warehouse_id' => trim((string) plugin_setting('shipping-courier', 'bigship_warehouse_id', '')),
        'bigship_warehouse_pincode' => trim((string) plugin_setting('shipping-courier', 'bigship_warehouse_pincode', '')),
        'bigship_segment' => strtolower(trim((string) plugin_setting('shipping-courier', 'bigship_segment', 'domestic_b2c'))),
        'bigship_warehouse_segment' => strtolower(trim((string) plugin_setting('shipping-courier', 'bigship_warehouse_segment', 'local'))),
        'bigship_risk_type_id' => (int) plugin_setting('shipping-courier', 'bigship_risk_type_id', 2),
        'bigship_risk_type' => strtolower(trim((string) plugin_setting('shipping-courier', 'bigship_risk_type', 'owner'))),
        'bigship_product_category_id' => (int) plugin_setting('shipping-courier', 'bigship_product_category_id', 1),
        'bigship_invoice_field' => trim((string) plugin_setting('shipping-courier', 'bigship_invoice_field', 'invoice_file')),
        'bigship_eway_bill_field' => trim((string) plugin_setting('shipping-courier', 'bigship_eway_bill_field', 'eway_bill_file')),
        'bigship_invoice_type' => trim((string) plugin_setting('shipping-courier', 'bigship_invoice_type', '')),
        'bigship_http_skip_tls_verify' => (int) plugin_setting('shipping-courier', 'bigship_http_skip_tls_verify', 0),
        'bigship_parcel_weight_kg' => (float) plugin_setting('shipping-courier', 'bigship_parcel_weight_kg', 0),
        'bigship_parcel_length_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_length_cm', 0),
        'bigship_parcel_width_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_width_cm', 0),
        'bigship_parcel_height_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_height_cm', 0),
        'bigship_packaging_weight_kg' => (float) plugin_setting('shipping-courier', 'bigship_packaging_weight_kg', 0.10),
        'bigship_weight_per_meter_kg' => (float) plugin_setting('shipping-courier', 'bigship_weight_per_meter_kg', 0.25),
        'bigship_weight_per_piece_kg' => (float) plugin_setting('shipping-courier', 'bigship_weight_per_piece_kg', 0.35),
        'bigship_weight_per_set_kg' => (float) plugin_setting('shipping-courier', 'bigship_weight_per_set_kg', 0.75),
        'bigship_parcel_height_per_unit_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_height_per_unit_cm', 1.5),
        'bigship_parcel_max_height_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_max_height_cm', 60),
    ];
}
