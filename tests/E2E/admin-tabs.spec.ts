import { test, expect, Page } from '@playwright/test';

/**
 * Every admin tab loads and is well-formed.
 *
 * This is the guard against the class of bug that bit twice during the
 * refactor: a stray </div> that pushed the WordPress footer into the middle
 * of the page, and inline <script>/<style> blocks the reviewer flagged.
 * PHPUnit renders the templates but never lays them out; a browser does.
 */

const TABS = [
	{ slug: 'overview', heading: /Welcome to DiluxOne Offload/ },
	{ slug: 'cloud-provider', heading: /Cloud Provider|Proveedor/ },
	{ slug: 'sync-offloading', heading: /Sync|Sincronizaci/ },
	{ slug: 'settings', heading: /Settings|Configuraci/ },
	{ slug: 'status', heading: /Status|Estado/ },
] as const;

const PLUGIN_PAGE = '/wp-admin/admin.php?page=diluxone-offload';

/** Collect PHP errors WordPress prints when WP_DEBUG_DISPLAY is on, and JS errors. */
function watchForErrors(page: Page): () => string[] {
	const found: string[] = [];
	page.on('pageerror', (err) => found.push(`JS: ${err.message}`));
	page.on('console', (msg) => {
		if (msg.type() === 'error') found.push(`console: ${msg.text()}`);
	});
	return () => found;
}

for (const tab of TABS) {
	test(`tab "${tab.slug}" renders without errors`, async ({ page }) => {
		const errors = watchForErrors(page);

		const response = await page.goto(`${PLUGIN_PAGE}&tab=${tab.slug}`);
		expect(response?.status(), 'admin page must answer 200').toBe(200);

		// Our wrapper is present: the request reached the plugin's renderer.
		const wrap = page.locator('.wrap.diluxone-offload-admin');
		await expect(wrap).toBeVisible();

		// No PHP notices/warnings/fatals leaked into the markup.
		const body = await page.locator('body').innerText();
		expect(body).not.toMatch(/Fatal error|Warning:|Notice:|Deprecated:/);
		expect(body).not.toMatch(/critical error|error crítico/i);

		// No inline <script> or <style> inside our page — the review team
		// asked for everything to go through wp_enqueue_*.
		const inlineScripts = await wrap.locator('script:not([src])').count();
		const inlineStyles = await wrap.locator('style').count();
		expect(inlineScripts, 'inline <script> inside plugin markup').toBe(0);
		expect(inlineStyles, 'inline <style> inside plugin markup').toBe(0);

		// The tab's own assets were enqueued (shared admin.js/admin.css always).
		await expect(page.locator('link[id^="diluxone-offload-admin"]').first()).toBeAttached();
		await expect(page.locator('script[id^="diluxone-offload-admin"]').first()).toBeAttached();

		// Layout integrity: the WordPress footer sits below our content, not
		// inside it. A stray </div> makes it float up next to the cards.
		const footer = page.locator('#wpfooter');
		await expect(footer).toBeAttached();
		const wrapBox = await wrap.boundingBox();
		const footerBox = await footer.boundingBox();
		expect(wrapBox && footerBox).toBeTruthy();
		expect(footerBox!.y).toBeGreaterThanOrEqual(wrapBox!.y + wrapBox!.height - 1);

		// The tab nav marks this tab active.
		await expect(page.locator('.nav-tab-active, .nav-tab.active, [aria-current="page"]').first()).toBeVisible();

		expect(errors(), 'browser errors').toEqual([]);
	});
}

test('an unknown tab falls back to overview instead of erroring', async ({ page }) => {
	const errors = watchForErrors(page);
	const response = await page.goto(`${PLUGIN_PAGE}&tab=does-not-exist`);
	expect(response?.status()).toBe(200);
	await expect(page.getByText(TABS[0].heading)).toBeVisible();
	expect(errors()).toEqual([]);
});

test('legacy "status-tools" tab alias still resolves', async ({ page }) => {
	const response = await page.goto(`${PLUGIN_PAGE}&tab=status-tools`);
	expect(response?.status()).toBe(200);
	await expect(page.locator('.wrap.diluxone-offload-admin')).toBeVisible();
});
