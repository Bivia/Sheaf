// Per-book style-set activation auto-saves over AJAX (no Save button). Toggling
// a checkbox on the book screen persists immediately and reports a status; it
// never strips styling already applied to the book's chapters.

const { test, expect } = require( '@playwright/test' );
const { setupStyleFixture, teardownStyleFixture, activeSets } = require( './helpers/fixtures' );

let fx;

test.beforeAll( () => {
	fx = setupStyleFixture();
} );

test.afterAll( () => {
	teardownStyleFixture( fx );
} );

test( 'toggling a style set on the book screen auto-saves', async ( { page } ) => {
	await page.goto( `/wp-admin/admin.php?page=sheaf-books&book=${ fx.book }` );

	const checkbox = page.locator( `.sheaf-style-set-list input[type="checkbox"][value="${ fx.set }"]` );
	const status = page.locator( '#sheaf-style-set-status' );

	// The fixture activates the set, so it starts checked.
	await expect( checkbox ).toBeChecked();

	// Uncheck → auto-saves and drops from the book's active sets.
	await checkbox.uncheck();
	await expect( status ).toHaveText( /Saved/i );
	await expect.poll( () => activeSets( fx.book ) ).not.toContain( fx.set );

	// Re-check → auto-saves and restores it.
	await checkbox.check();
	await expect( status ).toHaveText( /Saved/i );
	await expect.poll( () => activeSets( fx.book ) ).toContain( fx.set );
} );
