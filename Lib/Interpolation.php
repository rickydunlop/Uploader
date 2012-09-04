<?php
/**
 * This class is adapted from li3_attachable: the most rad li3 file uploader
 *
 * @copyright     Copyright 2012, Tobias Sandelius (http://sandelius.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */
App::uses('String', 'Utility');
class Interpolation {

    /**
     * Custom user interpolations.
     *
     * @var array
     */
    protected static $_interpolations = array();

    /**
     * Add a new interpolation to the collection.
     *
     * {{{
     * Interpolation::add('class', function($entity, $field) {
     *    return strtolower(Inflector::slug(get_class($entity)));
     * });
     * }}}
     *
     * @param string $name Name of the interpolation.
     * @param object $closure `Closure` object.
     * @return array The array of interpolations
     */
    public static function add($name, Closure $closure) {
        static::$_interpolations[$name] = $closure;
        return static::$_interpolations;
    }

    /**
     * Interpolates a string by substituting tokens in a string
     * 
     * Default interpolations:
     * - `:webroot` Path to the webroot folder
     * - `:model` The current model e.g images
     * - `:field` The database field
     * - `:filename` The filename
     * - `:extension` The extension of the file e.g png
     * - `:id` The record id
     * - `:style` The current style e.g thumb
     * - `:hash` Generates a hash based on the filename
     * 
     * @param string $string The string to be interpolated with data.
     * @param string $name The name of the model e.g. Image
     * @param int $id The id of the record e.g 1
     * @param string $field The name of the database field e.g file
     * @param string $style The style to use. Should be specified in the behavior settings.
     * @param array $options You can override the default interpolations by passing an array of key/value
     *              pairs or add extra interpolations. For example, if you wanted to change the hash method
     *              you could pass `array('hash' => sha1($id . Configure::read('Security.salt')))`
     * @return array Settings array containing interpolated strings along with the other settings for the field.
     */
    public static function run($string, $name, $id, $field, $filename, $style = 'original', $data = array()) {
        $info = new SplFileInfo($filename);
        $data += array(
            'webroot'    => preg_replace('/\/$/', '', WWW_ROOT),
            'model'      => Inflector::tableize($name),
            'field'      => strtolower($field),
            'filename'   => $info->getBasename($info->getExtension()),
            'extension'  => $info->getExtension(),
            'id'         => $id,
            'style'      => $style,
            'hash'       => md5($info->getFilename() . Configure::read('Security.salt'))
        );
        foreach (static::$_interpolations as $name => $closure) {
            $data[$name] = $closure($info);
        }
        return String::insert($string, $data);
    }

    /**
     * Clear all custom created interpolations.
     *
     * @return array The array of unterpolations
     */
    public static function clear() {
        static::$_interpolations = array();
        return static::$_interpolations;
    }
}

?>