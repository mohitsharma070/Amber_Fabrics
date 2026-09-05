// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Recommendation-card mobile overflow regression.
 *
 * Root-cause history: recommendation blocks were rendered without a
 * containing .container, and grid items lacked min-width:0, allowing
 * card content to push beyond the viewport on narrow phones.
 *
 * Assertions (per viewport width tested):
 *   1. document.scrollWidth <= viewport width  (no horizontal scroll)
 *   2. Every recommendation card box is fully inside the viewport
 *   3. Ordinary catalog grid cards are also contained
 *   4. Recommendation cards are clickable (non-zero size, not obscured)
 *
 * Tagged @mobile so it runs in the mobile-chromium project (360x800).
 * Internal width sweeps use page.setViewportSize() to cover 320-1440.
 */

const WIDTHS = [320, 360, 375, 390, 412, 768, 1024, 1440];
const providerAttempts = new WeakMap();
const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
    providerAttempts.set(page, []);
    pageErrors.set(page, []);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.route('**/*', async (route) => {
        const hostname = new URL(route.request().url()).hostname;
        if (!['127.0.0.1', 'localhost', '[::1]'].includes(hostname)) {
            providerAttempts.get(page).push(route.request().url());
            return route.abort('blockedbyclient');
        }
        return route.continue();
    });
    page.on('pageerror', (error) => pageErrors.get(page).push(error.message));
});
test.afterEach(async ({ page }) => {
    expect(providerAttempts.get(page)).toEqual([]);
    expect(pageErrors.get(page)).toEqual([]);
});

// Measure horizontal overflow: scrollWidth > clientWidth => overflow.
async function measureHorizontalOverflow(page) {
    return page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
}

// Return bounding boxes for all elements matching the selector.
async function boundingBoxes(page, selector) {
    return page.evaluate((sel) => {
        const els = Array.from(document.querySelectorAll(sel));
        return els.map((el) => {
            const r = el.getBoundingClientRect();
            return { left: r.left, right: r.right, width: r.width, height: r.height };
        });
    }, selector);
}

test('@mobile recommendation cards stay inside the viewport at all tested narrow widths', async ({ page }) => {
    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 800 });
        // Leave fixture products outside the results so recommendations are non-empty.
        await page.goto('/catalog?q=E2E+Simple+Product');

        // Wait for the page to settle (catalog grid must exist).
        await page.waitForSelector('.catalog-products-grid', { timeout: 10_000 });

        // --- 1. No horizontal scroll ---
        const overflow = await measureHorizontalOverflow(page);
        expect(
            overflow.scrollWidth,
            `[${width}px] document.scrollWidth (${overflow.scrollWidth}) should not exceed clientWidth (${overflow.clientWidth})`,
        ).toBeLessThanOrEqual(overflow.clientWidth);

        // --- 2. Recommendation cards inside viewport ---
        const recCards = await boundingBoxes(page, '[data-rec-section] .product-click-card');
        expect(recCards.length, 'Fixture must render recommendations').toBeGreaterThan(0);
        for (const box of recCards) {
            expect(
                box.left,
                `[${width}px] Recommendation card left edge (${box.left.toFixed(1)}) must be >= 0`,
            ).toBeGreaterThanOrEqual(0);
            expect(
                box.right,
                `[${width}px] Recommendation card right edge (${box.right.toFixed(1)}) must be <= viewport width ${width}`,
            ).toBeLessThanOrEqual(width + 1);
        }

        // --- 3. Ordinary catalog grid cards are also contained ---
        const catalogCards = await boundingBoxes(page, '.catalog-results .product-click-card');
        for (const box of catalogCards) {
            expect(
                box.left,
                `[${width}px] Catalog card left edge (${box.left.toFixed(1)}) must be >= 0`,
            ).toBeGreaterThanOrEqual(0);
            expect(
                box.right,
                `[${width}px] Catalog card right edge (${box.right.toFixed(1)}) must be <= viewport width ${width}`,
            ).toBeLessThanOrEqual(width + 1);
        }

        // --- 4. Recommendation cards are clickable (visible, non-zero size) ---
        for (const box of recCards) {
            expect(
                box.width,
                `[${width}px] Recommendation card must have positive width`,
            ).toBeGreaterThan(0);
            expect(
                box.height,
                `[${width}px] Recommendation card must have positive height`,
            ).toBeGreaterThan(0);
        }
    }
});

test('@mobile recommendation card links are reachable (not clipped to zero) at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await page.goto('/catalog?q=E2E+Simple+Product');
    await page.waitForSelector('.catalog-products-grid', { timeout: 10_000 });

    const recLinkCount = await page.locator('[data-rec-section] .fabric-thumb-link').count();
    expect(recLinkCount, 'Fixture must render recommendation links').toBeGreaterThan(0);

    // The first recommendation link must be clickable (intersects viewport, non-zero bounding box).
    const firstLink = page.locator('[data-rec-section] .fabric-thumb-link').first();
    const box = await firstLink.boundingBox();
    expect(box, 'First recommendation card link must have a bounding box').not.toBeNull();
    if (box) {
        expect(box.width, 'First recommendation card link must have positive width').toBeGreaterThan(0);
        expect(box.height, 'First recommendation card link must have positive height').toBeGreaterThan(0);
        expect(box.x + box.width, 'First recommendation card link right edge inside viewport').toBeLessThanOrEqual(321);
    }
    await firstLink.click();
    await expect(page).toHaveURL(/\/fabric(?:\/|\.php)/);
});

test('@mobile cart and checkout rows fit narrow viewports without horizontal scrolling', async ({ page }) => {
    await page.goto('/fabric/e2e-simple-product');
    await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
    await expect(page).toHaveURL(/\/cart$/);
    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 800 });
        for (const route of ['/cart', '/checkout']) {
            await page.goto(route);
            const overflow = await measureHorizontalOverflow(page);
            expect(overflow.clientWidth).toBe(width);
            expect(overflow.scrollWidth, `${route} at ${width}px`).toBeLessThanOrEqual(width);
        }
    }
});
