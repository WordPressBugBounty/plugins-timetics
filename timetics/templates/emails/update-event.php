<?php
defined( 'ABSPATH' ) || exit;

    $timetics_staff_name     = $this->staff->get_display_name();
    $timetics_customer_name  = $this->customer->get_display_name();
    $timetics_customer_email = $this->customer->get_email();
    $timetics_title          = $this->get_title();
    $timetics_description    = $this->meeting->get_description();
    $timetics_location       = $this->booking->get_location();
    $timetics_location_type  = $this->booking->get_location_type();

    $timetics_event     = $this->booking->get_event();
    $timetics_join_link = 'google-meet' === $timetics_location_type && ! empty( $timetics_event['hangoutLink'] ) ? $timetics_event['hangoutLink'] : '';
    $timetics_timezone  = $timetics_event ? $timetics_event['start']['timeZone'] : '';
    $timetics_timestr   = $timetics_event ? strtotime( $timetics_event['start']['dateTime'] ) : '';

    $timetics_day  = gmdate( 'l', strtotime( $this->booking->get_start_date() ) );
    $timetics_time = gmdate( 'h:i a', strtotime( $this->booking->get_start_time() ) );
    $timetics_date = gmdate( 'd F Y', strtotime( $this->booking->get_start_date() ) );

    $timetics_email_body  = timetics_get_option( 'booking_rescheduled_host_email_body' );
    $timetics_email_title = timetics_get_option( 'booking_rescheduled_host_email_title' );
    $timetics_email_title = ! empty( $timetics_email_title ) ? $timetics_email_title : $this->get_title();

    $timetics_placeholders = [
        '{%meeting_title%}'    => $this->meeting->get_name(),
        '{%meeting_date%}'     => $timetics_date,
        '{%meeting_time%}'     => $timetics_time,
        '{%meeting_location%}' => $timetics_location,
        '{%meeting_duration%}' => $this->meeting->get_duration(),
        '{%host_name%}'        => $timetics_staff_name,
        '{%host_email%}'       => $this->staff->get_email(),
        '{%customer_name%}'    => $timetics_customer_name,
        '{%customer_email%}'   => $timetics_customer_email,
    ];
?>

<div class="email-wrapper" style="margin-bottom: 30px; background-color: #f4f5f7; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI','Noto Sans',Helvetica,Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji', sans-serif;">
    <div style="max-width: 600px; margin: 0 auto;">
        <div class="email-content" style="padding: 40px; background-color: #ffffff; border-radius: 0 0 12px 12px; border-top: 4px solid #3161F1; margin-bottom: 40px;">
            <?php do_action('timetics_email_header') ;?>
            <div class="email-title">
                <h3 style="color: #3161F1; font-size: 24px; font-weight: 600; margin: 30px 0;">
                    <?php echo esc_html( timetics_replace_placeholder( $timetics_email_title, $timetics_placeholders ) ); ?>
                </h3>
            </div>
            <?php if ( $timetics_email_body ): ?>

                <?php echo wp_kses( timetics_replace_placeholder( $timetics_email_body, $timetics_placeholders ), 'post' ); ?>
                <?php if ( 'virtual' === $timetics_location_type && $timetics_join_link ): ?>
                    <div class="single-data-entry" style="margin: 10px 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;">
                            <?php esc_html_e( 'Location:', 'timetics' );?>
                        </p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                            <?php echo esc_html__('Joining link:', 'timetics') ;?>
                            <a style="color: #3161F1" href="<?php echo esc_url( $timetics_join_link ); ?>"><?php echo esc_url( $timetics_join_link ); ?></a>
                        </p>
                    </div>
                <?php endif;?>
                <?php do_action( 'timetics_new_event_email', $this->booking, 'staff' ); ?>
            <?php else: ?>

                <p class="greeting" style="color: #556880; margin-bottom: 5px">
                    <?php
                    /* translators: %s: Staff name */
                    printf( esc_html__( 'Hi %s,', 'timetics' ), esc_html( $timetics_staff_name ) );?>
                </p>

                <p style="color: #556880; margin-bottom: 5px"><?php esc_attr_e( 'Meeting has been rescheduled', 'timetics' );?></p>

                <div class="data-wrapper" style="border: 1px solid #E2ECF4; border-radius: 12px; padding: 30px; margin: 24px 0;">
                    <div class="single-data-entry" style="margin: 0 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Meeting Name:', 'timetics' ); ?></p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                            <?php echo esc_html( timetics_replace_placeholder( $timetics_email_title, $timetics_placeholders ) ); ?>
                        </p>

                    </div>
                    <div class="single-data-entry" style="margin: 0 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Invitee:', 'timetics' ); ?></p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                            <?php echo esc_html( $timetics_customer_name );?> -
                            <a style="color: #3161F1" href="mailto:<?php echo esc_attr( $timetics_customer_email )?>"><?php echo esc_html( $timetics_customer_email ); ?></a>
                        </p>
                    </div>

                    <div class="single-data-entry" style="margin: 10px 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Date and time:', 'timetics' ); ?></p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php
                        /* translators: 1: Time, 2: Day, 3: Date */
                        printf( esc_html__( '%1$s, %2$s %3$s', 'timetics' ), esc_html( $timetics_time ), esc_html( $timetics_day ), esc_html( $timetics_date ), esc_html( $timetics_timezone ) );?></p>
                    </div>

                    <?php if ( 'virtual' === $timetics_location_type && $timetics_join_link ): ?>
                        <div class="single-data-entry" style="margin: 10px 0 20px;">
                            <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;">
                                <?php esc_html_e( 'Location:', 'timetics' );?>
                            </p>
                            <img width="110" style="margin: 5px 0;" src="<?php echo esc_url( TIMETICS_PLUGIN_URL . 'assets/images/social-icons/google-meet.svg' ); ?>" alt="Google Meet">
                            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                                <?php echo esc_html__('Joining link:', 'timetics') ;?>
                                <a style="color: #3161F1" href="<?php echo esc_url( $timetics_join_link ); ?>"><?php echo esc_url( $timetics_join_link ); ?></a>
                            </p>
                        </div>
                    <?php endif;?>
                    <?php if($timetics_timezone) :?>
                        <div class="single-data-entry" style="margin: 10px 0 20px;">
                            <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Invitee Time Zone:', 'timetics' ); ?></p>
                            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php echo esc_html( $timetics_timezone ); ?></p>
                        </div>
                    <?php endif;?>
                </div>
            <?php endif;?>
            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php echo esc_html__('Thank you!', 'timetics'); ?></p>
        </div>
        <?php do_action('timetics_email_footer', $timetics_customer_email) ;?>
    </div>
</div>