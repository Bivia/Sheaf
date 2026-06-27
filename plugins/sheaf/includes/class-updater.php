<?php
/**
 * GitHub-powered plugin updates.
 *
 * Lets the WordPress admin update Sheaf straight from our GitHub releases, the
 * same one-click way it would update a plugin hosted on wordpress.org. The
 * bundled plugin-update-checker library does the work: it polls the public
 * repo, compares the newest release tag to the installed Version header, and
 * when a release is newer it hands WordPress the release's prebuilt zip asset.
 *
 * Releases are produced by .github/workflows/release.yml, which only publishes
 * a zip for tags that are already merged into master.
 *
 * @package Sheaf
 */

namespace Sheaf;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Updater {

	/** Public GitHub repository that publishes Sheaf releases. */
	const REPO = 'https://github.com/BiviaBen/sheaf/';

	/** Branch releases are cut from (used only as a fallback when no release exists). */
	const BRANCH = 'master';

	/**
	 * Hook the update checker into WordPress.
	 */
	public static function register() {
		require_once SHEAF_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPO, SHEAF_FILE, 'sheaf' );
		$checker->setBranch( self::BRANCH );

		// Each release ships a prebuilt sheaf.zip whose top-level folder is "sheaf".
		// Prefer that asset over GitHub's whole-repo source zip, which would nest the
		// plugin under plugins/sheaf/ and fail to install in place.
		$api = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/sheaf\.zip$/' );
		}
	}
}
