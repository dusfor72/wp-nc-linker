/**
 * Logs in the admin once and stores the session for the tests.
 */
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

module.exports = async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath: storageState,
	} );

	await requestUtils.setupRest();
	await requestContext.dispose();
};
