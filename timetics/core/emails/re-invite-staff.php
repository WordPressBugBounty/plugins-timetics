<?php
/**
 * New event email class
 *
 * @package Timetics
 */
namespace Timetics\Core\Emails;

use Timetics\Core\Staffs\Staff;

class Re_Invite_Staff extends Email {

    /**
     * Store staff object
     *
     * @var Object
     */
    public $staff;

    /**
     * Re Invite Staff Constructor
     *
     * @return void
     */
    public function __construct( $staff_id ) {
        $this->staff    = get_user_by( 'id', $staff_id );
        parent::__construct();
    }

    /**
     * Get email recipient
     *
     * @return string
     */
    public function get_recipient() {
        return $this->staff->user_email;
    }

    /**
     * Get new event email
     *
     * @return string
     */
    public function get_subject() {
        return esc_html__('Re Invitation Subject','timetics');
    }

    /**
     * Get email title
     *
     * @return string
     */
    public function get_title() {
        return esc_html__('Re Invitation Staff','timetics');
    }

    /**
     * Get template new event email
     *
     * @return  string
     */
    public function get_template() {
		$user_login = $this->staff->user_login;
		$locale     = get_user_locale($this->staff);
		$reset_key  = get_password_reset_key($this->staff);
		$reset_url = site_url( "wp-login.php?action=rp&key={$reset_key}&login={$user_login}&wp_lang={$locale}" );

        $reset_url = apply_filters( 'timetics_staff_password_reset_url', $reset_url, $reset_key, $user_login, $locale );
		include_once  TIMETICS_PLUGIN_DIR . '/templates/emails/new-staff.php';
    }
}
