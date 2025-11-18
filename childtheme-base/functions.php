<?php
require_once trailingslashit(get_stylesheet_directory()) . 'inc/region-data.php';

// REGISTER BOOTSTRAP
function viteseo_child_theme_enqueue_styles() {
    $parent_style = 'parent-style';

    wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
    wp_enqueue_style(
        'bootstrap-css',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        array(),
        '5.3.3'
    );

    wp_enqueue_style(
        'child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style, 'bootstrap-css' ),
        wp_get_theme()->get( 'Version' )
    );

    wp_enqueue_script(
        'bootstrap-js',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        array(),
        '5.3.3',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'viteseo_child_theme_enqueue_styles' );

// REGISTER BLOCKS
add_action('init', function () {
    
    // Uncomment to experiment with the sample block shipped with the starter.
    // register_block_type( get_stylesheet_directory() . '/blocks/example-block' );
});

?>
