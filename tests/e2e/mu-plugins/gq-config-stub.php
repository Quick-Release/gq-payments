<?php
/**
 * Plugin Name: gq-config stub (tests)
 * Description: Emulates the parts of the private gq-config plugin GQ Payments integrates with: the GETQUICK menu and the anonymous restricted-REST gate.
 *
 * Options that steer it:
 * - gq_test_gqconfig_menu = "off": remove the GETQUICK menu.
 * - gq_test_anonymous_rest_mode = "restricted": turn on restricted REST mode (default "core").
 * - gq_test_public_rest_routes: extra allow-list entries, array { "/route" | "/prefix/*": [ "GET", … ] },
 *   added before the getquick_config_public_rest_routes filter runs (stands in for other plugins' entries,
 *   e.g. /wc/store/v1/* for the storefront).
 *
 * @package GetQuick\Payments\Tests
 */

add_action(
	'admin_menu',
	static function (): void {
		if ( 'off' === get_option( 'gq_test_gqconfig_menu' ) ) {
			return;
		}
		// Same registration as gq-config/includes/class-admin-page.php.
		add_menu_page(
			'GETQUICK Options',
			'GETQUICK',
			'manage_options',
			'getquick-options',
			static function (): void {
				echo '<div class="wrap"><h1>GETQUICK Options</h1></div>';
			},
			'',
			56
		);
	}
);

// Same logic as gq-config/includes/class-access-policy.php Access_Policy::rest_request()
// (gq-config's settings store is replaced by option gq_test_anonymous_rest_mode).
add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) {
		if ( is_wp_error( $result ) || is_user_logged_in() ) {
			return $result;
		}
		$route = $request->get_route();
		// This gate cannot be overridden by an anonymous allowlist entry.
		if ( preg_match( '#^/wp/v2/users(?:/|$)#i', $route ) ) {
			return new WP_Error( 'getquick_account_auth_required', 'Authentication is required.', [ 'status' => 401 ] );
		}
		if ( 'restricted' !== get_option( 'gq_test_anonymous_rest_mode', 'core' ) ) {
			return $result;
		}
		// Exact route => allowed methods. Namespace wildcards must be explicit /prefix/*.
		$defaults = [ '/getquick-config/v1/storefront' => [ 'GET', 'HEAD', 'OPTIONS' ] ] + (array) get_option( 'gq_test_public_rest_routes', [] );
		$routes   = (array) apply_filters( 'getquick_config_public_rest_routes', $defaults, $request );
		foreach ( $routes as $pattern => $methods ) {
			$matches = $route === $pattern;
			if ( str_ends_with( $pattern, '/*' ) ) {
				$prefix  = substr( $pattern, 0, -1 );
				$matches = str_starts_with( $route, $prefix );
			}
			if ( $matches && in_array( $request->get_method(), (array) $methods, true ) ) {
				return $result;
			}
		}
		return new WP_Error( 'getquick_rest_auth_required', 'Authentication is required.', [ 'status' => 401 ] );
	},
	20,
	3
);
