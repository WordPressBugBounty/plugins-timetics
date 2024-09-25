<?php
/**
 * Shortcode class
 *
 * @package Timetics
 */

namespace Timetics\Core\Frontend;

use Timetics\Utils\Singleton;
use Timetics;

/**
 * Class Shortcode
 */
class Shortcode {
    use Singleton;

    /**
     * Initialize the shortcode class
     *
     * @return  void
     */
    public function init() {
        // [timetics-booking-form id='']
        add_shortcode( 'timetics-booking-form', [ $this, 'booking_form' ] );

        // [timetics-meeting-list limit='']
        add_shortcode( 'timetics-meeting-list', [ $this, 'meeting_list' ] );

      // [timetics-user-dashboard]
        add_shortcode( 'timetics-user-dashboard', [ $this, 'user_dashboard' ] );

      // [timetics-category id='']
        add_shortcode( 'timetics-category', [ $this, 'category_meetings' ] );

        if ( class_exists( 'Wpeventin' ) ) {
            add_action( 'init', [ $this, 'eventin_seat_plan' ] );
        }
    }

    /**
     * booking form for frontend
     *
     * @return void
     */
    public function booking_form( $attribute ) {
        wp_enqueue_style( 'timetics-vendor' );
        wp_enqueue_style( 'timetics-frontend' ); 
        wp_enqueue_script( 'timetics-flatpickr-scripts' );
        wp_enqueue_script( 'timetics-frontend-scripts' );
        wp_enqueue_script( 'calendar-locale' );

        $id            = isset( $attribute['id'] ) ? $attribute['id'] : '';
        $data_controls = [
            'id' => $id,
        ];
        $controls      = json_encode( $data_controls );

        ob_start();
        ?>
        <div class="timetics-shortcode-wrapper">
            <div class="timetics-single-booking-wrapper"
                 data-controls="<?php echo esc_attr( $controls ); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    /**
     * user dashboard for frontend
     *
     * @return void
     */
    public function user_dashboard( $attribute ) {
        wp_enqueue_style( 'timetics-vendor' );
        wp_enqueue_style( 'timetics-frontend' ); 
        wp_enqueue_script( 'timetics-frontend-scripts' );

        $id            =  get_current_user_id();
        $data_controls = [
            'id' => $id,
        ];
        $controls      = json_encode( $data_controls );

        ob_start();
        if (is_user_logged_in()) {

            ?>
            <div class="timetics-shortcode-wrapper">
                <div class="timetics-user-dashboard-wrapper"
                     data-controls="<?php echo esc_attr( $controls ); ?>">
                </div>
             </div>
            <?php

        }else{
            ?>
            <div class="timetics-userdashbaord-wrapper">
                <h3> <?php esc_html_e( 'You are not logged in', 'timetics' ); ?> <a href="<?php echo esc_url(wp_login_url()) ?>"><?php esc_html_e( 'Login', 'timetics' ); ?></a></h3>
            </div>
            <?php
        }
        return ob_get_clean();

    }

    public function eventin_seat_plan() {
        wp_enqueue_style( 'timetics-vendor' );
        wp_enqueue_style( 'timetics-frontend' ); 
        wp_enqueue_script( 'timetics-flatpickr-scripts' );
        wp_enqueue_script( 'timetics-frontend-scripts' );
    }

    /**
     * booking form for frontend
     *
     * @return void
     */
    public function meeting_list( $attribute ) {
        wp_enqueue_style( 'timetics-vendor' );
        wp_enqueue_style( 'timetics-frontend' ); 
        wp_enqueue_script( 'timetics-flatpickr-scripts' );
        wp_enqueue_script( 'timetics-frontend-scripts' );
        wp_enqueue_script( 'calendar-locale' );
        wp_enqueue_script( 'jquery' );

        wp_enqueue_script( 'timetics-meeting-filtering', TIMETICS_ASSETS_URL . 'js/meeting-filter.js', ['jquery'], time() );

        $limit = isset( $attribute['limit'] ) ? $attribute['limit'] : '';
        $show_filter = isset( $attribute['show_filter'] ) && 'true' == $attribute['show_filter'] ? $attribute['show_filter'] : false;

        ob_start();
        ?>
        <div class="timetics-shortcode-wrapper">
            <div class="timetics-meeting-list-wrapper">
                <?php
                if ( file_exists( TIMETICS_PLUGIN_DIR . 'core/frontend/templates/meeting-list.php' ) ) {
                    require_once TIMETICS_PLUGIN_DIR . 'core/frontend/templates/meeting-list.php';
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * category wise meeting for frontend
     *
     * @return void
     */
    public function category_meetings( $attribute ) {
        wp_enqueue_style( 'timetics-vendor' );
        wp_enqueue_style( 'timetics-frontend' ); 
        wp_enqueue_script( 'timetics-flatpickr-scripts' );
        wp_enqueue_script( 'timetics-frontend-scripts' );
        wp_enqueue_script( 'calendar-locale' );

        $id            = isset( $attribute['id'] ) ? $attribute['id'] : '';
        $data_controls = [
            'id' => $id,
        ];
        $controls      = json_encode( $data_controls );

        ob_start();
        ?>
        <div class="timetics-shortcode-wrapper">
            <div class="timetics-category-wrapper"
                 data-controls="<?php echo esc_attr( $controls ); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}
