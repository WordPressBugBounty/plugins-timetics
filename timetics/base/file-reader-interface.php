<?php
/**
 * Reader Interface
 *
 * @package Timetics
 */
namespace Timetics\Base;

interface FileReaderInterface {
    /**
     * Get data by reading file
     *
     * @param   file  $file  The file that will be read
     *
     * @return  array
     */
    public static function get_data($file);
}
