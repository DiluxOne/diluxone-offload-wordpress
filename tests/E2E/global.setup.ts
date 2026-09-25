import { test as setup, expect } from '@playwright/test';

/**
 * Log in once and persist the session for every other spec.
 *
 * Logging in per test would add a few seconds each and, more importantly,
 * trip WordPress's login rate limiting on a busy run.
 */
setup('log in to wp-admin', async ({ page }) => {
	const user = process.env.WP_USER ?? 'admin';
	const pass = process.env.WP_PASS ?? 'password';

	await page.goto('/wp-login.php');
	await page.getByLabel('Username or Email Address').fill(user);
	await page.getByLabel('Password', { exact: true }).fill(pass);
	await page.getByRole('button', { name: 'Log In' }).click();

	await expect(page).toHaveURL(/wp-admin/);
	await page.context().storageState({ path: 'build/e2e-auth.json' });
});
