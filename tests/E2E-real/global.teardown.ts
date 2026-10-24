import fs from 'node:fs';
import { deleteContainers, readRun, RUN_FILE } from './helpers/storage';
import { uploadsPerRun, Site } from './helpers/wp';

/** The run's containers or buckets go, whatever happened; the account stays empty, and the sites upload where WordPress puts files again. */
export default async function globalTeardown(): Promise< void > {
	if ( ! fs.existsSync( RUN_FILE ) ) return;
	try {
		await deleteContainers( readRun( 'single' ) );
	} finally {
		fs.unlinkSync( RUN_FILE );
		for ( const site of [ 'single', 'network' ] as Site[] ) {
			try { uploadsPerRun( site, null ); } catch { /* the site is gone already */ }
		}
	}
}
