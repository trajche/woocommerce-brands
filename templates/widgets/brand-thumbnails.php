<?php
/**
 * Show a grid of thumbnails
 */

$wrapper_class = '';
if ( wp_validate_boolean( $fluid_columns ) ) {
	$wrapper_class = ' fluid-columns';
}
?>
<ul class="brand-thumbnails<?php echo esc_attr( $wrapper_class ); ?>">

<?php
	$count = 0;
	$style_att = '';

	$brands = array_values( $brands );
	
	foreach ( $brands as $index => $brand ) :
		$count++;
		$class = '';

		if ( $index == 0 || $index % $columns == 0 ) {
			$class = 'first';
		} elseif ( ( $index + 1 ) % $columns == 0 ) {
			$class = 'last';
		}

		if ( 0 == $count % 2 ) {
			$class .= ' even';
		} else {
			$class .= ' odd';
		}

		if ( '' == $wrapper_class ) {
			$width = floor( ( ( 100 - ( ( $columns - 1 ) * 2 ) ) / $columns ) * 100 ) / 100;
			$style_att = ' style="width: ' . intval( $width ) . '%;"';
		}
		?>

		<li class="<?php echo esc_attr( $class ); ?>"<?php echo $style_att; ?>>
			<a href="<?php echo esc_url( get_term_link( $brand->slug, 'product_brand' ) ); ?>" title="<?php echo esc_attr( $brand->name ); ?>">
				<?php echo get_brand_thumbnail_image( $brand ); ?>
			</a>
		</li>

<?php endforeach; ?>

</ul>
