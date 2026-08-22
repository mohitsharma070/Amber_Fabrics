<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$css = glob($root . '/css/*.css') ?: [];
$js = glob($root . '/js/*.js') ?: [];
sort($css);
sort($js);
$assert(array_map('basename', $css) === ['admin.css', 'documents.css', 'foundation.css', 'storefront.css'], 'Only the four first-party CSS assets may remain.');
$assert(array_map('basename', $js) === ['admin.js', 'app.js', 'commerce.js', 'documents.js'], 'Only the four first-party JavaScript assets may remain.');

foreach (['admin/invoice.php', 'admin/packing-slip.php'] as $path) {
    $source = $read($path);
    $assert(str_contains($source, 'data-ui-area="document"'), $path . ' must declare the document UI area.');
    $assert(str_contains($source, "../css/documents.css") && str_contains($source, "../js/documents.js"), $path . ' must load the document assets.');
    $assert(!preg_match('/<style\\b|\\sstyle\\s*=|\\son[a-z]+\\s*=/i', $source), $path . ' must not contain inline browser presentation or events.');
    $assert(!preg_match('/<script\\b(?![^>]*\\bsrc\\s*=)/i', $source), $path . ' must not contain executable inline JavaScript.');
}

$documents = $read('js/documents.js');
$assert(str_contains($documents, 'data-document-print') && str_contains($documents, 'data-document-pdf'), 'documents.js must own print and PDF actions.');
$assert(str_contains($documents, 'window.html2pdf'), 'documents.js must preserve the optional HTML-to-PDF integration.');

foreach (['css/style.css', 'js/script.js', 'includes/partials/interaction-layer.php'] as $path) {
    $assert(!is_file($root . '/' . $path), $path . ' must be removed.');
}

if ($failures !== []) {
    fwrite(STDERR, "Document frontend rewrite contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: document frontend rewrite contracts passed\n";
