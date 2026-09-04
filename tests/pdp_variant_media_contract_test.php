<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$media = (string) file_get_contents($root . '/includes/helpers/media.php');
$fabric = (string) file_get_contents($root . '/fabric.php');
$productDetail = (string) file_get_contents($root . '/js/product-detail.js');

if (!function_exists('_cfg')) {
    function _cfg(string $key, string $default = ''): string
    {
        return $default;
    }
}
require_once $root . '/includes/helpers/media.php';

$fixtureDirectory = $root . '/images/fabrics';
$fixtureDirectoryCreated = !is_dir($fixtureDirectory);
$fixtureSuffix = (string) getmypid();
$fixtureImageOne = '__pdp-contract-image-' . $fixtureSuffix . '-one.jpg';
$fixtureImageTwo = '__pdp-contract-image-' . $fixtureSuffix . '-two.jpg';
$fixtureVideo = '__pdp-contract-video-' . $fixtureSuffix . '.mp4';
$fixturePaths = [
    $fixtureDirectory . DIRECTORY_SEPARATOR . $fixtureImageOne,
    $fixtureDirectory . DIRECTORY_SEPARATOR . $fixtureImageTwo,
    $fixtureDirectory . DIRECTORY_SEPARATOR . $fixtureVideo,
];

try {
    if ($fixtureDirectoryCreated && !mkdir($fixtureDirectory, 0777, true) && !is_dir($fixtureDirectory)) {
        throw new RuntimeException('Unable to create the isolated PDP media fixture directory.');
    }
    foreach ($fixturePaths as $fixturePath) {
        if (file_put_contents($fixturePath, 'contract fixture') === false) {
            throw new RuntimeException('Unable to create an isolated PDP media fixture.');
        }
    }

    $descriptorFixture = fabric_product_media_descriptors(
        [$fixtureImageOne, $fixtureImageTwo],
        $fixtureVideo,
        'Example product'
    );
} finally {
    foreach ($fixturePaths as $fixturePath) {
        if (is_file($fixturePath)) {
            unlink($fixturePath);
        }
    }
    if ($fixtureDirectoryCreated && is_dir($fixtureDirectory)) {
        rmdir($fixtureDirectory);
    }
}
$assert(
    count($descriptorFixture) === 3
        && ($descriptorFixture[0]['type'] ?? '') === 'image'
        && ($descriptorFixture[0]['src'] ?? '') === '/images/fabrics/' . $fixtureImageOne
        && array_key_exists('thumb_src', $descriptorFixture[0])
        && array_key_exists('webp_srcset', $descriptorFixture[0])
        && array_key_exists('width', $descriptorFixture[0])
        && array_key_exists('height', $descriptorFixture[0])
        && ($descriptorFixture[0]['webp_srcset'] ?? null) === ''
        && ($descriptorFixture[1]['type'] ?? '') === 'image'
        && ($descriptorFixture[1]['src'] ?? '') === '/images/fabrics/' . $fixtureImageTwo
        && ($descriptorFixture[2]['type'] ?? '') === 'video'
        && ($descriptorFixture[2]['src'] ?? '') === '/images/fabrics/' . $fixtureVideo,
    'The media helper must emit the ordered image/video descriptor shape consumed by the PDP.'
);
$assert(
    count($descriptorFixture) === 3
        && isset($descriptorFixture[0], $descriptorFixture[2])
        && array_keys($descriptorFixture[0]) === [
        'type', 'src', 'thumb_src', 'webp_srcset', 'width', 'height',
        'thumb_width', 'thumb_height', 'alt',
    ]
        && array_keys($descriptorFixture[2]) === ['type', 'src', 'alt'],
    'The PDP media contract must expose only presentation fields needed by image and video controls.'
);
$assert(
    fabric_product_media_descriptors(['missing-product-image.jpg'], 'missing-product-video.mp4', 'Missing') === [],
    'Missing image and video filenames must not produce browser requests.'
);

$assert(
    str_contains($media, 'function fabric_product_media_descriptors(')
        && str_contains($media, 'fabric_image_asset_data($filename)')
        && str_contains($media, "'thumb_src'")
        && str_contains($media, "'webp_srcset'")
        && str_contains($media, "'thumb_width'")
        && str_contains($media, "'thumb_height'"),
    'PDP media descriptors must be built server-side from the existing authoritative image asset helper.'
);
$assert(
    str_contains($fabric, "\$variantRow['media'] =")
        && str_contains($fabric, 'fabric_product_media_descriptors([')
        && str_contains($fabric, "'defaultMedia' => array_values(\$defaultMediaDescriptors)"),
    'The product data block must serialize ordered descriptors for both variant media and default gallery fallback media.'
);
$assert(
    str_contains($fabric, 'data-width="<?php echo (int) ($thumbAsset[\'width\'] ?? 0); ?>"')
        && str_contains($fabric, 'data-height="<?php echo (int) ($thumbAsset[\'height\'] ?? 0); ?>"')
        && str_contains($fabric, 'data-alt="<?php echo e((string) ($thumbAsset[\'alt\'] ?? $product[\'name\'])); ?>"'),
    'Initial server-rendered thumbnails must expose the same intrinsic main-image metadata used by delegated switching.'
);
$assert(
    str_contains($productDetail, 'var defaultMedia = Array.isArray(data.defaultMedia) ? data.defaultMedia : [];')
        && str_contains($productDetail, 'Array.isArray(v.media)')
        && str_contains($productDetail, "descriptor.webp_srcset")
        && str_contains($productDetail, "descriptor.thumb_src")
        && str_contains($productDetail, "descriptor.thumb_width")
        && str_contains($productDetail, "descriptor.thumb_height"),
    'Dynamic PDP switching must consume server-supplied responsive source, thumbnail, and intrinsic-dimension metadata.'
);
$assert(
    !str_contains($productDetail, "'/images/fabrics/' + encodeURIComponent")
        && !str_contains($productDetail, 'thumbsWrap.innerHTML = html;'),
    'PDP JavaScript must not infer media paths or rebuild media controls through HTML string concatenation.'
);
$assert(str_contains($productDetail, "source.removeAttribute('src')"), 'Variant changes must clear stale video source state.');
$assert(
    str_contains($productDetail, 'restoreImageAfterVideoFailure')
        && str_contains($productDetail, "['error', 'abort', 'stalled']")
        && str_contains($productDetail, 'lastImageDescriptor')
        && str_contains($fabric, 'id="product-media-status"'),
    'Failed product videos must restore the last valid image and announce the fallback.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "pdp_variant_media_contract_test: OK\n";
