<?php
/**
 * Example Block (PHP Render)
 *
 * This file demonstrates how to render a dynamic block on the server.
 * To enable it, add `"render": "file:./render.php"` to block.json
 * and ensure the block is registered via `register_block_type()`.
 *
 * WordPress automatically passes $attributes and $content (if any)
 * when the block is rendered on the front end.
 *
 * @package viteseo-childtheme-ttf-windowslive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$attributes = wp_parse_args(
	$attributes ?? array(),
	array(
		'exampleSetting' => 'default-value',
		'exampleToggle'  => false,
		'exampleColor'   => '#5650ff',
		'exampleNumber'  => 10,
		'alignment'      => 'left',
	)
);

$alignment_value = sanitize_key( $attributes['alignment'] );
$alignment_class = $alignment_value ? 'has-text-align-' . sanitize_html_class( $alignment_value ) : '';

$example_color = sanitize_hex_color( $attributes['exampleColor'] );
if ( ! $example_color ) {
	$example_color = '#5650ff';
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => trim( 'module-block-server ' . $alignment_class ),
		'style' => sprintf( '--example-color: %s;', $example_color ),
	)
);

?>
<div <?php echo $wrapper_attributes; ?>>
	<div class="module-block-output" style="background-color: var(--example-color); padding: 20px; border-radius: 8px; color: #fff;">
		<h3><?php esc_html_e( 'Rendered via render.php', 'viteseo-childtheme-ttf-windowslive' ); ?></h3>
		<p>
			<strong><?php esc_html_e( 'Example Setting:', 'viteseo-childtheme-ttf-windowslive' ); ?></strong>
			<?php echo esc_html( $attributes['exampleSetting'] ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Example Number:', 'viteseo-childtheme-ttf-windowslive' ); ?></strong>
			<?php echo esc_html( $attributes['exampleNumber'] ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Example Toggle:', 'viteseo-childtheme-ttf-windowslive' ); ?></strong>
			<?php echo $attributes['exampleToggle'] ? esc_html__( 'Enabled', 'viteseo-childtheme-ttf-windowslive' ) : esc_html__( 'Disabled', 'viteseo-childtheme-ttf-windowslive' ); ?>
		</p>

		<?php if ( ! empty( $content ) ) : ?>
			<div class="module-block-inner-content">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php else : ?>
			<p class="module-block-inner-content example">
				<?php esc_html_e( 'Optional inner blocks would appear here if provided.', 'viteseo-childtheme-ttf-windowslive' ); ?>
			</p>
		<?php endif; ?>

		<p class="module-block-note">
			<?php esc_html_e( 'Tip: Use this file to fetch data or run custom PHP before returning markup.', 'viteseo-childtheme-ttf-windowslive' ); ?>
		</p>
	</div>
</div>
