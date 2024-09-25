<?php
/**
 * Read CSV file
 *
 * @package Timetics
 */
namespace Timetics\Base;

/**
 * CSV Reader Class
 */
class CsvReader implements FileReaderInterface {
    /**
     * Store file
     *
     * @var string
     */
    private static $file;

    /**
     * Get data that will be read from csv file
     *
     * @param   file  $file
     *
     * @return  array
     */
    public static function get_data( $file ) {
        self::$file = $file;

        return self::read_file();
    }

    /**
     * Get from file
     *
     * @return  array
     */
    private static function read_file() {
        $file     = self::$file;
        $csv_data = [];

        $handle  = fopen( $file, 'r' );
        $headers = fgetcsv( $handle );

        if ( ! $headers ) {
            return $csv_data;
        }

        if ( $handle !== false ) {
            $header_count = count( $headers );

            while (  ( $data = fgetcsv( $handle ) ) !== false ) {
                $row = [];

                for ( $i = 0; $i < $header_count; $i++ ) {
                    $row[$headers[$i]] = $data[$i];
                }

                $csv_data[] = $row;
            }
        }

        fclose( $handle );

        return $csv_data;
    }
}
