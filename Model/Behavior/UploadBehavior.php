<?php
App::import('Lib', 'Imagine.ImagineLoader');
App::uses('Interpolation', 'Uploader.Lib');

/**
 * UploadBehavior
 *
 * UploadBehavior does all the job of saving files to disk while saving records to database. 
 * For more info read UploadPack documentation.
 *
 * @author Michał Szajbe (michal.szajbe@gmail.com)
 * @author Joe Bartlett (contact@jdbartlett.com)
 * @author Ricky Dunlop (ricky@rehabstudio.com)
 * @link https://github.com/rickydunlop/Uploader
 */
class UploadBehavior extends ModelBehavior {

    public static $attachments = array();

    protected $toWrite = array();

    protected $toDelete = array();

    protected $maxWidthSize = false;

    /**
     * Setup callback
     * 
     * Called when the behavior is attached to a model. 
     * Settings come from the attached model’s $actsAs property.
     */
    public function setup(Model $Model,  $settings = array()) {
        $defaults = array(
            'path'             => ':webroot/uploads/:model/:id/:style-:filename:extension',
            'styles'           => array(),
            'resizeToMaxWidth' => false,
            'quality'          => 90,
            'urlField'         => null,
            'engine'           => 'Gd',
            'meta'             => array(
                'filesize'     => 'filesize',
                'contentType'  => 'content_type'
            )
        );
        foreach ($settings as $field => $array) {
            $this->settings[$Model->alias][$field] = array_merge($defaults, $array);
            static::$attachments[$Model->alias][$field] = array_merge($defaults, $array);
        }
    }

    public static function getPaths($model, $id, $field, $filename, $style, $options) {
        $paths = array();
        $settings = static::$attachments[$model][$field];
        $keys = array('path', 'url', 'default_url');
        foreach ($keys as $key) {
            if (isset($settings[$key])) {
                $paths[$key] = Interpolation::run($settings[$key], $model, $id, $field, $filename, $style, $options);
            }
        }
        return $paths;
    }

    /**
     * beforeSave callback
     * 
     * Runs some checks to ensure the file has uploaded correctly.
     * Sets up the write/delete queues
     */
    public function beforeSave(Model $Model) {
        $this->reset();

        foreach ($this->settings[$Model->name] as $field => $settings) {
            if (!empty($Model->data[$Model->name][$field]) 
                && is_array($Model->data[$Model->name][$field]) 
                && file_exists($Model->data[$Model->name][$field]['tmp_name'])
            ) {

                if (!empty($Model->id)) {
                    $this->prepareToDeleteFiles($Model, $field, true);
                }
                $this->prepareToWriteFiles($Model, $field);
            }else{
            	unset($Model->data[$Model->name][$field]);
            }
        }
        return true;
    }

    /**
     * afterSave callback
     * 
     * Writes/deletes any files in the respective queues
     */
    public function afterSave(Model $Model, $created) {
        if (!$created) {
            $this->deleteFiles($Model);
        }
        $this->writeFiles($Model);
    }

    /**
     * beforeDelete callback
     * 
     * Prepares the files to be deleted
     */
    public function beforeDelete(Model $Model,$cascade = true) {
        $this->reset();
        $this->prepareToDeleteFiles($Model);
        return true;
    }

    /**
     * Aftersave callback
     * 
     * Deletes any files
     */
    public function afterDelete(Model $Model) {
        $this->deleteFiles($Model);
    }

    /**
     * beforeValidate callback
     * 
     * If no file is uploaded it checks if the urlField has a value and uses it
     * If the data is a string the it tries to fetch the image from a url
     */
    public function beforeValidate(Model $Model) {
        foreach ($this->settings[$Model->name] as $field => $settings) {
            if (isset($Model->data[$Model->name][$field])) {
                $data = $Model->data[$Model->name][$field];
                if ((empty($data) || is_array($data) 
                    && empty($data['tmp_name'])) 
                    && !empty($settings['urlField']) 
                    && !empty($Model->data[$Model->name][$settings['urlField']])
                ) {
                    $data = $Model->data[$Model->name][$settings['urlField']];
                }

                if (is_string($data)) {
                    $Model->data[$Model->name][$field] = $this->fetchFromUrl($data);
                }
            }
        }
        return true;
    }

    /**
     * Resets the Write/Delete queues
     */
    protected function reset() {
        $this->toWrite = null;
        $this->toDelete = null;
    }

    /**
     * Fetches a file from a url
     * 
     * @param string $url The url to be fetched
     * @return array $data Array in a similar format as a HTTP POST file upload
     */
    protected function fetchFromUrl($url) {
        $data = array('remote' => true);
        $data['name'] = end(explode('/', $url));
        $data['tmp_name'] = tempnam(sys_get_temp_dir(), $data['name']) . '.' . end(explode('.', $url));

        App::uses('HttpSocket', 'Network/Http');
        $httpSocket = new HttpSocket();

        $raw = $httpSocket->get($url);
        $response = $httpSocket->response;

        $data['size'] = strlen($raw);
        $data['type'] = reset(explode(';', $response['header']['Content-Type']));

        file_put_contents($data['tmp_name'], $raw);
        return $data;
    }

    /**
     * Prepare files to be written
     * 
     * Cleans the filename making it url friendly
     * If overwrite is true, it checks to see if the file exists
     * 
     * @param array $field The file to be processed.
     */
    protected function prepareToWriteFiles(Model $Model, $field) {
        $pathinfo = pathinfo($Model->data[$Model->alias][$field]['name']);
        extract($pathinfo);

        $filename = $this->cleanFilename($filename);
        $Model->data[$Model->alias][$field]['name'] = $filename . '.' . $extension;

        $this->toWrite[$field] = $Model->data[$Model->alias][$field];

        unset($Model->data[$Model->name][$field]);

        $settings = $this->settings[$Model->alias][$field];
        $Model->data[$Model->alias][$field] = $this->toWrite[$field]['name'];
        $Model->data[$Model->alias][$settings['meta']['filesize']] = $this->toWrite[$field]['size'];
        $Model->data[$Model->alias][$settings['meta']['contentType']] = $this->toWrite[$field]['type'];
    }

    /**
     * Writes files to a location
     * 
     * Takes care of resizing the files and also processing the different styles.
     * 
     * @return array An array of fields it attempted to write.
     *               Each field value will be true if successfull.
     */
    protected function writeFiles(Model $Model) {
        $result = array();
        if (!empty($this->toWrite)) {
            foreach ($this->toWrite as $field => $toWrite) {

                $settings = $this->interpolate($Model, $field, $toWrite['name'], 'original');
                if (!$this->isWritable($settings['path'])) {
                    throw new RuntimeException('Directory is not writable: ' . $settings['path']);
                }

                $move = !empty($settings['remote']) ? 'rename' : 'move_uploaded_file';
                if ($move($toWrite['tmp_name'], $settings['path'])) {
                    if (!empty($settings['remote'])) {
                        chmod($settings['path'], 0644);
                    }
                    if($this->maxWidthSize) {
                        $this->resize(array(
                            'source'      => $settings['path'],
                            'destination' => $settings['path'],
                            'geometry'    => $this->maxWidthSize.'w',
                            'engine'      => $settings['engine'],
                            'quality'     => $settings['quality'],
                        ));
                    }
                    foreach ($settings['styles'] as $style => $geometry) {
                        $newSettings = $this->interpolate($Model, $field, $toWrite['name'], $style);
                        if($this->isWritable($newSettings['path'])) {
                                $this->resize(array(
                                    'source'      => $settings['path'],
                                    'destination' => $newSettings['path'],
                                    'geometry'    => $geometry,
                                    'engine'      => $settings['engine'],
                                    'quality'     => $settings['quality'],
                                ));
                        }
                    }
                    $result[$field] = true;
                }
                $result[$field] = false;
            }
        }
        return $result;
    }

    /**
     * Sets up files for deletion
     * 
     * Prepares a list of files to be deleted
     * If the value is not in the data array it reads it from the database
     * 
     * @param string $field The field to delete, if not set it will be taken from the model settings
     * @param boolean $forceRead Forces a read of the database to get the filename
     */
    protected function prepareToDeleteFiles(Model $Model, $field = null, $forceRead = false) {
        $needToRead = true;
        if ($field === null) {
            $fields = array_keys($this->settings[$Model->alias]);
        } else {
            $fields = array($field);
        }

        if (!$forceRead && !empty($Model->data[$Model->alias])) {
            $needToRead = false;
            foreach ($fields as $field) {
                if (!array_key_exists($field, $Model->data[$Model->alias])) {
                    $needToRead = true;
                    break;
                }
            }
        }
        if ($needToRead) {
            $data = $Model->find('first', array(
                'conditions' => array($Model->alias . '.' . $Model->primaryKey => $Model->id), 
                'fields' => $fields, 
                'callbacks' => false
            ));
        } else {
            $data = $Model->data;
        }
        if (is_array($this->toDelete)) {
            $this->toDelete = array_merge($this->toDelete, $data[$Model->alias]);
        } else {
            $this->toDelete = $data[$Model->alias];
        }
        $this->toDelete['id'] = $Model->id;
    }

    /**
     * Deletes files
     */
    protected function deleteFiles(Model $Model) {
        foreach ($this->settings[$Model->name] as $field => $settings) {
            if (!empty($this->toDelete[$field])) {
                $styles  = array('original');
                $styles = array_merge($styles, array_keys($settings['styles']));
                foreach ($styles as $style) {
                    $settings = $this->interpolate($Model, $field, $this->toDelete[$field], $style);
                    if (file_exists($settings['path'])) {
                        unlink($settings['path']);
                    }
                }
            }
        }
    }

    protected function interpolate(Model $Model, $field, $filename, $style) {
        $settings = $this->settings[$Model->alias][$field];
        $keys = array('path', 'url', 'default_url');
        foreach ($keys as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = Interpolation::run($settings[$key], $Model->alias, $Model->id, $field, $filename, $style);
            }
        }
        return $settings;
    }

    protected function getImagine($engine) {
        if (!interface_exists('Imagine\Image\ImageInterface')) {
            $imaginePath = CakePlugin::path('Uploader') . 'Vendor' . DS;
            if (file_exists($imaginePath . 'imagine.phar')) {
                include_once 'phar://' . $imaginePath . 'imagine.phar';
            } else {
                throw new CakeException(
                    sprintf(
                        'Download imagine.phar: %s, and extract into vendor: %s',
                        'https://github.com/avalanche123/Imagine', $imaginePath
                    )
                );
            }
        }
        $class = 'Imagine\\' . $engine . '\Imagine';
        return new $class();   
    }

    /**
     * Resizes an image
     * 
     * @param array $options 
     *              - `source` - the source file
     *              - `destination` - The destination of the final image
     *              - `geometry` - The resize dimensions
     *              - `engine` - The engine to use (Gd, Imagick, Gmagick)
     *              - `quality` - Quality of image output
     */
    protected function resize($options) {
        extract($options);
        $imagine = $this->getImagine($engine);
        $image = $imagine->open($source);

        $srcDimensions = $image->getSize();
        $srcW = $srcDimensions->getwidth();
        $srcH = $srcDimensions->getHeight();
        $resizeMode = false;

        // determine destination dimensions and resize mode from provided geometry
        if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
            // resize with banding
            list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry) - 2));
        } elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
            // cropped resize (best fit)
            list($destW, $destH) = explode('x', $geometry);
            $resizeMode = 'crop';
        } elseif (preg_match('/^[\\d]+w$/', $geometry)) {
            // calculate height according to aspect ratio
            $destW = (int) $geometry - 1;
        } elseif (preg_match('/^[\\d]+h$/', $geometry)) {
            // calculate width according to aspect ratio
            $destH = (int) $geometry - 1;
        } elseif (preg_match('/^[\\d]+l$/', $geometry)) {
            // calculate shortest side according to aspect ratio
            if ($srcW > $srcH) {
                $destW = (int) $geometry - 1;
            } else {
                $destH = (int) $geometry - 1;
            }
        }

        if (!isset($destW)) {
            $destW = ($destH / $srcH) * $srcW;
        }
        if (!isset($destH)) {
            $destH = ($destW / $srcW) * $srcH;
        }

        if ($resizeMode == 'crop') {
           $mode = Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;
        } else {
           $mode = Imagine\Image\ImageInterface::THUMBNAIL_INSET;
        }
        $thumbnail = $image->thumbnail(new Imagine\Image\Box($destW, $destH), $mode);
        $options = array('quality' => $quality);
        return $thumbnail->save($destination, $options);
    }

    /**
     * Checks to see if a directory exists and is writable
     * It then tries to create the directory
     * 
     * @param string $dir The directory to check
     * @return boolean true if successful
     */
    protected function isWritable($path) {
        $dir = dirname($path);
        $d = new SplFileInfo($dir);
        if($d->isDir() && $d->isWritable()) {
            return true;
        }
        if (mkdir($dir, 0777, true)) {
            return true;
        }
        return false;
    }

    /**
     * Makes a filename URL friendly by using Inflector::slug
     * 
     * @param string $filename The filename to clean
     * @return string The clean filename
     */
    public function cleanFilename($filename){
        return Inflector::slug($filename);
    }

    /**
     * Checks that the attachment is within the allowed filesize
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file
     * @param array $limits The allowed size in bytes. Keyed array containing min and max keys.
     *              If a single non array value is passed, it will be used as a max size
     * @return boolean true if filesize is ok.
     */
    public function filesize(Model $Model, $file, $limits) {
        $file = array_shift($file);
        if (!empty($file['tmp_name'])) {
            if(!array($limits)) {
                $limits = array('max' => $limits);
            }
            extract($limits);

            if(isset($min) && isset($max)) {
                return ($this->convertStringToBytes($min) <= (int) $file['size']) && ((int) $file['size'] <= $this->convertStringToBytes($max));
            }
            if(isset($min) && !isset($max)) {
                return $this->convertStringToBytes($min) <= (int) $file['size'];
            }
            if(!isset($min) && isset($max)) {
                return (int) $file['size'] <= $this->convertStringToBytes($max);
            }
        }
        return true;
    }

    /**
     * Parses a string and returns the size in bytes
     * 
     * Pass something like 1MB or 100KB to get the value in bytes
     *
     * @param string $string
     * @return int $bytes
     */
    public function convertStringToBytes($string) {
        if (preg_match('/([0-9\.]+) ?([a-z]*)/i', $string, $matches)) {
            $number = $matches[1];
            $suffix = $matches[2];
            $suffixes = array(
                ""      => 0,
                "Bytes" => 0,
                "KB"    => 1,
                "MB"    => 2,
                "GB"    => 3,
                "TB"    => 4,
                "PB"    => 5
            );
            if (isset($suffixes[$suffix])) {
                $bytes = round($number * pow(1024, $suffixes[$suffix]));
                return (int) $bytes;
            }
            return false;
        }
        return false;
    }

    /**
     * Checks that the content type of the uploaded file is allowed
     * 
     * Uses finfo to check the mime type of the file, if finfo is unavailable it 
     * uses the uploaded files `type` 
     *
     * @param object $Model Model.
     * @param array $value The uploaded file
     * @param array $contentTypes Array of mime types allowed. Can be a mimetype or a regular expression 
     *        e.g array('image/jpg', '/^video\/.+/')
     * @return boolean true if content type is allowed.
     */
    public function contentType(Model $Model, $value, $contentTypes) {
        $value = array_shift($value);
        if (!is_array($contentTypes)) {
            $contentTypes = array($contentTypes);
        }

        if (!empty($value['tmp_name'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimetype = $finfo->file($value['tmp_name']);
            } else {
                $mimetype = $value['type'];
            }

            foreach ($contentTypes as $contentType) {
                if (substr($contentType, 0, 1) == '/') {
                    if (preg_match($contentType, $mimetype)) {
                        return true;
                    }
                } elseif ($contentType == $mimetype) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    /**
     * Checks that a file upload is present.
     * 
     * If the record exists and no upload is found then the current value of the record is
     * added to the data array.
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file to be validated.
     * @return boolean true if a file is detected.
     */
    public function attachmentPresence(Model $Model, $file) {
        $keys  = array_keys($file);
        $field = $keys[0];
        $file = array_shift($file);

        if (!empty($file['tmp_name'])) {
            return true;
        }

        if (!empty($Model->id)) {
            if (!empty($Model->data[$Model->alias][$field])) {
                return true;
            } elseif (!isset($Model->data[$Model->alias][$field])) {
                $existingFile = $Model->field($field, array($Model->primaryKey => $Model->id));
                if (!empty($existingFile)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Exact width validation
     * Checks that an image is exactly the specified width
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file to be validated.
     * @param string $width Width.
     * @return boolean true if the validation is successful.
     */
    public function exactWidth(Model $Model, $file, $width) {
        return $this->validateImageDimensions($file, 'exactWidth', $width);
    }

    /**
     * Exact height validation
     * Checks that an image is exactly the specified height
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file to be validated.
     * @param string $height Height.
     * @return boolean true if the validation is successful.
     */
    public function exactHeight(Model $Model, $file, $height) {
        return $this->validateImageDimensions($file, 'exactHeight', $height);
    }

    /**
     * Min width validation
     * Checks that an image is not smaller than the min width
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file to be validated.
     * @param string $minWidth Min width allowed.
     * @return boolean true if the validation is successful.
     */
    public function minWidth(Model $Model, $file, $minWidth) {
        return $this->validateImageDimensions($file, 'minWidth', $minWidth);
    }

    /**
     * Min height validation
     * Checks that an image is not smaller than the min height
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file to be validated.
     * @param string $minHeight Min height allowed.
     * @return boolean true if the validation is successful.
     */
    public function minHeight(Model $Model, $file, $minHeight) {
        return $this->validateImageDimensions($file, 'minHeight', $minHeight);
    }

    /**
     * Max width validation
     * Checks that an image is not wider than the max width
     * 
     * @param object $Model Model.
     * @param array $value The uploaded file to be validated.
     * @param string $maxWidth Max width allowed.
     * @return boolean true if the validation is successful.
     */
    public function maxWidth(Model $Model, $file, $maxWidth) {
        $keys = array_keys($file);
        $field = $keys[0];
        $settings = $this->settings[$Model->name][$field];
        if($settings['resizeToMaxWidth'] && !$this->validateImageDimensions($file, 'maxWidth', $maxWidth)) {
            $this->maxWidthSize = $maxWidth;
            return true;
        }
        return $this->validateImageDimensions($file, 'maxWidth', $maxWidth);
    }

    /**
     * Max height validation
     * Checks that an image is not taller than the max height
     * 
     * @param object $Model Model.
     * @param array $file The uploaded file to be validated.
     * @param string $maxHeight Max height allowed.
     * @return boolean true if the validation is successful.
     */
    public function maxHeight(Model $Model, $file, $maxHeight) {
        return $this->validateImageDimensions($file, 'maxHeight', $maxHeight);
    }

    /**
     * Helper method to validate various dimensions of images
     * 
     * @param array $file File to be validated
     * @param string $type Allowed values: width, height, minHeight, maxHeight, minWidth, maxWidth
     * @param int $value Width or height value to compare against.
     * @return boolean true if the dimension is within the boundary.
     */
    protected function validateImageDimensions($file, $type, $value) {
        $file = array_shift($file);
        if (empty($file['tmp_name'])){
        	return false;
        }
        $dimensions = getimagesize($file['tmp_name']);
        if(!$dimensions) {
            return false;
        }
        list($width, $height) = $dimensions;

        switch($type) {
            case 'exactWidth':
                return ($width == $value); 
                break;
            case 'exactHeight':
                return ($height == $value);
                break;
            case 'maxWidth':
                return ($width <= $value);
                break;
            case 'maxHeight':
                return ($height <= $value);
                break;
            case 'minWidth':
                return ($width >= $value);
                break;
            case 'minHeight':
                return ($height >= $value);
                break;
        }
        return true;
    }
}