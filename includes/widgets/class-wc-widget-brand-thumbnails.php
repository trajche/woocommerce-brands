<?php
/**
 * Brand Thumbnails Widget
 *
 * Show brand images as thumbnails
 *
 * @package		WooCommerce
 * @category	Widgets
 * @author		WooThemes
 */
class WC_Widget_Brand_Thumbnails extends WP_Widget {

	/** Variables to setup the widget. */
	public $woo_widget_cssclass;
	public $woo_widget_description;
	public $woo_widget_idbase;
	public $woo_widget_name;

	/** constructor */
	public function __construct() {

		/* Widget variable settings. */
		$this->woo_widget_name        = __('WooCommerce Brand Thumbnails', 'woocommerce-brands' );
		$this->woo_widget_description = __( 'Show a grid of brand thumbnails.', 'woocommerce-brands' );
		$this->woo_widget_idbase      = 'wc_brands_brand_thumbnails';
		$this->woo_widget_cssclass    = 'widget_brand_thumbnails';

		/* Widget settings. */
		$widget_ops = array( 'classname' => $this->woo_widget_cssclass, 'description' => $this->woo_widget_description );

		/* Create the widget. */
		parent::__construct( $this->woo_widget_idbase, $this->woo_widget_name, $widget_ops );
	}

	/**
	 * Echoes the widget content.
	 *
	 * @see WP_Widget
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title',
	 *                        'before_widget', and 'after_widget'.
	 * @param array $instance The settings for the particular instance of the widget.
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args(
			$instance,
			array(
				'title'      => '',
				'columns'    => 1,
				'exclude'    => '',
				'orderby'    => 'name',
				'hide_empty' => 0,
				'number'     => '',
			)
		);

		$exclude = array_map( 'intval', explode( ',', $instance['exclude'] ) );
		$order   = 'name' === $instance['orderby'] ? 'asc' : 'desc';

		$brands = get_terms(
			'product_brand',
			array(
				'hide_empty' => $instance['hide_empty'],
				'orderby'    => $instance['orderby'],
				'exclude'    => $exclude,
				'number'     => $instance['number'],
				'order'      => $order,
			)
		);

		if ( ! $brands ) {
			return;
		}

		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->woo_widget_idbase );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $title !== '' ) {
			echo $args['before_title'] . $title . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		wc_get_template( 'widgets/brand-thumbnails.php', array(
			'brands'        => $brands,
			'columns'       => $instance['columns'],
			'fluid_columns' => ! empty( $instance['fluid_columns'] ) ? true : false,
		), 'woocommerce-brands', untrailingslashit( plugin_dir_path( dirname( dirname( __FILE__ ) ) ) ) . '/templates/' );

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** @see WP_Widget->update */
	public function update( $new_instance, $old_instance ) {
		$instance['title']         = strip_tags( stripslashes( $new_instance['title'] ) );
		$instance['columns']       = strip_tags( stripslashes( $new_instance['columns'] ) );
		$instance['fluid_columns'] = ! empty( $new_instance['fluid_columns'] ) ? true : false;
		$instance['orderby']       = strip_tags( stripslashes( $new_instance['orderby'] ) );
		$instance['exclude']       = strip_tags( stripslashes( $new_instance['exclude'] ) );
		$instance['hide_empty']    = strip_tags( stripslashes( $new_instance['hide_empty'] ) );
		$instance['number']        = strip_tags( stripslashes( $new_instance['number'] ) );

		if ( ! $instance['columns'] ) {
			$instance['columns'] = 1;
		}

		if ( ! $instance['orderby'] ) {
			$instance['orderby'] = 'name';
		}

		if ( ! $instance['exclude'] ) {
			$instance['exclude'] = '';
		}

		if ( ! $instance['hide_empty'] ) {
			$instance['hide_empty'] = 0;
		}

		if ( ! $instance['number'] ) {
			$instance['number'] = '';
		}

		return $instance;
	}

	/**
	 * Outputs the settings update form.
	 *
	 * @param array $instance Current settings.
	 */
	public function form( $instance ) {
		if ( ! isset( $instance['hide_empty'] ) ) {
			$instance['hide_empty'] = 0;
		}

		if ( ! isset( $instance['orderby'] ) ) {
			$instance['orderby'] = 'name';
		}

		if ( empty( $instance['fluid_columns'] ) ) {
			$instance['fluid_columns'] = false;
		}

		?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'woocommerce-brands' ); ?></label>
				<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php if ( isset ( $instance['title'] ) ) echo esc_attr( $instance['title'] ); ?>" />
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'columns' ) ); ?>"><?php esc_html_e( 'Columns:', 'woocommerce-brands' ); ?></label>
				<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'columns' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'columns' ) ); ?>" value="<?php if ( isset ( $instance['columns'] ) ) echo esc_attr( $instance['columns'] ); else echo '1'; ?>" />
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'fluid_columns' ) ); ?>"><?php esc_html_e( 'Fluid columns:', 'woocommerce-brands' ); ?></label>
				<input type="checkbox" <?php checked( $instance['fluid_columns'] ); ?> id="<?php echo esc_attr( $this->get_field_id( 'fluid_columns' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'fluid_columns' ) ); ?>" />
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Number:', 'woocommerce-brands' ); ?></label>
				<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php if ( isset ( $instance['number'] ) ) { echo esc_attr( $instance['number'] ); } // phpcs:ignore ?>" placeholder="<?php esc_attr_e( 'All', 'woocommerce-brands' ); ?>" />
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'exclude' ) ); ?>"><?php esc_html_e( 'Exclude:', 'woocommerce-brands' ); ?></label>
				<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'exclude' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'exclude' ) ); ?>" value="<?php if ( isset ( $instance['exclude'] ) ) { echo esc_attr( $instance['exclude'] ); } // phpcs:ignore ?>" placeholder="<?php esc_attr_e( 'None', 'woocommerce-brands' ); ?>" />
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'hide_empty' ) ); ?>"><?php esc_html_e( 'Hide empty brands:', 'woocommerce-brands' ); ?></label>
				<select id="<?php echo esc_attr( $this->get_field_id( 'hide_empty' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'hide_empty' ) ); ?>">
					<option value="1" <?php selected( $instance['hide_empty'], 1 ); ?>><?php esc_html_e( 'Yes', 'woocommerce-brands' ); ?></option>
					<option value="0" <?php selected( $instance['hide_empty'], 0 ); ?>><?php esc_html_e( 'No', 'woocommerce-brands' ); ?></option>
				</select>
			</p>

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>"><?php esc_html_e( 'Order by:', 'woocommerce-brands' ); ?></label>
				<select id="<?php echo esc_attr( $this->get_field_id( 'orderby' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'orderby' ) ); ?>">
					<option value="name" <?php selected( $instance['orderby'], 'name' ); ?>><?php esc_html_e( 'Name', 'woocommerce-brands' ); ?></option>
					<option value="count" <?php selected( $instance['orderby'], 'count' ); ?>><?php esc_html_e( 'Count', 'woocommerce-brands' ); ?></option>
				</select>
			</p>
		<?php
	}

}
