<?php
/**
 * New staff email template
 *
 * @package Timetics
 */

defined( 'ABSPATH' ) || exit;
?>

<?php do_action( 'timetics-email-header' ); ?>

<?php do_action( 'timetics-email-body' ); ?>

<?php
do_action( 'timetics-email-footer' );
