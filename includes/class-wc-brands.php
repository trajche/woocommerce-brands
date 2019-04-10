<?php

/**
 * WC_Brands class.
 */
class WC_Brands {

	var $template_url;
	var $plugin_path;

	/**
	 * __construct function.
	 */
	public function __construct() {
		$this->template_url = apply_filters( 'woocommerce_template_url', 'woocommerce/' );

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
		add_filter( 'woocommerce_structured_data_product', array( $this, 'add_structured_data' ), 20 );

		// duplicate product brands
		add_action( 'woocommerce_product_duplicate_before_save', array( $this, 'duplicate_store_temporary_brands' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( $this, 'duplicate_add_product_brand_terms' ) );

		add_filter( 'post_type_link', array( $this, 'post_type_link' ), 11, 2 );

		if ( 'yes' === get_option( 'wc_brands_show_description' ) ) {
			add_action( 'woocommerce_archive_description', array( $this, 'brand_description' ) );
		}

		add_filter( 'woocommerce_product_query_tax_query', array( $this, 'update_product_query_tax_query' ), 10, 2 );

		// REST API.
		add_action( 'rest_api_init', array( $this, 'rest_api_register_routes' ) );
		add_action( 'woocommerce_rest_insert_product', array( $this, 'rest_api_maybe_set_brands' ), 10, 2 );
		add_filter( 'woocommerce_rest_prepare_product', array( $this, 'rest_api_prepare_brands_to_product' ), 10, 2 ); // WC 2.6.x
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'rest_api_prepare_brands_to_product' ), 10, 2 ); // WC 3.x
		add_action( 'woocommerce_rest_insert_product', array( $this, 'rest_api_add_brands_to_product' ), 10, 3 ); // WC 2.6.x
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'rest_api_add_brands_to_product' ), 10, 3 ); // WC 3.x

		if ( version_compare( WC_VERSION, '3.2', '>=' ) ) {
			require_once( 'class-wc-brands-coupons.php' );
		} else {
			require_once( 'class-wc-brands-coupons-legacy.php' );
		}
		// Layered nav widget compatibility.
		add_filter( 'woocommerce_layered_nav_term_html', array( $this, 'woocommerce_brands_update_layered_nav_link' ), 10, 4 );
	}

	/**
	 * Update the main product fetch query to filter by selected brands.
	 *
	 * @param array    $tax_query array of current taxonomy filters.
	 * @param WC_Query $wc_query WC_Query object.
	 *
	 * @return array
	 */
	public function update_product_query_tax_query( array $tax_query, WC_Query $wc_query ) {
		if ( isset( $_GET['filter_product_brand'] ) ) { // WPCS: input var ok, CSRF ok.
			$brands_filter = array_filter( array_map( 'absint', explode( ',', $_GET['filter_product_brand'] ) ) ); // WPCS: input var ok, CSRF ok, Sanitization ok.

			if ( $brands_filter ) {
				$tax_query[] = array(
					'taxonomy' => 'product_brand',
					'terms'    => $brands_filter,
					'operator' => 'IN',
				);
			}
		}

		return $tax_query;
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
		wp_enqueue_style( 'brands-styles', plugins_url( '/assets/css/style.css', dirname( __FILE__ ) ), array(), WC_BRANDS_VERSION );
	}

	/**
	 * init_taxonomy function.
	 *
	 * @access public
	 */
	public static function init_taxonomy() {
		global $woocommerce;

		$shop_page_id = wc_get_page_id( 'shop' );

		$base_slug     = $shop_page_id > 0 && get_page( $shop_page_id ) ? get_page_uri( $shop_page_id ) : 'shop';
		$category_base = get_option('woocommerce_prepend_shop_page_to_urls') == "yes" ? trailingslashit( $base_slug ) : '';

		$slug = $category_base . __( 'brand', 'wc_brands' );
		if ( '' === $category_base ) {
			$slug = get_option( 'woocommerce_brand_permalink', '' );
		}

		// Can't provide transatable string as get_option default.
		if ( '' === $slug ) {
			$slug = __( 'brand', 'wc_brands' );
		}

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
						'new_item_name'     => __( 'New Brand Name', 'wc_brands' ),
						'not_found'         => __( 'No Brands Found', 'wc_brands' ),
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

				'rewrite' => array( 
					'slug'         => $slug,
					'with_front'   => false,
					'hierarchical' => true
				)
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
			$terms       = get_the_terms( $post->ID, 'product_brand' );
			$brand_count = is_array( $terms ) ? sizeof( $terms ) : 0;

			$taxonomy = get_taxonomy( 'product_brand' );
			$labels   = $taxonomy->labels;

			echo get_brands( $post->ID, ', ', ' <span class="posted_in">' . sprintf( _n( '%1$s: ', '%2$s: ', $brand_count ), $labels->singular_name, $labels->name ), '</span>' );
		}
	}

	/**
	 * Add structured data to product page.
	 *
	 * @access public
	 * @param  array $markup
	 * @return array $markup
	 */
	public function add_structured_data( $markup ) {
		global $post;

		if ( array_key_exists( 'brand', $markup ) ) {
			return $markup;
		}

		$brands = get_the_terms( $post->ID, 'product_brand' );

		if ( ! empty( $brands ) && is_array( $brands ) ) {
			// Can only return one brand, so pick the first.
			$markup['brand'] = $brands[0]->name;
		}

		return $markup;
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
			$alphabet    = apply_filters( 'woocommerce_brands_list_alphabet', range( 'a', 'z' ) );
			$numbers     = apply_filters( 'woocommerce_brands_list_numbers', '0-9' );

			// Allow a locale to be set for ctype_alpha()
			if ( has_filter( 'woocommerce_brands_list_locale' ) ) {
				setLocale( LC_CTYPE, apply_filters( 'woocommerce_brands_list_locale', 'en_US.UTF-8' ) );
			}

			if ( ctype_alpha( $term_letter ) ) {

				foreach ( $alphabet as $i )
					if ( $i == $term_letter ) {
						$product_brands[ $i ][] = $term;
						break;
					}

			} else {
				$product_brands[ $numbers ][] = $term;
			}

		}

		ob_start();

		wc_get_template( 'shortcodes/brands-a-z.php', array(
			'terms'          => $terms,
			'index'          => array_merge( $alphabet, array( $numbers ) ),
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
			'columns'       => is_numeric( $columns ) ? intval( $columns ) : 4,
			'fluid_columns' => wp_validate_boolean( $fluid_columns )
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
			'category' => '',
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
			'product_cat'          => $atts['category'],
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

		$controllers = array(
			'WC_Brands_REST_API_Controller',
		);

		// WooCommerce 3.5 has moved v2 endpoints to legacy classes
		if ( version_compare( WC_VERSION, '3.5', '>=' ) ) {
			require_once( $this->plugin_path() . '/includes/class-wc-brands-rest-api-v2-controller.php' );
			$controllers[] = 'WC_Brands_REST_API_V2_Controller';
		}

		foreach ( $controllers as $controller ) {
			WC()->api->$controller = new $controller();
			WC()->api->$controller->register_routes();
		}
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
		$product_id = is_callable( array( $product, 'get_id' ) ) ? $product->get_id() : ( ! empty( $product->ID ) ? $product->ID : null );
		$params     = $request->get_params();
		$brands     = isset( $params['brands'] ) ? $params['brands'] : array();

		if ( ! empty( $brands ) ) {
			$brands = array_map( 'absint', $brands );
			wp_set_object_terms( $product_id, $brands, 'product_brand' );
		}
	}

	/**
	 * Injects Brands filters into layered nav links.
	 *
	 * @param  string  $term_html Original link html.
	 * @param  mixed   $term      Term that is currently added.
	 * @param  string  $link      Original layered nav item link.
	 * @param  number  $count     Number of items in that filter.
	 * @return string             Term html.
	 * @since 1.6.3
	 * @version 1.6.3
	 */
	public function woocommerce_brands_update_layered_nav_link( $term_html, $term, $link, $count ) {
		$filter_name = 'filter_product_brand';
		if ( empty( $_GET[$filter_name] ) ) {
			return $term_html;
		}

		$current_attributes = array_map( 'intval', explode( ',', $_GET['filter_product_brand'] ) );
		$current_values     = ! empty( $current_attributes ) ? $current_attributes : array();
		$link = add_query_arg( array( 'filtering' => '1', $filter_name => implode( ',', $current_values ) ), wp_specialchars_decode( $link ) );
		$link = esc_url( $link );
		$term_html = '<a rel="nofollow" href="' . $link . '">' . esc_html( $term->name ) . '</a>';
		$term_html .= ' ' . apply_filters( 'woocommerce_layered_nav_count', '<span class="count">(' . absint( $count ) . ')</span>', $count, $term );
		return $term_html;
	}

	/**
	 * Temporarily tag a post with meta before it is saved in order
	 * to allow us to be able to use the meta when the product is saved to add
	 * the brands when an ID has been generated.
	 *
	 * @since 1.5.3
	 *
	 * @param WC_Product $duplicate
	 * @return WC_Product $original
	 */
	public function duplicate_store_temporary_brands( $duplicate, $original ) {
		$terms = get_the_terms( $original->get_id(), 'product_brand' );
		if ( ! is_array( $terms ) ) {
			return;
		}

		$ids = array();
		foreach ( $terms as $term ) {
			$ids[] = $term->term_id;
		}
		$duplicate->add_meta_data( 'duplicate_temp_brand_ids', $ids );
	}

	/**
	 * After product was added check if there are temporary brands and
	 * add them officially and remove the temporary brands.
	 *
	 * @since 1.5.3
	 *
	 * @param int $product_id
	 */
	public function duplicate_add_product_brand_terms( $product_id ) {
		$product = wc_get_product( $product_id );
		$term_ids = $product->get_meta( 'duplicate_temp_brand_ids' );
		if ( empty( $term_ids ) ) {
			return;
		}
		$term_taxonomy_ids = wp_set_object_terms( $product_id, $term_ids, 'product_brand' );
		$product->delete_meta_data( 'duplicate_temp_brand_ids' );
		$product->save();
	}
}

$GLOBALS['WC_Brands'] = new WC_Brands();
