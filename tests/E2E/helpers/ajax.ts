import { Page, Route } from '@playwright/test';

/**
 * What admin-ajax.php answers to one action instead of the server: a JSON
 * body (sent with 200), `null` to drop the connection (the script's error
 * callback), 'hang' to never answer (a timeout), or a function of the posted
 * fields and the call's number for an answer that depends on them (it may
 * wait first, and may say 'continue' to let the server answer). Actions not
 * listed reach the server as usual.
 */
type AnswerValue = object | string | null | 'hang' | 'continue';
export type Answer = object | string | null | 'hang' | ( ( fields: URLSearchParams, n: number ) => AnswerValue | Promise< AnswerValue > );

export async function answerAjax( page: Page, answers: Record< string, Answer > ): Promise< void > {
	const counts: Record< string, number > = {};
	await page.route( '**/wp-admin/admin-ajax.php', async ( route: Route ) => {
		const fields = new URLSearchParams( route.request().postData() ?? '' );
		const action = ( fields.get( 'action' ) ?? '' ).replace( /^diluxone_offload_/, '' );
		if ( ! ( action in answers ) ) {
			await route.continue().catch( () => undefined );
			return;
		}
		counts[ action ] = ( counts[ action ] ?? 0 ) + 1;
		let answer = answers[ action ];
		if ( typeof answer === 'function' ) {
			answer = await answer( fields, counts[ action ] );
		}
		if ( answer === 'continue' ) {
			await route.continue().catch( () => undefined );
		} else if ( answer === 'hang' ) {
			// Never answered: the page's own timeout (or the end of the test) ends it.
		} else if ( answer === null ) {
			await route.abort( 'connectionreset' ).catch( () => undefined );
		} else {
			await route.fulfill( { status: 200, contentType: 'application/json', body: typeof answer === 'string' ? answer : JSON.stringify( answer ) } ).catch( () => undefined );
		}
	} );
}

export const ok = ( data: unknown = null ) => ( { success: true, data } );
export const refused = ( data: unknown ) => ( { success: false, data } );
