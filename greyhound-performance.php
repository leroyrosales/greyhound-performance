<?php
/**
 * Plugin Name:       Greyhound Performance
 * Description:       Lean WordPress tuning, fewer head tags, no emoji bloat, tighter XML-RPC/pingback surface (named for the track greyhound: built for speed).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Leroy Rosales
 * Author URI:        https://leroyrosales.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       greyhound-performance
 *
 * @package Greyhound_Performance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap hooks after plugins are loaded (translations, safe load order).
 */
add_action( 'plugins_loaded', array( 'Greyhound_Performance', 'init' ), 1 );

/**
 * Performance and hardening routines.
 */
final class Greyhound_Performance {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		self::remove_head_noise();
		self::register_jquery_migrate_removal();
		self::remove_oembed_head();
		self::disable_trackbacks_and_xmlrpc();
		self::disable_emojis();
	}

	/**
	 * Remove low-value or fingerprinting output from wp_head.
	 */
	private static function remove_head_noise(): void {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );

		if (
			apply_filters(
				'greyhound_perf_remove_feed_head_links',
				apply_filters( 'sfas_perf_remove_feed_head_links', true )
			)
		) {
			remove_action( 'wp_head', 'feed_links', 2 );
		}

		remove_action( 'wp_head', 'start_post_rel_link' );
		remove_action( 'wp_head', 'index_rel_link' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
	}

	/**
	 * Drop jquery-migrate on the front end only (admin/editor may still need it).
	 */
	private static function register_jquery_migrate_removal(): void {
		add_action( 'wp_default_scripts', array( self::class, 'remove_jquery_migrate_frontend' ), 20 );
	}

	/**
	 * @param WP_Scripts $scripts WP_Scripts instance.
	 */
	public static function remove_jquery_migrate_frontend( $scripts ): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}

		$script = $scripts->registered['jquery'];
		if ( empty( $script->deps ) || ! is_array( $script->deps ) ) {
			return;
		}

		$script->deps = array_values(
			array_diff( $script->deps, array( 'jquery-migrate' ) )
		);
	}

	/**
	 * Remove oEmbed discovery and related hooks (saves requests; embed blocks still work for remote URLs in many cases).
	 */
	private static function remove_oembed_head(): void {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
	}

	/**
	 * Reduce pingback / XML-RPC attack surface.
	 */
	private static function disable_trackbacks_and_xmlrpc(): void {
		add_action( 'pre_ping', array( self::class, 'strip_internal_ping_links' ) );
		add_filter( 'wp_headers', array( self::class, 'remove_x_pingback_header' ) );
		add_filter( 'bloginfo_url', array( self::class, 'strip_pingback_url' ), 10, 2 );
		add_filter( 'bloginfo', array( self::class, 'strip_pingback_url' ), 10, 2 );

		if (
			apply_filters(
				'greyhound_perf_disable_xmlrpc',
				apply_filters( 'sfas_perf_disable_xmlrpc', true )
			)
		) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}

		add_filter( 'xmlrpc_methods', array( self::class, 'remove_xmlrpc_pingback_method' ) );
	}

	/**
	 * Remove self-references from URLs about to be pinged (avoids internal pingbacks).
	 *
	 * @param string[] $post_links URLs to ping (passed by reference; first arg of `pre_ping`).
	 */
	public static function strip_internal_ping_links( &$post_links ): void {
		if ( ! is_array( $post_links ) ) {
			return;
		}

		$home = (string) get_option( 'home', '' );
		if ( '' === $home ) {
			return;
		}

		foreach ( $post_links as $index => $link ) {
			if ( ! is_string( $link ) ) {
				continue;
			}
			if ( 0 === strpos( $link, $home ) ) {
				unset( $post_links[ $index ] );
			}
		}
		$post_links = array_values( $post_links );
	}

	/**
	 * @param string[] $headers Response headers.
	 * @return string[]
	 */
	public static function remove_x_pingback_header( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}
		unset( $headers['X-Pingback'], $headers['x-pingback'] );
		return $headers;
	}

	/**
	 * @param mixed  $output Filtered output.
	 * @param string $show   bloginfo key.
	 * @return mixed
	 */
	public static function strip_pingback_url( $output, string $show = '' ) {
		if ( 'pingback_url' === $show ) {
			return '';
		}
		return $output;
	}

	/**
	 * @param string[] $methods XML-RPC methods.
	 * @return string[]
	 */
	public static function remove_xmlrpc_pingback_method( $methods ): array {
		if ( ! is_array( $methods ) ) {
			return array();
		}
		unset( $methods['pingback.ping'] );
		return $methods;
	}

	/**
	 * Disable emoji scripts, styles, TinyMCE plugin, and DNS prefetch for emoji CDN.
	 */
	private static function disable_emojis(): void {
		add_action( 'init', array( self::class, 'remove_emoji_hooks' ), 1 );
		add_filter( 'tiny_mce_plugins', array( self::class, 'disable_emojis_tinymce' ) );
		add_filter( 'wp_resource_hints', array( self::class, 'disable_emojis_dns_prefetch' ), 10, 2 );
	}

	public static function remove_emoji_hooks(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	/**
	 * @param mixed $plugins TinyMCE plugin list.
	 * @return string[]
	 */
	public static function disable_emojis_tinymce( $plugins ): array {
		if ( ! is_array( $plugins ) ) {
			return array();
		}
		return array_values( array_diff( $plugins, array( 'wpemoji' ) ) );
	}

	/**
	 * @param string[]       $urls          URLs to hint.
	 * @param string         $relation_type Relation type.
	 * @return string[]|mixed
	 */
	public static function disable_emojis_dns_prefetch( $urls, string $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}

		/** This filter is documented in wp-includes/formatting.php */
		$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );

		return array_values( array_diff( $urls, array( $emoji_svg_url ) ) );
	}
}

/*
 * Optional: force the classic editor (uncomment in a child plugin or here if required).
 *
 * add_filter( 'use_block_editor_for_post', '__return_false' );
 */
