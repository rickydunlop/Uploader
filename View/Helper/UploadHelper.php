<?php
App::uses('HtmlHelper', 'View/Helper');
App::uses('Interpolation', 'Uploader.Lib');

/**
 * UploadHelper
 *
 * UploadHelper provides fine access to files uploaded with UploadBehavior. 
 * It generates url for those files and can display image tags of uploaded images. 
 * For more info read Uploader documentation.
 *
 * @author Michał Szajbe (michal.szajbe@gmail.com)
 * @author Ricky Dunlop (ricky@rehabstudio.com)
 * @link https://github.com/rickydunlop/Uploader
 */
class UploadHelper extends AppHelper {
	public $helpers = array('Html');

	public function image($data, $field, $options = array(), $htmlOptions = array()) {
		$options += array('urlize' => false);
		return $this->output($this->Html->image($this->url($data, $field, $options), $htmlOptions));
	}

	public function link($title, $data, $field, $urlOptions = array(), $htmlOptions = array()) {
		$options += array('style' => 'original', 'urlize' => true);
		return $this->Html->link($title, $this->url($data, $field, $urlOptions), $htmlOptions);
	}

	public function url($data, $field, $options = array()) {
		$options += array('style' => 'original', 'urlize' => true);
		list($model, $field) = explode('.', $field);
		if(is_array($data)) {
			if(isset($data[$model])) {
				if(isset($data[$model]['id'])) {
					$id = $data[$model]['id'];
					$filename = $data[$model][$field];
				}
			} elseif(isset($data['id'])) {
				$id = $data['id'];
				$filename = $data[$field];
			}
		}
		
		if(isset($id) && isset($filename)) {
			$paths = UploadBehavior::getPaths($model, $id, $field, $filename, $options['style'], array('webroot' => ''));
			$url = isset($paths['url']) ? $paths['url'] : $paths['path'];
		} else {
			$settings = Interpolation::run($model, null, $field, null, $options['style'], array('webroot' => ''));
			$url = isset($settings['default_url']) ? $settings['default_url'] : null;
		}
		return $options['urlize'] ? $this->Html->url($url) : $url;
	}
}
?>