import { test, expect, Page } from '@playwright/test';

/**
 * Every admin screen and tab loads and is well-formed.
 *
 * This is the guard against the class of bug that bit twice during the
 * refactor: a stray </div> that pushed the WordPress footer into the middle
 * of the page, and inline <script>/<style> blocks the reviewer flagged.
 * PHPUnit renders the templates but never lays them out; a browser does.
 */

const VIEWS = [
	{ page: 'diluxone-offload', tab: '', heading: /DiluxOne Offload \| Overview/, tabs: 0 },
	{ page: 'diluxone-offload-provider', tab: 'connection', heading: /DiluxOne Offload \| Cloud Provider/, tabs: 2 },
	{ page: 'diluxone-offload-provider', tab: 'credentials', heading: /DiluxOne Offload \| Cloud Provider/, tabs: 2 },
	{ page: 'diluxone-offload-sync', tab: 'sync', heading: /DiluxOne Offload \| Sync & Offloading/, tabs: 3 },
	{ page: 'diluxone-offload-sync', tab: 'offloading', heading: /DiluxOne Offload \| Sync & Offloading/, tabs: 3 },
	{ page: 'diluxone-offload-sync', tab: 'disconnect', heading: /DiluxOne Offload \| Sync & Offloading/, tabs: 3 },
	{ page: 'diluxone-offload-settings', tab: 'transfers', heading: /DiluxOne Offload \| Settings/, tabs: 3 },
	{ page: 'diluxone-offload-settings', tab: 'serving', heading: /DiluxOne Offload \| Settings/, tabs: 3 },
	{ page: 'diluxone-offload-settings', tab: 'logging', heading: /DiluxOne Offload \| Settings/, tabs: 3 },
	{ page: 'diluxone-offload-status', tab: 'health', heading: /DiluxOne Offload \| Status/, tabs: 2 },
	{ page: 'diluxone-offload-status', tab: 'system', heading: /DiluxOne Offload \| Status/, tabs: 2 },
] as const;

const ADMIN = '/wp-admin/admin.php';
const url = ( page: string, tab = '' ) => `${ ADMIN }?page=${ page }${ tab ? `&tab=${ tab }` : '' }`;

/** Collect PHP errors WordPress prints when WP_DEBUG_DISPLAY is on, and JS errors. */
function watchForErrors(page: Page): () => string[] {
	const found: string[] = [];
	page.on('pageerror', (err) => found.push(`JS: ${err.message}`));
	page.on('console', (msg) => {
		if (msg.type() === 'error') found.push(`console: ${msg.text()}`);
	});
	return () => found;
}

for (const view of VIEWS) {
	test(`"${view.page}${view.tab ? ' › ' + view.tab : ''}" renders without errors`, async ({ page }) => {
		const errors = watchForErrors(page);

		const response = await page.goto(url(view.page, view.tab));
		expect(response?.status(), 'admin page must answer 200').toBe(200);

		// Our wrapper is present: the request reached the plugin's renderer.
		const wrap = page.locator('.wrap.diluxone-offload-admin');
		await expect(wrap).toBeVisible();

		// The heading names the plugin and the screen, the browser title too.
		await expect(wrap.locator('h1')).toHaveText(view.heading);
		await expect(page).toHaveTitle(view.heading);

		// No PHP notices/warnings/fatals leaked into the markup.
		const body = await page.locator('body').innerText();
		// PHP prints these in this exact case; the plugin's own "WARNING:" (key rotation) is not one.
		expect(body).not.toMatch(/Fatal error: |Warning: |Notice: |Deprecated: /);
		expect(body).not.toMatch(/critical error|error crítico/i);

		// No inline <script> or <style> inside our page — the review team
		// asked for everything to go through wp_enqueue_*.
		const inlineScripts = await wrap.locator('script:not([src])').count();
		const inlineStyles = await wrap.locator('style').count();
		expect(inlineScripts, 'inline <script> inside plugin markup').toBe(0);
		expect(inlineStyles, 'inline <style> inside plugin markup').toBe(0);

		// The screen's own assets were enqueued (shared admin.js/admin.css always).
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

		// The tab strip: only on a screen with tabs, marking the open one.
		const strip = wrap.locator('.nav-tab-wrapper');
		if (view.tabs === 0) {
			await expect(strip).toHaveCount(0);
		} else {
			await expect(strip.locator('.nav-tab')).toHaveCount(view.tabs);
			await expect(strip.locator('.nav-tab-active[aria-current="page"]')).toHaveCount(1);
			await expect(strip.locator('.nav-tab-active')).toHaveAttribute('href', new RegExp(`tab=${view.tab}`));
		}

		// The submenu marks this screen, and the rail is there.
		await expect(page.locator(`#adminmenu .current a[href*="page=${view.page}"]`).first()).toBeAttached();
		await expect(wrap.locator('.diluxone-offload-rail-state')).toBeVisible();

		expect(errors(), 'browser errors').toEqual([]);
	});
}

test('the menu has one submenu per screen', async ({ page }) => {
	await page.goto(url('diluxone-offload'));
	const labels = await page.locator('#adminmenu li.wp-has-current-submenu .wp-submenu a').allInnerTexts();
	expect(labels.map((l) => l.trim())).toEqual(['Overview', 'Cloud Provider', 'Sync & Offloading', 'Settings', 'Status']);
});

test('an unknown tab shows the screen\'s first tab instead of erroring', async ({ page }) => {
	const errors = watchForErrors(page);
	const response = await page.goto(url('diluxone-offload-settings', 'does-not-exist'));
	expect(response?.status()).toBe(200);
	await expect(page.locator('.nav-tab-active')).toHaveText(/Transfers/);
	expect(errors()).toEqual([]);
});

for (const legacy of [
	{ tab: 'cloud-provider', to: /page=diluxone-offload-provider&tab=connection/ },
	{ tab: 'sync', to: /page=diluxone-offload-sync&tab=sync/ },
	{ tab: 'settings', to: /page=diluxone-offload-settings&tab=transfers/ },
	{ tab: 'status-tools', to: /page=diluxone-offload-status&tab=health/ },
]) {
	test(`the old "&tab=${legacy.tab}" URL redirects to its screen`, async ({ page }) => {
		const response = await page.goto(url('diluxone-offload', legacy.tab));
		expect(response?.status()).toBe(200);
		await expect(page).toHaveURL(legacy.to);
		await expect(page.locator('.wrap.diluxone-offload-admin')).toBeVisible();
	});
}
