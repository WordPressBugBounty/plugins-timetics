<?php

/**
 * Inline Enqueue class
 *
 * @package Timetics
 */

namespace Timetics\Core\EnqueueInline;

use Timetics\Utils\Singleton;
use Timetics;

/**
 * Class Enqueue_Inline
 */
class Enqueue_Inline
{
    use Singleton;

    /**
     * Initialize the shortcode class
     *
     * @return  void
     */
    public function init()
    {
        add_action('wp_head', array($this, 'custom_inline_css'));
    }

    /**
     * Custom inline css
     */
    public function custom_inline_css()
    {
        $custom_css  = '';
        $primary_color   = timetics_get_option('primary_color');
        $secondary_color = timetics_get_option('secondary_color');

        $custom_css .= "
		.ant-tabs-tab.ant-tabs-tab-active .ant-tabs-tab-btn,
        .ant-btn-background-ghost.ant-btn-primary,
         .ant-spin,
        .ant-radio-button-wrapper-checked:not(.ant-radio-button-wrapper-disabled),
        .ant-radio-button-wrapper:hover {
            color: {$primary_color};
        }

        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange,
         .ant-spin .ant-spin-dot-item,
        .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus, .flatpickr-day.selected:hover, .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover, .flatpickr-day.selected.prevMonthDay, .flatpickr-day.startRange.prevMonthDay, .flatpickr-day.endRange.prevMonthDay, .flatpickr-day.selected.nextMonthDay, .flatpickr-day.startRange.nextMonthDay, .flatpickr-day.endRange.nextMonthDay,
        .ant-btn-primary{
            background-color: {$primary_color};
        }


        .ant-btn-background-ghost.ant-btn-primary,
        .ant-btn-primary,
        .ant-input-focused, .ant-input:focus,
        .ant-input:hover,
        .ant-radio-button-wrapper-checked:not(.ant-radio-button-wrapper-disabled),
        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus, .flatpickr-day.selected:hover, .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover, .flatpickr-day.selected.prevMonthDay, .flatpickr-day.startRange.prevMonthDay, .flatpickr-day.endRange.prevMonthDay, .flatpickr-day.selected.nextMonthDay, .flatpickr-day.startRange.nextMonthDay, .flatpickr-day.endRange.nextMonthDay{
            border-color: {$primary_color};
        }

        .tt-form-left-sidebar .ant-space-item svg,
        .tt-form-category-sidebar .ant-space-item svg,
        .anticon svg,
        .meeting-info-list li svg,
        .tt-form-left-sidebar .tt-meeting-location-list .anticon svg ,
        .tt-form-category-sidebar .tt-meeting-location-list .anticon svg{
            fill: {$primary_color};
        }

        .ant-btn-background-ghost.ant-btn-primary:focus, .ant-btn-background-ghost.ant-btn-primary:hover,
        button.ant-btn.ant-btn-default.ant-btn-lg.tt-mb-30:hover{
            color: {$secondary_color};
        }

        .ant-btn-background-ghost.ant-btn-primary:focus, .ant-btn-background-ghost.ant-btn-primary:hover,
        .ant-radio-button-wrapper-checked:not(.ant-radio-button-wrapper-disabled),
        button.ant-btn.ant-btn-default.ant-btn-lg.tt-mb-30:hover,
        .ant-btn-primary:focus, .ant-btn-primary:hover{
            border-color: {$secondary_color};
        }

        .ant-btn-primary:focus,
        .ant-btn-primary:hover{
            background-color: {$secondary_color};
        }

        .toplevel_page_timetics .ant-btn.ant-btn-primary {
            background: {$primary_color};
            border-color: {$primary_color};
        }

        .tt-slot-list .ant-list-item .ant-btn-block:hover {
            color: {$primary_color};
            border-color: {$primary_color};
        }

        .tt-form-left-sidebar .submit-btn,
        .tt-form-category-sidebar .submit-btn,
        .tt-booking-body-wrap .submit-btn,
        .tt-category-booking-wrap .submit-btn,
        .tt-flatpickr-calendar .flatpickr-day.selected,
        .toplevel_page_timetics .tt-flatpickr-calendar .flatpickr-day.selected {
            background: {$primary_color};
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
            background: {$secondary_color};
        }

        .tt-form-left-sidebar .tt-meeting-location-list .anticon.tt-money-icon svg path ,
        .tt-form-category-sidebar .tt-meeting-location-list .anticon.tt-money-icon svg path {
            stroke: {$primary_color};
        }

        ";

        // add inline css.
        wp_register_style('timetics-custom-css', false);
        wp_enqueue_style('timetics-custom-css');
        wp_add_inline_style('timetics-frontend', $custom_css);
    }
}
