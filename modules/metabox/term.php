<?php

add_action( 'after_setup_theme', function () {

	$taxonomies = apply_filters( 'wizhi_taxonomy_setting_supports', [ 'category', 'prodcat' ] );

	$fm = new Fieldmanager_Textfield( [
		'name' => '_term_posts_per_page',
	] );
	$fm->add_term_meta_box( __( 'Post per page', 'wizhi' ), $taxonomies );

	$fm = new Fieldmanager_Media( [
		'name' => '_banner_image',
	] );
	$fm->add_term_meta_box( __( 'Cover image', 'wizhi' ), $taxonomies );

	$fm = new Fieldmanager_Select( [
		'name'    => '_term_template',
		'options' => wizhi_get_loop_template( 'wizhi/archive' ),
	] );
	$fm->add_term_meta_box( __( 'Archive template', 'wizhi' ), $taxonomies );

} );