<?php
require_once __DIR__ . '/includes/init.php';

// The review-rating plugin handles this route during app.init.
// Keep a safe fallback redirect if plugin is disabled or not installed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    flash('error', 'Review submit is currently unavailable.');
}
redirect('/catalog.php');
