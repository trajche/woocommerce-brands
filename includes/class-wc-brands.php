<?php

/**
 * WC_Brands class.
 */
class WC_Brands {

	const E_WC_COUPON_EXCLUDED_BRANDS = 115;

	var $template_url;
	var $plugin_path;

	/**
	 * __construct function.
	 */
	public function __construct() {
		$this->template_url = apply_filters( 'woocommerce_template_url', 'woocommerce/' );

		if ( function_exists( 'add_image_size' ) ) {
			add_image_size( 'brand-thumb', 300, 9999 );
		}

		add_action( 'woocommerce_loaded', array( $this, 'register_hooks' ) );

		$this->register_shortcodes();
	}

	/**
	 * Register our hooks
	 *
	 */
	public function register_hooks() {
		add_action( 'woocommerce_register_taxonomy', array( __CLASS__, 'init_taxonomy' ) );
		add_action( 'widgets_init', array( $this, 'init_widgets' ) );
		add_filter( 'template_include', array( $this, 'template_loader' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'wp', array( $this, 'body_class' ) );

		add_action( 'woocommerce_product_meta_end', array( $this, 'show_brand' ) );

		// Coupon validation and error handling.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon' ), null, 2 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'add_coupon_error_message' ), null, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'maybe_apply_discount' ), null, 5 );

		add_filter( 'post_type_link', array( $this, 'post_type_link' ), 11, 2 );

		if ( 'yes' === get_option( 'wc_brands_show_description' ) ) {
			add_action( 'woocommerce_archive_description', array( $this, 'brand_description' ) );
		}

		add_filter( 'loop_shop_post_in', array( $this, 'woocommerce_brands_layered_nav_init' ) );

		// REST API.
		add_action( 'rest_api_init', array( $this, 'rest_api_register_routes' ) );
		add_action( 'woocommerce_rest_insert_product', array( $this, 'rest_api_maybe_set_brands' ), 10, 2 );
		add_filter( 'woocommerce_rest_prepare_product', array( $this, 'rest_api_prepare_brands_to_product' ), 10, 2 ); // WC 2.6.x
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'rest_api_prepare_brands_to_product' ), 10, 2 ); // WC 3.x
		add_action( 'woocommerce_rest_insert_product', array( $this, 'rest_api_add_brands_to_product' ), 10, 3 ); // WC 2.6.x
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'rest_api_add_brands_to_product' ), 10, 3 ); // WC 3.x
	}

	/**
	 * Layered Nav Init
	 *
	 * @package 	WooCommerce/Widgets
	 * @access public
	 * @return void
	 */
	public function woocommerce_brands_layered_nav_init( $filtered_posts ) {
		$_chosen_attributes = WC_Query::get_layered_nav_chosen_attributes();

		if ( is_active_widget( false, false, 'woocommerce_brand_nav', true ) && ! is_admin() ) {

			if ( ! empty( $_GET[ 'filter_product_brand' ] ) ) {

				$terms 	= array_map( 'intval', explode( ',', $_GET[ 'filter_product_brand' ] ) );

				if ( sizeof( $terms ) > 0 ) {
					$matched_products = get_posts(
						array(
							'post_type'     => 'product',
							'numberposts'   => -1,
							'post_status'   => 'publish',
							'fields'        => 'ids',
							'no_found_rows' => true,
							'tax_query'     => array(

								'relation' => 'AND',
								array(
									'taxonomy' => 'product_brand',
									'terms'    => $terms,
									'field'    => 'id'
								)
							)
						)
					);

					$filtered_posts = array_merge( $filtered_posts, $matched_products );

					if ( sizeof( $filtered_posts ) == 0 ) {
						$filtered_posts = $matched_products;
					} else {
						$filtered_posts = array_intersect( $filtered_posts, $matched_products );
					}

				}

			}

		}

		return (array) $filtered_posts;
	}

	/**
	 * Filter to allow product_brand in the permalinks for products.
	 *
	 * @access public
	 * @param string $permalink The existing permalink URL.
	 * @param WP_Post $post
	 * @return string
	 */
	public function post_type_link( $permalink, $post ) {
		// Abort if post is not a product
		if ( $post->post_type !== 'product' )
			return $permalink;

		// Abort early if the placeholder rewrite tag isn't in the generated URL
		if ( false === strpos( $permalink, '%' ) )
			return $permalink;

		// Get the custom taxonomy terms in use by this post
		$terms = get_the_terms( $post->ID, 'product_brand' );

		if ( empty( $terms ) ) {
			// If no terms are assigned to this post, use a string instead (can't leave the placeholder there)
			$product_brand = _x( 'uncategorized', 'slug', 'wc_brands' );
		} else {
			// Replace the placeholder rewrite tag with the first term's slug
			$first_term = array_shift( $terms );
			$product_brand = $first_term->slug;
		}

		$find = array(
			'%product_brand%'
		);

		$replace = array(
			$product_brand
		);

		$replace = array_map( 'sanitize_title', $replace );

		$permalink = str_replace( $find, $replace, $permalink );

		return $permalink;
	} // End post_type_link()

	/**
	 * Display a specific error message if the coupon doesn't validate because of a brands-related element.
	 *
	 * @access public
	 * @since  1.3.0
	 * @param  string $err        The error message
	 * @param  string $err_code   The error code
	 * @param  object $coupon_obj Cart object
	 * @return string
	 */
	public function add_coupon_error_message( $err, $err_code, $coupon_obj ) {
		if ( self::E_WC_COUPON_EXCLUDED_BRANDS == $err_code ) {
			$this->_set_brand_settings_on_coupon( $coupon_obj );

			$brands = array();
			if ( sizeof( WC()->cart->get_cart() ) > 0 ) {
				foreach( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

					$product_brands = wp_get_post_terms( $cart_item['product_id'], 'product_brand', array( 'fields' => 'ids' ) );
					if ( sizeof( $intersect = array_intersect( $product_brands, $coupon_obj->excluded_brands ) ) > 0 ) {
						foreach( $intersect as $cat_id) {
							$cat = get_term( $cat_id, 'product_brand' );
							$brands[] = $cat->name;
						}
					}
				}
			}

			$err = sprintf( __( 'Sorry, this coupon is not applicable to the brands: %s.', 'wc_brands' ), implode( ', ', array_unique( $brands ) ) );
		}
		return $err;
	}

	/**
	 * Conditionally apply brands discounts.
	 *
	 * @access private
	 * @since  1.3.1
	 * @return  void
	 */
	public function maybe_apply_discount( $discount, $discounting_amount, $cart_item, $single, $this_obj ) {
		// Deal only with product-centric coupons.
		if ( ! is_a( $this_obj, 'WC_Coupon' ) || ! $this_obj->is_type( array( 'fixed_product', 'percent_product' ) ) ) {
			return $discount;
		}

		$product_brands = wp_get_post_terms( $cart_item['product_id'], 'product_brand', array( 'fields' => 'ids' ) );

		// If our coupon brands aren't present in the products in our cart, don't assign the discount.
		if ( ! empty( $this_obj->included_brands ) && ! $this->_product_has_brands( $product_brands, $this_obj->included_brands ) ) {
			$discount = 0;
		}

		// If our excluded coupon brands are present in the products in our cart, don't assign the discount.
		if ( ! empty( $this_obj->excluded_brands ) && $this->_product_has_brands( $product_brands, $this_obj->excluded_brands ) ) {
			$discount = 0;
		}

		return $discount;
	}

	/**
	 * Check whether given product brands are assigned to the current coupon being inspected.
	 *
	 * @access private
	 * @since  1.3.1
	 * @return  void
	 */
	private function _product_has_brands( $product_brands, $coupon_brands ) {
		return sizeof( array_intersect( $product_brands, $coupon_brands ) ) > 0;
	}

	/**
	 * This validate coupon based on included and/or excluded product brands on
	 * a given coupon.
	 *
	 * If followings conditions are met, exception will be thrown and displayed
	 * as error notice on the cart page:
	 *
	 * * Coupon has Product Brands restriction set and no item in the cart is associated
	 *   with the Product Brands.
	 * * For cart-based discount, NOT all items are in Product Brands.
	 * * Coupon has Exclude Brands restriction set and all items in the cart are associated
	 *   with the Exclude Brands.
	 * * For cart-based discount, part of cart items are in the Exclude Brands restriction.
	 *
	 * @throws Exception
	 *
	 * @param bool      $valid      Whether the coupon is valid
	 * @param WC_Coupon $coupon_obj Coupon object
	 *
	 * @return bool True if coupon is valid, otherwise Exception will be thrown
	 */
	public function validate_coupon( $valid, $coupon_obj ) {
		$this->_set_brand_settings_on_coupon( $coupon_obj );

		// Only check if coupon still valid.
		if ( $valid ) {
			$valid = $this->_validate_included_product_brands( $valid, $coupon_obj );
			$valid = $this->_validate_excluded_product_brands( $valid, $coupon_obj );
		}

		return $valid;
	}

	/**
	 * Set brand settings as properties on coupon object. These properties are
	 * list of included product brand IDs and list of excluded brand IDs.
	 *
	 * @param WC_Coupon $coupon_obj Coupon object
	 *
	 * @return void
	 */
	private function _set_brand_settings_on_coupon( $coupon_obj ) {
		if ( isset( $coupon_obj->included_brands ) && isset( $coupon_obj->excluded_brands ) ) {
			return;
		}

		$coupon_id = is_callable( array( $coupon_obj, 'get_id' ) ) ? $coupon_obj->get_id() : $coupon_obj->id;
		$included_product_brands = get_post_meta( $coupon_id, 'product_brands', true );
		if ( empty( $included_product_brands ) ) {
			$included_product_brands = array();
		}
		$excluded_product_brands = get_post_meta( $coupon_id, 'exclude_product_brands', true );
		if ( empty( $excluded_product_brands ) ) {
			$excluded_product_brands = array();
		}

		// Store these for later, to avoid multiple look-ups when we filter on the discount.
		$coupon_obj->included_brands = $included_product_brands;
		$coupon_obj->excluded_brands = $excluded_product_brands;
	}

	/**
	 * Validate whether cart items are in Product Brands restriction. If no item
	 * is in Product Brands then Exception will be thrown. Or, if coupon is cart-
	 * based discount, Exception will be thrown if NOT all items are in Product Brands.
	 *
	 * @throws Exception
	 *
	 * @param bool      $valid
	 * @param WC_Coupon $coupon_obj
	 *
	 * @return bool
	 */
	private function _validate_included_product_brands( $valid, $coupon_obj ) {
		if ( sizeof( $coupon_obj->included_brands ) > 0 ) {
			$num_items_match_included = 0;
			if ( ! WC()->cart->is_empty() ) {
				foreach( WC()->cart->get_cart() as $cart_item ) {
					$product_brands = wp_get_post_terms( $cart_item['product_id'], 'product_brand', array( 'fields' => 'ids' ) );
					if ( $this->_product_has_brands( $product_brands, $coupon_obj->included_brands ) ) {
						$num_items_match_included++;
					}
				}
			}

			// For cart-based discount, all items MUST BE in Product Brands.
			if ( $coupon_obj->is_type( array( 'fixed_cart', 'percent' ) ) && $num_items_match_included < sizeof( WC()->cart->get_cart() ) ) {
				throw new Exception( $coupon_obj::E_WC_COUPON_NOT_APPLICABLE );
			}

			// No item in Product Brands.
			if ( $num_items_match_included === 0 ) {
				throw new Exception( $coupon_obj::E_WC_COUPON_NOT_APPLICABLE );
			}
		}

		return $valid;
	}

	/**
	 * Validate whether cart items are in the Exclude Brands restriction.
	 *
	 * If coupon has Exclude Brands restriction set and all items in the cart are associated
	 * with the Exclude Brands then Exception will be thrown.
	 *
	 * For cart-based discount, if part of cart items are in the Exclude Brands restriction
	 * then Exception will be thrown.
	 *
	 * @throws Exception
	 *
	 * @param bool      $valid
	 * @param WC_Coupon $coupon_obj
	 *
	 * @return bool
	 */
	private function _validate_excluded_product_brands( $valid, $coupon_obj ) {
		if ( sizeof( $coupon_obj->excluded_brands ) > 0 ) {
			$num_items_match_excluded = 0;
			if ( ! WC()->cart->is_empty() ) {
				foreach( WC()->cart->get_cart() as $cart_item ) {
					$product_brands = wp_get_post_terms( $cart_item['product_id'], 'product_brand', array( 'fields' => 'ids' ) );
					if ( $this->_product_has_brands( $product_brands, $coupon_obj->excluded_brands ) ) {
						$num_items_match_excluded++;
					}
				}
			}

			// If all items in the cart are in Exclude Brands properties, coupon
			// is not applicable.
			if ( sizeof( WC()->cart->get_cart() ) === $num_items_match_excluded ) {
				throw new Exception( self::E_WC_COUPON_EXCLUDED_BRANDS );
			}

			// For cart-based discount, if at least on item in the Exclude Brands then
			// coupon is not applicable.
			if ( $coupon_obj->is_type( array( 'fixed_cart', 'percent' ) ) && $num_items_match_excluded > 0 ) {
				throw new Exception( self::E_WC_COUPON_EXCLUDED_BRANDS );
			}
		}

		return $valid;
	}

	public function body_class() {
		if ( is_tax( 'product_brand' ) ) {
			add_filter( 'body_class', array( $this, 'add_body_class' ) );
		}
	}

	public function add_body_class( $classes ) {
		$classes[] = 'woocommerce';
		$classes[] = 'woocommerce-page';
		return $classes;
	}

	public function styles() {
		wp_enqueue_style( 'brands-styles', plugins_url( '/assets/css/style.css', dirname( __FILE__ ) ) );
	}

	/**
	 * init_taxonomy function.
	 *
	 * @access public
	 */
	public static function init_taxonomy() {
		global $woocommerce;

		$shop_page_id = wc_get_page_id( 'shop' );

		$base_slug = $shop_page_id > 0 && get_page( $shop_page_id ) ? get_page_uri( $shop_page_id ) : 'shop';

		$category_base = get_option('woocommerce_prepend_shop_page_to_urls') == "yes" ? trailingslashit( $base_slug ) : '';

		register_taxonomy( 'product_brand',
			array('product'),
			apply_filters( 'register_taxonomy_product_brand', array(
				'hierarchical'          => true,
				'update_count_callback' => '_update_post_term_count',
				'label'                 => __( 'Brands', 'wc_brands'),
				'labels'                => array(
						'name'              => __( 'Brands', 'wc_brands' ),
						'singular_name'     => __( 'Brand', 'wc_brands' ),
						'search_items'      => __( 'Search Brands', 'wc_brands' ),
						'all_items'         => __( 'All Brands', 'wc_brands' ),
						'parent_item'       => __( 'Parent Brand', 'wc_brands' ),
						'parent_item_colon' => __( 'Parent Brand:', 'wc_brands' ),
						'edit_item'         => __( 'Edit Brand', 'wc_brands' ),
						'update_item'       => __( 'Update Brand', 'wc_brands' ),
						'add_new_item'      => __( 'Add New Brand', 'wc_brands' ),
						'new_item_name'     => __( 'New Brand Name', 'wc_brands' )
				),

				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'capabilities'      => array(
					'manage_terms' => 'manage_product_terms',
					'edit_terms'   => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms'
				),

				'rewrite' => array( 'slug' => $category_base . __( 'brand', 'wc_brands' ), 'with_front' => false, 'hierarchical' => true )
			) )
		);
	}

	/**
	 * init_widgets function.
	 *
	 * @access public
	 */
	public function init_widgets() {

		// Inc
		require_once( 'widgets/class-wc-widget-brand-description.php' );
		require_once( 'widgets/class-wc-widget-brand-nav.php' );
		require_once( 'widgets/class-wc-widget-brand-thumbnails.php' );

		// Register
		register_widget( 'WC_Widget_Brand_Description' );
		register_widget( 'WC_Widget_Brand_Nav' );
		register_widget( 'WC_Widget_Brand_Thumbnails' );
	}

	/**
	 * Get the plugin path
	 */
	public function plugin_path() {
		if ( $this->plugin_path ) return $this->plugin_path;

		return $this->plugin_path = untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) );
	}

	/**
	 * template_loader
	 *
	 * Handles template usage so that we can use our own templates instead of the themes.
	 *
	 * Templates are in the 'templates' folder. woocommerce looks for theme
	 * overides in /theme/woocommerce/ by default
	 *
	 * For beginners, it also looks for a woocommerce.php template first. If the user adds
	 * this to the theme (containing a woocommerce() inside) this will be used for all
	 * woocommerce templates.
	 */
	public function template_loader( $template ) {

		$find = array( 'woocommerce.php' );
		$file = '';

		if ( is_tax( 'product_brand' ) ) {

			$term = get_queried_object();

			$file   = 'taxonomy-' . $term->taxonomy . '.php';
			$find[] = 'taxonomy-' . $term->taxonomy . '-' . $term->slug . '.php';
			$find[] = $this->template_url . 'taxonomy-' . $term->taxonomy . '-' . $term->slug . '.php';
			$find[] = $file;
			$find[] = $this->template_url . $file;

		}

		if ( $file ) {
			$template = locate_template( $find );
			if ( ! $template ) $template = $this->plugin_path() . '/templates/' . $file;
		}

		return $template;
	}

	/**
	 * brand_image function.
	 *
	 * @access public
	 */
	public function brand_description() {

		if ( ! is_tax( 'product_brand' ) )
			return;

		if ( ! get_query_var( 'term' ) )
			return;

		$thumbnail = '';

		$term = get_term_by( 'slug', get_query_var( 'term' ), 'product_brand' );
		$thumbnail = get_brand_thumbnail_url( $term->term_id, 'full' );

		wc_get_template( 'brand-description.php', array(
			'thumbnail' => $thumbnail
		), 'woocommerce-brands', $this->plugin_path() . '/templates/' );
	}

	/**
	 * show_brand function.
	 *
	 * @access public
	 * @return void
	 */
	public function show_brand() {
		global $post;

		if ( is_singular( 'product' ) ) {
			$brand_count = sizeof( get_the_terms( $post->ID, 'product_brand' ) );

			$taxonomy = get_taxonomy( 'product_brand' );
			$labels   = $taxonomy->labels;

			echo get_brands( $post->ID, ', ', ' <span class="posted_in">' . sprintf( _n( '%1$s: ', '%2$s: ', $brand_count ), $labels->singular_name, $labels->name ), '</span>' );
		}
	}

	/**
	 * Loop over found products.
	 *
	 * @access public
	 * @param  array $query_args
	 * @param  array $atts
	 * @param  string $loop_name
	 * @return string
	 */
	public function product_loop( $query_args, $atts, $loop_name ) {
		global $woocommerce_loop;

		$products                    = new WP_Query( apply_filters( 'woocommerce_shortcode_products_query', $query_args, $atts ) );
		$columns                     = absint( $atts['columns'] );
		$woocommerce_loop['columns'] = $columns;

		ob_start();

		if ( $products->have_posts() ) : ?>

			<?php do_action( "woocommerce_shortcode_before_{$loop_name}_loop" ); ?>

			<?php woocommerce_product_loop_start(); ?>

				<?php while ( $products->have_posts() ) : $products->the_post(); ?>

					<?php wc_get_template_part( 'content', 'product' ); ?>

				<?php endwhile; // end of the loop. ?>

			<?php woocommerce_product_loop_end(); ?>

			<?php do_action( "woocommerce_shortcode_after_{$loop_name}_loop" ); ?>

		<?php endif;

		woocommerce_reset_loop();
		wp_reset_postdata();

		return '<div class="woocommerce columns-' . $columns . '">' . ob_get_clean() . '</div>';
	}

	/**
	 * register_shortcodes function.
	 *
	 * @access public
	 */
	public function register_shortcodes() {

		add_shortcode( 'product_brand', array( $this, 'output_product_brand' ) );
		add_shortcode( 'product_brand_thumbnails', array( $this, 'output_product_brand_thumbnails' ) );
		add_shortcode( 'product_brand_thumbnails_description', array( $this, 'output_product_brand_thumbnails_description' ) );
		add_shortcode( 'product_brand_list', array( $this, 'output_product_brand_list' ) );
		add_shortcode( 'brand_products', array( $this, 'output_brand_products' ) );

	}

	/**
	 * output_product_brand function.
	 *
	 * @access public
	 */
	public function output_product_brand( $atts ) {
		global $post;

		extract( shortcode_atts( array(
			'width'   => '',
			'height'  => '',
			'class'   => 'aligncenter',
			'post_id' => ''
		), $atts ) );

		if ( ! $post_id && ! $post )
			return;

		if ( ! $post_id )
			$post_id = $post->ID;

		$brands = wp_get_post_terms( $post_id, 'product_brand', array( "fields" => "ids" ) );

		$output = null;

		if ( count( $brands ) > 0 ) {

			ob_start();

			foreach( $brands as $brand ) {

				$thumbnail = get_brand_thumbnail_url( $brand );

				if ( $thumbnail ) {

					$term = get_term_by( 'id', $brand, 'product_brand' );

					if ( $width || $height ) {
						$width = $width ? $width : 'auto';
						$height = $height ? $height : 'auto';
					}


					wc_get_template( 'shortcodes/single-brand.php', array(
						'term'      => $term,
						'width'     => $width,
						'height'    => $height,
						'thumbnail' => $thumbnail,
						'class'     => $class
					), 'woocommerce-brands', untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . '/templates/' );

				}
			}
			$output = ob_get_clean();
		}

		return $output;
	}

	/**
	 * output_product_brand_list function.
	 *
	 * @access public
	 * @return void
	 */
	public function output_product_brand_list( $atts ) {

		extract( shortcode_atts( array(
			'show_top_links'    => true,
			'show_empty'        => true,
			'show_empty_brands' => false
		), $atts ) );

		if ( $show_top_links === "false" )
			$show_top_links = false;

		if ( $show_empty === "false" )
			$show_empty = false;

		if ( $show_empty_brands === "false" )
			$show_empty_brands = false;

		$product_brands = array();
		$terms          = get_terms( 'product_brand', array( 'hide_empty' => ( $show_empty_brands ? false : true ) ) );

		foreach ( $terms as $term ) {

			$term_letter = substr( $term->slug, 0, 1 );

			if ( ctype_alpha( $term_letter ) ) {

				foreach ( range( 'a', 'z' ) as $i )
					if ( $i == $term_letter ) {
						$product_brands[ $i ][] = $term;
						break;
					}

			} else {
				$product_brands[ '0-9' ][] = $term;
			}

		}

		ob_start();

		wc_get_template( 'shortcodes/brands-a-z.php', array(
			'terms'          => $terms,
			'index'          => array_merge( range( 'a', 'z' ), array( '0-9' ) ),
			'product_brands' => $product_brands,
			'show_empty'     => $show_empty,
			'show_top_links' => $show_top_links
		), 'woocommerce-brands', untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . '/templates/' );

		return ob_get_clean();
	}

	/**
	 * output_product_brand_thumbnails function.
	 *
	 * @access public
	 * @param mixed $atts
	 * @return void
	 */
	public function output_product_brand_thumbnails( $atts ) {

		extract( shortcode_atts( array(
			'show_empty'    => true,
			'columns'       => 4,
			'hide_empty'    => 0,
			'orderby'       => 'name',
			'exclude'       => '',
			'number'        => '',
			'fluid_columns' => false
		 ), $atts ) );

		$exclude = array_map( 'intval', explode(',', $exclude) );
		$order = $orderby == 'name' ? 'asc' : 'desc';

		if ( 'true' == $show_empty ) {
			$hide_empty = false;
		} else {
			$hide_empty = true;
		}

		$brands = get_terms( 'product_brand', array( 'hide_empty' => $hide_empty, 'orderby' => $orderby, 'exclude' => $exclude, 'number' => $number, 'order' => $order ) );

		if ( ! $brands )
			return;

		ob_start();

		wc_get_template( 'widgets/brand-thumbnails.php', array(
			'brands'        => $brands,
			'columns'       => $columns,
			'fluid_columns' => $fluid_columns
		), 'woocommerce-brands', untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . '/templates/' );

		return ob_get_clean();
	}

	/**
	 * output_product_brand_thumbnails_description function.
	 *
	 * @access public
	 * @param mixed $atts
	 * @return void
	 */
	public function output_product_brand_thumbnails_description( $atts ) {

		extract( shortcode_atts( array(
			'show_empty' => true,
			'columns'    => 1,
			'hide_empty' => 0,
			'orderby'    => 'name',
			'exclude'    => '',
			'number'     => ''
		 ), $atts ) );

		$exclude = array_map( 'intval', explode(',', $exclude) );
		$order = $orderby == 'name' ? 'asc' : 'desc';

		$brands = get_terms( 'product_brand', array( 'hide_empty' => $hide_empty, 'orderby' => $orderby, 'exclude' => $exclude, 'number' => $number, 'order' => $order ) );

		if ( ! $brands )
			return;

		ob_start();

		wc_get_template( 'widgets/brand-thumbnails-description.php', array(
			'brands'  => $brands,
			'columns' => $columns
		), 'woocommerce-brands', untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . '/templates/' );

		return ob_get_clean();
	}

	/**
	 * output_brand_products function.
	 *
	 * @access public
	 * @param mixed $atts
	 * @return void
	 */
	public function output_brand_products( $atts ) {

		$atts = shortcode_atts( array(
			'per_page' => '12',
			'columns'  => '4',
			'orderby'  => 'title',
			'order'    => 'desc',
			'brand'    => '',
			'operator' => 'IN'
		), $atts );

		if ( ! $atts['brand'] ) {
			return '';
		}

		// Default ordering args
		$ordering_args = WC()->query->get_catalog_ordering_args( $atts['orderby'], $atts['order'] );
		$meta_query    = WC()->query->get_meta_query();
		$query_args    = array(
			'post_type'            => 'product',
			'post_status'          => 'publish',
			'ignore_sticky_posts'  => 1,
			'orderby'              => $ordering_args['orderby'],
			'order'                => $ordering_args['order'],
			'posts_per_page'       => $atts['per_page'],
			'meta_query'           => $meta_query,
			'tax_query'            => array(
				array(
					'taxonomy'     => 'product_brand',
					'terms'        => array_map( 'sanitize_title', explode( ',', $atts['brand'] ) ),
					'field'        => 'slug',
					'operator'     => $atts['operator']
				)
			)
		);

		if ( isset( $ordering_args['meta_key'] ) ) {
			$query_args['meta_key'] = $ordering_args['meta_key'];
		}

		$return = $this->product_loop( $query_args, $atts, 'product_cat' );

		// Remove ordering query arguments
		WC()->query->remove_ordering_args();

		return $return;
	}

	/**
	 * Register REST API route for /products/brands.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function rest_api_register_routes() {
		if ( ! is_a( WC()->api, 'WC_API' ) ) {
			return;
		}

		require_once( $this->plugin_path() . '/includes/class-wc-brands-rest-api-controller.php' );

		WC()->api->WC_Brands_REST_API_Controller = new WC_Brands_REST_API_Controller();
		WC()->api->WC_Brands_REST_API_Controller->register_routes();
	}

	/**
	 * Maybe set brands when requesting PUT /products/<id>
	 *
	 * @since 1.5.0
	 *
	 * @param WP_Post         $post    Post object
	 * @param WP_REST_Request $request Request object
	 *
	 * @return void
	 */
	public function rest_api_maybe_set_brands( $post, $request ) {
		if ( isset( $request['brands'] ) && is_array( $request['brands'] ) ) {
			$terms = array_map( 'absint', $request['brands'] );
			wp_set_object_terms( $post->ID, $terms, 'product_brand' );
		}
	}

	/**
	 * Prepare brands in product response.
	 *
	 * @param WP_REST_Response   $response   The response object.
	 * @param WP_Post|WC_Data    $post       Post object or WC object.
	 * @since 1.5.0
	 * @version 1.5.2
	 * @return WP_REST_Response
	 */
	public function rest_api_prepare_brands_to_product( $response, $post ) {
		$post_id = is_callable( array( $post, 'get_id' ) ) ? $post->get_id() : ( ! empty( $post->ID ) ? $post->ID : null );

		if ( empty( $response->data['brands'] ) ) {
			$terms = array();

			foreach ( wp_get_post_terms( $post_id, 'product_brand' ) as $term ) {
				$terms[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}

			$response->data['brands'] = $terms;
		}

		return $response;
	}

	/**
	 * Add brands in product response.
	 *
	 * @param WC_Data         $product   Inserted product object.
	 * @param WP_REST_Request $request   Request object.
	 * @param boolean         $creating  True when creating object, false when updating.
	 * @since 1.5.2
	 * @version 1.5.2
	 */
	public function rest_api_add_brands_to_product( $product, $request, $creating = true ) {
		$product_id   = is_callable( array( $product, 'get_id' ) ) ? $product->get_id() : ( ! empty( $product->ID ) ? $product->ID : null );
		$request_body = json_decode( $request->get_body() );
		$brands       = isset( $request_body->brands ) ? $request_body->brands : array();

		if ( ! empty( $brands ) ) {
			$brands = array_map( 'absint', $brands );
			wp_set_object_terms( $product_id, $brands, 'product_brand' );
		}
	}
}

$GLOBALS['WC_Brands'] = new WC_Brands();
