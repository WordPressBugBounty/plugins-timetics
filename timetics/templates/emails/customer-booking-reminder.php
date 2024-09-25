<?php
    $staff_name     = $this->staff->get_display_name();
    $customer_name  = $this->customer->get_display_name();
    $customer_email = $this->customer->get_email();
    $title          = $this->get_title();
    $description    = $this->meeting->get_description();
    $location       = $this->booking->get_location();
    $location_type  = $this->booking->get_location_type();

    $event     = $this->booking->get_event();
    $join_link = 'google-meet' === $location_type && ! empty( $event['hangoutLink'] ) ? $event['hangoutLink'] : '';
    $timezone  = $event ? $event['start']['timeZone'] : '';
    $timestr   = $event ? strtotime( $event['start']['dateTime'] ) : '';

    $day  = gmdate( 'l', strtotime( $this->booking->get_start_date() ) );
    $time = gmdate( 'h:i a', strtotime( $this->booking->get_start_time() ) );
    $date = gmdate( 'd F Y', strtotime( $this->booking->get_start_date() ) );

    $email_body  = timetics_get_option( 'booking_created_customer_email_body' );
    $email_title = timetics_get_option( 'booking_created_customer_email_title' );
    $email_title = ! empty( $email_title ) ? $email_title : $this->get_title();

    $placeholders = [
        '{%meeting_title%}'    => $this->meeting->get_name(),
        '{%meeting_date%}'     => $date,
        '{%meeting_time%}'     => $time,
        '{%meeting_location%}' => $location,
        '{%meeting_duration%}' => $this->meeting->get_duration(),
        '{%host_name%}'        => $staff_name,
        '{%host_email%}'       => $this->staff->get_email(),
        '{%customer_name%}'    => $customer_name,
        '{%customer_email%}'   => $customer_email,
    ];
?>

<div class="email-wrapper" style="margin-bottom: 30px; background-color: #f4f5f7; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI','Noto Sans',Helvetica,Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji', sans-serif;">
    <div style="max-width: 600px; margin: 0 auto;">
        <div class="email-content" style="padding: 40px; background-color: #ffffff; border-radius: 0 0 12px 12px; border-top: 4px solid #3161F1; margin-bottom: 40px;">
            <?php do_action('timetics_email_header') ;?>
            <div class="email-title">
                <h3 style="color: #3161F1; font-size: 24px; font-weight: 600; margin: 30px 0;">
                    <?php echo esc_html( timetics_replace_placeholder( $email_title, $placeholders ) ); ?>
                </h3>
            </div>
            <?php if ( $email_body ): ?>
                <?php echo wp_kses( timetics_replace_placeholder( $email_body, $placeholders ), 'post' ); ?>
                <?php if ( 'virtual' === $location_type && $join_link ): ?>
                    <div class="single-data-entry" style="margin: 10px 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;">
                            <?php esc_html_e( 'Location:', 'timetics' );?>
                        </p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                            <?php echo esc_html__('Joining link:', 'timetics') ;?>
                            <a style="color: #3161F1" href="<?php echo esc_url( $join_link ); ?>"><?php echo esc_url( $join_link ); ?></a>
                        </p>
                    </div>
                <?php endif;?>
                <?php do_action( 'timetics_new_event_email', $this->booking, 'customer' ); ?>
            <?php else: ?>

                <p class="greeting" style="color: #556880; margin-bottom: 5px">
                    <?php printf( esc_html__( 'Hi %s,', 'timetics' ), esc_html( $customer_name ) );?>
                </p>

                <p style="color: #556880; margin-bottom: 5px"><?php esc_html_e( "I hope this email finds you well. This is just a friendly reminder that you have a reservation with us on {$date} at {$time}. We are looking forward to welcoming you to {$this->meeting->name}.", 'timetics' );?></p>

                <div class="data-wrapper" style="border: 1px solid #E2ECF4; border-radius: 12px; padding: 30px; margin: 24px 0;">
                    <div class="single-data-entry" style="margin: 0 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Invitee:', 'timetics' ); ?></p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                            <?php echo esc_html( $customer_name );?> -
                            <a style="color: #3161F1" href="mailto:<?php echo esc_attr( $customer_email )?>"><?php echo esc_html( $customer_email ); ?></a>
                        </p>
                    </div>

                    <div class="single-data-entry" style="margin: 10px 0 20px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Date and time:', 'timetics' ); ?></p>
                        <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php printf( esc_html__( '%s, %s %s', 'timetics' ), esc_html( $time ), esc_html( $day ), esc_html( $date ), esc_html( $timezone ) );?></p>
                    </div>

                    <?php if ( 'google-meet' === $location_type && $join_link ): ?>
                        <div class="single-data-entry" style="margin: 10px 0 20px;">
                            <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;">
                                <?php esc_html_e( 'Location:', 'timetics' );?>
                            </p>
                            <img width="110" style="margin: 5px 0;" src="https://arraytics.dev/social-icons/google-meet.png" alt="Google Meet">
                            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;">
                                <?php echo esc_html__('Joining link:', 'timetics') ;?>
                                <a style="color: #3161F1" href="<?php echo esc_url( $join_link ); ?>"><?php echo esc_url( $join_link ); ?></a>
                            </p>
                        </div>
                    <?php endif;?>
                    <?php do_action( 'timetics_new_event_email', $this->booking, 'customer' ); ?>
                    <?php if($timezone) :?>
                        <div class="single-data-entry" style="margin: 10px 0 20px;">
                            <p style="font-weight: 600; font-size: 14px; line-height: 1; color: #0C274A; margin: 0 0 5px;"><?php echo esc_html__( 'Invitee Time Zone:', 'timetics' ); ?></p>
                            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php echo esc_html( $timezone ); ?></p>
                        </div>
                    <?php endif;?>
                </div>
            <?php endif;?>
            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php echo esc_html__('Thank you!', 'timetics'); ?></p>
        </div>
        <?php do_action('timetics_email_footer', $customer_email) ;?>
    </div>
</div>
