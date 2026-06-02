<?php
// mu-plugin: aeromorning-api.php
add_action('rest_api_init', function () {
    register_rest_route('aeromorning/v1', '/yoast/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'aeromorning_update_yoast_meta',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'id' => [
                'validate_callback' => fn($v) => is_numeric($v),
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

function aeromorning_update_yoast_meta(WP_REST_Request $request): WP_REST_Response {
    $post_id  = (int) $request->get_param('id');
    $body     = $request->get_json_params();
    $metadesc = isset($body['metadesc']) ? sanitize_text_field($body['metadesc']) : null;
    $focuskw  = isset($body['focuskw'])  ? sanitize_text_field($body['focuskw'])  : null;
    $reindex  = !empty($body['reindex']);

    if (! get_post($post_id)) {
        return new WP_REST_Response(['error' => 'post_not_found'], 404);
    }

    $updated = [];

    if ($metadesc !== null) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $metadesc);
        $updated['metadesc'] = $metadesc;
    }
    if ($focuskw !== null) {
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focuskw);
        $updated['focuskw'] = $focuskw;
    }

    // ─── Rebuild Yoast indexable (scores + structured data) ──────────────────
    // wp_update_post() alone does NOT recompute Yoast scores.
    // We must go through Yoast's own Indexable_Builder.
    $reindex_result = 'skipped';

    if ($reindex && function_exists('YoastSEO')) {
        try {
            $container = YoastSEO()->classes;

            // Yoast SEO ≥ 14 stores scores in yoast_indexable table.
            // build_for_id_and_type(id, type, update) forces a full rebuild:
            // re-reads meta, recalculates seo_score, readability_score, etc.
            $builder = $container->get(\Yoast\WP\SEO\Builders\Indexable_Builder::class);
            $builder->build_for_id_and_type($post_id, 'post', true);

            // Also rebuild the indexable hierarchy link (breadcrumbs etc.)
            $hierarchy = $container->get(\Yoast\WP\SEO\Builders\Indexable_Hierarchy_Builder::class);
            $indexable_repository = $container->get(\Yoast\WP\SEO\Repositories\Indexable_Repository::class);
            $indexable = $indexable_repository->find_by_id_and_type($post_id, 'post');
            if ($indexable) {
                $hierarchy->build($indexable);
            }

            $reindex_result = 'ok';
        } catch (\Throwable $e) {
            // Fallback: fire save_post hooks manually (works on Yoast < 14)
            do_action('save_post', $post_id, get_post($post_id), true);
            $reindex_result = 'fallback_save_post: ' . $e->getMessage();
        }
    }

    return new WP_REST_Response([
        'success'        => true,
        'post_id'        => $post_id,
        'updated'        => $updated,
        'reindex_result' => $reindex_result,
    ], 200);
}