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

/**
 * Compute a basic Yoast-compatible SEO score (1–100) from post content and focus keyphrase.
 *
 * Scoring mirrors Yoast's weighted checks:
 *   - Keyphrase in title            : 9 pts
 *   - Keyphrase in introduction      : 9 pts
 *   - Keyphrase in meta description  : 9 pts
 *   - Keyphrase in slug              : 6 pts
 *   - Keyphrase density 0.5%–3%      : 9 pts
 *   - Text length ≥ 300 words        : 9 pts
 *   - Meta description length ok     : 9 pts
 *   - Keyphrase present in content   : 3 pts  (fallback when density off)
 *
 * Returns 0 when no focus keyword is provided.
 * Minimum returned value when keyword is set: 1 (avoids "Non disponible").
 */
function aeromorning_compute_seo_score(int $post_id, string $focuskw, string $metadesc): int {
    $focuskw = trim($focuskw);
    if ($focuskw === '') {
        return 0;
    }

    $post = get_post($post_id);
    if (! $post) {
        return 0;
    }

    $kw      = mb_strtolower($focuskw, 'UTF-8');
    $title   = mb_strtolower(strip_tags((string) $post->post_title), 'UTF-8');
    $content = mb_strtolower(wp_strip_all_tags((string) $post->post_content), 'UTF-8');
    $desc    = mb_strtolower(trim($metadesc), 'UTF-8');
    $slug    = (string) $post->post_name;

    $score    = 0;
    $max      = 63; // sum of all achievable points

    // Keyphrase in title (9 pts)
    if (mb_strpos($title, $kw, 0, 'UTF-8') !== false) {
        $score += 9;
    }

    // Keyphrase in introduction — first ~300 chars of content (9 pts)
    $intro = mb_substr($content, 0, 300, 'UTF-8');
    if ($intro !== '' && mb_strpos($intro, $kw, 0, 'UTF-8') !== false) {
        $score += 9;
    }

    // Keyphrase in meta description (9 pts)
    if ($desc !== '' && mb_strpos($desc, $kw, 0, 'UTF-8') !== false) {
        $score += 9;
    }

    // Meta description length 50–156 chars (9 pts; partial 3 pts if present but off)
    $desc_len = mb_strlen($desc, 'UTF-8');
    if ($desc_len >= 50 && $desc_len <= 156) {
        $score += 9;
    } elseif ($desc_len > 0) {
        $score += 3;
    }

    // Keyphrase in slug (6 pts)
    $kw_slug = sanitize_title($focuskw);
    if ($kw_slug !== '' && $slug !== '' && str_contains($slug, $kw_slug)) {
        $score += 6;
    }

    // Text length ≥ 300 words (9 pts; partial 5 pts for 150–299)
    $word_count = str_word_count($content);
    if ($word_count >= 300) {
        $score += 9;
    } elseif ($word_count >= 150) {
        $score += 5;
    }

    // Keyphrase density 0.5%–3% (9 pts; 3 pts if present but outside range)
    $content_len = mb_strlen($content, 'UTF-8');
    if ($content_len > 0 && mb_strlen($kw, 'UTF-8') > 0) {
        $kw_occurrences = substr_count($content, $kw);
        $density = ($kw_occurrences * mb_strlen($kw, 'UTF-8')) / $content_len * 100;
        if ($density >= 0.5 && $density <= 3.0) {
            $score += 9;
        } elseif ($kw_occurrences > 0) {
            $score += 3;
        }
    }

    // Normalise to 1–100 (always at least 1 when focus keyword is provided)
    $normalized = (int) round(($score / $max) * 100);
    return max(1, min(100, $normalized));
}

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

    // ─── SEO score (linkdex) ────────────────────────────────────────────────
    // _yoast_wpseo_linkdex drives primary_focus_keyword_score in yoast_indexable.
    // Without a non-zero value, Yoast displays "Non disponible" in the admin column.
    //
    // Priority:
    //   1. Explicit seo_score from caller  → use as-is
    //   2. No score provided + focus keyword set + current stored score is 0
    //      → compute a PHP-based approximation so the column shows a colored dot
    //      instead of "Non disponible". The real score will be overwritten the
    //      first time an editor opens and saves the post (Yoast JS analysis).
    if ($seo_score !== null) {
        update_post_meta($post_id, '_yoast_wpseo_linkdex', $seo_score);
        $updated['seo_score'] = $seo_score;
        $updated['seo_score_source'] = 'explicit';
    } elseif ($focuskw !== null) {
        $existing_score = (int) get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
        if ($existing_score === 0) {
            $computed_score = aeromorning_compute_seo_score(
                $post_id,
                $focuskw,
                (string) ($metadesc ?? get_post_meta($post_id, '_yoast_wpseo_metadesc', true))
            );
            if ($computed_score > 0) {
                update_post_meta($post_id, '_yoast_wpseo_linkdex', $computed_score);
                $updated['seo_score'] = $computed_score;
                $updated['seo_score_source'] = 'computed';
            }
        }
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
    // wp_update_post() fires save_post which triggers Yoast's indexable builder.
    // When reindex=true we additionally call build_for_id_and_type() explicitly
    // to guarantee the indexable is rebuilt even if the save_post hook is skipped.
    $reindex_result = 'skipped';

    if ($reindex && function_exists('YoastSEO')) {
        try {
            $container = YoastSEO()->classes;

            $builder = $container->get(\Yoast\WP\SEO\Builders\Indexable_Builder::class);
            $builder->build_for_id_and_type($post_id, 'post', true);

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
