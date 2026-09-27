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
		const values = await page.locator('#cloud_provider option').evaluateAll((options) =>
			options.map((o) => (o as HTMLOptionElement).value).filter((v) => v !== '')
		);
		expect(values).toEqual(['azure', 's3']);
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

test.describe('Cloud Provider › Connection, S3-compatible', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(CONNECTION);
		await page.locator('#cloud_provider').selectOption('s3');
	});

	test('choosing S3 shows its section and hides Azure\'s', async ({ page }) => {
		await expect(page.locator('#s3-config')).toBeVisible();
		await expect(page.locator('#azure-config')).toBeHidden();
		await expect(page.locator('#s3_preset')).toHaveValue('aws');
		await expect(page.locator('.diluxone-offload-s3-hints [data-preset="aws"]')).toBeVisible();
	});

	test('the service fills in the endpoint and the public URL from region and bucket', async ({ page }) => {
		await page.locator('#s3_region').fill('eu-west-1');
		await page.locator('#s3_bucket').fill('demo');
		await expect(page.locator('#s3_endpoint')).toHaveValue('https://s3.eu-west-1.amazonaws.com');
		await expect(page.locator('#s3_public_url')).toHaveValue('https://demo.s3.eu-west-1.amazonaws.com');
	});

	test('a public URL the user typed survives a region change and is reset by another service', async ({ page }) => {
		await page.locator('#s3_bucket').fill('demo');
		await page.locator('#s3_public_url').fill('https://cdn.example.com');
		await page.locator('#s3_region').fill('eu-west-3');
		await expect(page.locator('#s3_public_url')).toHaveValue('https://cdn.example.com');
		await expect(page.locator('#s3_endpoint')).toHaveValue('https://s3.eu-west-3.amazonaws.com', { timeout: 5000 });
		await page.locator('#s3_preset').selectOption('wasabi');
		await expect(page.locator('#s3_public_url')).toHaveValue('https://s3.us-east-1.wasabisys.com/demo');
	});

	test('R2 fixes the region, leaves both URLs to the user and shows its hint', async ({ page }) => {
		await page.locator('#s3_preset').selectOption('r2');
		await expect(page.locator('#s3_region')).toHaveValue('auto');
		await expect(page.locator('#s3_region')).toHaveAttribute('readonly', '');
		await expect(page.locator('#s3_endpoint')).toHaveValue('');
		await expect(page.locator('#s3_public_url')).toHaveValue('');
		await expect(page.locator('.diluxone-offload-s3-hints [data-preset="r2"]')).toBeVisible();
		await expect(page.locator('.diluxone-offload-s3-hints [data-preset="aws"]')).toBeHidden();
	});

	test('Custom accepts a plain http endpoint and warns about it', async ({ page }) => {
		await page.locator('#s3_preset').selectOption('custom');
		await expect(page.locator('.diluxone-offload-s3-http-warning')).toBeHidden();
		await page.locator('#s3_endpoint').fill('http://minio:9000');
		await expect(page.locator('.diluxone-offload-s3-http-warning')).toBeVisible();
		await page.locator('#s3_endpoint').fill('https://minio.example.com');
		await expect(page.locator('.diluxone-offload-s3-http-warning')).toBeHidden();
	});

	test('Advanced offers the object ACL only where the service honours one, and the addressing only under Custom', async ({ page }) => {
		await page.locator('.diluxone-offload-s3-advanced summary').click();
		await expect(page.locator('.diluxone-offload-s3-acl-row')).toBeVisible();
		await expect(page.locator('#s3_path_style')).toBeDisabled();
		await expect(page.locator('#s3_path_style')).toHaveValue('virtual');
		await page.locator('#s3_preset').selectOption('r2');
		await expect(page.locator('.diluxone-offload-s3-acl-row')).toBeHidden();
		await expect(page.locator('#s3_path_style')).toHaveValue('path');
		await page.locator('#s3_preset').selectOption('custom');
		await expect(page.locator('#s3_path_style')).toBeEnabled();
	});

	test('an empty required field is blocked, and Save stays disabled without a test', async ({ page }) => {
		await expect(page.locator('#submit')).toBeDisabled();
		await page.locator('#s3-config .test-connection-btn').click();
		await expect(page.locator('#s3-config .connection-result')).toContainText(/fill in all required fields/i);
		await expect(page.locator('#submit')).toBeDisabled();
	});

	test('a test against a server that is not there fails and says so, without the secret', async ({ page }) => {
		await page.locator('#s3_preset').selectOption('custom');
		await page.locator('#s3_region').fill('us-east-1');
		await page.locator('#s3_endpoint').fill('https://s3.invalid');
		await page.locator('#s3_bucket').fill('nowhere');
		await page.locator('#s3_access_key_id').fill('AKIDMOCK');
		await page.locator('#s3_secret_access_key').fill('mock-secret-never-echoed');
		await page.locator('#s3_public_url').fill('https://s3.invalid/nowhere');
		await page.locator('#s3-config .test-connection-btn').click();
		const result = page.locator('#s3-config .connection-result');
		await expect(result).toContainText(/Connection Failed/i, { timeout: 60_000 });
		await expect(result).not.toContainText('mock-secret-never-echoed');
		await expect(page.locator('#submit')).toBeDisabled();
	});
});
