<?php

/**
 * WC_Brands_Coupons class.
 */
class WC_Brands_Coupons {

	const E_WC_COUPON_EXCLUDED_BRANDS = 115;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Coupon validation and error handling.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'is_coupon_valid' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'is_valid_for_product' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'brand_exclusion_error' ), 10, 3 );
	}

	/**
	 * Validate the coupon based on included and/or excluded product brands.
	 *
	 * If one of the following conditions are met, an exception will be thrown and
	 * displayed as an error notice on the cart page:
	 * 
	 * 1) Coupon has a brand requirement but no products in the cart have the brand.
	 * 2) All products in the cart match the brand exclusion rule.
	 * 3) For a cart discount, there is at least one product in cart that matches exclusion rule.
	 *
	 * @throws Exception
	 * @param bool      $valid  Whether the coupon is valid
	 * @param WC_Coupon $coupon Coupon object
	 * @return bool     $valid  True if coupon is valid, otherwise Exception will be thrown
	 */
	public function is_coupon_valid( $valid, $coupon, $discounts = null ) {
		$this->set_brand_settings_on_coupon( $coupon );

		// Only check if coupon still valid and the coupon has brand restrictions on it.
		$brand_restrictions = ( ! empty( $coupon->included_brands ) || ! empty( $coupon->excluded_brands ) );
		if ( $valid && ! $brand_restrictions && ! WC()->cart->is_empty() ) {
			return $valid;
		}

		$included_brands_match   = false;
		$excluded_brands_matches = 0;

		$items = WC()->cart->get_cart();

		// In case we're applying a coupon from the backend, use discounts items
		if ( empty( $items ) && is_callable( array( $discounts, 'get_items' ) ) ) {
			$items = array_map( function( $order_item_id ) {
				return array(
					'product_id' => $order_item_id,
				);
			}, array_keys( $discounts->get_items() ) );
		}

		// If we don't have items to work with we should just pass the original validity.
		if ( empty( $items ) ) {
			return $valid;
		}

		foreach( $items as $cart_item ) {
			$product_brands = $this->get_product_brands( $cart_item['product_id'] );

			if ( ! empty( array_intersect( $product_brands, $coupon->included_brands ) ) ) {
				$included_brands_match = true;
			}

			if ( ! empty( array_intersect( $product_brands, $coupon->excluded_brands ) ) ) {
				$excluded_brands_matches++;
			}
		}

		// 1) Coupon has a brand requirement but no products in the cart have the brand.
		if ( ! $included_brands_match && ! empty( $coupon->included_brands ) ) {
			throw new Exception( WC_Coupon::E_WC_COUPON_NOT_APPLICABLE );
		}

		// 2) All products in the cart match brand exclusion rule.
		if ( sizeof( $items ) === $excluded_brands_matches ) {
			throw new Exception( self::E_WC_COUPON_EXCLUDED_BRANDS );
		}

		// 3) For a cart discount, there is at least one product in cart that matches exclusion rule.
		if ( $coupon->is_type( 'fixed_cart' ) && $excluded_brands_matches > 0 ) {
			throw new Exception( self::E_WC_COUPON_EXCLUDED_BRANDS );
		}

		return $valid;
	}

	/**
	 * Check if a coupon is valid for a product.
	 * 
	 * This allows percentage and product discounts to apply to only
	 * the correct products in the cart.
	 *
	 * @access public
	 * @param  bool       $valid   Whether the product should get the coupon's discounts
	 * @param  WC_Product $product WC Product Object
	 * @param  WC_Coupon  $coupon  Coupon object
	 * @return bool       $valid
	 */
	public function is_valid_for_product( $valid, $product, $coupon ) {
		$this->set_brand_settings_on_coupon( $coupon );

		$product_id     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$product_brands = $this->get_product_brands( $product_id );

		// Check if coupon has a brand requirement and if this product has that brand attached.
		if ( ! empty( $coupon->included_brands ) && empty( array_intersect( $product_brands, $coupon->included_brands ) ) ) {
			return false;
		}

		// Check if coupon has a brand exclusion and if this product has that brand attached.
		if ( ! empty( $coupon->excluded_brands ) && ! empty( array_intersect( $product_brands, $coupon->excluded_brands ) ) ) {
			return false;
		}

		return $valid;
	}

	/**
	 * Display a custom error message when a cart discount coupon does not validate
	 * because an excluded brand was found in the cart.
	 *
	 * @access public
	 * @param  string $err      The error message
	 * @param  string $err_code The error code
	 * @param  object $coupon   Coupon object
	 * @return string
	 */
	public function brand_exclusion_error( $err, $err_code, $coupon ) {
		if ( self::E_WC_COUPON_EXCLUDED_BRANDS != $err_code && ! WC()->cart->is_empty() ) {
			return $err;
		}

		$this->set_brand_settings_on_coupon( $coupon );

		// Get a list of excluded brands that are present in the cart.
		$brands = array();
		foreach( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$intersect = array_intersect( $this->get_product_brands( $cart_item['product_id'] ), $coupon->excluded_brands );

			if ( ! empty( $intersect ) ) {
				foreach( $intersect as $cat_id) {
					$cat = get_term( $cat_id, 'product_brand' );
					$brands[] = $cat->name;
				}
			}
		}

		return sprintf( __( 'Sorry, this coupon is not applicable to the brands: %s.', 'wc_brands' ), implode( ', ', array_unique( $brands ) ) );
	}

	/**
	 * Get a list of brands that are assigned to a specific product
	 *
	 * @param  int   $product_id
	 * @return array brands
	 */
	private function get_product_brands( $product_id ) {
		return wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'ids' ) );
	}

	/**
	 * Set brand settings as properties on coupon object. These properties are
	 * lists of included product brand IDs and list of excluded brand IDs.
	 *
	 * @param WC_Coupon $coupon Coupon object
	 *
	 * @return void
	 */
	private function set_brand_settings_on_coupon( $coupon ) {
		if ( isset( $coupon->included_brands ) && isset( $coupon->excluded_brands ) ) {
			return;
		}

		$included_brands = get_post_meta( $coupon->get_id(), 'product_brands', true );
		if ( empty( $included_brands ) ) {
			$included_brands = array();
		}

		$excluded_brands = get_post_meta( $coupon->get_id(), 'exclude_product_brands', true );
		if ( empty( $excluded_brands ) ) {
			$excluded_brands = array();
		}

		// Store these for later to avoid multiple look-ups.
		$coupon->included_brands = $included_brands;
		$coupon->excluded_brands = $excluded_brands;
	}

}

new WC_Brands_Coupons();
