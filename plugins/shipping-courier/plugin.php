<?php

// Compatibility entry point. Public functions and hook names remain unchanged;
// implementation is grouped by responsibility in modules/.
require_once __DIR__ . '/../../includes/services/BigshipService.php';
require_once __DIR__ . '/modules/configuration.php';
require_once __DIR__ . '/modules/reference-and-rates.php';
require_once __DIR__ . '/modules/bigship-payloads.php';
require_once __DIR__ . '/modules/shipment-lifecycle.php';
require_once __DIR__ . '/modules/webhook-handling.php';
require_once __DIR__ . '/modules/returns.php';
require_once __DIR__ . '/modules/admin-presentation.php';
require_once __DIR__ . '/modules/lifecycle-callbacks.php';
require_once __DIR__ . '/modules/cron-callbacks.php';
require_once __DIR__ . '/modules/registration.php';
