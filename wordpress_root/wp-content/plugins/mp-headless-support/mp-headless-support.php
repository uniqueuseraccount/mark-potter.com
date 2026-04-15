<?php
/**
 * Plugin Name: Mark Potter Headless Support
 * Description: Custom post types, Gallery Management UI, and REST API endpoints.
 * Version: 1.1.0
 * Author: Mark Potter
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Register Custom Post Types
function mp_register_post_types() {
    register_post_type('gallery', [
        'labels' => [
            'name' => 'Galleries',
            'singular_name' => 'Gallery',
            'add_new_item' => 'Add New Gallery',
            'edit_item' => 'Edit Gallery',
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'], // Removed 'custom-fields' from UI to avoid clutter, we use our own box
        'menu_icon' => 'dashicons-format-gallery',
        'taxonomies' => ['category', 'post_tag'],
    ]);

    register_post_type('video', [
        'labels' => [
            'name' => 'Videos',
            'singular_name' => 'Video',
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'menu_icon' => 'dashicons-format-video',
        'taxonomies' => ['category', 'post_tag'],
    ]);
}
add_action('init', 'mp_register_post_types');

// 2. Custom Gallery Meta Box (Replaces ACF)
function mp_add_gallery_metabox() {
    add_meta_box(
        'mp_gallery_images',
        'Gallery Images',
        'mp_render_gallery_metabox',
        'gallery',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'mp_add_gallery_metabox');

function mp_render_gallery_metabox($post) {
    // Retrieve existing value
    // Data is stored as a serialized array (from migration) or just metadata.
    // get_post_meta with true returns the single value (which might be an array or string depending on how it was saved).
    $value = get_post_meta($post->ID, 'gallery_images', true);
    
    // Normalize to array of IDs
    $ids = [];
    if (is_array($value)) {
        $ids = $value; // It was a serialized array
    } elseif (!empty($value)) {
        // It might be a comma separated string if saved manually differently, but we aim for array.
        // Or if it was a single string ID.
        if (is_string($value) && strpos($value, ',') !== false) {
             $ids = explode(',', $value);
        } else {
             $ids = [$value];
        }
    }

    wp_nonce_field('mp_save_gallery_images', 'mp_gallery_images_nonce');
    ?>
    <div id="mp_gallery_images_wrapper">
        <input type="hidden" name="gallery_images_ids" id="mp_gallery_images_ids" value="<?php echo implode(',', $ids); ?>" />
        
        <div id="mp_gallery_images_container">
            <?php foreach ($ids as $id): 
                if(!$id) continue;
                $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                $url = $thumb ? $thumb[0] : '';
                if(!$url) continue; // Skip if image deleted
            ?>
                <div class="mp-gallery-image" data-id="<?php echo esc_attr($id); ?>">
                    <img src="<?php echo esc_url($url); ?>" />
                    <div class="mp-gallery-remove">&times;</div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <button id="mp_add_gallery_images" class="button button-primary mp-add-images-button">Add Images</button>
        <p class="description">Drag and drop to reorder.</p>
    </div>
    <?php
}

function mp_save_gallery_metabox($post_id) {
    if (!isset($_POST['mp_gallery_images_nonce']) || !wp_verify_nonce($_POST['mp_gallery_images_nonce'], 'mp_save_gallery_images')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['gallery_images_ids'])) {
        $ids_string = sanitize_text_field($_POST['gallery_images_ids']);
        $ids_array = array_filter(explode(',', $ids_string));
        
        // Save as array (WP will automatically serialize it)
        // This matches the format used by the migration script (phpserialize.dumps)
        update_post_meta($post_id, 'gallery_images', $ids_array);
    }
}
add_action('save_post', 'mp_save_gallery_metabox');

function mp_admin_scripts($hook) {
    global $post;
    if (($hook == 'post-new.php' || $hook == 'post.php') && 'gallery' === $post->post_type) {
        wp_enqueue_media();
        wp_enqueue_script('mp-admin-gallery', plugin_dir_url(__FILE__) . 'admin-gallery.js', ['jquery', 'jquery-ui-sortable'], '1.0.0', true);
        wp_enqueue_style('mp-admin-gallery', plugin_dir_url(__FILE__) . 'admin-gallery.css', [], '1.0.0');
    }
}
add_action('admin_enqueue_scripts', 'mp_admin_scripts');

// 3. REST API Fields
function mp_register_rest_fields() {
    register_rest_field('gallery', 'gallery_images', [
        'get_callback' => function($post_arr) {
            $images = get_post_meta($post_arr['id'], 'gallery_images', true);
            if (!$images) return [];
            
            // Normalize
            $ids = is_array($images) ? $images : explode(',', $images);
            
            $gallery = [];
            foreach ($ids as $id) {
                if(!$id) continue;
                $url = wp_get_attachment_url($id);
                if ($url) {
                    $gallery[] = [
                        'id' => (int)$id,
                        'url' => $url,
                        'alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
                        'caption' => wp_get_attachment_caption($id),
                        'sizes' => [
                            'thumbnail' => wp_get_attachment_image_src($id, 'thumbnail')[0],
                            'medium' => wp_get_attachment_image_src($id, 'medium')[0],
                            'large' => wp_get_attachment_image_src($id, 'large')[0],
                            'full' => wp_get_attachment_image_src($id, 'full')[0],
                        ]
                    ];
                }
            }
            return $gallery;
        },
        'update_callback' => null,
        'schema' => null,
    ]);

    register_rest_field('video', 'video_url', [
        'get_callback' => function($post_arr) {
            return get_post_meta($post_arr['id'], 'video_url', true);
        },
        'update_callback' => null,
        'schema' => null,
    ]);
}
add_action('rest_api_init', 'mp_register_rest_fields');

// 4. Messages Table & Contact API
function mp_create_messages_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mp_messages';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        first_name tinytext NOT NULL,
        message text NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'mp_create_messages_table');

add_action('rest_api_init', function () {
    register_rest_route('mp/v1', '/contact', [
        'methods' => 'POST',
        'callback' => 'mp_handle_contact_form',
        'permission_callback' => '__return_true', 
    ]);
});

function mp_handle_contact_form($request) {
    global $wpdb;
    $params = $request->get_json_params();

    $first_name = sanitize_text_field($params['first_name'] ?? '');
    $message = sanitize_textarea_field($params['message'] ?? '');

    if (empty($first_name) || empty($message)) {
        return new WP_Error('missing_fields', 'Please fill in all fields', ['status' => 400]);
    }

    $encryption_key = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'default_key';
    $ciphering = "AES-128-CTR";
    $iv_length = openssl_cipher_iv_length($ciphering);
    $options = 0;
    $encryption_iv = '1234567891011121'; 
    $encrypted_message = openssl_encrypt($message, $ciphering, $encryption_key, $options, $encryption_iv);

    $table_name = $wpdb->prefix . 'mp_messages';
    $result = $wpdb->insert($table_name, [
        'time' => current_time('mysql'),
        'first_name' => $first_name,
        'message' => $encrypted_message,
    ]);

    if ($result) {
        return ['status' => 'success', 'message' => 'Message sent successfully'];
    } else {
        return new WP_Error('db_error', 'Could not save message', ['status' => 500]);
    }
}