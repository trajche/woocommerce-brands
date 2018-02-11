<?php
/**
 * Plugin Name: WooCommerce Brands
 * Plugin URI: https://woocommerce.com/products/brands/
 * Description: Add brands to your products, as well as widgets and shortcodes for displaying your brands.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Developer: WooCommerce
 * Developer URI: http://woocommerce.com/
 * Requires at least: 3.3.0
 * Tested up to: 4.9
 * Version: 1.6.0
 * Text Domain: wc_brands
 * Domain Path: /languages/
 * WC tested up to: 3.3
 * WC requires at least: 2.6
 *
 * Copyright (c) 2015-2017 WooCommerce
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * Woo: 18737:8a88c7cbd2f1e73636c331c7a86f818c
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '8a88c7cbd2f1e73636c331c7a86f818c', '18737' );

if ( is_woocommerce_active() ) {

	define( 'WC_BRANDS_VERSION', '1.6.0' );

	/**
	 * Localisation
	 **/
	load_plugin_textdomain( 'wc_brands', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	/**
	 * WC_Brands classes
	 **/
	require_once( 'includes/class-wc-brands.php' );

	if ( is_admin() ) {
		require_once( 'includes/class-wc-brands-admin.php' );
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'plugin_action_links' );
	}

	register_activation_hook( __FILE__, array( 'WC_Brands', 'init_taxonomy' ), 10 );
	register_activation_hook( __FILE__, 'flush_rewrite_rules', 20 );

	/**
	 * Add custom action links on the plugin screen.
	 *
	 * @param	mixed $actions Plugin Actions Links
	 * @return	array
	 */
	function plugin_action_links( $actions ) {

		$custom_actions = array();

		// documentation url if any
		$custom_actions['docs'] = sprintf( '<a href="%s">%s</a>', 'https://docs.woocommerce.com/document/wc-brands/', __( 'Docs', 'wc_brands' ) );

		// support url
		$custom_actions['support'] = sprintf( '<a href="%s">%s</a>', 'https://support.woocommerce.com/', __( 'Support', 'wc_brands' ) );

		// changelog link
		$custom_actions['changelog'] = sprintf( '<a href="%s" target="_blank">%s</a>', 'https://www.woocommerce.com/changelogs/woocommerce-brands/changelog.txt', __( 'Changelog', 'wc_brands' ) );

		// add the links to the front of the actions list
		return array_merge( $custom_actions, $actions );
	}

	/**
	 * Helper function :: get_brand_thumbnail_url function.
	 *
	 * @access public
	 * @return string
	 */
	function get_brand_thumbnail_url( $brand_id, $size = 'full' ) {
		$thumbnail_id = get_woocommerce_term_meta( $brand_id, 'thumbnail_id', true );

		if ( $thumbnail_id )
			$thumb_src = wp_get_attachment_image_src( $thumbnail_id, $size );
			if ( ! empty( $thumb_src ) ) {
				return current( $thumb_src );
			}
	}

	/**
	 * Helper function :: get_brand_thumbnail_image function.
	 *
	 * @since 1.5.0
	 *
	 * @access public
	 * @return string
	 */
	function get_brand_thumbnail_image( $brand, $size = '' ) {
		$thumbnail_id = get_woocommerce_term_meta( $brand->term_id, 'thumbnail_id', true );

		if ( '' === $size || 'brand-thumb' === $size ) {
			$size = apply_filters( 'woocommerce_brand_thumbnail_size', 'shop_catalog' );
		}

		if ( $thumbnail_id ) {
			$image_src    = wp_get_attachment_image_src( $thumbnail_id, $size );
			$image_src    = $image_src[0];
			$dimensions   = wc_get_image_size( $size );
			$image_srcset = function_exists( 'wp_get_attachment_image_srcset' ) ? wp_get_attachment_image_srcset( $thumbnail_id, $size ) : false;
			$image_sizes  = function_exists( 'wp_get_attachment_image_sizes' ) ? wp_get_attachment_image_sizes( $thumbnail_id, $size ) : false;
		} else {
			$image_src    = wc_placeholder_img_src();
			$dimensions   = wc_get_image_size( $size );
			$image_srcset = $image_sizes = false;
		}

		// Add responsive image markup if available
		if ( $image_srcset && $image_sizes ) {
			$image = '<img src="' . esc_url( $image_src ) . '" alt="' . esc_attr( $brand->name ) . '" class="brand-thumbnail" width="' . esc_attr( $dimensions['width'] ) . '" height="' . esc_attr( $dimensions['height'] ) . '" srcset="' . esc_attr( $image_srcset ) . '" sizes="' . esc_attr( $image_sizes ) . '" />';
		} else {
			$image = '<img src="' . esc_url( $image_src ) . '" alt="' . esc_attr( $brand->name ) . '" class="brand-thumbnail" width="' . esc_attr( $dimensions['width'] ) . '" height="' . esc_attr( $dimensions['height'] ) . '" />';
		}

		return $image;
	}

	/**
	 * get_brands function.
	 *
	 * @access public
	 * @param int $post_id (default: 0)
	 * @param string $sep (default: ')
	 * @param mixed '
	 * @param string $before (default: '')
	 * @param string $after (default: '')
	 * @return void
	 */
	function get_brands( $post_id = 0, $sep = ', ', $before = '', $after = '' ) {
		global $post;

		if ( ! $post_id )
			$post_id = $post->ID;

		return get_the_term_list( $post_id, 'product_brand', $before, $sep, $after );
	}
}
