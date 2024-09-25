<?php
/**
 * Staff Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Staffs;

use WP_User_Query;

/**
 * Class Staff
 */
class Staff {
    /**
     * Store staff role
     *
     * @var string
     */
    protected $role = 'timetics-staff';

    /**
     * Store meta prefix
     *
     * @var string
     */
    protected $meta_prefix = '_staff_';

    /**
     * Store staff id
     *
     * @var integer
     */
    protected $id;

    /**
     * Store staff data
     *
     * @var array
     */
    protected $data = [
        'first_name' => '',
        'last_name'  => '',
        'user_email' => '',
        'image'      => '',
        'user_login' => '',
        'phone'      => '',
        'schedule'   => [],
        'status'     => 0,
    ];

    /**
     * Store WP_Error
     *
     * @var Object
     */
    public $error;

    /**
     * Staff Constructor
     *
     * @return void
     */
    public function __construct( $staff = 0 ) {
        if ( $staff instanceof self ) {
            $this->set_id( $staff->get_id() );
        } elseif ( ! empty( $staff->ID ) ) {
            $this->set_id( $staff->ID );
        } elseif ( is_numeric( $staff ) && $staff > 0 ) {
            $this->set_id( $staff );
        }
    }

    /**
     * Get user id
     *
     * @return  integer
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get first name
     *
     * @return string
     */
    public function get_first_name() {
        return get_user_meta( $this->id, 'first_name', true );
    }

    /**
     * Get last name
     *
     * @return  string
     */
    public function get_last_name() {
        return get_user_meta( $this->id, 'last_name', true );
    }

	/**
     * Get password
     *
     * @return  string
     */
    public function get_password() {
        $user = get_user_by( 'id', $this->id );
        return $user->user_pass;
    }

    /**
     * Get staff display name
     *
     * @return  string
     */
    public function get_display_name() {
        $user         = get_userdata( $this->id );
        $display_name = '';

        if ( $user ) {
            $display_name = $user->display_name;
        }

        return $display_name;
    }

    /**
     * Get user email
     *
     * @return  string
     */
    public function get_email() {
        $staff = get_userdata( $this->id );

        if ( $staff ) {
            return $staff->user_email;
        }

        return '';
    }

    /**
     * Get phone
     *
     * @return string
     */
    public function get_phone() {
        return $this->get_prop( 'phone', '' );
    }

    /**
     * Get image
     *
     * @return  string
     */
    public function get_image() {
        $image = $this->get_prop( 'image' );

        if ( filter_var( $image, FILTER_VALIDATE_URL ) ) {
            return $image;
        }


        if ( ! $image ) {
            return false;
        }

        return wp_get_attachment_image_url( $image );
    }

    /**
     * Get user name
     *
     * @return  string
     */
    public function get_user_name() {
        return get_userdata($this->id)->user_login;
    }

    /**
     * Get services
     *
     * @return  array
     */
    public function get_service() {
        return $this->get_prop( 'service' );
    }

    /**
     * Get schedule
     *
     * @return  array
     */
    public function get_schedule() {
        return $this->get_prop( 'schedule', []);
    }

    /**
     * Get staff status
     *
     * @return  mixed
     */
    public function get_status() {
        $user = get_userdata( $this->id );

        if ( user_can( $user, 'manage_options' ) ) {
            return 1;
        }

        return $this->get_prop( 'status' );
    }

    /**
     * Get full name;
     *
     * @return string
     */
    public function get_full_name() {
        $first_name = $this->get_first_name();
        $last_name = $this->get_last_name();

        if ( ! $first_name ) {
            return $this->get_display_name();
        }

        if ( ! $last_name ) {
            return $first_name;
        }



        return $first_name . ' ' . $last_name;
    }

    /**
     * Get all data for a staff
     *
     * @return array
     */
    public function get_data() {
        return [
            'id'         => $this->get_id(),
            'full_name'  => $this->get_full_name(),
            'first_name' => $this->get_first_name(),
            'last_name'  => $this->get_last_name(),
            'user_name'  => $this->get_user_name(),
            'email'      => $this->get_email(),
            'phone'      => $this->get_phone(),
            'image'      => $this->get_image(),
            'status'     => $this->get_status(),
            'service'    => $this->get_service(),
            'schedule'   => $this->get_schedule(),
        ];
    }

    /**
     * Get appointment data
     *
     * @param   string  $prop
     *
     * @return  mixed
     */
    private function get_prop( $prop = '', $default = false ) {
        $data = $this->get_metadata( $prop );

        if ( ! $data ) {
            return $default;
        }

        return $data;
    }

    /**
     * Get metadata
     *
     * @param   string  $prop
     *
     * @return  mixed
     */
    private function get_metadata( $prop = '' ) {
        $meta_key = $this->meta_prefix . $prop;

        return get_user_meta( $this->id, $meta_key, true );
    }

    /**
     * Set id
     *
     * @param   integer  $id  User ID
     *
     * @return void
     */
    public function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Set props
     *
     * @param   array  $args  User Data
     *
     * @return  void
     */
    public function set_props( $args = [] ) {
        $this->data = wp_parse_args( $args, $this->data );
    }

    /**
     * Create staff
     *
     * @param   array $args Staff data
     *
     * @return bool
     */
    public function create( $args = [] ) {
        $defaults = [
            'first_name' => '',
            'last_name'  => '',
            'user_login' => '',
            'user_email' => '',
            'user_pass'  => wp_generate_password(),
            'role'       => 'timetics-staff',
        ];

        $args    = wp_parse_args( $args, $defaults );

        $email = ! empty( $args['user_email'] ) ? $args['user_email'] : '';

        if ( $email ) {
            $user['user_email'] = $email;
        }

        $user = get_user_by( 'email', $email );

        if ( $user && ! in_array( 'timetics-staff', $user->roles, true ) ) {
            $user->add_role( 'timetics-staff' );

            return $user->ID;
        }

        $user_id = wp_insert_user( $args );

        if ( ! is_wp_error( $user_id ) ) {
            $this->set_id( $user_id );
            $this->save_metadata( $args );
            $this->retrieve_password();
        }

        return $user_id;
    }

    /**
     * Update staff data
     *
     * @return  void
     */
    public function update( $args = [] ) {
        $user  = get_userdata( $this->id )->to_array();
        $email = ! empty( $args['user_email'] ) ? $args['user_email'] : '';

        if ( ! empty( $args['user_pass'] ) ) {
            $user['user_pass'] = $args['user_pass'];
        }

        if ( $email ) {
            $user['user_email'] = $email;
        }

        $user_data = get_user_by( 'email', $email );

        if ( $user_data && ! in_array( 'timetics-staff', $user_data->roles, true ) ) {
            $user_data->add_role( 'timetics-staff' );

            return $user_data->ID;
        }

        $updated = wp_update_user( $user );

        if ( ! is_wp_error( $updated ) ) {

            $this->save_metadata( $args );
        }

        return $updated;
    }

    /**
     * Update meta data
     *
     * @return  void
     */
    public function save_metadata( $data = []) {
        foreach ( $data as $key => $value ) {
            if ( ! isset( $this->data[ $key ] )) {
                continue;
            }

            // Prepare meta key.
			if( $key == 'first_name' || $key == 'last_name' ) {
				$meta_key = $key;
			} else {
				$meta_key = $this->meta_prefix . $key;
			}

            // Update appointment meta data.
            update_user_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Send password reset email
     *
     * @return void
     */
    public function retrieve_password() {
        if ( 0 === intval( $this->get_status() ) ) {
            retrieve_password( $this->get_email() );
        }
    }

    /**
     * Delete staff
     *
     * @return  bool
     */
    public function delete() {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $user = get_userdata( $this->id );

        if ( count( $user->roles ) > 1 ) {
            $user->remove_role('timetics-staff');

            return true;
        }

        if ( is_multisite() ) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';

            return wpmu_delete_user( $this->id );
        }

        return wp_delete_user( $this->id );
    }

    /**
     * Check user is valid staff
     *
     * @return  bool
     */
    public function is_staff() {
        $user = get_userdata( $this->id );

        if ( $user ) {
            return in_array( 'timetics-staff', $user->roles, true );
        }

        return false;
    }

    /**
     * Get all appointments
     *
     * @param   array  $args  Appointments args
     *
     * @return  array
     */
    public static function all( $args = [] ) {
        $defaults = [
            'role'   => 'timetics-staff',
            'number' => 20,
            'paged'  => 1,
        ];

        $args = wp_parse_args( $args, $defaults );

        $users = new WP_User_Query( $args );

        return [
            'total' => $users->get_total(),
            'items' => $users->get_results(),
        ];
    }
}
