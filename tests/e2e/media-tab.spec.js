/**
 * E2E tests for the "Nextcloud" tab of the media dialog (F3, acceptance tests 2 and 11).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const EDITOR_PASSWORD = process.env.WPNCL_E2E_USER_PASSWORD;

/**
 * Opens a new post as the "editor" user of the test environment.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @return {Promise<import('@playwright/test').Page>} Page with the block editor.
 */
async function openEditorAsEditor( browser ) {
	const context = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	// wp-login.php focuses the user field by script shortly after loading; wait for it,
	// otherwise the focus change can move typed text into the wrong field.
	await expect( page.locator( '#user_login' ) ).toBeFocused();
	await page.fill( '#user_login', 'editor' );
	await page.fill( '#user_pass', EDITOR_PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );

	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )
	);
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		preferences.set( 'core/edit-post', 'welcomeGuide', false );
		preferences.set( 'core', 'enableChoosePatternModal', false );
	} );
	await page.keyboard.press( 'Escape' );

	return page;
}

/**
 * Inserts a block and opens its media dialog on the "Nextcloud" tab.
 *
 * @param {import('@playwright/test').Page} page      Editor page.
 * @param {string}                          blockName Block name.
 */
async function openNextcloudTab( page, blockName ) {
	await page.evaluate(
		( name ) =>
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks( window.wp.blocks.createBlock( name ) ),
		blockName
	);
	await page
		.frameLocator( 'iframe[name="editor-canvas"]' )
		.getByRole( 'button', { name: 'Media Library' } )
		.click();
	await page.getByRole( 'tab', { name: 'Nextcloud' } ).click();
}

/**
 * Visible tile names.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} Tile name locator.
 */
const tileNames = ( page ) => page.locator( '.wpncl-tile__name' );

/**
 * Button of an area (folders, own albums, shared albums).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name Area label.
 * @return {import('@playwright/test').Locator} Button.
 */
const areaButton = ( page, name ) =>
	page
		.getByRole( 'group', { name: 'Source' } )
		.getByRole( 'button', { name } );

test.describe( 'Nextcloud tab', () => {
	test.skip(
		! EDITOR_PASSWORD,
		'Run with dev/bin/test-e2e.sh (WPNCL_E2E_USER_PASSWORD).'
	);

	test( 'editor browses folders, own albums and shared albums', async ( {
		browser,
	} ) => {
		const page = await openEditorAsEditor( browser );
		await openNextcloudTab( page, 'core/image' );

		await expect( tileNames( page ) ).toHaveText( [
			'Hidden Download',
			'Own',
			'Shared With Linker',
		] );

		await page
			.locator( '.wpncl-tile button[data-path="Shared With Linker"]' )
			.click();
		// The image block only offers images.
		await expect( tileNames( page ) ).toHaveText( [
			'Subfolder',
			'forest.jpg',
			'harbor.jpg',
		] );
		const thumbnails = page.locator( '.wpncl-tile img' );
		await expect( thumbnails ).toHaveCount( 2 );
		for ( const thumbnail of await thumbnails.all() ) {
			await expect
				.poll( () => thumbnail.evaluate( ( img ) => img.naturalWidth ) )
				.toBeGreaterThan( 0 );
		}

		await page.getByRole( 'button', { name: 'forest.jpg' } ).click();
		await expect( page.locator( '.wpncl-browser__count' ) ).toHaveText(
			'1 file selected'
		);

		await areaButton( page, 'My albums' ).click();
		await expect( tileNames( page ) ).toHaveText( [ 'Linker Album' ] );

		await areaButton( page, 'Shared albums' ).click();
		await expect( tileNames( page ) ).toHaveText( [
			'Group Album (content)',
			'Shared Album (content)',
		] );
		await page
			.locator( '.wpncl-tile button[data-path="Group Album (content)"]' )
			.click();
		await expect( tileNames( page ) ).toHaveText( [ 'square.png' ] );

		await areaButton( page, 'Shared albums' ).click();
		await page
			.locator( '.wpncl-tile button[data-path="Shared Album (content)"]' )
			.click();
		await expect( tileNames( page ) ).toHaveText( [
			'landscape.jpg',
			'large-original.jpg',
			'portrait.jpg',
		] );
		// Selection survives switching areas.
		await expect( page.locator( '.wpncl-browser__count' ) ).toHaveText(
			'1 file selected'
		);

		await page.context().close();
	} );

	test( 'file block offers documents; restricted files are disabled', async ( {
		browser,
	} ) => {
		const page = await openEditorAsEditor( browser );
		await openNextcloudTab( page, 'core/file' );

		await page
			.locator( '.wpncl-tile button[data-path="Shared With Linker"]' )
			.click();
		await expect( tileNames( page ) ).toContainText( [ 'sample.pdf' ] );

		await areaButton( page, 'Folders' ).click();
		await page
			.locator( '.wpncl-tile button[data-path="Hidden Download"]' )
			.click();
		await expect(
			page.locator(
				'.wpncl-tile button[data-path="Hidden Download/no-download.jpg"]'
			)
		).toBeDisabled();

		await page.context().close();
	} );

	test( 'classic "Add Media" frame allows multiple selection', async ( {
		browser,
	} ) => {
		const page = await openEditorAsEditor( browser );
		await page.evaluate( () =>
			window.wp.media( { frame: 'post', multiple: true } ).open()
		);
		await page.getByRole( 'tab', { name: 'Nextcloud' } ).click();
		await expect( tileNames( page ) ).toHaveText( [
			'Hidden Download',
			'Own',
			'Shared With Linker',
		] );

		await page
			.locator( '.wpncl-tile button[data-path="Shared With Linker"]' )
			.click();
		await page.getByRole( 'button', { name: 'forest.jpg' } ).click();
		await page.getByRole( 'button', { name: 'harbor.jpg' } ).click();
		await areaButton( page, 'Shared albums' ).click();
		await page
			.locator( '.wpncl-tile button[data-path="Group Album (content)"]' )
			.click();
		await page.getByRole( 'button', { name: 'square.png' } ).click();
		await expect( page.locator( '.wpncl-browser__count' ) ).toHaveText(
			'3 files selected'
		);

		await page
			.getByRole( 'button', { name: 'Add to media library' } )
			.click();
		await expect(
			page.locator( '.attachments-browser li.attachment.selected' )
		).toHaveCount( 3 );

		await page.context().close();
	} );

	test( 'links a file and inserts it in the image block', async ( {
		browser,
	} ) => {
		const page = await openEditorAsEditor( browser );
		await openNextcloudTab( page, 'core/image' );
		await page
			.locator( '.wpncl-tile button[data-path="Shared With Linker"]' )
			.click();
		await page.getByRole( 'button', { name: 'harbor.jpg' } ).click();
		// Single selection in the image block: the second click replaces the first.
		await page.getByRole( 'button', { name: 'forest.jpg' } ).click();
		await expect( page.locator( '.wpncl-browser__count' ) ).toHaveText(
			'1 file selected'
		);

		await page
			.getByRole( 'button', { name: 'Add to media library' } )
			.click();
		await expect(
			page.getByRole( 'tab', { name: 'Media Library' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		await expect(
			page.locator( '.attachments-browser li.attachment.selected' )
		).toHaveCount( 1 );

		await page
			.getByRole( 'button', { name: 'Select', exact: true } )
			.click();
		const image = page
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.locator( 'figure.wp-block-image img' );
		await expect( image ).toHaveAttribute(
			'src',
			/\/nextcloud-media\/\d+\/1024x683\/forest-1024x683\.jpg$/
		);

		await page.context().close();
	} );

	test( 'links a PDF in the file block', async ( { browser } ) => {
		const page = await openEditorAsEditor( browser );
		await openNextcloudTab( page, 'core/file' );
		await page
			.locator( '.wpncl-tile button[data-path="Shared With Linker"]' )
			.click();
		await page.getByRole( 'button', { name: 'sample.pdf' } ).click();
		await page
			.getByRole( 'button', { name: 'Add to media library' } )
			.click();
		await page
			.getByRole( 'button', { name: 'Select', exact: true } )
			.click();

		const link = page
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.locator( '.wp-block-file a' )
			.first();
		await expect( link ).toHaveAttribute(
			'href',
			/\/nextcloud-media\/\d+\/full\/sample\.pdf$/
		);

		await page.context().close();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllMedia();
	} );
} );
