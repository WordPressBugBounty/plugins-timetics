<?php
/**
 * Class Service
 *
 * @package Timetics
 */
namespace Timetics\Core\Services;

use WP_Term_Query;

class Service {

    /**
     * Store service taxonomy
     *
     * @var string
     */
    protected $taxonomy = 'timetics-service';

    /**
     * Store service taxonomy meta
     *
     * @var string
     */
    protected $meta_prefix = '_tt_service_';

    /**
     * Store taxonomy id
     *
     * @var integer
     */
    protected $id;

    /**
     * Store service category data
     *
     * @var array
     */
    protected $data = [
        'name'     => '',
        'parent'   => 0,
        'duration' => '',
        'location' => '',
        'staff'    => '',
        'seat'     => '',
        'price'    => 0,
        'color'    => '',
    ];

    /**
     * Store WP_Error
     *
     * @var Object
     */
    public $error;

    /**
     * Service Constructor
     *
     * @return  void
     */
    public function __construct( $service = 0 ) {
        if ( $service instanceof self ) {
            $this->set_id( $service->get_id() );
        } elseif ( ! empty( $service->ID ) ) {
            $this->set_id( $service->ID );
        } elseif ( is_numeric( $service ) && $service > 0 ) {
            $this->set_id( $service );
        }
    }

    // Getter.

    /**
     * Get service id
     *
     * @return  integer
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get service name
     *
     * @return  string
     */
    public function get_name() {
        return $this->get_prop( 'name' );
    }

    /**
     * Get service parent id
     *
     * @return  integer
     */
    public function get_parent() {
        return $this->get_prop( 'parent' );
    }

    /**
     * Get duration
     *
     * @return string
     */
    public function get_duration() {
        return $this->get_prop( 'duration' );
    }

    /**
     * Get location
     *
     * @return  string
     */
    public function get_location() {
        return $this->get_prop( 'location' );
    }

    /**
     * Get service price
     *
     * @return  float
     */
    public function get_price() {
        return $this->get_prop( 'price' );
    }

    /**
     * Get seat
     *
     * @return string
     */
    public function get_seat() {
        return $this->get_prop( 'seat' );
    }

    /**
     * Get staff id
     *
     * @return integer
     */
    public function get_staff() {
        return $this->get_prop( 'staff' );
    }

    /**
     * Get service color
     *
     * @return  string
     */
    public function get_color() {
        return $this->get_prop( 'color' );
    }

    /**
     * Get link
     *
     * @return string
     */
    public function get_link() {
        return get_term_link( $this->id );
    }

    /**
     * Get single data.
     *
     * @param   string  $prop  Service meta key
     *
     * @return  mixed
     */
    private function get_prop( $prop ) {
        return $this->get_metadata( $prop );
    }

    /**
     * Get service meta data
     *
     * @param   string  $prop  Service metadata
     *
     * @return  mixed
     */
    private function get_metadata( $prop ) {
        $key = $this->meta_prefix . $prop;

        return get_term_meta( $this->id, $key, true );
    }

    /**
     * Get all services
     *
     * @param   array
     *
     * @return  array
     */
    public static function all( $args = [] ) {
        $term = new WP_Term_Query(
            [
				'taxonomy'   => 'timetics-service',
				'number'     => $args['per_page'],
				'offset'     => $args['paged'] - 1,
				'hide_empty' => false,
				'count'      => true,
			]
        );

        return $term->terms ? $term->terms : [];
    }

    // Setter.

    /**
     * Set service id
     *
     * @param   integer  $id
     *
     * @return void
     */
    public function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Set props
     *
     * @param   array  $args  Service args
     *
     * @return  void
     */
    public function set_props( $args = [] ) {
        $this->data = wp_parse_args( $args, $this->data );
    }

    /**
     * Save service
     *
     * @return void
     */
    public function save() {
        if ( $this->id ) {
            $term = wp_update_term(
                $this->id, $this->taxonomy, [
					'name'   => $this->data['name'],
					'parent' => $this->data['parent'],
				]
            );
        } else {
            $term = wp_insert_term(
                $this->data['name'], $this->taxonomy, [
					'parent' => $this->data['parent'],
				]
            );
        }

        if ( is_wp_error( $term ) ) {
            $this->error = $term;
        } else {
            $this->set_id( $term['term_id'] );
            $this->save_metadata();
        }
    }

    /**
     * Save service meta data
     *
     * @return void
     */
    public function save_metadata() {
        foreach ( $this->data as $key => $value ) {
            $meta_key = $this->meta_prefix . $key;

            update_term_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Delete service
     *
     * @return bool
     */
    public function delete() {
        $delete = wp_delete_term( $this->id, $this->taxonomy );

        if ( is_wp_error( $delete ) ) {
            $this->error = $delete;
        }

        return $delete;
    }

    /**
     * Check a service is valid
     *
     * @return bool
     */
    public function is_service() {
        if ( term_exists( $this->id, $this->taxonomy ) ) {
            return true;
        }

        return false;
    }
}
