<?php
/**
 * Plugin Name: AeroMorning Yoast API Reindex
 */

add_action('rest_api_init', function () {
    register_rest_route('aeromorning/v1', '/yoast/(?P<id>\d+)', [
        'methods'  => 'POST',
        'callback' => 'aeromorning_update_yoast_meta_and_reindex',
        'permission_callback' => function (WP_REST_Request $request) {
            $post_id = (int) $request['id'];
            return current_user_can('edit_post', $post_id);
        },
        'args' => [
            'metadesc' => ['type' => 'string', 'required' => false],
            'focuskw'  => ['type' => 'string', 'required' => false],
            'reindex'  => ['type' => 'boolean', 'required' => false],
        ],
    ]);
});

function aeromorning_update_yoast_meta_and_reindex(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    if (!$post_id || get_post_status($post_id) === false) {
        return new WP_REST_Response(['success' => false, 'message' => 'Post introuvable'], 404);
    }

    $metadesc = (string) $request->get_param('metadesc');
    $focuskw  = (string) $request->get_param('focuskw');
    $reindex  = (bool) $request->get_param('reindex');

    if ($metadesc !== '') {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', wp_strip_all_tags($metadesc));
    }

    if ($focuskw !== '') {
        update_post_meta($post_id, '_yoast_wpseo_focuskw', wp_strip_all_tags($focuskw));
    }

    // Force un "save" serveur pour déclencher les hooks Yoast (équivalent sauvegarde BO).
    if ($reindex || defined('WP_DEBUG')) {
        remove_action('save_post', 'wp_save_post_revision');
        wp_update_post([
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);
        add_action('save_post', 'wp_save_post_revision');
        clean_post_cache($post_id);
    }

    return new WP_REST_Response([
        'success' => true,
        'post_id' => $post_id,
        'meta' => [
            '_yoast_wpseo_metadesc' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            '_yoast_wpseo_focuskw'  => get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
        ],
        'reindexed' => (bool) ($reindex || defined('WP_DEBUG')),
    ], 200);
}