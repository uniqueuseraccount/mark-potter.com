<?php
/**
 * Plugin Name: Mark Potter Content Manager
 * Description: Content management and migration tools for tag/collection structure
 * Version: 2.0.0
 * Author: Mark Potter
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
add_action('admin_menu', 'mp_manager_menu');

function mp_manager_menu() {
    add_menu_page(
        'Content Manager',
        'Content Manager',
        'manage_options',
        'mp-content-manager',
        'mp_manager_page',
        'dashicons-images-alt2',
        30
    );
}

// Admin page
function mp_manager_page() {
    // Handle confirmation
    if (isset($_POST['mp_confirm_action']) && check_admin_referer('mp_confirm_action')) {
        mp_execute_bulk_action();
        wp_redirect(admin_url('admin.php?page=mp-content-manager&updated=1'));
        exit;
    }
    
    // Handle bulk action preview
    if (isset($_POST['mp_bulk_action']) && check_admin_referer('mp_bulk_action')) {
        mp_show_preview();
        return;
    }
    
    // Get parameters
    $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
    $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
    $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
    
    // Build query args
    $args = array(
        'post_type' => array('post', 'gallery'),
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'orderby' => $orderby === 'featured' ? 'meta_value_num' : $orderby,
        'order' => $order,
    );
    
    if ($orderby === 'featured') {
        $args['meta_key'] = '_thumbnail_id';
    }
    
    $query = new WP_Query($args);
    
    ?>
    <div class="wrap">
        <h1>Content Manager</h1>
        
        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Bulk action completed successfully!</strong></p>
            </div>
        <?php endif; ?>
        
        <div class="notice notice-info">
            <p><strong>Content Organization Strategy:</strong></p>
            <ul>
                <li><strong>Upload Batches:</strong> Auto-incremented tags (batch-001, batch-002) that preserve original grouping</li>
                <li><strong>Featured Images:</strong> First image in each post used as thumbnail</li>
                <li><strong>Tags:</strong> Subject, location, medium, equipment (camping, pentax, film, minnesota, etc.)</li>
                <li><strong>Categories:</strong> Content types (film-photography, digital, B&W, color, automotive, landscape)</li>
            </ul>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('mp_bulk_action'); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="bulk_action" id="bulk-action">
                        <option value="">Bulk Actions</option>
                        <option value="auto_assign_batches">Auto-Assign Upload Batches (Oldest→Newest)</option>
                        <option value="auto_featured_images">Auto-Set Featured Images</option>
                        <option value="set_post_type_gallery">Set Post Type → Gallery</option>
                    </select>
                    <input type="submit" class="button action" value="Preview Changes">
                </div>
                
                <div class="alignleft actions">
                    <label>Per page:</label>
                    <select name="per_page_select" onchange="window.location.href='<?php echo admin_url('admin.php?page=mp-content-manager&per_page='); ?>' + this.value;">
                        <option value="5" <?php selected($per_page, 5); ?>>5</option>
                        <option value="10" <?php selected($per_page, 10); ?>>10</option>
                        <option value="25" <?php selected($per_page, 25); ?>>25</option>
                        <option value="50" <?php selected($per_page, 50); ?>>50</option>
                        <option value="100" <?php selected($per_page, 100); ?>>100</option>
                    </select>
                </div>
                
                <div class="tablenav-pages">
                    <?php
                    $total_pages = $query->max_num_pages;
                    
                    if ($total_pages > 1) {
                        echo paginate_links(array(
                            'base' => add_query_arg(array('paged' => '%#%', 'per_page' => $per_page)),
                            'format' => '',
                            'current' => $paged,
                            'total' => $total_pages,
                        ));
                    }
                    ?>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="select-all"></th>
                        <th style="width: 60px;">
                            <?php echo mp_sortable_link('ID', 'ID', $orderby, $order, $per_page); ?>
                        </th>
                        <th>Title</th>
                        <th style="width: 80px;">Type</th>
                        <th style="width: 100px;">
                            <?php echo mp_sortable_link('date', 'Date', $orderby, $order, $per_page); ?>
                        </th>
                        <th style="width: 80px;">
                            <?php echo mp_sortable_link('featured', 'Featured', $orderby, $order, $per_page); ?>
                        </th>
                        <th style="width: 120px;">Upload Batch</th>
                        <th style="width: 60px;">Images</th>
                        <th style="width: 200px;">Tags</th>
                        <th style="width: 150px;">Categories</th>
                        <th style="width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($query->have_posts()) {
                        while ($query->have_posts()) {
                            $query->the_post();
                            mp_render_post_row(get_the_ID());
                        }
                    } else {
                        echo '<tr><td colspan="11">No posts found.</td></tr>';
                    }
                    wp_reset_postdata();
                    ?>
                </tbody>
            </table>
        </form>
        
        <style>
            .mp-status-yes { color: #46b450; font-weight: bold; }
            .mp-status-no { color: #dc3232; }
            .mp-image-count { 
                display: inline-block;
                background: #2271b1;
                color: white;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .mp-tags-truncated {
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .mp-post-type-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                background: #ddd;
            }
            .mp-post-type-gallery {
                background: #9b51e0;
                color: white;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                $('#select-all').on('change', function() {
                    $('.post-checkbox').prop('checked', this.checked);
                });
            });
        </script>
    </div>
    <?php
}

function mp_sortable_link($column, $label, $current_orderby, $current_order, $per_page) {
    $new_order = ($current_orderby === $column && $current_order === 'DESC') ? 'ASC' : 'DESC';
    $arrow = '';
    
    if ($current_orderby === $column) {
        $arrow = $current_order === 'DESC' ? ' ▼' : ' ▲';
    }
    
    $url = add_query_arg(array(
        'orderby' => $column,
        'order' => $new_order,
        'per_page' => $per_page,
    ));
    
    return '<a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a>';
}

function mp_render_post_row($post_id) {
    $post = get_post($post_id);
    $featured_image = has_post_thumbnail($post_id);
    
    // Get upload batch
    $batches = wp_get_post_terms($post_id, 'upload_batch');
    $batch_name = !empty($batches) ? $batches[0]->name : '<span class="mp-status-no">None</span>';
    
    // Count images
    $image_count = 0;
    if ($featured_image) $image_count++;
    preg_match_all('/<img[^>]+wp-image-(\d+)[^>]*>/i', $post->post_content, $matches);
    $image_count += count($matches[1]);
    
    // Get tags (show first 3)
    $tags = wp_get_post_terms($post_id, 'post_tag');
    $tag_names = array_map(function($t) { return $t->name; }, $tags);
    $tags_display = '';
    if (!empty($tag_names)) {
        $display_tags = array_slice($tag_names, 0, 3);
        $tags_display = esc_html(implode(', ', $display_tags));
        if (count($tag_names) > 3) {
            $tags_display .= ' <span style="color: #999;">+' . (count($tag_names) - 3) . ' more</span>';
        }
    } else {
        $tags_display = '<span class="mp-status-no">None</span>';
    }
    
    // Get categories
    $categories = wp_get_post_terms($post_id, 'category');
    $cat_names = array_filter(array_map(function($c) { 
        return $c->slug !== 'uncategorized' ? $c->name : null; 
    }, $categories));
    $cats_display = !empty($cat_names) ? esc_html(implode(', ', $cat_names)) : '<span class="mp-status-no">None</span>';
    
    ?>
    <tr>
        <td><input type="checkbox" class="post-checkbox" name="post_ids[]" value="<?php echo $post_id; ?>"></td>
        <td><?php echo $post_id; ?></td>
        <td>
            <strong><?php echo esc_html($post->post_title); ?></strong>
        </td>
        <td>
            <?php if ($post->post_type === 'gallery'): ?>
                <span class="mp-post-type-badge mp-post-type-gallery">Gallery</span>
            <?php else: ?>
                <span class="mp-post-type-badge">Post</span>
            <?php endif; ?>
        </td>
        <td><?php echo get_the_date('Y-m-d', $post_id); ?></td>
        <td>
            <?php if ($featured_image): ?>
                <span class="mp-status-yes">✓ Yes</span>
            <?php else: ?>
                <span class="mp-status-no">✗ No</span>
            <?php endif; ?>
        </td>
        <td><?php echo $batch_name; ?></td>
        <td><span class="mp-image-count"><?php echo $image_count; ?></span></td>
        <td class="mp-tags-truncated"><?php echo $tags_display; ?></td>
        <td><?php echo $cats_display; ?></td>
        <td>
            <a href="<?php echo get_edit_post_link($post_id); ?>" class="button button-small">Edit</a>
        </td>
    </tr>
    <?php
}

function mp_show_preview() {
    if (empty($_POST['post_ids']) || empty($_POST['bulk_action'])) {
        echo '<div class="wrap"><div class="error"><p>No posts selected or action chosen.</p></div></div>';
        return;
    }
    
    $post_ids = array_map('intval', $_POST['post_ids']);
    $action = sanitize_text_field($_POST['bulk_action']);
    
    ?>
    <div class="wrap">
        <h1>Preview Changes</h1>
        
        <div class="notice notice-warning">
            <p><strong>Review the changes below before applying:</strong></p>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('mp_confirm_action'); ?>
            <input type="hidden" name="bulk_action" value="<?php echo esc_attr($action); ?>">
            <?php foreach ($post_ids as $id): ?>
                <input type="hidden" name="post_ids[]" value="<?php echo $id; ?>">
            <?php endforeach; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>Title</th>
                        <th style="width: 200px;">Current Value</th>
                        <th style="width: 200px;">New Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($post_ids as $post_id) {
                        $post = get_post($post_id);
                        $current = mp_get_current_value($post_id, $action);
                        $new = mp_get_new_value($post_id, $action);
                        
                        ?>
                        <tr>
                            <td><?php echo $post_id; ?></td>
                            <td><?php echo esc_html($post->post_title); ?></td>
                            <td><?php echo $current; ?></td>
                            <td><strong style="color: #2271b1;"><?php echo $new; ?></strong></td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
            
            <p class="submit">
                <input type="submit" name="mp_confirm_action" class="button button-primary button-large" value="Apply Changes">
                <a href="<?php echo admin_url('admin.php?page=mp-content-manager'); ?>" class="button button-large">Cancel</a>
            </p>
        </form>
    </div>
    <?php
}

function mp_get_current_value($post_id, $action) {
    switch ($action) {
        case 'auto_assign_batches':
            $batches = wp_get_post_terms($post_id, 'upload_batch');
            return !empty($batches) ? $batches[0]->name : '<em>None</em>';
            
        case 'auto_featured_images':
            return has_post_thumbnail($post_id) ? 'Has featured image' : '<em>No featured image</em>';
            
        case 'set_post_type_gallery':
            return get_post_type($post_id);
            
        default:
            return '';
    }
}

function mp_get_new_value($post_id, $action) {
    switch ($action) {
        case 'auto_assign_batches':
            $batches = wp_get_post_terms($post_id, 'upload_batch');
            if (!empty($batches)) {
                return $batches[0]->name . ' <em>(no change)</em>';
            }
            $batch_num = mp_get_batch_for_post($post_id);
            return 'batch-' . str_pad($batch_num, 3, '0', STR_PAD_LEFT);
            
        case 'auto_featured_images':
            if (has_post_thumbnail($post_id)) {
                return 'Has featured image <em>(no change)</em>';
            }
            preg_match('/<img[^>]+wp-image-(\d+)[^>]*>/i', get_post_field('post_content', $post_id), $matches);
            return !empty($matches[1]) ? 'Will set image ID ' . $matches[1] : '<em>No images found in content</em>';
            
        case 'set_post_type_gallery':
            return 'gallery';
            
        default:
            return '';
    }
}

function mp_execute_bulk_action() {
    if (empty($_POST['post_ids']) || empty($_POST['bulk_action'])) {
        return;
    }
    
    $post_ids = array_map('intval', $_POST['post_ids']);
    $action = sanitize_text_field($_POST['bulk_action']);
    
    switch ($action) {
        case 'auto_assign_batches':
            mp_execute_assign_batches($post_ids);
            break;
        case 'auto_featured_images':
            mp_execute_featured_images($post_ids);
            break;
        case 'set_post_type_gallery':
            mp_execute_set_post_type($post_ids);
            break;
    }
}

function mp_execute_assign_batches($post_ids) {
    foreach ($post_ids as $post_id) {
        $batches = wp_get_post_terms($post_id, 'upload_batch');
        if (!empty($batches)) continue; // Skip if already has batch
        
        $batch_num = mp_get_batch_for_post($post_id);
        $batch_slug = 'batch-' . str_pad($batch_num, 3, '0', STR_PAD_LEFT);
        
        $term = term_exists($batch_slug, 'upload_batch');
        if (!$term) {
            $term = wp_insert_term($batch_slug, 'upload_batch');
        }
        
        if (!is_wp_error($term)) {
            wp_set_post_terms($post_id, array($term['term_id']), 'upload_batch');
        }
    }
}

function mp_execute_featured_images($post_ids) {
    foreach ($post_ids as $post_id) {
        if (has_post_thumbnail($post_id)) continue;
        
        preg_match('/<img[^>]+wp-image-(\d+)[^>]*>/i', get_post_field('post_content', $post_id), $matches);
        if (!empty($matches[1])) {
            set_post_thumbnail($post_id, intval($matches[1]));
        }
    }
}

function mp_execute_set_post_type($post_ids) {
    foreach ($post_ids as $post_id) {
        set_post_type($post_id, 'gallery');
    }
}

function mp_get_batch_for_post($post_id) {
    // Get all posts ordered oldest to newest
    static $post_order = null;
    
    if ($post_order === null) {
        $all_posts = get_posts(array(
            'post_type' => array('post', 'gallery'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC', // Oldest first
            'fields' => 'ids',
        ));
        
        $post_order = array_flip($all_posts);
    }
    
    return isset($post_order[$post_id]) ? $post_order[$post_id] + 1 : 999;
}