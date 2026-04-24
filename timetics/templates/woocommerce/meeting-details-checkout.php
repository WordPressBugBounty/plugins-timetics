<?php
/**
 * Meeting Details - Checkout Page Template
 *
 * @package Timetics
 * @version 1.0.0
 *
 * Variables passed:
 * @var string $timetics_formatted_date - Formatted meeting date
 * @var string $timetics_formatted_time - Formatted meeting time (converted to meeting timezone)
 * @var string $timetics_duration - Meeting duration
 * @var string $timetics_location_label - Location type label
 * @var string $timetics_timezone - Customer's selected timezone
 * @var string $timetics_display_timezone - Timezone the time is displayed in (meeting timezone)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<div class="timetics-meeting-details" style="margin: 20px 0; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #0073aa; border-radius: 4px;">
	<h3 style="margin-top: 0; color: #0073aa; font-size: 16px;"><?php echo esc_html__( 'Meeting Details', 'timetics' ); ?></h3>
	<dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 10px;">
		<?php if ( $timetics_formatted_date ) : ?>
			<dt style="font-weight: bold; color: #333;"><?php echo esc_html__( 'Date:', 'timetics' ); ?></dt>
			<dd style="margin: 0; color: #666;"><?php echo esc_html( $timetics_formatted_date ); ?></dd>
		<?php endif; ?>

		<?php if ( $timetics_formatted_time ) : ?>
			<dt style="font-weight: bold; color: #333;"><?php echo esc_html__( 'Time:', 'timetics' ); ?></dt>
			<dd style="margin: 0; color: #666;">
				<?php echo esc_html( $timetics_formatted_time ); ?>
				<?php if ( isset( $timetics_display_timezone ) && $timetics_display_timezone ) : ?>
					<span style="color: #999; font-size: 0.9em;">(<?php echo esc_html( $timetics_display_timezone ); ?>)</span>
				<?php endif; ?>
			</dd>
		<?php endif; ?>

		<?php if ( $timetics_duration ) : ?>
			<dt style="font-weight: bold; color: #333;"><?php echo esc_html__( 'Duration:', 'timetics' ); ?></dt>
			<dd style="margin: 0; color: #666;"><?php echo esc_html( $timetics_duration ); ?></dd>
		<?php endif; ?>

		<?php if ( $timetics_location_label ) : ?>
			<dt style="font-weight: bold; color: #333;"><?php echo esc_html__( 'Location:', 'timetics' ); ?></dt>
			<dd style="margin: 0; color: #666;"><?php echo esc_html( $timetics_location_label ); ?></dd>
		<?php endif; ?>

		<?php if ( $timetics_timezone ) : ?>
			<dt style="font-weight: bold; color: #333;"><?php echo esc_html__( 'Timezone:', 'timetics' ); ?></dt>
			<dd style="margin: 0; color: #666;"><?php echo esc_html( $timetics_timezone ); ?></dd>
		<?php endif; ?>
	</dl>
</div>
