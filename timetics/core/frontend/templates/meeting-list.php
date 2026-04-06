<?php
defined( 'ABSPATH' ) || exit;

use Timetics\Core\Appointments\Appointment as Appointment;
use Timetics\Core\Staffs\Staff;

$timetics_args = [
    'posts_per_page' => $timetics_limit
];

if ( ! current_user_can( 'manage_options' ) ) {
    $timetics_args['visibility'] = 'enabled';
}

$timetics_meetings = Appointment::all($timetics_args);

$timetics_staffs = Staff::all();
$timetics_terms = get_terms( [
    'taxonomy'   => 'timetics-meeting-category',
    'hide_empty' => false,
] );

?>
<?php if ( $timetics_show_filter ) : ?>
<div class="timetics-meeting-filter timetics-input">
    <div class="timetics-select-wrapper  ant-select">
        <!-- Category List -->
        <select type="select" id="timetics-filter-category" class="timetics-select ant-select-selector">
            <option value=""><?php esc_html_e( 'All Department', 'timetics' ); ?></option>
            <?php if ( $timetics_terms ): ?>
                <?php foreach( $timetics_terms as $timetics_term ): ?>
                    <option value="<?php echo esc_attr( $timetics_term->term_id ); ?>"><?php echo esc_html( $timetics_term->name ); ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <!-- End Category List -->

        <!-- Staff List -->
        <select type="select" id="timetics-filter-staff"class="timetics-select ant-select-selector">
            <option value=""><?php esc_html_e( 'All Organizers', 'timetics' ); ?></option>
            <?php if ( $timetics_staffs ): ?>
                <?php foreach( $timetics_staffs['items'] as $timetics_staff ): ?>
                    <?php
                        $timetics_staff = new Staff( $timetics_staff->ID );
                    ?>
                    <option value="<?php echo esc_attr( $timetics_staff->get_id() ); ?>"><?php echo esc_attr( $timetics_staff->get_full_name() ); ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>
    <!-- Staff List End -->
</div>
<?php endif; ?>

<div class="tt-meeting-list-wrapper toplevel_page_timetics">
    <?php if(!empty($timetics_meetings['items'])) : ?>
        <?php
        foreach ($timetics_meetings['items'] as  $timetics_item) {
            $timetics_meeting = new Appointment($timetics_item->ID);
            $timetics_staffs   = $timetics_meeting->get_staff();
            $timetics_locations = $timetics_meeting->get_locations();
            $timetics_id = $timetics_meeting->get_id();
            $timetics_prices = $timetics_meeting->get_price();
        ?>
            <div class="tt-meeting-list-item">
                <!-- meeting description -->
                <div class="tt-meeting-information">
                    <?php
                        if (!empty($timetics_staffs)) {

                                // Display both image and full name if there are two or fewer staff members
                                ?>
                                <ul class="tt-author">
                                    <?php
                                    foreach ($timetics_staffs as $timetics_key => $timetics_staff) {
                                        ?>
                                        <li>
                                            <?php if ($timetics_staff['image']) {
                                            ?>
                                                <img src="<?php echo esc_url($timetics_staff['image']); ?>" alt="<?php echo esc_attr($timetics_staff['full_name']); ?>" />
                                            <?php } ?>
                                            <span><?php echo esc_html($timetics_staff['full_name']); ?></span>
                                        </li>
                                        <?php
                                    }
                                    ?>
                                </ul>
                                <?php

                        }
                        ?>
                    </ul>
                    <h3 class="tt-title">
                        <?php
                        echo esc_html($timetics_meeting->get_name());
                        ?>
                    </h3>
                    <?php
                        if($timetics_meeting->get_description()) {
                            ?>
                                <p><?php echo esc_html($timetics_meeting->get_description()); ?></p>
                            <?php
                        }
                    ?>
                </div>
                <div class="tt-meeting-action">
                    <!-- meeting info -->
                    <ul class="meeting-info-list">
                        <!-- meeting duration -->
                        <?php if (!empty($timetics_meeting->get_duration())) : ?>
                            <li>
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="CurrentColor" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2C5.58172 2 2 5.58172 2 10C2 14.4183 5.58172 18 10 18C14.4183 18 18 14.4183 18 10C18 5.58172 14.4183 2 10 2ZM0 10C0 4.47715 4.47715 0 10 0C15.5228 0 20 4.47715 20 10C20 15.5228 15.5228 20 10 20C4.47715 20 0 15.5228 0 10ZM10 3.6C10.5523 3.6 11 4.04772 11 4.6V9.38197L14.0472 10.9056C14.5412 11.1526 14.7414 11.7532 14.4944 12.2472C14.2474 12.7412 13.6468 12.9414 13.1528 12.6944L9.55279 10.8944C9.214 10.725 9 10.3788 9 10V4.6C9 4.04772 9.44771 3.6 10 3.6Z"/>
                             </svg>
                                <?php echo esc_html($timetics_meeting->get_duration()); ?>
                            </li>
                        <?php endif; ?>
                        <!-- meeting price -->

                    </ul>
                    <button class="ant-btn ant-btn-primary ant-btn-block" data-id="<?php echo esc_attr($timetics_id); ?>">
                        <?php echo esc_html__("Book Now", "timetics"); ?>
                    </button>
                </div>
            </div>
        <?php
        }
        ?>
    <?php else: ?>
        <p><?php echo esc_html__("No meetings found", "timetics")?></p>
    <?php endif ;?>
    <div class="timetics-booking-modal"></div>
</div>