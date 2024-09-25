<?php
/**
 * Base Exporter Class
 *
 * @package Timetics
 */
namespace Timetics\Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exporter Class
 */
abstract class Exporter {

    /**
     * Raw data to export
     *
     * @var array
     */
    protected $row_data = [];

    /**
     * Column name to export
     *
     * @var array
     */
    protected $colunms = [];

    /**
     * Store file name
     *
     * @var string
     */
    protected $file_name = '';

    /**
     * Store file format
     *
     * @var string
     */
    protected $format = '';

    /**
     * Store file type
     *
     * @var string
     */
    protected $file_type = '';

    /**
     * Prepare data that will be exported
     *
     * @return  void
     */
    abstract protected function prepare_data();

    /**
     * Set file name that will be exported
     *
     * @return  void
     */
    protected function set_file_name( $file_name ) {
        $this->file_name = $file_name;
    }

    /**
     * Set file format that will be exported
     *
     * @return  void
     */
    protected function set_format( $format ) {
        $this->format = $format;
    }

    /**
     * Get column names
     *
     * @return  array
     */
    protected function get_columns() {
        return [];
    }

    /**
     * Set content type
     *
     * @return void
     */
    protected function send_headers() {
        header( 'Content-Type: application/' . $this->format . '; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $this->file_name );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
    }
    /**
     * Get data to export
     *
     * @return  array
     */
    protected function get_data() {
        return $this->row_data;
    }

    /**
     * Get columns as csv
     *
     * @return  string
     */
    protected function export_columns() {
        $colunms = $this->get_columns();
        ob_clean();
        $output = fopen( 'php://output', 'w' );
        ob_start();

        fputcsv( $output, $colunms );

        fclose( $output );

        return ob_get_clean();
    }

    /**
     * Export rows
     *
     * @return  string
     */
    protected function export_rows() {
        $data   = $this->get_data();
        ob_clean();
        $buffer = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        ob_start();

        array_walk( $data, array( $this, 'export_row' ), $buffer );

        return ob_get_clean();
    }

    /**
     * Export row
     *
     * @param   array  $row_data  [$row_data description]
     * @param   [type]  $key       [$key description]
     * @param   file  $buffer    [$buffer description]
     *
     * @return  void
     */
    protected function export_row( $row_data, $key, $buffer ) {
        $colunms    = $this->get_columns();
        $export_row = [];

        foreach ( $colunms as $colunm ) {
            if ( ! empty( $row_data[$colunm] ) ) {
                $export_row[] = $row_data[$colunm];
            } else {
                $export_row[] = '';
            }
        }

        fputcsv( $buffer, $export_row );
    }

    /**
     * Print the content that will be exported
     *
     * @param   string  $content
     *
     * @return  void
     */
    protected function send_content( $content ) {
        echo wp_kses( $content, 'post' );
    }

    /**
     * Export data to csv format
     *
     * @return  void
     */
    public function export_csv() {
        $this->set_file_name( "export-{$this->file_type}.csv" );
        $this->set_format( 'csv' );
        $this->prepare_data();
        $this->send_headers();
        $this->send_content( $this->export_columns() . $this->export_rows() );
        die();
    }

    /**
     * Export data to JSON format
     *
     * @return  void
     */
    public function export_json() {
        $this->set_file_name( "export-{$this->file_type}.json" );
        $this->set_format( 'json' );
        $this->prepare_data();
        $this->send_headers();
        $data = $this->get_data();

        echo json_encode( $data, JSON_PRETTY_PRINT );
        die();
    }

    /**
     * Export file
     *
     * @return void
     */
    public function export() {
        $format = $this->format;

        switch ( $format ) {
        case 'csv':
            $this->export_csv();
            break;
        case 'json':
            $this->export_json();
            break;
        }
    }
}
