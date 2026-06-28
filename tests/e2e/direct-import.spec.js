// End-to-end Phase 6: import a .docx that carries direct (unnamed) formatting,
// map the detected cluster to a new style in the book's active set, and verify
// the created draft carries the mapped class and the new style holds the props.

const { test, expect } = require( '@playwright/test' );
const { setupStyleFixture, teardownStyleFixture, cleanupE2E } = require( './helpers/fixtures' );
const { wpEvalJson } = require( './helpers/wp' );
const { makeDirectDocx } = require( './helpers/docx' );

let fx;

test.beforeAll( () => {
	fx = setupStyleFixture(); // book with an active set
} );

test.afterAll( () => {
	teardownStyleFixture( fx );
	cleanupE2E();
} );

test( 'map an unnamed (direct) formatting cluster to a new style on import', async ( { page } ) => {
	const buffer = await makeDirectDocx();

	// Upload.
	await page.goto( `/wp-admin/admin.php?page=sheaf-import&sheaf_book=${ fx.book }` );
	const unnamed = page.locator( 'input[name="settings[keep_unnamed_styles]"]' );
	if ( ! ( await unnamed.isChecked() ) ) {
		await unnamed.check();
	}
	await page.locator( 'input[name="sheaf_files[]"]' ).setInputFiles( {
		name: 'E2E Direct.docx',
		mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		buffer,
	} );
	await page.getByRole( 'button', { name: /Upload and preview/i } ).click();

	// The Unnamed styles section + its cluster row.
	await expect( page.getByRole( 'heading', { name: 'Unnamed styles' } ) ).toBeVisible();
	await expect( page.locator( 'code', { hasText: /Courier New, 10pt/ } ) ).toBeVisible();
	await expect( page.locator( '.sheaf-direct-apply' ) ).toBeVisible(); // bulk control present

	// Map the cluster to a new style in the book's active set.
	await page.locator( '.sheaf-direct-select' ).selectOption( `new:${ fx.set }` );

	await page.getByRole( 'button', { name: /Create .*draft/i } ).click( { force: true } );
	await page.waitForLoadState( 'load' );

	// A new inline style carrying the direct props was created, and a draft
	// chapter carries its class.
	const result = wpEvalJson( `
		$sd = \\Sheaf\\Style_Sets::get_set( '${ fx.set }' );
		$class = '';
		$has_props = false;
		foreach ( (array) ( $sd['styles'] ?? [] ) as $key => $style ) {
			if ( 'Courier New' === ( $style['props']['font-family'] ?? '' ) ) {
				$class = \\Sheaf\\Style_Sets::style_class( '${ fx.set }', $key );
				$has_props = true;
			}
		}
		$chaps = get_posts( [ 'post_type' => 'sheaf_chapter', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => \\Sheaf\\Books::BOOK_META, 'meta_value' => ${ fx.book }, 'fields' => 'ids' ] );
		$in_content = false;
		foreach ( $chaps as $cid ) {
			if ( $class && false !== strpos( (string) get_post_field( 'post_content', $cid ), $class ) ) { $in_content = true; }
		}
		echo wp_json_encode( [ 'class' => $class, 'has_props' => $has_props, 'in_content' => $in_content ] );
	` );

	expect( result.has_props, 'a new style carrying the direct props was created' ).toBe( true );
	expect( result.class ).not.toBe( '' );
	expect( result.in_content, 'the imported draft carries the mapped class' ).toBe( true );
} );
