<?php

/**
 * Creates the view for Jetpack's contact form
 */

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'sandwich_toggle', 11 );

function sandwich_toggle() {

    add_shortcode( 'pbs_toggle', 'sandwich_toggle_shortcode' );

    // Check if Shortcake exists
    if( !function_exists( 'shortcode_ui_register_for_shortcode' ) ) {
        return;
    }

    if( !is_admin() ) {
        return;
    }

    shortcode_ui_register_for_shortcode( 'pbs_toggle', [
            'label'         => __( '切换', 'pbsandwich' ),
            'listItemImage' => 'dashicons-plus',
            'attrs'         => [
                [
                    'label' => __( '标题', 'pbsandwich' ),
                    'attr'  => 'title',
                    'type'  => 'text',
                ],
            ],
            'inner_content' => [
                'value'       => '点击切换',
                'type'        => 'textarea',
                'description' => __( '切换内容', 'pbsandwich' ),
            ],
        ] );

}

function sandwich_toggle_shortcode( $attr, $content ) {

    $attr = wp_parse_args( $attr, [
        'title' => '',
    ] );

    global $_sandwich_toggle_id;

    if( !isset( $_sandwich_toggle_id ) ) {
        $_sandwich_toggle_id = 1;
    }

    $id = strtolower( str_replace( ' ', '-', preg_replace( '/[^a-zA-Z0-9 ]/', '', $attr[ 'title' ] ) ) ) . '-' . $_sandwich_toggle_id++;

    ob_start();

    ?>

    <div class="sandwich">
        <div class="panel panel-default toggle">
            <div class="panel-body">
                <a data-toggle="collapse" href="#<?php echo esc_attr( $id ) ?>" aria-expanded="false"
                   aria-controls="<?php echo esc_attr( $id ) ?>"><?php echo esc_html( $attr[ 'title' ] ) ?></a>
                <div class="collapse"
                     id="<?php echo esc_attr( $id ) ?>"><?php echo wpautop( do_shortcode( $content ) ) ?></div>
            </div>
        </div>
    </div>

    <?php

    return ob_get_clean();
}