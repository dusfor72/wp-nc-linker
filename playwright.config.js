/**
 * Playwright configuration for the E2E tests (run with dev/bin/test-e2e.sh).
 */
const path = require( 'node:path' );
const { defineConfig, devices } = require( '@playwright/test' );

process.env.WP_BASE_URL ??= 'http://localhost:8080';
process.env.STORAGE_STATE_PATH ??= path.join(
	__dirname,
	'artifacts/storage-states/admin.json'
);

module.exports = defineConfig( {
	testDir: './tests/e2e',
	outputDir: './test-results',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	reporter: process.env.CI ? 'github' : 'list',
	workers: 1,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	forbidOnly: !! process.env.CI,
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: process.env.WP_BASE_URL,
		storageState: process.env.STORAGE_STATE_PATH,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
