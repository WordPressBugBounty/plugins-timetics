<?php
/**
 * Booking links email template: the customer's upcoming bookings.
 *
 * @package Timetics
 */

defined( 'ABSPATH' ) || exit;

    $timetics_customer_name  = $this->customer->display_name;
    $timetics_customer_email = $this->customer->user_email;
?>

<main class="email-wrapper" style="margin-bottom: 30px; background-color: #f4f5f7; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI','Noto Sans',Helvetica,Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji', sans-serif;">
    <div style="max-width: 600px; margin: 0 auto;">
        <div class="email-content" style="padding: 40px; background-color: #ffffff; border-radius: 0 0 12px 12px; border-top: 4px solid #3161F1; margin-bottom: 40px;">
            <?php do_action( 'timetics_email_header' ); ?>
            <div class="email-title">
                <h3 style="color: #3161F1; font-size: 24px; font-weight: 600; margin: 30px 0;">
                    <?php esc_html_e( 'Your upcoming bookings', 'timetics' ); ?>
                </h3>
            </div>

            <p class="greeting" style="color: #556880; margin-bottom: 5px">
                <?php
                /* translators: %s: Recipient name */
                printf( esc_html__( 'Hi %s,', 'timetics' ), esc_html( $timetics_customer_name ) );
                ?>
            </p>

            <p style="color: #556880; margin-bottom: 5px"><?php esc_html_e( 'Here are your upcoming bookings. Use the buttons to reschedule or cancel.', 'timetics' ); ?></p>

            <?php
            // Built and escaped by Manage_Links_Sender::rows_html().
            echo $this->rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>

            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php esc_html_e( 'If you did not ask for this email, you can ignore it.', 'timetics' ); ?></p>
        </div>
        <?php do_action( 'timetics_email_footer', $timetics_customer_email ); ?>
    </div>
</main>
