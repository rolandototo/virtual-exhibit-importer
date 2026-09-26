<?php
/**
 * Plugin Name:       Virtual Exhibit Importer
 * Plugin URI:        https://github.com/rolandototo/virtual-exhibit-importer
 * Description:       Imports posts from a remote WordPress REST API into the virtual_exhibit post type, with featured images, a progress bar and a downloadable report.
 * Version:           7.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rolando Escobar
 * Author URI:        https://rolandowp.com
 * Text Domain:       virtual-exhibit-importer
 *
 * Built at Counterintuity for the Louis Armstrong House Museum.
 *
 * The main file keeps its original name (virtual-exhibit-importer-v7.php):
 * renaming it would deactivate the plugin on sites that already run it.
 */

if (!defined('ABSPATH')) exit;

define('VEI_VERSION', '7.1.0');

/**
 * Base URL of the WordPress site to import from.
 *
 * To import from another site, define VEI_SOURCE_URL in wp-config.php or
 * use the vei_source_url filter.
 */
function vei_source_url() {
    $url = defined('VEI_SOURCE_URL') ? VEI_SOURCE_URL : 'https://virtualexhibits.louisarmstronghouse.org';
    return untrailingslashit(apply_filters('vei_source_url', $url));
}

/**
 * URL of the source site's posts endpoint with the given query arguments.
 */
function vei_posts_endpoint(array $args) {
    return add_query_arg($args, vei_source_url() . '/wp-json/wp/v2/posts');
}

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'toplevel_page_virtual_exhibit_importer_v7') return;
    wp_enqueue_style('vei-admin-style', plugin_dir_url(__FILE__) . 'css/admin-style.css', [], VEI_VERSION);
    wp_enqueue_script('vei-importer', plugin_dir_url(__FILE__) . 'js/importer.js', ['jquery'], VEI_VERSION, true);
    wp_localize_script('vei-importer', 'vei_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('vei_nonce')
    ]);
});

// Admin page
add_action('admin_menu', function() {
    add_menu_page(
        'Virtual Exhibit Importer',
        'Exhibit Importer',
        'manage_options',
        'virtual_exhibit_importer_v7',
        'vei_importer_admin_page',
        'dashicons-update',
        80
    );
});

function vei_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>Virtual Exhibit Importer</h1>
        <p>Source site: <code><?php echo esc_html(vei_source_url()); ?></code></p>
        <button id="start-import" class="button button-primary">Start Import</button>
        <button id="force-import" class="button button-secondary">Force Reimport</button>
        <button id="delete-all" class="button button-danger" style="background:#b32d2e;border-color:#b32d2e;">Delete All Exhibits</button>
        <div id="vei-status" style="margin-top:10px;"></div>
        <div id="vei-progress-bar"><div></div></div>
        <div id="vei-summary" style="margin-top:10px;"></div>
        <pre id="vei-error-log" style="display:none; background:#fdd; padding:10px;"></pre>
        <button id="download-log" class="button" style="display:none; margin-top:10px;">Download Report</button>
    </div>
    <?php
}

/**
 * Stops an AJAX request unless the user can manage options, the same
 * capability the admin page requires. The nonce alone doesn't prove that.
 */
function vei_require_admin() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You are not allowed to run the importer.'], 403);
    }
}

add_action('wp_ajax_vei_start_import_step', 'vei_ajax_start_import');

function vei_ajax_start_import() {
    check_ajax_referer('vei_nonce', 'nonce');
    vei_require_admin();
    $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'count';
    // jQuery sends booleans as the strings "true"/"false", so parse the value
    // instead of using empty(), which treats "false" as true.
    $force = isset($_POST['force']) && filter_var(wp_unslash($_POST['force']), FILTER_VALIDATE_BOOLEAN);

    if ($step === 'count') {
        $response = wp_remote_get(vei_posts_endpoint(['per_page' => 1]));
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'API request failed', 'error' => $response->get_error_message()]);
        }
        $total_posts = wp_remote_retrieve_header($response, 'X-WP-Total');
        if (!$total_posts) {
            wp_send_json_error(['message' => 'Could not read total posts from API.']);
        }
        wp_send_json_success([
            'message' => 'Total posts in API: ' . $total_posts,
            'total' => intval($total_posts)
        ]);
    }

    if ($step === 'compare') {
        $existing = get_posts([
            'post_type' => 'virtual_exhibit',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        wp_send_json_success([
            'message' => 'Existing posts found: ' . count($existing),
            'existing' => array_map('intval', $existing)
        ]);
    }

    if ($step === 'import') {
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $response = wp_remote_get(vei_posts_endpoint(['per_page' => 1, 'page' => $page]));
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'API error during import', 'error' => $response->get_error_message()]);
        }
        $body = wp_remote_retrieve_body($response);
        $posts = json_decode($body);
        // A page past the end, or any API error, returns an error object instead of a list.
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($posts) || empty($posts)) {
            wp_send_json_error(['message' => 'No more posts to import.']);
        }

        $post = $posts[0];
        $original_id = intval($post->id);
        $original_url = !empty($post->link) ? esc_url_raw($post->link) : '';
        $title = sanitize_text_field($post->title->rendered);
        $content = wp_kses_post($post->content->rendered);
        // Keep the source slug so URLs match; fall back to the title.
        $slug = !empty($post->slug) ? sanitize_title($post->slug) : sanitize_title($title);
        $excerpt = wp_kses_post($post->excerpt->rendered);

        $existing_query = new WP_Query([
            'post_type' => 'virtual_exhibit',
            'post_status' => ['any', 'trash'],
            'meta_key' => 'original_id',
            'meta_value' => $original_id,
            'posts_per_page' => 1,
            'no_found_rows' => true
        ]);
        $existing = $existing_query->have_posts() ? $existing_query->posts[0] : null;
        if ($existing && !$force) {
            if ($original_url) {
                $current_url = get_post_meta($existing->ID, 'original_url', true);
                if ($current_url !== $original_url) {
                    update_post_meta($existing->ID, 'original_url', $original_url);
                }
            }
            wp_send_json_success([
                'message' => "Post already exists: $title",
                'status' => 'skipped',
                'imported' => false,
                'title' => $title
            ]);
        }

        if ($existing && $force) {
            $new_post = wp_update_post([
                'ID' => $existing->ID,
                'post_title' => $title,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
            ], true);
            if ($original_url && !is_wp_error($new_post)) {
                update_post_meta($existing->ID, 'original_url', $original_url);
            }
            $status = 'updated';
        } else {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            $meta_input = ['original_id' => $original_id];
            if ($original_url) {
                $meta_input['original_url'] = $original_url;
            }

            $new_post = wp_insert_post([
                'post_type' => 'virtual_exhibit',
                'post_title' => $title,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => 'publish',
                'post_name' => $slug,
                'meta_input' => $meta_input
            ]);
            $status = 'imported';
        }

        if (is_wp_error($new_post)) {
            wp_send_json_error([
                'message' => 'Failed to insert/update post',
                'error' => $new_post->get_error_message()
            ]);
        }
        if (!has_post_thumbnail($new_post) && !empty($post->_links->{'wp:featuredmedia'}[0]->href)) {
            $media_link = esc_url_raw($post->_links->{'wp:featuredmedia'}[0]->href);
            $media_response = wp_remote_get($media_link);
            if (!is_wp_error($media_response)) {
                $media_body = wp_remote_retrieve_body($media_response);
                $media_obj = json_decode($media_body);
                if (!empty($media_obj->source_url)) {
                    $image_url = esc_url_raw($media_obj->source_url);
                    $tmp = download_url($image_url);
                    if (!is_wp_error($tmp)) {
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/media.php');
                        $file_array = [
                            'name'     => basename($image_url),
                            'tmp_name' => $tmp
                        ];
                        $id = media_handle_sideload($file_array, $new_post);
                        if (!is_wp_error($id)) {
                            set_post_thumbnail($new_post, $id);
                        } else {
                            @unlink($file_array['tmp_name']);
                        }
                    }
                }
            }
        }

        wp_send_json_success([
            'message' => ($status === 'updated' ? "Updated post: $title" : "Imported post: $title"),
            'status' => $status,
            'imported' => true,
            'title' => $title,
            'page' => $page
        ]);
    }
}


add_action('wp_ajax_vei_delete_all_exhibits', function() {
    check_ajax_referer('vei_nonce', 'nonce');
    vei_require_admin();
    $deleted = 0;
    // 'any' leaves out trashed posts, so ask for the trash explicitly.
    $post_ids = get_posts([
        'post_type' => 'virtual_exhibit',
        'post_status' => ['any', 'trash'],
        'numberposts' => -1,
        'fields' => 'ids'
    ]);
    foreach ($post_ids as $post_id) {
        if (wp_delete_post($post_id, true)) {
            $deleted++;
        }
    }
    wp_send_json_success(['message' => "Deleted $deleted Virtual Exhibit posts."]);
});

add_action('add_meta_boxes', 'vei_register_original_url_metabox');

function vei_register_original_url_metabox() {
    add_meta_box(
        'vei-original-url',
        __('Original URL', 'virtual-exhibit-importer'),
        'vei_render_original_url_metabox',
        'virtual_exhibit',
        'side',
        'default'
    );
}

function vei_render_original_url_metabox($post) {
    $original_url = get_post_meta($post->ID, 'original_url', true);
    if (!$original_url) {
        echo '<p>' . esc_html__('No original URL stored for this exhibit.', 'virtual-exhibit-importer') . '</p>';
        return;
    }

    $escaped_url = esc_url($original_url);
    echo '<p>' . esc_html__('This URL is provided for reference and cannot be modified.', 'virtual-exhibit-importer') . '</p>';
    echo '<p><a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer">' . $escaped_url . '</a></p>';
    echo '<input type="text" class="widefat" readonly value="' . esc_attr($original_url) . '" />';
}
