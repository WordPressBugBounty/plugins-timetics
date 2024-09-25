<?php
/**
 * Read CSV file
 *
 * @package Timetics
 */
namespace Timetics\Base;

/**
 * JSON Reader Class
 */
class JsonReader implements FileReaderInterface {
    /**
     * Store file
     *
     * @var string
     */
    private static $file;

    /**
     * Get data that will be read from json file
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
        $file    = self::$file;
        $data    = [];
        $content = file_get_contents( $file );

        if ( $content ) {
            $data = json_decode( $content, true );
        }

        return $data;
    }
}
