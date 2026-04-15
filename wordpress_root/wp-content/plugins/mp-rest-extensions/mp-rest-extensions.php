<?php
/**
 * Plugin Name: Mark Potter REST Extensions
 * Description: Custom REST API endpoints for Mark Potter Photography site
 * Version: 1.0.0
 * Author: Mark Potter
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register custom taxonomies
add_action('init', 'mp_register_taxonomies');

function mp_register_taxonomies() {
    // Upload Batch taxonomy
    register_taxonomy('upload_batch', 'post', array(
        'labels' => array(
            'name' => 'Upload Batches',
            'singular_name' => 'Upload Batch',
        ),
        'hierarchical' => false,
        'public' => true,
        'show_in_rest' => true,
        'rest_base' => 'upload_batches',
    ));
}

// Register REST API routes
add_action('rest_api_init', 'mp_register_rest_routes');

function mp_register_rest_routes() {
    // Enhanced posts endpoint
    register_rest_route('mp/v1', '/posts', array(
        'methods' => 'GET',
        'callback' => 'mp_get_enhanced_posts',
        'permission_callback' => '__return_true',
    ));
    
    // Single post with full gallery
    register_rest_route('mp/v1', '/posts/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'mp_get_single_post',
        'permission_callback' => '__return_true',
    ));
    
    // Tags with counts
    register_rest_route('mp/v1', '/tags', array(
        'methods' => 'GET',
        'callback' => 'mp_get_tags',
        'permission_callback' => '__return_true',
    ));
    
    // Categories with counts
    register_rest_route('mp/v1', '/categories', array(
        'methods' => 'GET',
        'callback' => 'mp_get_categories',
        'permission_callback' => '__return_true',
    ));
    
    // Upload batches
    register_rest_route('mp/v1', '/upload-batches', array(
        'methods' => 'GET',
        'callback' => 'mp_get_upload_batches',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Get enhanced posts with all images
 */
function mp_get_enhanced_posts($request) {
    $params = $request->get_params();
    
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => isset($params['per_page']) ? intval($params['per_page']) : 20,
        'paged' => isset($params['page']) ? intval($params['page']) : 1,
        'orderby' => 'date',
        'order' => 'DESC',
    );
    
    // Filter by category
    if (!empty($params['categories'])) {
        $args['category__in'] = array_map('intval', explode(',', $params['categories']));
    }
    
    // Filter by tag
    if (!empty($params['tags'])) {
        $args['tag__in'] = array_map('intval', explode(',', $params['tags']));
    }
    
    // Filter by upload batch
    if (!empty($params['upload_batch'])) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'upload_batch',
                'field' => 'slug',
                'terms' => $params['upload_batch'],
            ),
        );
    }
    
    $query = new WP_Query($args);
    $posts = array();
    
    foreach ($query->posts as $post) {
        $posts[] = mp_format_post($post);
    }
    
    return rest_ensure_response($posts);
}

/**
 * Get single post with full gallery
 */
function mp_get_single_post($request) {
    $post_id = intval($request['id']);
    $post = get_post($post_id);
    
    if (!$post || $post->post_status !== 'publish') {
        return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
    }
    
    return rest_ensure_response(mp_format_post($post));
}

/**
 * Format post with all images and metadata
 */
function mp_format_post($post) {
    $featured_id = get_post_thumbnail_id($post->ID);
    $images = array();
    
    // Add featured image first
    if ($featured_id) {
        $images[] = mp_format_image($featured_id);
    }
    
    // Extract images from content
    preg_match_all('/<img[^>]+wp-image-(\d+)[^>]*>/i', $post->post_content, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $image_id) {
            // Avoid duplicates
            if ($image_id != $featured_id) {
                $images[] = mp_format_image($image_id);
            }
        }
    }
    
    // Get tags
    $tags = wp_get_post_terms($post->ID, 'post_tag');
    $tag_names = array_map(function($tag) { return $tag->name; }, $tags);
    
    // Get categories
    $categories = wp_get_post_terms($post->ID, 'category');
    $category_names = array_map(function($cat) { return $cat->name; }, $categories);
    
    // Get upload batch
    $upload_batches = wp_get_post_terms($post->ID, 'upload_batch');
    $upload_batch = !empty($upload_batches) ? $upload_batches[0]->slug : null;
    
    return array(
        'id' => $post->ID,
        'title' => get_the_title($post->ID),
        'date' => $post->post_date,
        'excerpt' => get_the_excerpt($post->ID),
        'images' => $images,
        'tags' => $tag_names,
        'categories' => $category_names,
        'upload_batch' => $upload_batch,
        'image_count' => count($images),
    );
}

/**
 * Format image data
 */
function mp_format_image($image_id) {
    $image_data = wp_get_attachment_metadata($image_id);
    $url = wp_get_attachment_url($image_id);
    
    // Convert to relative URL
    $url = str_replace('http://www.mark-potter.com', '', $url);
    $url = str_replace('https://www.mark-potter.com', '', $url);
    $url = str_replace('http://mark-potter.com', '', $url);
    $url = str_replace('https://mark-potter.com', '', $url);
    
    return array(
        'id' => $image_id,
        'url' => $url,
        'width' => isset($image_data['width']) ? $image_data['width'] : 1000,
        'height' => isset($image_data['height']) ? $image_data['height'] : 1000,
        'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
        'sizes' => isset($image_data['sizes']) ? $image_data['sizes'] : array(),
    );
}

/**
 * Get tags with post counts
 */
function mp_get_tags($request) {
    $tags = get_tags(array(
        'orderby' => 'count',
        'order' => 'DESC',
        'number' => 100,
    ));
    
    $result = array();
    foreach ($tags as $tag) {
        $result[] = array(
            'id' => $tag->term_id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'count' => $tag->count,
        );
    }
    
    return rest_ensure_response($result);
}

/**
 * Get categories with post counts
 */
function mp_get_categories($request) {
    $categories = get_categories(array(
        'orderby' => 'count',
        'order' => 'DESC',
        'number' => 100,
    ));
    
    $result = array();
    foreach ($categories as $category) {
        // Skip 'Uncategorized'
        if ($category->slug === 'uncategorized') {
            continue;
        }
        
        $result[] = array(
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'count' => $category->count,
        );
    }
    
    return rest_ensure_response($result);
}

/**
 * Get upload batches with post counts
 */
function mp_get_upload_batches($request) {
    $batches = get_terms(array(
        'taxonomy' => 'upload_batch',
        'orderby' => 'name',
        'order' => 'DESC',
        'hide_empty' => true,
    ));
    
    if (is_wp_error($batches)) {
        return rest_ensure_response(array());
    }
    
    $result = array();
    foreach ($batches as $batch) {
        $result[] = array(
            'id' => $batch->term_id,
            'name' => $batch->name,
            'slug' => $batch->slug,
            'count' => $batch->count,
        );
    }
    
    return rest_ensure_response($result);
}
