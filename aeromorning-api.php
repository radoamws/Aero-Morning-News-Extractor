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
    $touch_post = !empty($body['touch_post']);

    $post_title = array_key_exists('post_title', $body)
        ? sanitize_text_field((string) $body['post_title'])
        : null;
    $post_excerpt = array_key_exists('post_excerpt', $body)
        ? sanitize_text_field((string) $body['post_excerpt'])
        : null;
    $post_content = array_key_exists('post_content', $body)
        ? wp_kses_post((string) $body['post_content'])
        : null;

    $seo_score = array_key_exists('seo_score', $body) ? (int) $body['seo_score'] : null;
    $readability_score = array_key_exists('readability_score', $body) ? (int) $body['readability_score'] : null;

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
    if ($seo_score !== null) {
        update_post_meta($post_id, '_yoast_wpseo_linkdex', $seo_score);
        $updated['seo_score'] = $seo_score;
    }
    if ($readability_score !== null) {
        update_post_meta($post_id, '_yoast_wpseo_content_score', $readability_score);
        $updated['readability_score'] = $readability_score;
    }

    $should_touch_post = $touch_post || $post_title !== null || $post_excerpt !== null || $post_content !== null;
    if ($should_touch_post) {
        $post_update = [
            'ID' => $post_id,
        ];

        if ($post_title !== null) {
            $post_update['post_title'] = $post_title;
        }
        if ($post_excerpt !== null) {
            $post_update['post_excerpt'] = $post_excerpt;
        }
        if ($post_content !== null) {
            $post_update['post_content'] = $post_content;
        }

        $touch_result = wp_update_post($post_update, true, true);
        if (is_wp_error($touch_result)) {
            return new WP_REST_Response([
                'success' => false,
                'post_id' => $post_id,
                'error' => 'post_touch_failed',
                'message' => $touch_result->get_error_message(),
                'updated' => $updated,
            ], 500);
        }

        $updated['post_touched'] = true;
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