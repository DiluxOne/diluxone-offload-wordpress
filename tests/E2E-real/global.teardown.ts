import fs from 'node:fs';
import { deleteContainers, readRun, RUN_FILE } from './helpers/storage';

/** The run's containers or buckets go, whatever happened; the account stays empty. */
export default async function globalTeardown(): Promise< void > {
	if ( ! fs.existsSync( RUN_FILE ) ) return;
	try {
		await deleteContainers( readRun( 'single' ) );
	} finally {
		fs.unlinkSync( RUN_FILE );
	}
}
