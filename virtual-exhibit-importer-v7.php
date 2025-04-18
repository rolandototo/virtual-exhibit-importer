<?php
/**
 * Plugin Name: Virtual Exhibit Importer v7
 * Description: Imports Virtual Exhibits from external API with AJAX, progress bar, and error reporting.
 * Version: 7.0
 * Author: Rolando Escobar & ChatGPT
 */

if (!defined('ABSPATH')) exit;

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'toplevel_page_virtual_exhibit_importer_v7') return;
    wp_enqueue_style('vei-admin-style', plugin_dir_url(__FILE__) . 'css/admin-style.css');
    wp_enqueue_script('vei-importer', plugin_dir_url(__FILE__) . 'js/importer.js', ['jquery'], null, true);
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
        <h1>Virtual Exhibit Importer v7</h1>
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

add_action('wp_ajax_vei_start_import_step', 'vei_ajax_start_import');

function vei_ajax_start_import() {
    check_ajax_referer('vei_nonce', 'nonce');
    $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'count';
    $force = !empty($_POST['force']);

    if ($step === 'count') {
        $response = wp_remote_get('https://virtualexhibits.louisarmstronghouse.org/wp-json/wp/v2/posts?per_page=1');
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
        $response = wp_remote_get("https://virtualexhibits.louisarmstronghouse.org/wp-json/wp/v2/posts?per_page=1&page=$page");
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'API error during import', 'error' => $response->get_error_message()]);
        }
        $body = wp_remote_retrieve_body($response);
        $posts = json_decode($body);
        if (empty($posts)) {
            wp_send_json_error(['message' => 'No more posts to import.']);
        }

        $post = $posts[0];
        $original_id = intval($post->id);
        $title = sanitize_text_field($post->title->rendered);
        $content = wp_kses_post($post->content->rendered);
        $slug = sanitize_title($title);

        $existing_query = new WP_Query([
            'post_type' => 'virtual_exhibit',
            'meta_key' => 'original_id',
            'meta_value' => $original_id,
            'posts_per_page' => 1
        ]);
        $existing = $existing_query->have_posts() ? $existing_query->posts[0] : null;
        if ($existing && !$force) {
            wp_send_json_success([
                'message' => "Post already exists: $title",
                'imported' => false,
                'title' => $title
            ]);
        }

        if ($existing && $force) {
            wp_update_post([
                'ID' => $existing->ID,
                'post_title' => $title,
                'post_content' => $content
            ]);
            $new_post = $existing->ID;
        } else {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $new_post = wp_insert_post([
                'post_type' => 'virtual_exhibit',
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'publish',
                'post_name' => $slug,
                'meta_input' => ['original_id' => $original_id]
            ]);
        }

        if (is_wp_error($new_post)) {
            wp_send_json_error([
                'message' => 'Failed to insert/update post',
                'error' => $new_post->get_error_message()
            ]);
        }

        wp_send_json_success([
            'message' => ($force ? "Force updated post: $title" : "Imported post: $title"),
            'imported' => true,
            'title' => $title,
            'page' => $page
        ]);
    }
}


add_action('wp_ajax_vei_delete_all_exhibits', function() {
    check_ajax_referer('vei_nonce', 'nonce');
    $deleted = 0;
    $posts = get_posts([
        'post_type' => 'virtual_exhibit',
        'post_status' => 'any',
        'numberposts' => -1
    ]);
    foreach ($posts as $post) {
        if (wp_delete_post($post->ID, true)) {
            $deleted++;
        }
    }
    wp_send_json_success(['message' => "Deleted $deleted Virtual Exhibit posts."]);
});
