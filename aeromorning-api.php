<?php

add_action('rest_api_init', function () {

    register_rest_route(
        'aeromorning/v1',
        '/yoast/(?P<id>\d+)',
        [
            'methods' => 'POST',
            'callback' => function ($request) {

                $post_id = (int) $request['id'];

                $params = $request->get_json_params();

                if (!$post_id || !get_post($post_id)) {
                    return new WP_Error(
                        'invalid_post',
                        'Post not found',
                        ['status' => 404]
                    );
                }

                if (!empty($params['metadesc'])) {
                    update_post_meta(
                        $post_id,
                        '_yoast_wpseo_metadesc',
                        sanitize_text_field($params['metadesc'])
                    );
                }

                if (!empty($params['focuskw'])) {
                    update_post_meta(
                        $post_id,
                        '_yoast_wpseo_focuskw',
                        sanitize_text_field($params['focuskw'])
                    );
                }

                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'meta_description' => get_post_meta(
                        $post_id,
                        '_yoast_wpseo_metadesc',
                        true
                    ),
                    'focus_keyword' => get_post_meta(
                        $post_id,
                        '_yoast_wpseo_focuskw',
                        true
                    )
                ];
            },
            'permission_callback' => '__return_true'
        ]
    );

});