ALTER TABLE shipments
    DROP COLUMN IF EXISTS package_weight_kg,
    DROP COLUMN IF EXISTS service_type,
    DROP COLUMN IF EXISTS parcel_count_label,
    DROP COLUMN IF EXISTS awb_number,
    DROP COLUMN IF EXISTS routing_hub,
    DROP COLUMN IF EXISTS routing_zone,
    DROP COLUMN IF EXISTS routing_lane,
    DROP COLUMN IF EXISTS routing_bay,
    DROP COLUMN IF EXISTS routing_rack,
    DROP COLUMN IF EXISTS rto_address;

DELETE FROM site_settings
WHERE setting_key IN (
    'ship_from_name',
    'ship_from_phone',
    'ship_from_email',
    'ship_from_address_1',
    'ship_from_address_2',
    'ship_from_gstin',
    'ship_from_state',
    'default_label_weight_kg',
    'default_hub',
    'default_lane',
    'default_bay',
    'default_rack'
);
