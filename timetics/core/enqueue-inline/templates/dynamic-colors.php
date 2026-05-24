<?php
/**
 * Dynamic color CSS template.
 *
 * Rendered by Enqueue_Inline::custom_inline_css().
 *
 * @var string $primary_color   Sanitized primary color (hex, rgb, etc.).
 * @var string $secondary_color Sanitized secondary color.
 *
 * @package Timetics
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( ! empty( $primary_color ) ) : ?>
:root {
    --tt-primary-color: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-flatpickr-calendar .flatpickr-day.selected,
.tt-flatpickr-calendar .flatpickr-day.selected:hover,
.tt-flatpickr-calendar .flatpickr-day.selected:focus,
.tt-flatpickr-calendar .flatpickr-day.selected.startRange,
.tt-flatpickr-calendar .flatpickr-day.selected.endRange,
.tt-flatpickr-calendar .flatpickr-day.selected.inRange {
    background: <?php echo esc_attr( $primary_color ); ?>;
    border-color: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-flatpickr-calendar .flatpickr-day.today::before {
    background: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-slot-list .ant-list-item .ant-btn:hover,
.tt-slot-list .ant-list-item .ant-btn:focus {
    color: <?php echo esc_attr( $primary_color ); ?>;
    border-color: <?php echo esc_attr( $primary_color ); ?>;
}

.ant-btn-color-primary.ant-btn-background-ghost,
.ant-btn-color-primary.ant-btn-background-ghost:not(:disabled):not(.ant-btn-disabled),
.ant-btn-color-primary.ant-btn-background-ghost:not(:disabled):not(.ant-btn-disabled):hover,
.ant-btn-color-primary.ant-btn-background-ghost:not(:disabled):not(.ant-btn-disabled):focus,
.ant-btn-color-primary.ant-btn-background-ghost:not(:disabled):not(.ant-btn-disabled):active {
    color: <?php echo esc_attr( $primary_color ); ?>;
    border-color: <?php echo esc_attr( $primary_color ); ?>;
}

.ant-tabs-tab.ant-tabs-tab-active .ant-tabs-tab-btn,
.ant-btn-background-ghost.ant-btn-primary,
.ant-spin,
.ant-radio-button-wrapper-checked:not(.ant-radio-button-wrapper-disabled),
.ant-radio-button-wrapper:hover {
    color: <?php echo esc_attr( $primary_color ); ?>;
}

.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange,
.ant-spin .ant-spin-dot-item,
.flatpickr-day.selected.inRange,
.flatpickr-day.startRange.inRange,
.flatpickr-day.endRange.inRange,
.flatpickr-day.selected:focus,
.flatpickr-day.startRange:focus,
.flatpickr-day.endRange:focus,
.flatpickr-day.selected:hover,
.flatpickr-day.startRange:hover,
.flatpickr-day.endRange:hover,
.flatpickr-day.selected.prevMonthDay,
.flatpickr-day.startRange.prevMonthDay,
.flatpickr-day.endRange.prevMonthDay,
.flatpickr-day.selected.nextMonthDay,
.flatpickr-day.startRange.nextMonthDay,
.flatpickr-day.endRange.nextMonthDay,
.ant-btn-primary {
    background-color: <?php echo esc_attr( $primary_color ); ?>;
}

.ant-btn-background-ghost.ant-btn-primary,
.ant-btn-primary,
.ant-input-focused,
.ant-input:focus,
.ant-input:hover,
.ant-radio-button-wrapper-checked:not(.ant-radio-button-wrapper-disabled),
.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange,
.flatpickr-day.selected.inRange,
.flatpickr-day.startRange.inRange,
.flatpickr-day.endRange.inRange,
.flatpickr-day.selected:focus,
.flatpickr-day.startRange:focus,
.flatpickr-day.endRange:focus,
.flatpickr-day.selected:hover,
.flatpickr-day.startRange:hover,
.flatpickr-day.endRange:hover,
.flatpickr-day.selected.prevMonthDay,
.flatpickr-day.startRange.prevMonthDay,
.flatpickr-day.endRange.prevMonthDay,
.flatpickr-day.selected.nextMonthDay,
.flatpickr-day.startRange.nextMonthDay,
.flatpickr-day.endRange.nextMonthDay {
    border-color: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-form-left-sidebar .ant-space-item svg,
.tt-form-category-sidebar .ant-space-item svg,
.anticon svg,
.meeting-info-list li svg,
.tt-form-left-sidebar .tt-meeting-location-list .anticon svg,
.tt-form-category-sidebar .tt-meeting-location-list .anticon svg {
    fill: <?php echo esc_attr( $primary_color ); ?>;
}

.toplevel_page_timetics .ant-btn.ant-btn-primary {
    background: <?php echo esc_attr( $primary_color ); ?>;
    border-color: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-slot-list .ant-list-item .ant-btn-block:hover {
    color: <?php echo esc_attr( $primary_color ); ?>;
    border-color: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-form-left-sidebar .submit-btn,
.tt-form-category-sidebar .submit-btn,
.tt-booking-body-wrap .submit-btn,
.tt-category-booking-wrap .submit-btn,
.tt-flatpickr-calendar .flatpickr-day.selected,
.toplevel_page_timetics .tt-flatpickr-calendar .flatpickr-day.selected {
    background: <?php echo esc_attr( $primary_color ); ?>;
}

.tt-form-left-sidebar .tt-meeting-location-list .anticon.tt-money-icon svg path,
.tt-form-category-sidebar .tt-meeting-location-list .anticon.tt-money-icon svg path {
    stroke: <?php echo esc_attr( $primary_color ); ?>;
}
<?php endif; ?>

<?php if ( ! empty( $secondary_color ) ) : ?>
:root {
    --tt-secondary-color: <?php echo esc_attr( $secondary_color ); ?>;
}

.ant-btn-background-ghost.ant-btn-primary:focus,
.ant-btn-background-ghost.ant-btn-primary:hover,
button.ant-btn.ant-btn-default.ant-btn-lg.tt-mb-30:hover {
    color: <?php echo esc_attr( $secondary_color ); ?>;
}

.ant-btn-background-ghost.ant-btn-primary:focus,
.ant-btn-background-ghost.ant-btn-primary:hover,
.ant-radio-button-wrapper-checked:not(.ant-radio-button-wrapper-disabled),
button.ant-btn.ant-btn-default.ant-btn-lg.tt-mb-30:hover,
.ant-btn-primary:focus,
.ant-btn-primary:hover {
    border-color: <?php echo esc_attr( $secondary_color ); ?>;
}

.ant-btn-primary:focus,
.ant-btn-primary:hover {
    background-color: <?php echo esc_attr( $secondary_color ); ?>;
}

.toplevel_page_timetics .ant-btn.ant-btn-primary:hover,
.toplevel_page_timetics .ant-btn.ant-btn-primary:focus,
.tt-form-left-sidebar .submit-btn:hover,
.tt-form-left-sidebar .submit-btn:focus,
.tt-form-category-sidebar .submit-btn:hover,
.tt-form-category-sidebar .submit-btn:focus,
.tt-booking-body-wrap .submit-btn:hover,
.tt-booking-body-wrap .submit-btn:focus,
.tt-category-booking-wrap .submit-btn:hover,
.tt-category-booking-wrap .submit-btn:focus {
    background: <?php echo esc_attr( $secondary_color ); ?>;
}
<?php endif; ?>
