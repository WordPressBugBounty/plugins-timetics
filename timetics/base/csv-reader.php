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

        // Initialize WP_Filesystem
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        // Read file contents using WP_Filesystem
        $contents = $wp_filesystem->get_contents( $file );
        if ( false === $contents ) {
            return $csv_data;
        }

        // Split content into lines
        $lines = explode( "\n", $contents );
        if ( empty( $lines ) ) {
            return $csv_data;
        }

        // Parse header row
        $headers = str_getcsv( array_shift( $lines ) );
        if ( ! $headers ) {
            return $csv_data;
        }

        $header_count = count( $headers );

        foreach ( $lines as $line ) {
            // Skip empty lines
            if ( empty( trim( $line ) ) ) {
                continue;
            }

            $data = str_getcsv( $line );
            if ( false === $data ) {
                continue;
            }

            $row = [];
            for ( $i = 0; $i < $header_count; $i++ ) {
                $row[$headers[$i]] = isset( $data[$i] ) ? $data[$i] : '';
            }

            $csv_data[] = $row;
        }

        return $csv_data;
    }
}
