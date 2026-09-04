const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');

const productDetailScript = path.resolve(__dirname, '../../js/product-detail.js');
const forbiddenProviderHosts = [
  /(^|\.)razorpay\.com$/i,
  /(^|\.)bigship\.in$/i,
  /(^|\.)facebook\.com$/i,
  /(^|\.)facebook\.net$/i,
  /(^|\.)google-analytics\.com$/i,
  /(^|\.)googletagmanager\.com$/i,
];

const providerAttempts = new WeakMap();
const pageErrors = new WeakMap();

test.beforeEach(async ({ page }) => {
  providerAttempts.set(page, []);
  pageErrors.set(page, []);

  await page.route('**/*', async (route) => {
    const hostname = new URL(route.request().url()).hostname;
    if (forbiddenProviderHosts.some((pattern) => pattern.test(hostname))) {
      providerAttempts.get(page).push(route.request().url());
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
  page.on('pageerror', (error) => {
    pageErrors.get(page).push(error.message);
  });
});

test.afterEach(async ({ page }) => {
  expect(providerAttempts.get(page), 'Product media E2E must not contact payment, courier, or analytics providers.').toEqual([]);
  expect(pageErrors.get(page), 'Product media E2E must not produce uncaught first-party page errors.').toEqual([]);
});

async function expectMediaAxeBaseline(page, state) {
  const results = await new AxeBuilder({ page })
    .include('.product-media-main')
    .include('#product-media-thumbs')
    .analyze();
  const violations = results.violations.filter((violation) => (
    violation.impact === 'serious' || violation.impact === 'critical'
  ));
  expect(violations, `${state} must not introduce serious or critical media accessibility violations.`).toEqual([]);
}

function imageMedia(name, options = {}) {
  return {
    type: 'image',
    src: `/media/${name}.jpg`,
    thumb_src: `/media/${name}-thumb.webp`,
    webp_srcset: options.webp || '',
    width: options.width || 0,
    height: options.height || 0,
    thumb_width: options.thumbWidth || 0,
    thumb_height: options.thumbHeight || 0,
    alt: options.alt || `${name} product image`,
  };
}

async function mountProductMediaFixture(page) {
  const defaultMedia = [imageMedia('default', {
    webp: '/media/default-360w.webp 360w, /media/default-720w.webp 720w',
    width: 1200,
    height: 1600,
    thumbWidth: 360,
    thumbHeight: 360,
    alt: 'Default product image',
  })];
  const navyMedia = [imageMedia('navy', {
    webp: '/media/navy-360w.webp 360w, /media/navy-720w.webp 720w',
    width: 900,
    height: 1200,
    thumbWidth: 360,
    thumbHeight: 360,
    alt: 'Navy product image',
  })];
  const amberMedia = [
    imageMedia('amber', { alt: 'Amber product image' }),
    imageMedia('amber-detail', {
      webp: '/media/amber-detail-360w.webp 360w, /media/amber-detail-720w.webp 720w',
      width: 1000,
      height: 750,
      thumbWidth: 320,
      thumbHeight: 240,
      alt: 'Amber product detail image',
    }),
    { type: 'video', src: '/media/amber-demo.mp4', alt: 'Amber product video' },
  ];
  const purpleMedia = [
    imageMedia('purple', { alt: 'Purple product image' }),
    { type: 'video', src: '/media/purple-demo.mp4', alt: 'Purple product video' },
  ];
  const data = {
    variants: [
      { id: 11, color: 'Navy', size: 'Small', is_active: 1, price_override: 249, stock: 4, stock_meters: 0, media: navyMedia },
      { id: 12, color: 'Amber', size: 'Large', is_active: 1, price_override: 279, stock: 3, stock_meters: 0, media: amberMedia },
      { id: 13, color: 'Natural', size: 'Medium', is_active: 1, price_override: 0, stock: 2, stock_meters: 0, media: [] },
      { id: 14, color: 'Purple', size: 'XL', is_active: 1, price_override: 289, stock: 2, stock_meters: 0, media: purpleMedia },
    ],
    hideVariantSize: false,
    unitType: 'piece',
    basePricePerUnit: 199,
    regularPricePerUnit: 299,
    unitSingleLabel: 'piece',
    minimumOrderQty: 1,
    quantityStep: 1,
    defaultMedia,
  };

  await page.setContent(`
    <script type="application/json" id="product-detail-data">${JSON.stringify(data)}</script>
    <div class="product-media-main">
      <picture id="product-main-picture">
        <source id="product-main-webp-source" type="image/webp" srcset="${defaultMedia[0].webp_srcset}" sizes="(max-width: 991px) 100vw, 50vw">
        <img id="product-main-image" src="${defaultMedia[0].src}" alt="${defaultMedia[0].alt}" width="1200" height="1600">
      </picture>
      <video id="product-main-video" class="d-none"><source></video>
      <div id="product-media-status" aria-live="polite"></div>
    </div>
    <div id="product-media-thumbs">
      <button type="button" class="media-thumb product-media-thumb border-primary"
              data-type="image" data-src="${defaultMedia[0].src}"
              data-webp-srcset="${defaultMedia[0].webp_srcset}"
              data-width="1200" data-height="1600" data-alt="${defaultMedia[0].alt}"
              aria-current="true"><img src="${defaultMedia[0].thumb_src}" width="360" height="360"></button>
    </div>
    <div id="product_price_block"><span>Rs 199.00 / piece</span></div>
    <div id="size-picker-section"><div id="size-btn-group">
      <button type="button" class="size-option-btn" data-size="Small">Small</button>
      <button type="button" class="size-option-btn" data-size="Large">Large</button>
      <button type="button" class="size-option-btn" data-size="Medium">Medium</button>
      <button type="button" class="size-option-btn" data-size="XL">XL</button>
    </div></div>
    <button type="button" class="color-swatch-btn" data-color="Navy">Navy</button>
    <button type="button" class="color-swatch-btn" data-color="Amber">Amber</button>
    <button type="button" class="color-swatch-btn" data-color="Natural">Natural</button>
    <button type="button" class="color-swatch-btn" data-color="Purple">Purple</button>
    <div id="variant-stock-badge"></div>
    <input id="selected_color_add" value="Navy">
    <input id="selected_color_buy" value="Navy">
    <input id="selected_size_add" value="Small">
    <input id="selected_size_buy" value="Small">
    <input id="selected_variant_id_add" value="11">
    <input id="selected_variant_id_buy" value="11">
    <select id="product_quantity"><option value="1">1</option></select>
    <input id="buy_now_quantity" value="1">
    <button id="qty_dec" type="button">Decrease</button>
    <button id="qty_inc" type="button">Increase</button>
    <button id="add_to_cart_submit" type="button">Add to Cart</button>
    <button id="buy_now_submit" type="button">Buy Now</button>
  `);
  await page.addScriptTag({ path: productDetailScript });
}

test('@desktop preserves responsive media metadata across repeated variant, video, and fallback switching', async ({ page }) => {
  await mountProductMediaFixture(page);

  const mainImage = page.locator('#product-main-image');
  const webpSource = page.locator('#product-main-webp-source');
  const thumbnails = page.locator('#product-media-thumbs .media-thumb');

  await expect(mainImage).toHaveAttribute('src', '/media/navy.jpg');
  await expect(mainImage).toHaveAttribute('width', '900');
  await expect(mainImage).toHaveAttribute('height', '1200');
  await expect(webpSource).toHaveAttribute('srcset', '/media/navy-360w.webp 360w, /media/navy-720w.webp 720w');
  await expect(thumbnails.locator('img')).toHaveAttribute('src', '/media/navy-thumb.webp');
  await expect(thumbnails.locator('img')).toHaveAttribute('width', '360');
  await expect(thumbnails.locator('img')).toHaveAttribute('height', '360');
  await expect(thumbnails).toHaveAttribute('aria-current', 'true');
  await expect(page.locator('#variant-stock-badge')).toHaveText('In Stock (4)');
  await expect(page.locator('#selected_variant_id_add')).toHaveValue('11');

  await page.getByRole('button', { name: 'Amber', exact: true }).click();
  await expect(mainImage).toHaveAttribute('src', '/media/amber.jpg');
  await expect(mainImage).not.toHaveAttribute('width', /.+/);
  await expect(mainImage).not.toHaveAttribute('height', /.+/);
  await expect(webpSource).not.toHaveAttribute('srcset', /.+/);
  await expect(thumbnails).toHaveCount(3);
  await expect(page.locator('#product_price_block')).toContainText('279');
  await expect(page.locator('#variant-stock-badge')).toHaveText('In Stock (3)');
  await expect(page.locator('#selected_variant_id_add')).toHaveValue('12');
  await expect(page.locator('#add_to_cart_submit')).toBeEnabled();

  const amberDetailThumb = page.getByRole('button', { name: 'View Amber product detail image 2' });
  await amberDetailThumb.click();
  await expect(mainImage).toHaveAttribute('src', '/media/amber-detail.jpg');
  await expect(mainImage).toHaveAttribute('alt', 'Amber product detail image');
  await expect(mainImage).toHaveAttribute('width', '1000');
  await expect(mainImage).toHaveAttribute('height', '750');
  await expect(webpSource).toHaveAttribute('srcset', '/media/amber-detail-360w.webp 360w, /media/amber-detail-720w.webp 720w');
  await expect(amberDetailThumb).toHaveAttribute('aria-current', 'true');
  await expect(thumbnails.first()).toHaveAttribute('aria-current', 'false');

  await page.getByRole('button', { name: 'Amber product video' }).click();
  await expect(mainImage).toHaveClass(/d-none/);
  await expect(mainImage).toHaveAttribute('src', '/media/amber-detail.jpg');
  await expect(page.locator('#product-main-video')).not.toHaveClass(/d-none/);
  await expect(page.locator('#product-main-video source')).toHaveAttribute('src', '/media/amber-demo.mp4');
  await expectMediaAxeBaseline(page, 'selected variant video');

  await page.locator('#product-main-video').dispatchEvent('stalled');
  await expect(page.locator('#product-main-video')).toHaveClass(/d-none/);
  await expect(mainImage).not.toHaveClass(/d-none/);
  await expect(mainImage).toHaveAttribute('src', '/media/amber-detail.jpg');
  await expect(page.locator('#product-media-status')).toHaveText('Product video could not be played. The product image has been restored.');

  await page.getByRole('button', { name: 'Purple', exact: true }).click();
  await expect(mainImage).toHaveAttribute('src', '/media/purple.jpg');
  await expect(page.locator('#product-main-video')).toHaveClass(/d-none/);
  await expect(page.locator('#product-main-video source')).not.toHaveAttribute('src', /.+/);
  await page.getByRole('button', { name: 'Purple product video' }).click();
  await expect(page.locator('#product-main-video source')).toHaveAttribute('src', '/media/purple-demo.mp4');

  await page.getByRole('button', { name: 'Natural', exact: true }).click();
  await expect(mainImage).toHaveAttribute('src', '/media/default.jpg');
  await expect(mainImage).toHaveAttribute('width', '1200');
  await expect(mainImage).toHaveAttribute('height', '1600');
  await expect(webpSource).toHaveAttribute('srcset', '/media/default-360w.webp 360w, /media/default-720w.webp 720w');
  await expect(thumbnails).toHaveCount(1);
  await expect(page.locator('#product-main-video source')).not.toHaveAttribute('src', /.+/);
  await expect(page.locator('#selected_variant_id_add')).toHaveValue('13');
  await expect(page.locator('#variant-stock-badge')).toHaveText('In Stock (2)');
  await expectMediaAxeBaseline(page, 'default gallery fallback');

  await page.getByRole('button', { name: 'Navy', exact: true }).click();
  await expect(mainImage).toHaveAttribute('src', '/media/navy.jpg');
  await expect(webpSource).toHaveAttribute('srcset', '/media/navy-360w.webp 360w, /media/navy-720w.webp 720w');
  await expect(thumbnails).toHaveCount(1);
  await expectMediaAxeBaseline(page, 'repeated variant image switch');
});

test('@desktop renders the established no-image state when fixture media files do not exist', async ({ page }) => {
  await page.goto('/fabric/e2e-simple-product');
  await expect(page.getByRole('heading', { name: 'E2E Simple Product' })).toBeVisible();
  await expect(page.locator('.product-gallery-column').getByText('No image', { exact: true })).toBeVisible();
  await expect(page.locator('#product-main-image')).toHaveCount(0);
});
