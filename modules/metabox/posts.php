<?php

add_action( 'after_setup_theme', 'wizhi_cms_post_meta' );
function wizhi_cms_post_meta() {

	$types = [ 'post', 'page' ];

	$fields = [
		"_banner_image"    => new Fieldmanager_Media( __( 'Cover image', 'wizhi' ) ),
		"_seo_title"       => new Fieldmanager_Textfield( __( 'SEO Title', 'wizhi' ) ),
		"_seo_description" => new Fieldmanager_TextArea( __( 'SEO Description', 'wizhi' ) ),
	];

	$fm = new Fieldmanager_Group( [
		'name'           => 'wizhi_post_metas',
		'serialize_data' => false,
		'add_to_prefix'  => false,
		'children'       => apply_filters( 'wizhi_post_fields', $fields ),
	] );

	$fm->add_meta_box( __( 'Post Fields', 'wizhi' ), apply_filters( 'wizhi_post_fields_supports', $types ) );

}