<?php
/**
 * Data Importer Class
 *
 * @package Timetics
 */
namespace Timetics\Base;

use Exception;
use Timetics\Utils\Validator;

/**
 * Class Importer
 */
abstract class Importer {
    use Validator;

    /**
     * Store file
     *
     * @var array
     */
    protected $file;

    /**
     * Store data that will be imported
     *
     * @var array
     */
    protected $data;

    /**
     * Store number of rows
     *
     * @var integer
     */
    protected $total_row = 0;

    /**
     * Store total imported rows
     *
     * @var integer
     */
    protected $total_imported_row;

    /**
     * Import data
     *
     * @return mixed
     */
    abstract function import();

    /**
     * Read file that will be imported
     *
     * @param   array  $file
     *
     * @return  void
     */
    public function read_file() {
        $file = $this->file;
        $file_type = ! empty( $file['type'] ) ? $file['type'] : '';
        $file_name = ! empty( $file['tmp_name'] ) ? $file['tmp_name'] : '';

        switch ( $file_type ) {
        case 'application/json':
            $this->data = JsonReader::get_data( $file_name );
            break;
        case 'text/csv':
            $this->data = CsvReader::get_data( $file_name );
            break;
        default:
            throw new Exception( esc_html__( 'You must provide a valid file type', 'timetics' ) );
        }

        $this->set_total_row();
    }

    /**
     * Get total number of rows
     *
     * @return  integer
     */
    public function get_total_rows() {
        return $this->total_row;
    }

    /**
     * Set total row
     *
     * @return void
     */
    protected function set_total_row() {
        if ( ! $this->data ) {
            return;
        }

        $this->total_row = count( $this->data );
    }

    /**
     * Get total imported rows
     *
     * @return  integer
     */
    public function get_total_imported_rows() {
        return $this->total_imported_row;
    }
}
