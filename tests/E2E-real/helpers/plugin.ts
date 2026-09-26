import { expect, Page } from '@playwright/test';

/**
 * The plugin's admin screens, driven the way a user drives them. Every
 * helper clicks what the user clicks and waits for what the user sees;
 * none of them calls an AJAX action directly.
 */
export const PLUGIN_PAGE = 'wp-admin/admin.php?page=diluxone-offload';

/** Ten minutes: a sync of ~90 MB plus thumbnails, from a CI runner to Azure. */
export const LONG = 10 * 60 * 1000;

/**
 * The plugin's screens and their tabs, by tab slug (or the screen key for a
 * screen without tabs): the submenu page each one lives on. The same list as
 * Admin::screens() in the plugin.
 */
export const VIEWS: Record< string, { page: string; tab: string } > = {
	overview: { page: 'diluxone-offload', tab: '' },
	connection: { page: 'diluxone-offload-provider', tab: 'connection' },
	credentials: { page: 'diluxone-offload-provider', tab: 'credentials' },
	sync: { page: 'diluxone-offload-sync', tab: 'sync' },
	offloading: { page: 'diluxone-offload-sync', tab: 'offloading' },
	disconnect: { page: 'diluxone-offload-sync', tab: 'disconnect' },
	transfers: { page: 'diluxone-offload-settings', tab: 'transfers' },
	serving: { page: 'diluxone-offload-settings', tab: 'serving' },
	logging: { page: 'diluxone-offload-settings', tab: 'logging' },
	health: { page: 'diluxone-offload-status', tab: 'health' },
	system: { page: 'diluxone-offload-status', tab: 'system' },
};

export function tabUrl( base: string, view: string ): string {
	const v = VIEWS[ view ];
	if ( ! v ) throw new Error( `Unknown screen or tab: ${ view }` );
	return `${ base.replace( /\/$/, '' ) }/wp-admin/admin.php?page=${ v.page }${ v.tab ? `&tab=${ v.tab }` : '' }`;
}

/** Open a screen (or a tab of one) and check it rendered: the wrapper, no PHP errors. */
export async function goTab( page: Page, base: string, view: string ): Promise< void > {
	await page.goto( tabUrl( base, view ) );
	await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toBeVisible();
	await expectNoPhpErrors( page );
}

/** The URL of a screen, for waitForURL after a reload or a redirect. */
export function onScreen( view: string ): RegExp {
	return new RegExp( `page=${ VIEWS[ view ].page }` );
}

export async function expectNoPhpErrors( page: Page ): Promise< void > {
	const body = await page.locator( 'body' ).innerText();
	expect( body ).not.toMatch( /Fatal error|Warning:|Notice:|Deprecated:|critical error/i );
}

/** Fill the provider form and click Test Connection; returns the result box text. */
export async function testConnection( page: Page, creds: { account: string; key: string; container: string } ): Promise< string > {
	await page.locator( '#cloud_provider' ).selectOption( 'azure' );
	await expect( page.locator( '#azure-config' ) ).toBeVisible();
	await page.locator( '#account_name' ).fill( creds.account );
	await page.locator( '#account_key' ).fill( creds.key );
	await page.locator( '#container_name' ).fill( creds.container );
	await page.locator( '.test-connection-btn' ).click();
	const result = page.locator( '.test-connection-section .connection-result' ).first();
	await expect( result ).toContainText( /success|failed|error|invalid|denied/i, { timeout: 60_000 } );
	return ( await result.innerText() ).trim();
}

export async function saveProvider( page: Page ): Promise< void > {
	await expect( page.locator( '#submit' ) ).toBeEnabled();
	await page.locator( '#submit' ).click();
	await expect( page ).toHaveURL( onScreen( 'connection' ) );
	await expect( page.locator( '#provider-info' ) ).toBeVisible();
}

/**
 * Start (or continue) the sync from the Sync tab and wait for the completion
 * summary. Returns 'success' | 'errors' | 'failed' by which button the modal
 * ends with.
 */
export async function runSyncToCompletion( page: Page, mode: 'scratch' | 'continue' = 'scratch' ): Promise< 'success' | 'errors' | 'failed' > {
	await page.locator( '#start-sync-btn' ).click();
	await expect( page.locator( '#sync-modal' ) ).toBeVisible();
	await confirmSyncOptions( page, mode );
	return waitForSyncSummary( page );
}

/**
 * The options modal (Continue / From Scratch) appears before any upload;
 * confirm it with whichever button it offers. Used after Start Sync and
 * after Retry Failed Files, which goes through the same pre-check.
 */
export async function confirmSyncOptions( page: Page, prefer: 'scratch' | 'continue' = 'continue' ): Promise< void > {
	// When nothing is pending and everything is synced ("Complete Sync"), the
	// plugin skips the options and shows the summary straight away.
	const options = page.locator( '#continue-upload-btn, #scratch-upload-btn' );
	const summary = page.locator( '#sync-modal-summary #enable-offloading-btn, #sync-modal-summary #accept-errors-btn, #sync-modal-summary #sync-complete-close-btn' );
	await expect( options.or( summary ).first() ).toBeVisible( { timeout: 60_000 } );
	if ( await summary.count() ) {
		return;
	}
	const preferred = page.locator( prefer === 'scratch' ? '#scratch-upload-btn' : '#continue-upload-btn' );
	if ( await preferred.count() ) {
		await preferred.click();
	} else {
		await options.first().click();
	}
}

export async function waitForSyncSummary( page: Page ): Promise< 'success' | 'errors' | 'failed' > {
	const summary = page.locator( '#sync-modal-summary' );
	await expect( summary.locator( '#enable-offloading-btn, #accept-errors-btn, #sync-complete-close-btn' ).first() ).toBeVisible( { timeout: LONG } );
	if ( await summary.locator( '#enable-offloading-btn' ).count() ) return 'success';
	if ( await summary.locator( '#accept-errors-btn' ).count() ) return 'errors';
	return 'failed';
}

/** Retry the failed files from the Sync tab and wait for the completion summary. */
export async function retryFailedFiles( page: Page ): Promise< 'success' | 'errors' | 'failed' > {
	await page.locator( '.retry-failed-btn' ).first().click();
	await expect( page.locator( '#retry-upload-btn' ) ).toBeVisible( { timeout: 60_000 } );
	await page.locator( '#retry-upload-btn' ).click();
	// The retry goes through the same pre-check and confirmation as a sync.
	await confirmSyncOptions( page, 'continue' );
	return waitForSyncSummary( page );
}

/** Enable offloading from the completion modal; the page reloads into OFFLOADING_ACTIVE on the Sync tab. */
export async function enableOffloadingFromModal( page: Page ): Promise< void > {
	await page.locator( '#sync-modal-summary #enable-offloading-btn' ).click();
	await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( /enabled/i, { timeout: 60_000 } );
	await page.waitForURL( onScreen( 'sync' ), { timeout: 60_000 } );
	await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Offloading Active/, { timeout: 60_000 } );
}

/**
 * After a Disconnect the plugin is CONFIGURED with every row synced and offers
 * "Complete Sync": one click scans for changes, finds none, and offers to
 * enable offloading again. This is that path, end to end.
 */
export async function completeSyncAndEnable( page: Page ): Promise< void > {
	await expect( page.locator( '#start-sync-btn' ) ).toContainText( /Complete Sync|Continue Sync/i );
	expect( await runSyncToCompletion( page, 'continue' ) ).toBe( 'success' );
	await enableOffloadingFromModal( page );
}

/** Start a sync and cancel it from the progress modal as soon as one file has been processed. */
export async function startSyncAndCancel( page: Page ): Promise< void > {
	await page.locator( '#start-sync-btn' ).click();
	await expect( page.locator( '#scratch-upload-btn' ) ).toBeVisible( { timeout: 60_000 } );
	await page.locator( '#scratch-upload-btn' ).click();
	await expect( page.locator( '#sync-modal-progress' ) ).toBeVisible();
	await expect
		.poll( async () => Number( ( await page.locator( '#sync-modal-stats-processed' ).innerText() ).replace( /\D/g, '' ) ), { timeout: LONG } )
		.toBeGreaterThan( 0 );
	await page.locator( '#sync-modal-cancel' ).click();
	await page.waitForURL( onScreen( 'sync' ), { timeout: 60_000 } );
	// A cancelled sync leaves a continuation offer, not a fresh start.
	await expect( page.locator( '#start-sync-btn' ) ).toContainText( /Continue Sync/i );
}

export async function resetSync( page: Page ): Promise< void > {
	await page.locator( '#cancel-all-sync-btn' ).first().click();
	await expect( page.locator( '#cancel-sync-modal' ) ).toBeVisible( { timeout: 30_000 } );
	await page.locator( '#confirm-cancel-sync' ).click();
	await page.waitForURL( onScreen( 'sync' ), { timeout: 60_000 } );
	await expect( page.locator( '#start-sync-btn' ) ).toContainText( /Start Sync/i );
}

/** Delete the local copies, from the Offloading tab; the page reloads there. */
export async function deleteLocalFiles( page: Page ): Promise< { total: number } > {
	await expect( page.locator( '#delete-local-files-btn' ) ).toBeVisible();
	await page.locator( '#delete-local-files-btn' ).click();
	await expect( page.locator( '#delete-modal-info' ) ).toBeVisible( { timeout: 60_000 } );
	const total = Number( ( await page.locator( '#delete-modal-total-files' ).innerText() ).replace( /\D/g, '' ) );
	await page.locator( '#delete-modal-start' ).click();
	await expect( page.locator( '#delete-accept-btn' ) ).toBeVisible( { timeout: LONG } );
	await expect( page.locator( '#delete-modal-summary' ) ).toContainText( /completed successfully/i );
	await page.locator( '#delete-accept-btn' ).click();
	await page.waitForURL( onScreen( 'offloading' ), { timeout: 60_000 } );
	return { total };
}

/**
 * Disconnect from the cloud, from the Disconnect tab: scan, download
 * everything, offloading disabled, page reloads. When every file already
 * exists locally there is nothing to download: the plugin shows the success
 * view and reloads on its own within a couple of seconds, so the view is read
 * the moment it appears. Afterwards the plugin is CONFIGURED and the caller
 * finds "Complete Sync" on the Sync tab.
 */
export async function disconnectToCompletion( page: Page ): Promise< void > {
	await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toBeVisible();
	await page.locator( '#disconnect-from-cloud-btn' ).click();
	await expect( page.locator( '#disconnect-confirm-view' ) ).toBeVisible();
	await page.locator( '#confirm-disconnect' ).click();
	// `.first()` on a plain list would pin the first element in DOM order,
	// hidden or not; `:visible` keeps only the view that is actually shown.
	const shown = page.locator( '#disconnect-options-view:visible, #disconnect-success-view:visible, #disconnect-error-view:visible' ).first();
	await expect( shown ).toBeVisible( { timeout: LONG } );
	// The success view may already have reloaded the page by now.
	const view = await shown.getAttribute( 'id', { timeout: 5_000 } ).catch( () => 'disconnect-success-view' );
	if ( view === 'disconnect-error-view' ) {
		throw new Error( 'Disconnect failed: ' + ( await page.locator( '#disconnect-error-message' ).innerText() ) );
	}
	if ( view === 'disconnect-options-view' ) {
		await page.locator( '#start-disconnect' ).click();
		await expect( page.locator( '#disconnect-success-view' ) ).toBeVisible( { timeout: LONG } );
	}
	// The reload lands on the same URL; the button going away is what proves it.
	await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toHaveCount( 0, { timeout: 60_000 } );
}

/** Start a disconnect (from the Disconnect tab) and cancel the download at its first progress update. */
export async function startDisconnectAndCancel( page: Page ): Promise< void > {
	await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toBeVisible();
	await page.locator( '#disconnect-from-cloud-btn' ).click();
	await page.locator( '#confirm-disconnect' ).click();
	await expect( page.locator( '#disconnect-options-view' ) ).toBeVisible( { timeout: LONG } );
	await page.locator( '#start-disconnect' ).click();
	await expect( page.locator( '#disconnect-progress-view' ) ).toBeVisible();
	await expect
		.poll( async () => Number( ( await page.locator( '#disconnect-stats-downloaded' ).innerText() ).replace( /\D/g, '' ) ), { timeout: LONG } )
		.toBeGreaterThan( 0 );
	await page.locator( '#cancel-disconnect' ).click();
	await page.waitForURL( onScreen( 'disconnect' ), { timeout: 60_000 } );
	// Still offloading: the download can be resumed.
	await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toBeVisible();
}

export async function resyncAll( page: Page ): Promise< void > {
	await page.locator( '.resync-all-btn' ).first().click();
	await expect( page.locator( '#resync-confirm-btn' ) ).toBeVisible();
	await page.locator( '#resync-confirm-btn' ).click();
	await page.waitForURL( onScreen( 'sync' ), { timeout: 60_000 } );
	await expect( page.locator( '#start-sync-btn' ) ).toContainText( /Start Sync/i );
}

/** Delete the provider from the Credentials tab; the plugin lands on Connection, unconfigured. */
export async function removeProvider( page: Page ): Promise< void > {
	await expect( page.locator( '#remove-provider' ) ).toBeVisible();
	await page.locator( '#remove-provider' ).click();
	await expect( page.locator( '#remove-provider-modal' ) ).toBeVisible();
	await page.locator( '#confirm-delete-provider' ).click();
	await page.waitForURL( /page=diluxone-offload-provider&tab=connection/, { timeout: 60_000 } );
	await expect( page.locator( '#cloud_provider' ) ).toBeVisible();
}

/** Rotate the access key on the Credentials tab: test the new one, then save it. */
export async function updateKey( page: Page, key: string, expectSuccess: boolean ): Promise< void > {
	await expect( page.locator( '#new_account_key' ) ).toBeVisible();
	await page.locator( '#new_account_key' ).fill( key );
	await page.locator( '#test-new-credentials' ).click();
	const result = page.locator( '#new-credentials-result' );
	await expect( result ).toContainText( /success|failed|error|invalid|denied/i, { timeout: 60_000 } );
	if ( expectSuccess ) {
		await expect( result ).toContainText( /success/i );
		await expect( page.locator( '#save-new-credentials' ) ).toBeEnabled();
		await page.locator( '#save-new-credentials' ).click();
		await page.waitForURL( /page=diluxone-offload-provider&tab=credentials/, { timeout: 60_000 } );
		await expect( page.locator( '.notice-success' ).first() ).toBeVisible();
	} else {
		await expect( result ).not.toContainText( /success/i );
		await expect( page.locator( '#save-new-credentials' ) ).toBeDisabled();
	}
}

/** Refresh the Overview stats and return the file count it shows. */
export async function refreshStats( page: Page ): Promise< number > {
	await page.locator( '#refresh-stats-btn' ).click();
	await expect( page.locator( '#stat-file-count' ) ).not.toHaveText( /—|^$/, { timeout: 60_000 } );
	await expect( page.locator( '.diluxone-offload-loading' ) ).toHaveCount( 0, { timeout: 60_000 } );
	return Number( ( await page.locator( '#stat-file-count' ).innerText() ).replace( /\D/g, '' ) );
}

/** Upload a file through the Media Library's own uploader; returns the attachment ID. */
export async function uploadThroughMediaLibrary( page: Page, base: string, file: string ): Promise< number > {
	await page.goto( `${ base.replace( /\/$/, '' ) }/wp-admin/media-new.php?browser-uploader` );
	await page.locator( 'input[type="file"][name="async-upload"]' ).setInputFiles( file );
	await page.locator( '#html-upload' ).click();
	await page.waitForURL( /upload\.php/, { timeout: LONG } );
	await expectNoPhpErrors( page );
	// The library lists newest first; read the first row's id.
	await page.goto( `${ base.replace( /\/$/, '' ) }/wp-admin/upload.php?mode=list&orderby=date&order=desc` );
	const first = page.locator( 'table.media tbody tr' ).first();
	await expect( first ).toBeVisible();
	const id = await first.getAttribute( 'id' );
	return Number( ( id ?? '' ).replace( /\D/g, '' ) );
}
