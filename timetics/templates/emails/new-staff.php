<div class="email-wrapper" style="margin-bottom: 30px; background-color: #f4f5f7; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI','Noto Sans',Helvetica,Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji', sans-serif;">
    <div style="max-width: 600px; margin: 0 auto;">
        <div class="email-content" style="padding: 40px; background-color: #ffffff; border-radius: 0 0 12px 12px; border-top: 4px solid #3161F1; margin-bottom: 40px;">
            <?php do_action('timetics_email_header') ;?>
            <div class="email-title">
                <h3 style="color: #3161F1; font-size: 24px; font-weight: 600; margin: 30px 0;">
                    <?php esc_html_e( 'You\'re almost there!', 'timetics' ); ?>
                </h3>
            </div>

            <p style="color: #556880; margin-bottom: 5px">
                <?php
                esc_html_e(
                    'We\'re excited to have you on board! Your\'are just a few
                    steps away from setting up your first booking page. All
                    that\'s left to do is verify your email address and set a
                    password', 'timetics'
                );
                ?>
            </p>
            <table cellspacing="0" cellpadding="0" border="0" style="margin: 30px 0 40px;">
                <tr>
                    <td style="padding-right: 10px;">
                        <a style="padding:9px 11px 10px 11px; border-radius: 5px; font-weight: 600; font-size: 13px; border: 1px solid #D8DCE2; text-decoration: none; color: #3161F1; text-align: center;" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Verify email and get started', 'timetics' ); ?></a>
                    </td>
                </tr>
            </table>
            <p style="color: #556880; margin-bottom: 5px"><?php esc_html_e( 'This link is valid for 24 hours', 'timetics' ); ?></p>
            <p style="font-weight: 400; font-size: 14px; color: #556880; margin: 0;"><?php echo esc_html__('Thank you!', 'timetics'); ?></p>
        </div>
        <?php 
            $customer_email = !empty( $customer_email ) ? $customer_email : '' ;
            do_action( 'timetics_email_footer', $customer_email );
        ?>
    </div>
</div>
