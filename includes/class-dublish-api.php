<?php
/**
 * Dublish API Handler Class
 * 
 * Handles creation of Elementor pages from Snefuru system
 */

if (!defined('ABSPATH')) {
    exit;
}

class Snefuru_Dublish_API {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('snefuru/v1', '/dublish-create-page', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_elementor_page'),
            'permission_callback' => array($this, 'permission_check'),
            'args' => array(
                'action' => array(
                    'required' => true,
                    'type' => 'string'
                ),
                'post_data' => array(
                    'required' => true,
                    'type' => 'object'
                )
            )
        ));
        
        register_rest_route('snefuru/v1', '/snoverride-update-page', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_elementor_page'),
            'permission_callback' => array($this, 'permission_check'),
            'args' => array(
                'action' => array(
                    'required' => true,
                    'type' => 'string'
                ),
                'post_id' => array(
                    'required' => true,
                    'type' => 'integer'
                ),
                'update_data' => array(
                    'required' => true,
                    'type' => 'object'
                )
            )
        ));
    }
    
    /**
     * Permission check for API access
     */
    public function permission_check($request) {
        // For now, allow access (you can add authentication later)
        // You might want to check for API keys or other authentication
        $user_agent = $request->get_header('User-Agent');
        
        // Only allow requests from Snefuru system
        if (strpos($user_agent, 'Snefuru-Dublish-System') === false && 
            strpos($user_agent, 'Snefuru-Snoverride-System') === false) {
            return new WP_Error('unauthorized', 'Unauthorized access', array('status' => 401));
        }
        
        return true;
    }
    
    /**
     * Create Elementor page from Snefuru data
     */
    public function create_elementor_page($request) {
        try {
            $params = $request->get_json_params();
            
            if (!isset($params['action']) || $params['action'] !== 'dublish_create_elementor_page') {
                return new WP_Error('invalid_action', 'Invalid action specified', array('status' => 400));
            }
            
            if (!isset($params['post_data'])) {
                return new WP_Error('missing_data', 'Post data is required', array('status' => 400));
            }
            
            $post_data = $params['post_data'];
            $gcon_piece_id = isset($params['gcon_piece_id']) ? $params['gcon_piece_id'] : '';
            
            // Validate required fields
            if (empty($post_data['post_title']) || empty($post_data['meta_input']['_elementor_data'])) {
                return new WP_Error('missing_required', 'Post title and Elementor data are required', array('status' => 400));
            }
            
            // Check if Elementor is active
            if (!is_plugin_active('elementor/elementor.php')) {
                return new WP_Error('elementor_inactive', 'Elementor plugin is not active', array('status' => 400));
            }
            
            // Validate Elementor data format
            $elementor_data = $post_data['meta_input']['_elementor_data'];
            if (is_string($elementor_data)) {
                $parsed_data = json_decode($elementor_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return new WP_Error('invalid_elementor_data', 'Invalid Elementor JSON data', array('status' => 400));
                }
                // Ensure it's stored as string for Elementor
                $post_data['meta_input']['_elementor_data'] = $elementor_data;
            }
            
            // Prepare post data for wp_insert_post
            $insert_post_data = array(
                'post_title' => sanitize_text_field($post_data['post_title']),
                'post_name' => isset($post_data['post_name']) ? sanitize_title($post_data['post_name']) : '',
                'post_status' => 'draft', // Start as draft to avoid errors on live site
                'post_type' => isset($post_data['post_type']) ? $post_data['post_type'] : 'page',
                'post_content' => '', // Elementor uses meta, not content
                'post_author' => 1, // Use admin user
                'meta_input' => array()
            );
            
            // Prepare meta data - use safe defaults
            $safe_meta = array(
                '_elementor_edit_mode' => 'builder',
                '_elementor_template_type' => 'wp-page',
                '_elementor_version' => '3.17.0',
                '_wp_page_template' => 'default', // Use default template instead of elementor_header_footer
            );
            
            // Add custom meta if provided
            if (isset($post_data['meta_input']) && is_array($post_data['meta_input'])) {
                foreach ($post_data['meta_input'] as $meta_key => $meta_value) {
                    $safe_meta[$meta_key] = $meta_value;
                }
            }
            
            $insert_post_data['meta_input'] = $safe_meta;
            
            // Add Snefuru tracking meta
            $insert_post_data['meta_input']['_snefuru_gcon_piece_id'] = $gcon_piece_id;
            $insert_post_data['meta_input']['_snefuru_created_via'] = 'dublish_system';
            $insert_post_data['meta_input']['_snefuru_created_at'] = current_time('mysql');
            
            // Create the post
            $post_id = wp_insert_post($insert_post_data, true);
            
            if (is_wp_error($post_id)) {
                error_log('Dublish Error - Post Creation Failed: ' . $post_id->get_error_message());
                error_log('Dublish Error - Post Data: ' . print_r($insert_post_data, true));
                return new WP_Error('post_creation_failed', 'Failed to create post: ' . $post_id->get_error_message(), array('status' => 500));
            }
            
            // After successful creation, try to publish if all is well
            $publish_result = wp_update_post(array(
                'ID' => $post_id,
                'post_status' => isset($post_data['post_status']) ? $post_data['post_status'] : 'publish'
            ));
            
            if (is_wp_error($publish_result)) {
                error_log('Dublish Warning - Could not publish post ' . $post_id . ': ' . $publish_result->get_error_message());
                // Continue anyway, post exists as draft
            }
            
            // Log the creation
            $this->log_dublish_activity($post_id, $gcon_piece_id, $post_data['post_title']);
            
            // Get the post URL and edit URL
            $post_url = get_permalink($post_id);
            $edit_url = admin_url('post.php?post=' . $post_id . '&action=elementor');
            
            // Prepare success response
            $response_data = array(
                'success' => true,
                'message' => 'Elementor page created successfully',
                'post_id' => $post_id,
                'post_url' => $post_url,
                'edit_url' => $edit_url,
                'post_title' => $post_data['post_title'],
                'gcon_piece_id' => $gcon_piece_id,
                'created_at' => current_time('mysql')
            );
            
            return new WP_REST_Response($response_data, 200);
            
        } catch (Exception $e) {
            error_log('Snefuru Dublish API Error: ' . $e->getMessage());
            return new WP_Error('internal_error', 'Internal server error: ' . $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * Log Dublish activity
     */
    private function log_dublish_activity($post_id, $gcon_piece_id, $post_title) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'snefuru_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'action' => 'dublish_create_elementor_page',
                'data' => json_encode(array(
                    'post_id' => $post_id,
                    'post_title' => $post_title,
                    'gcon_piece_id' => $gcon_piece_id,
                    'site_url' => get_site_url()
                )),
                'status' => 'completed'
            ),
            array('%s', '%s', '%s')
        );
    }
    
    /**
     * Get Dublish activity logs
     */
    public function get_dublish_logs($limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'snefuru_logs';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE action = 'dublish_create_elementor_page' ORDER BY timestamp DESC LIMIT %d",
            $limit
        ));
        
        return $results;
    }
    
    /**
     * Update existing Elementor page via Snoverride
     */
    public function update_elementor_page($request) {
        try {
            $params = $request->get_json_params();
            
            if (!isset($params['action']) || $params['action'] !== 'snoverride_update_elementor_page') {
                return new WP_Error('invalid_action', 'Invalid action specified', array('status' => 400));
            }
            
            if (!isset($params['post_id']) || !isset($params['update_data'])) {
                return new WP_Error('missing_data', 'Post ID and update data are required', array('status' => 400));
            }
            
            $post_id = intval($params['post_id']);
            $update_data = $params['update_data'];
            $gcon_piece_id = isset($params['gcon_piece_id']) ? $params['gcon_piece_id'] : '';
            
            // Validate that post exists
            $existing_post = get_post($post_id);
            if (!$existing_post) {
                return new WP_Error('post_not_found', 'Post with ID ' . $post_id . ' not found', array('status' => 404));
            }
            
            // Check if Elementor is active
            if (!is_plugin_active('elementor/elementor.php')) {
                return new WP_Error('elementor_inactive', 'Elementor plugin is not active', array('status' => 400));
            }
            
            // Prepare update data
            $post_update_data = array(
                'ID' => $post_id
            );
            
            // Update post title if provided
            if (!empty($update_data['post_title'])) {
                $post_update_data['post_title'] = sanitize_text_field($update_data['post_title']);
            }
            
            // Update the post
            $update_result = wp_update_post($post_update_data, true);
            
            if (is_wp_error($update_result)) {
                error_log('Snoverride Error - Post Update Failed: ' . $update_result->get_error_message());
                return new WP_Error('update_failed', 'Failed to update post: ' . $update_result->get_error_message(), array('status' => 500));
            }
            
            // Update meta fields
            if (isset($update_data['meta_input']) && is_array($update_data['meta_input'])) {
                foreach ($update_data['meta_input'] as $meta_key => $meta_value) {
                    // Special handling for Elementor data
                    if ($meta_key === '_elementor_data') {
                        // Ensure it's a valid JSON string
                        if (is_string($meta_value)) {
                            $parsed = json_decode($meta_value, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                error_log('Snoverride Warning - Invalid Elementor JSON data');
                                continue;
                            }
                        }
                        // Clear Elementor cache for this post
                        delete_post_meta($post_id, '_elementor_css');
                    }
                    
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }
            
            // Add Snoverride tracking meta
            update_post_meta($post_id, '_snefuru_last_snoverride', current_time('mysql'));
            update_post_meta($post_id, '_snefuru_snoverride_gcon_piece_id', $gcon_piece_id);
            update_post_meta($post_id, '_snefuru_snoverride_count', intval(get_post_meta($post_id, '_snefuru_snoverride_count', true)) + 1);
            
            // Clear WordPress and Elementor caches
            wp_cache_delete($post_id, 'posts');
            wp_cache_delete($post_id, 'post_meta');
            
            // If Elementor Pro is active, clear its cache too
            if (defined('ELEMENTOR_PRO_VERSION')) {
                do_action('elementor/core/files/clear_cache');
            }
            
            // Log the update
            $this->log_snoverride_activity($post_id, $gcon_piece_id, $existing_post->post_title);
            
            // Get the post URL
            $post_url = get_permalink($post_id);
            $edit_url = admin_url('post.php?post=' . $post_id . '&action=elementor');
            
            // Prepare success response
            $response_data = array(
                'success' => true,
                'message' => 'Elementor page updated successfully',
                'post_id' => $post_id,
                'post_url' => $post_url,
                'edit_url' => $edit_url,
                'post_title' => get_the_title($post_id),
                'gcon_piece_id' => $gcon_piece_id,
                'updated_at' => current_time('mysql')
            );
            
            return new WP_REST_Response($response_data, 200);
            
        } catch (Exception $e) {
            error_log('Snefuru Snoverride API Error: ' . $e->getMessage());
            return new WP_Error('internal_error', 'Internal server error: ' . $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * Log Snoverride activity
     */
    private function log_snoverride_activity($post_id, $gcon_piece_id, $post_title) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'snefuru_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'action' => 'snoverride_update_elementor_page',
                'data' => json_encode(array(
                    'post_id' => $post_id,
                    'post_title' => $post_title,
                    'gcon_piece_id' => $gcon_piece_id,
                    'site_url' => get_site_url()
                )),
                'status' => 'completed'
            ),
            array('%s', '%s', '%s')
        );
    }
}