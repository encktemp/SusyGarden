<?php
if (!class_exists('Awpa_Ratings_Rest_Controller')) {
    class Awpa_Ratings_Rest_Controller
    {

        private $namespace;
        public function __construct()
        {
            $this->namespace = 'awpa-pro-api/v1';
            add_action('init', [$this, 'awpa_pro_insert_rating_settings']);
        }

        public function awpa_ratings_register_routes()
        {
            register_rest_route(
                $this->namespace,
                '/awpa-pro-rating',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'awpa_pro_api_get_rating_settings'),
                        'permission_callback' => array($this, 'awpa_permission_check'),

                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/awpa-pro-rating',
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array($this, 'awpa_pro_api_set_rating_settings'),
                        'permission_callback' => array($this, 'awpa_permission_check'),

                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/post-rating-review',
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array($this, 'awpa_pro_api_post_rating_review'),
                        'permission_callback' =>  function ($request) {
                            $nonce = $request->get_header('X-WP-Nonce');
                            
                            if (current_user_can('read')) {
                                if (wp_verify_nonce($nonce, 'wp_rest')) {
                                    return true;
                                }
                            } else {
                                return new WP_Error('rest_forbidden', 'Validation Failed', array('status' => 403));
                            }
                        }

                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/post-rating-review',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'awpa_pro_api_get_post_rating_review'),
                        'permission_callback' =>  '__return_true'

                    ),
                )
            );
        }

        public function awpa_permission_check($request)
        {
            return current_user_can('edit_posts');
        }

        public function awpa_checkuser_permession($request)
        {
            return current_user_can('read');
        }

        public function awpa_pro_insert_rating_settings()
        {
            $options = get_option('awpa_pro_rating_settings');
            if (!$options) {
                //$rating_default_settings = 
                $default_options = $this->awpa_pro_rating_setting_default_options();
                update_option('awpa_pro_rating_settings', $default_options);
            }
        }

        public function awpa_pro_rating_setting_default_options()
        {
            $default_options = array(
                'enable_pro_rating' => false,
                'show_star_rating' => false,
                'top_post_content' => false,
                'bottom_post_content' => true,
                'post_types' => array(
                    array('name' => 'post', 'label' => 'Posts', 'value' => true)
                    // 'page' => false
                ),
                'rating_color_front' => '#ffb900',
                'rating_color_back' => '#EEEEEE',
                'exclude_post' => array(),
                'rating_review' => '5_star',
                'send_review_email' => true,
                'rating_heading' => __('Love it or Not? Let us know!', 'wp-post-author'),
                'rating_text' => __('Rate this', 'wp-post-author'),
                'button_text' => __('Submit', 'wp-post-author'),
                'rating_display_on' => 'bottom'
            );
            return apply_filters('awpa_pro_rating_setting_default_options', $default_options);
        }



        public function awpa_pro_get_rating_settings($key = '')
        {
            $options = get_option('awpa_pro_rating_settings');
            $default_options = $this->awpa_pro_rating_setting_default_options();

            if (!empty($key)) {
                if (isset($options[$key])) {
                    return $options[$key];
                }
                return isset($default_options[$key]) ? $default_options[$key] : false;
            } else {
                if (!is_array($options)) {
                    $options = array();
                }
                return array_merge($default_options, $options);
            }
        }

        public function awpa_pro_api_get_rating_settings()
        {
            $args_posts = array(
                'public' => true,
            );
            return new WP_REST_Response([
                'settings' => $this->awpa_pro_get_rating_settings(),
                'new_post_types' => get_post_types($args_posts, 'objects', 'and'),
            ]);
        }
        public function awpa_pro_api_set_rating_settings(\WP_REST_Request $request)
        {
            $params = $request->get_params();
            $this->awpa_pro_set_rating_settings($params['awpa_pro_rating']);

            return new WP_REST_Response([
                'status' => 200,
            ]);
        }

        public function awpa_pro_set_rating_settings($settings)
        {
            $setting_keys = array_keys($this->awpa_pro_rating_setting_default_options());

            $options = array();
            foreach ($settings as $key => $value) {

                if (in_array($key, $setting_keys)) {

                    switch ($key) {
                        case in_array($key, array('post_types', 'rating_color_front', 'rating_color_back', 'rating_review', 'rating_display_on')):
                            $fvalue = $value;
                            break;
                        case in_array($key, array('rating_text', 'button_text', 'rating_heading')):

                            $fvalue = sanitize_text_field($value);
                            break;
                        case in_array($key, array('enable_rating', 'show_star_rating', 'top_post_content', 'bottom_post_content', 'send_review_email')):
                            $fvalue = (bool) $value;
                            break;
                        default:
                            $fvalue = sanitize_key($value);
                            break;
                    }
                    $options[$key] = $fvalue;
                }
            }
            update_option('awpa_pro_rating_settings', $options);
        }

        public function awpa_pro_api_post_rating_review(\WP_REST_Request $request)
        {
            // Verify nonce
            $nonce = $request->get_header('X-WP-Nonce');
            if (!wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error('rest_forbidden', 'Validation Failed', array('status' => 403));
            }

            // Sanitize and validate input parameters
            $params = $request->get_params();
            $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
            $value = isset($params['value']) ? intval($params['value']) : 0;
            $review_type = isset($params['review_type']) ? sanitize_text_field($params['review_type']) : '';

            if (empty($post_id) || empty($review_type) || $value < 1 || $value > 5) {
                return new WP_Error('rest_invalid_param', 'Invalid Parameters', array('status' => 400));
            }

            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('rest_post_invalid', 'Invalid Post', array('status' => 404));
            }

            $user_id = get_current_user_id();
            $search_meta_key = 'awpa_pro_post_' . $review_type . '_rating_review';
            $post_meta = get_post_meta($post_id, $search_meta_key, true);
            $rating_settings = get_option('awpa_pro_rating_settings', []);
            $send_mail = isset($rating_settings['send_review_email']) ? (bool)$rating_settings['send_review_email'] : false;



            $user_has_reviewed = get_post_meta($post_id, "awpa_pro_post_" . $review_type . "_rating_reviewed_user", true);
            if (in_array($review_type, ['5_star'])) {
                $data = $post_meta ? $post_meta : [];
                $current_user_id = get_current_user_id();


                $target_key = 'id_' . $current_user_id;
                if (!empty($data)) {
                    if (isset($data['ratings'][$target_key])) {
                        // Step 1: Replace the star by existing user
                        $data['ratings'][$target_key] = (int)$value; // Replace with the new value you want to set

                        $people_count = [];
                        $countstarsValues = array_count_values($data['ratings']);
                        foreach ($countstarsValues as $key => $val) {
                            if (!empty($key) && !empty($val)) {
                                $people_count['count_' . $key] = $val;
                            }
                            $data['people_count'] = $people_count;
                        }


                        $sum = 0;

                        //Iterate over the array and add up the values
                        foreach ($data['ratings'] as $key => $value) {
                            $sum += $value;
                        }
                        $data['sum'] = (int)$sum;
                        $data['count'] = count($data['ratings']);
                        $data['avg'] = (int)$sum / count($data['ratings']);
                        $people_count = [];
                    } else {
                        $sum = 0;

                        // Iterate over the array and add up the values
                        foreach ($data['ratings'] as $k => $v) {
                            $sum += $v;
                        }
                        $data['count'] += 1;
                        $data['sum'] = (int)$sum + (int)$value;
                        $data['avg'] = $data['sum'] / $data['count'];
                        $data['ratings']['id_' . $current_user_id] = (int)$value;
                        $people_count = [];
                        if (array_key_exists('people_count', $data)) {
                            $people_count = $data['people_count'];
                            if (array_key_exists('count_' . $value, $people_count)) {
                                $people_count['count_' . $value] += 1;
                            } else {
                                $people_count['count_' . $value] = 1;
                            }
                        }

                        $data['people_count'] = $people_count;
                    }
                    update_post_meta($post_id, "awpa_pro_post_" . $review_type . "_rating_review", $data);
                    update_post_meta($post_id, 'awpa_top_rated_posts_' . $review_type, $data['avg']);
                } else {

                    $data['ratings']['id_' . $current_user_id] = (int)$value;
                    $data['sum'] = $value;
                    $data['count'] = 1;
                    $data['avg'] = $data['sum'] / 1;
                    $people_count = ['count_' . $value => 1];
                    $data['people_count'] = $people_count;
                    update_post_meta($post_id, "awpa_pro_post_" . $review_type . "_rating_review", $data);
                    update_post_meta($post_id, 'awpa_top_rated_posts_' . $review_type, $data['avg']);
                }


                update_post_meta($post_id, "awpa_pro_post_" . $review_type . "_rating_reviewed_user", $user_id);
                if ($send_mail) {
                    $user = get_user_by('id', $user_id);
                    do_action('awpa_pro_rating_review_mail_notification', array(
                        'name' => $user->user_nicename,
                        'email' => $user->user_email,
                        'post_title' => $post->post_title
                    ));
                }

                return new WP_REST_Response([
                    'rating' => $data,
                    'message' => __('Post reviewed!', 'wp-post-author')
                ], 200);
            }
        }




        public function checkIfUserReviewedPost($post_id, $user_id, $review_type)
        {
            global $wpdb;
            $search_meta_key = 'awpa_pro_post_' . $review_type . '_rating_reviewed_user';
            $table = $wpdb->prefix . 'postmeta';
            $result = $wpdb->get_results("SELECT * FROM $table WHERE post_id = $post_id 
                AND meta_key = '$search_meta_key'  AND meta_value = $user_id");

            return $result ? true : false;
        }

        public function awpa_pro_api_get_post_rating_review(\WP_REST_Request $request)
        {
            $post_id = $request->get_param('post_id');
            $post_rating_type = $request->get_param('post_rating_type');
            $rating = get_option('awpa_pro_rating_settings');
            $awpa_post_rating_type = get_post_meta($post_id, 'awpa_post_rating_type', true);
            $awpa_post_global_type = get_post_meta($post_id, 'awpa_post_global_type', true);
            $review_type = "awpa_pro_post_" . $post_rating_type . "_rating_review";
            $enable_per_post = get_post_meta($post_id, 'awpa_rating_review_enable', true);
            $awpa_post_rating_title = get_post_meta($post_id, 'awpa_post_rating_title', true);
            $rating_review_meta = get_post_meta($post_id, $review_type, true);
            return
                array(
                    'rating' => $rating,
                    'post_id' => $post_id,
                    'has_rating_review' => $rating_review_meta ? 'true' : 'false',
                    'rating_review_meta' => json_encode($rating_review_meta),
                    'rating_review_enable' => $enable_per_post,
                    'rating_title' => $awpa_post_rating_title,
                    'logged_in' => is_user_logged_in(),
                    'is_admin' => is_admin(),
                    'enable_per_post' => $enable_per_post,
                    'awpa_post_rating_type' => $post_rating_type,
                    'post_has_global_type' => $awpa_post_global_type == 'global_type' ? 'true' : 'false'
                );
        }
    }
}
