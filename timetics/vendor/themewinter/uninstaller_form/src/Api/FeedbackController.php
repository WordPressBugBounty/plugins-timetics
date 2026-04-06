<?php
namespace UninstallerForm\Api;

use WP_REST_Request;

/**
 * Feedback controller for the uninstaller form.
 *
 * @since 1.0.0
 *
 * @package UNINSTALLER_FORM
 */
class FeedbackController {
    protected $plugin_file;
    protected $plugin_text_domain;
    protected $plugin_name;
    protected $plugin_slug;

    /**
     * Store namespace
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $namespace;

    /**
     * Store rest base
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $rest_base = 'feedback';

    /**
     * Store webhook
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $webhook;

    /**
     * FeedbackController Constructor.
     *
     * @param string $plugin_file The path to the plugin file.
     * @param string $plugin_text_domain The text domain of the plugin.
     * @param string $plugin_name The name of the plugin.
     * @param string $plugin_slug The slug of the plugin.
     *
     * @since 1.0.0
     */
    public function __construct($plugin_file, $plugin_text_domain, $plugin_name, $plugin_slug,$webhook='') {
        $this->plugin_file        = $plugin_file;
        $this->plugin_text_domain = $plugin_text_domain;
        $this->plugin_name        = $plugin_name;
        $this->plugin_slug        = $plugin_slug;
        $this->namespace          = $plugin_slug . '/v1';
        $this->webhook            = $webhook;
        $this->register_routes();
    }

    /**
     * Register REST routes for the feedback controller.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function register_routes() {
        register_rest_route($this->namespace, $this->rest_base, [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_feedback'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Handle feedback submission.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function handle_feedback(WP_REST_Request $request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (! wp_verify_nonce($nonce, 'wp_rest')) {
            return rest_ensure_response([
                'status_code' => 403,
                'success'     => 0,
                'message'     => 'Invalid nonce. Unauthorized request.',
            ]);
        }

        $data           = $request->get_json_params();
        $feedback       = ! empty($data['feedback']) ? sanitize_text_field($data['feedback']) : 'No feedback';
        $reasons        = ! empty($data['reasons']) ? sanitize_text_field($data['reasons']) : 'No reasons';
        $theme_name     = ! empty($data['theme_name']) ? sanitize_text_field($data['theme_name']) : '';
        $is_agree       = isset( $data['is_agree'] ) && filter_var( $data['is_agree'], FILTER_VALIDATE_BOOLEAN );
        
        $current_user  = wp_get_current_user();
        $user_name = $current_user->exists() ? $current_user->display_name : 'Guest';
        $user_email = $current_user->exists() ? $current_user->user_email : '';
        
        $customer_name  = ! empty($data['customer_name']) ? sanitize_text_field($data['customer_name']) : $user_name;
        $customer_email  = ! empty($data['customer_email']) ? sanitize_text_field($data['customer_email']) : 'Not Given';

        // if (! $this->verify_email_status($customer_email)) {
        //     $customer_email = '';
        // }

        // Get current user info
        

        try {
            // $config        = include plugin_dir_path($this->plugin_file) . 'vendor/themewinter/uninstaller_form/config/google-sheet.php';
            // $spreadsheetId = $config['spreadsheet_id'] ?? '';

            // $credentialsPath = plugin_dir_path($this->plugin_file) . 'vendor/themewinter/uninstaller_form/config/google-credentials.json';

            $sheetName = str_replace(' ', '_', $this->plugin_name);

            if (! empty($customer_email) && ! empty($reasons)) {

                $feedback_response = wp_remote_post('https://products.arraytics.com/feedback/wp-json/afp/v1/feedback', [
                    'method'  => 'POST',
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => json_encode([
                        'customer_name'  => $customer_name,
                        'feedback'       => $feedback,
                        'customer_email' => $customer_email,
                        'plugin_name'    => $this->plugin_name,
                        'theme'          => $theme_name,
                        'reason'         => explode(',',$reasons),
                        'is_agree'       => $is_agree
                    ]),
                ]);


                //Storing data to excell sheet
                // $sheetClient = new \UninstallerForm\Support\GoogleSheetClient($credentialsPath, $spreadsheetId, $sheetName);
                // $sheetClient->appendRow([
                //     $customer_name,        // Customer name
                //     $customer_email,       // Customer email
                //     $this->plugin_name,    // Plugin Slug
                //     $reasons,              // Reason
                //     $feedback,             // Feedback message,
                //     $theme_name,           // Theme name
                //     current_time('mysql'), // Timestamp
                // ]);

                //Send data through webhook
                
                if(empty($this->webhook)){
                    $this->webhook = "https://themewinter.com/?fluentcrm=1&route=contact&hash=50d358fa-e039-4459-a3d0-ef73b3c7d451";
                }
                $body    = [
                    'customer_name' => $customer_name,
                    'email'         => $customer_email,
                    'plugin_name'   => $this->plugin_name,
                    'reason'        => $reasons,
                    'feedback'      => $feedback,
                    'theme_name'    => $theme_name,
                ];
                $webhook_response = wp_remote_post($this->webhook, ['body' => $body]);
            }
        } catch (\Exception $e) {
            return rest_ensure_response([
                'status_code' => 500,
                'success'     => 0,
                'message'     => 'Unable to store feedback.',
            ]);
        }

        return rest_ensure_response([
            'status_code' => 200,
            'success'     => 1,
            'message'     => 'Feedback saved successfully.',
        ]);
    }

    public function verify_email_status(string $email = "") {
        $api_key = '700tpaQtc06FcqN93Ljkoibz6oo76KWk'; // Replace with your actual API key
        $url     = 'https://emailverifier.reoon.com/api/v1/verify';

        $response = wp_remote_get(add_query_arg([
            'email' => $email,
            'key'   => $api_key,
            'mode'  => 'quick',
        ], $url));

        if (is_wp_error($response)) {
            return 'error';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['status']) && $data['status'] === "valid";
    }
}