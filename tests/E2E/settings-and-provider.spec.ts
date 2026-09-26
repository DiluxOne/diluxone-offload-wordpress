import { test, expect } from '@playwright/test';

/**
 * User-facing flows that need no cloud account.
 *
 * Anything that talks to Azure or DiluxOne is out of scope here on purpose:
 * an E2E suite that needs real credentials is one nobody runs. These cover
 * the parts a reviewer clicks through first — saving settings and being told
 * clearly when a provider form is incomplete.
 */

const ADMIN = '/wp-admin/admin.php';
const LOGGING = `${ADMIN}?page=diluxone-offload-settings&tab=logging`;
const CONNECTION = `${ADMIN}?page=diluxone-offload-provider&tab=connection`;

test.describe('Settings › Logging', () => {
	test('debug logging toggle round-trips through save', async ({ page }) => {
		await page.goto(LOGGING);

		const toggle = page.locator('input[name="enable_debug_logging"]');
		await expect(toggle).toBeVisible();

		const before = await toggle.isChecked();
		await toggle.setChecked(!before);
		await page.getByRole('button', { name: /Save|Guardar/ }).first().click();

		// Back on the Logging tab with the new value persisted.
		await expect(page).toHaveURL(/page=diluxone-offload-settings&tab=logging/);
		await expect(page.locator('input[name="enable_debug_logging"]')).toBeChecked({ checked: !before });

		// Restore, so the run leaves the site as it found it.
		await page.locator('input[name="enable_debug_logging"]').setChecked(before);
		await page.getByRole('button', { name: /Save|Guardar/ }).first().click();
		await expect(page.locator('input[name="enable_debug_logging"]')).toBeChecked({ checked: before });
	});

	test('saving settings shows a confirmation notice', async ({ page }) => {
		await page.goto(LOGGING);
		await page.getByRole('button', { name: /Save|Guardar/ }).first().click();
		await expect(page.locator('.notice-success, .updated').first()).toBeVisible();
	});
});

test.describe('Settings › Transfers', () => {
	test('a save on one tab leaves the other tabs\' settings alone', async ({ page }) => {
		// Serving: force HTTPS off.
		await page.goto(`${ADMIN}?page=diluxone-offload-settings&tab=serving`);
		const https = page.locator('input[name="force_https_on_cloud"]');
		const before = await https.isChecked();
		await https.setChecked(false);
		await page.getByRole('button', { name: /Save|Guardar/ }).first().click();
		await expect(page.locator('input[name="force_https_on_cloud"]')).not.toBeChecked();

		// Transfers: save a timeout. The Serving checkbox must stay off.
		await page.goto(`${ADMIN}?page=diluxone-offload-settings&tab=transfers`);
		await page.locator('#timeout').fill('90');
		await page.getByRole('button', { name: /Save|Guardar/ }).first().click();
		await expect(page).toHaveURL(/tab=transfers/);
		await expect(page.locator('#timeout')).toHaveValue('90');
		await page.goto(`${ADMIN}?page=diluxone-offload-settings&tab=serving`);
		await expect(page.locator('input[name="force_https_on_cloud"]')).not.toBeChecked();

		// Restore.
		await page.locator('input[name="force_https_on_cloud"]').setChecked(before);
		await page.getByRole('button', { name: /Save|Guardar/ }).first().click();
		await expect(page.locator('input[name="force_https_on_cloud"]')).toBeChecked({ checked: before });
	});
});

test.describe('Cloud Provider › Connection', () => {
	test('lists the implemented providers only', async ({ page }) => {
		await page.goto(CONNECTION);
		// 1.0.0 ships one provider: the select offers Azure and nothing else.
		const values = await page.locator('#cloud_provider option').evaluateAll((options) =>
			options.map((o) => (o as HTMLOptionElement).value).filter((v) => v !== '')
		);
		expect(values).toEqual(['azure']);
	});

	test('submitting Azure with empty fields does not silently succeed', async ({ page }) => {
		await page.goto(CONNECTION);

		const azure = page.locator('input[type="radio"][value="azure"], select[name="cloud_provider"]').first();
		if ((await azure.getAttribute('type')) === 'radio') {
			await azure.check();
		} else {
			await azure.selectOption('azure');
		}

		// Leave every credential field empty and try to save.
		const save = page.getByRole('button', { name: /Save|Guardar|Test Connection|Probar/ }).first();
		await save.click();

		// Either the browser blocks it (required attributes) or the server
		// answers with an error notice. Both are acceptable; "configured" is not.
		const status = await page.locator('.wrap.diluxone-offload-admin').innerText();
		expect(status).not.toMatch(/Connection successful|Conexión exitosa/);

		const blockedByBrowser = await page.locator('input:invalid').count();
		const serverError = await page.locator('.notice-error, .error, .diluxone-offload-notice-error').count();
		expect(blockedByBrowser + serverError, 'an empty form must be rejected somewhere').toBeGreaterThan(0);
	});
});
