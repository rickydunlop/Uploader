# Uploader plugin
The Uploader plugin helps you to upload files really easily with CakePHP.
It is based on [Paperclip](https://github.com/thoughtbot/paperclip) from thoughtbot

A lot of credit goes to [Michał Szajbe](https://github.com/szajbus/) for the original [UploadPack Plugin](https://github.com/szajbus/uploadpack) and also [Tobias Sandelius](https://github.com/sandelius/li3_attachable) for the Interpolation class.

## Requirements
- CakePHP v2.x
- PHP 5.3+

## Installation

Place the plugin in your APP/Plugin directory

## Simple usage

Attach `UploadBehavior` to your model and set it up to handle images.

```php
class Post extends AppModel {
	public $actsAs = array(
		'Uploader.Upload' => array(
			'image' => array(
				'styles' => array(
					'thumb' => '50x50'
				)
			)
		)
	);
}
```

Here we have defined one style named 'thumb' which means that a thumnbnail of 50x50 pixels will be generated with the original image.

By default the files will be saved to 
`:webroot/uploads/:model/:id/:style-:filename:extension` (with :keys appropriately substituted at run time). 
Make sure that `webroot/uploads` folder is writeable.

We now need to add a file field to the form. 

Your form must have the right enctype attribute to support file uploads, 

e.g.

```php
$this->Form->create('Posts', array('type' => 'file'));
$this->Form->input('Post.image', array('type' => 'file'));
$this->Form->end('Submit');
```


## Showing the files using the helper

First, add the helper to your controller `Controllers/PostsController.php`


```php
public $helpers = array('Uploader.Upload');
```

To get the original file:

```php 
echo $this->Upload->image($post, 'Post.image');
```

And for the thumbnail:

```php 
echo $this->Upload->image($user, 'Post.image', array('style' => 'thumb'));
```

## File Validation methods

### Attachment filesize:

You can pass either a min value, a max value or both.

The validation method will recognise the following strings `Bytes, KB, MB, GB, TB and PB`. 

It is case insensitive and if an integer is passed it will be interpreted as bytes.

```php
public $validate = array(
	'image' => array(
		'filesize' => array(
			'rule' => array('filesize', array('min' => '50KB', 'max' => '5MB')),
			'message' => 'Image must be between 50KB and 5MB'
		),
	)
);
```

### Attachment content type:

You can pass a single content type such as `image/jpeg` or you can pass an array of content types `array('image/jpg', 'image/jpeg')`.

It also accepts regular expressions if you wrap the content with an opening and closing `/`

```php
public $validate = array(
	'image' => array(
		'contentType => array(
			'rule' => array('contentType', array(
				'image/jpg', 'image/jpeg', 'image/png', 'image/gif'
			)),
			'message' => 'Only images are allowed.'
		),
	),
	'video' => array(
		'contentType' => array(
			'rule' => array('contentType', '/video\/.+/'),
			'message' => 'Only videos are allowed.'
		)
	),
);
```

### Attachment presence:

```php 
public $validate = array(
	'image' => array(
		'rule' => array('attachmentPresence'),
		'message' => 'An image is required'
	)
);
```

### Attachment dimensions:

The following code shows the various checks available.

When editing a record that already has image attached and you don't supply a new one, 
the value will be obtained from the data array or by a database read.

```php
public $validate = array(
	'image' => array(
		'exactWidth' => array(
			'rule' => array('exactWidth', '100'),
			'message' => 'Image must be exactly 100 pixels wide'
		),
		'exactHeight' => array(
			'rule' => array('exactHeight', '100'),
			'message' => 'Image must be exactly 100 pixels high'
		),
		'minWidth' => array(
			'rule' => array('minWidth', '100'),
			'message' => 'Image must be at least 100 pixels wide'
		),
		'maxWidth' => array(
			'rule' => array('maxWidth', '600'),
			'message' => 'Image can\'t be over 600 pixels wide'
		),
		'minHeight' => array(
			'rule' => array('minHeight', '100'),
			'message' => 'Image must be at least 100 pixels wide'
		),
		'maxHeight' => array(
			'rule' => array('maxHeight', '600'),
			'message' => 'Image can\'t be over 600 pixels wide'
		)
	)
);
```


## Configuration

### Changing the path where files are saved

The path determines where the uploaded file is saved. It is a string that contains special `:keys` that are 
substitued at runtime by [String::insert](http://api20.cakephp.org/class/string#method-Stringinsert).

The default value is `:webroot/uploads/:model/:id/:style-:filename:extension`.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'path' => ':webroot/your/new/path/:filename_:style:extension'
		)
	)
);
```

#### List of available @:keys@ with the values they're substituted with.

* :webroot    - Path to the webroot folder `WWW_ROOT`
* :model      - name of the model tableized with Inflector::tableize (would be `posts` for Post model)
* :field      - The name of the database field (e.g `image`)
* :filename   - The uploaded file's original name (would be `photo` for photo.jpg)
* :extension  - The extension of the uploaded file's original name (would be `jpg` for photo.jpg)
* :id         - ID of the record e.g `23`
* :style      - The style e.g `thumb`
* :hash       - md5 hash of the original filename + Security.salt


### Adding/Overriding keys

You can add/override `:keys` using the `Interpolation::add` method.
This accepts a closure which you can use to set your values.

Example:
To override `:hash` to use sha1 instead of md5, put the following in your model.

```php
public function __construct() {
	parent::__construct();
	Interpolation::add('hash', function($info){
		return sha1($info->getFilename() . Configure::read('Security.salt'));
	});
	return true;
}
```


#### Changing the url the helper points to when displaying or linking to uploaded files

This setting accepts all the `:keys` mentioned above and it's default value depends on path setting. 
For the default path it would be `/uploads/:model/:id/:style-:filename:extension`.

You probably won't need to change this too often.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'url' => 'your/new/url/path'
		)
	)
);
```

#### Setting a default url if the uploaded file is missing

If uploading a file is not required this field allows you to have a default file as a fallback.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'default_url' => 'path/to/default/image'
		)
	)
);
```
	
#### Automatically scale down images that are wider than the `maxWidth` specified in validation.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'resizeToMaxWidth' => true
		)
	)
);
```

#### JPEG Quality

The jpeg quality can be set with the `quality` setting.
The default is set to 90.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'quality' => 75
		)
	)
);
```

#### Set field for an external url

This way the user can paste an url or choose a file from their filesystem. The url will mimic usual uploading so will still be validated and resized.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'urlField' => 'gravatar'
		)
	)
);
```

#### Image manipulation engine
The upload behavior uses the [Imagine](https://github.com/avalanche123/Imagine) image library to generate the thumbnails which means you can switch the engine really easily.
The default configuration uses the GD library. The other options are `Imagick` and `Gmagick`.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'engine' => 'Imagick'
		)
	)
);
```

### Styles

Styles are the definition of thumbnails that will be generated for original image. You can define as many as you want.

Be sure to have `:style` key included in your path setting to differentiate file names. The original file is also saved and has 'original' as `:style` value, so you don't need it to define it yourself.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'styles' => array(
				'big' => '200x200',
				'small' => '120x120'
				'thumb' => '80x80'
			)
		)
	)
);
```

If you are not uploading an image file then define no styles at all. It does not make sense to generate thumbnails for zip or pdf files.

You can specify any of the following resize modes for your styles:

* **100x80**   - resize for best fit into these dimensions, with overlapping edges trimmed if original aspect ratio differs
* **[100x80]** - resize to fit these dimensions, with white banding if original aspect ratio differs (Michał's original resize method)
* **100w**     - maintain original aspect ratio, resize to 100 pixels wide
* **80h**      - maintain original aspect ratio, resize to 80 pixels high
* **80l**      - maintain original aspect ratio, resize so that longest side is 80 pixels

### Meta Data

When the file is processed, some meta information is also gathered which may be useful to you, content type and filesize.

if you wish to save the values of these along with you file you can specify the database field names in your configuration
If you are uploading multiple files then you will need to make sure you create unique meta field names for each file.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(
			'meta' => array(
				'filesize'     => 'filesize',
				'contentType'  => 'content_type'
			)
		)
	)
);
```


### Uploading multiple files at once
Models can have many uploaded files at once. 
For example define two fields in database table: `image` and `video`.

```php
public $actsAs = array(
	'Uploader.Upload' => array(
		'image' => array(),
		'video' => array()
		)
	)
);
```

## Using the helper

There are two methods of `UploadHelper` you can use:

### Display as an image

`UploadHelper::image($data, $field, $options = array(), $htmlOptions = array())`
	
```php
echo $this->Upload->image($post, 'Post.image');
```

* **$data** - record from database (would be `$post` here)
* **$field** - name of a field, like this `Modelname.fieldname` (would be `Post.image` here)
* **$options** - url options
	* **'style'** - name of a style
	* **'urlize'** - if true returned url is wrapped with `$this->Html->url()`
* **$htmlOptions** - array of HTML attributes passed to `$this->Html->image()`

To display a certain style of an image, pass the style to the options array.


```php 
echo $this->Upload->image($post, 'Post.image', array('style' => 'thumb'), array('title' => 'My image title'));
```
	
### Get url of a file

```php
echo $this->Upload->url($post, 'Post.image');
```

* **$data** - record from database (would be `$post` here)
* **$field** - name of a field, like this `Modelname.fieldname` (would be `Post.image` here)
* **$options** - url options
	* **'style'** - name of a style
	* **'urlize'** - if true returned url is wrapped with `$this->Html->url()`


## TODO

* Make file saving adaptable to allow saving to the cloud or other sources.
* Allow deletion of a file without deleting whole record.
* Full test coverage.
* Shell method to (re)generate images if you change styles etc.

## Copyright

See license file.