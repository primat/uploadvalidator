<?php
namespace Primat\Validation;

/**
 * Class UploadValidator
 *
 * REQUIRES PHP 5
 * File: UploadValidator.php
 * Version: 0.3.0
 * Author: Mat Price - dev.msp@gmail.com
 */

/*
sample config (defaults)
$config = array(
               'fieldname' => '',         // Name attribute of the form field we want to validate
              'fieldlabel' => '',         // Label of the field which is being validated. This is the label that will appear in the error messages. If left empty, it is omitted.
                'language' => 'en',       // Language of validation error messages.

      'allowed_file_types' => array(),    // This may be either a list (array) of file extensions or a string corresponding to the key of $this->file_types
           'max_file_size' => 128000,     // Maximum number of bytes for the file upload
      'upload_is_required' => false,      // If set to true, an error will be signaled if there was no upload (i.e. $_FILES['userfile']['error'] === 4)

          'min_img_height' => 1, 		  // Minimum height for an uploaded image
          'max_img_height' => 1200, 	  // Maximum height for an uploaded image
           'min_img_width' => 1, 		  // Minimum width for an uploaded image
           'max_img_width' => 1600, 	  // Maximum width for an uploaded image

               'move_file' => false,      // Set to true to move_upload() within this runValidation() function
               'overwrite' => false,      // When set to false, a number between parentheses is appended to the end of the filename to try and make it unique
              'upload_dir' => '',         // Directory where the upload may be copied to

        'file_permissions' => 0775,       // Set the file permissions of newly uploaded files to this
                'filename' => '',         // Custom filename (without .extension) which will override the uploaded one. It is passed through validation and sanitization if they are enabled.
 'filename_img_dimensions' => true,       // Append img dimensions to the filename (i.e. photo-400x300.jpg)
     'filename_max_length' => 150,        // Maximum length for the filename - cannot be lower than 20
         'filename_prefix' => '',         // Prefix for the filename
       'filename_sanitize' => true,       // Turn a human readable string into a machine readable string.
       'filename_validate' => true,       // Validate the filename against exploits, filename length, undesirable characters...
);
*/

class UploadValidator
{
	/** @var array $config Current config settings */
	protected $config;
	/** @var array $defaults Default config settings */
	protected $defaults;
	/** @var array $errors List of messages add by the validator */
	protected $errors;
	/** @var array $errorMessages All error messages are stored here */
	protected $errorMessages;
	/** @var array $fileTypes Pool of all types of files to process */
	protected $fileTypes;
	/** @var array $supportedLanguages Currently supports English and French (CA) */
	protected $supportedLanguages;

	/**
	 * Constructor
	 */
	function __construct()
	{
		// *******************************************************************
		//  DEFAULT CONFIG SETTING
		$this->defaults = array();
		$this->defaults['fieldname'] = '';
		$this->defaults['fieldlabel'] = '';
		$this->defaults['language'] = 'en';

		$this->defaults['allowed_file_types'] = array();
		$this->defaults['max_file_size'] = 128000;
		$this->defaults['upload_is_required'] = false;

		$this->defaults['min_img_width'] = 1;
		$this->defaults['min_img_height'] = 1;
		$this->defaults['max_img_width'] = 5000;
		$this->defaults['max_img_height'] = 5000;

		$this->defaults['move_file'] = false;
		$this->defaults['overwrite'] = false; // Only applicable if move_file is true
		$this->defaults['upload_dir'] = sys_get_temp_dir(); // "

		$this->defaults['file_permissions'] = 0775;
		$this->defaults['filename'] = '';
		$this->defaults['filename_img_dimensions'] = false;
		$this->defaults['filename_max_length'] = 150;
		$this->defaults['filename_prefix'] = '';
		$this->defaults['filename_sanitize'] = true;
		$this->defaults['filename_validate'] = true;

		// *******************************************************************
		//  DON'T MODIFY BELOW THIS
		$this->config = array(); // Storage for config settings
		$this->errorMessages = array(); // Storage for all error messages

		// Lists of allowed upload types
		$this->fileTypes = array();
		$this->fileTypes['images'] = array('gif', 'png', 'jpg', 'jpeg');
		$this->fileTypes['documents'] = array('doc', 'docx', 'txt', 'xls', 'ppt', 'pdf');
		$this->fileTypes['videos'] = array('avi', 'mov', 'wmv', 'flv', 'mp4', 'ogg', 'webm');
		$this->fileTypes['music'] = array('mp3', 'wmv');

		$this->supportedLanguages = array('en' => 'CA', 'fr' => 'CA');
		// *******************************************************************
	}

	/**
	 * Case insensitive version of file_exists()
	 * @param string $filename The filename/path to check for existence
	 * @return bool
	 */
	protected function ciFileExists($filename)
	{
		if (file_exists($filename)) {
			return true;
		}

		$dir = dirname($filename);
		$files = glob($dir . '/*');
		$lCaseFilename = strtolower($filename);

		foreach ($files as $file) {
			if (strtolower($file) == $lCaseFilename) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get customized error messages
	 * @param string $errorKey The language independent key of the error message
	 * @param bool $validation_data Set to true to include validation info in messages
	 * @return string
	 */
	protected function getErrorMsg($errorKey, $validation_data = false)
	{
		$retval = '';

		if (isset($this->errorMessages[$errorKey])) {
			$label = '';
			if (!empty($this->config['fieldlabel'])) {
				$label = $this->config['fieldlabel'] . ': ';
			}

			// 
			if ($errorKey === 'INVALID_FILE_EXTENSION') {
				$retval = sprintf($this->errorMessages['INVALID_FILE_EXTENSION'], $label, implode(', ', $this->config['allowed_file_types']));
			}
			elseif ($errorKey === 'FILE_UPLOAD_SIZE_TOO_LARGE') {
				$retval = sprintf($this->errorMessages['FILE_UPLOAD_SIZE_TOO_LARGE'],
					$label,
					$this->bytesToShorthand($this->config['max_file_size'], $this->config['language']),
					$this->bytesToShorthand($_FILES[$this->config['fieldname']]['size'], $this->config['language'])
				);
			}
			elseif ($errorKey === 'FILE_SIZE_TOO_LARGE') {
				$retval = sprintf($this->errorMessages['FILE_SIZE_TOO_LARGE'],
					$label,
					$this->bytesToShorthand($this->config['max_file_size'], $this->config['language'])
				);
			}
			elseif ($errorKey === 'IMAGE_DIMENSIONS_OOB') {
				if ($this->config['min_img_width'] != $this->config['max_img_width']) {
					if ($this->config['language'] == 'fr') {
						$msg_width_part = "entre {$this->config['min_img_width']}px et {$this->config['max_img_width']}px en largeur";
					}
					else {
						$msg_width_part = "between {$this->config['min_img_width']}px and {$this->config['max_img_width']}px wide";
					}
				}
				else {
					if ($this->config['language'] == 'fr') {
						$msg_width_part = "{$this->config['max_img_width']}px en largeur";
					}
					else {
						$msg_width_part = "{$this->config['max_img_width']}px wide";
					}
				}

				if ($this->config['min_img_height'] != $this->config['max_img_height']) {
					if ($this->config['language'] == 'fr') {
						$msg_height_part = "entre {$this->config['min_img_height']}px et {$this->config['max_img_height']}px en hauteur";
					}
					else {
						$msg_height_part = "between {$this->config['min_img_height']}px and {$this->config['max_img_height']}px high";
					}
				}
				else {
					if ($this->config['language'] == 'fr') {
						$msg_height_part = "{$this->config['max_img_height']}px en hauteur";
					}
					else {
						$msg_height_part = "{$this->config['max_img_height']}px high";
					}
				}

				$retval = sprintf($this->errorMessages['IMAGE_DIMENSIONS_OOB'],
					$label,
					$msg_width_part,
					$msg_height_part,
					$validation_data['img_width'],
					$validation_data['img_height']);
			}
			elseif ($errorKey === 'FILENAME_TOO_LONG') {
				$retval = sprintf($this->errorMessages['FILENAME_TOO_LONG'], $label, $this->config['filename_max_length'], $validation_data['filename_length']);
			}
			elseif ($errorKey === 'UNKNOWN_ERROR') {
				$retval = sprintf($this->errorMessages['UNKNOWN_ERROR'], $label, $_FILES[$this->config['fieldname']]['error']);
			}
			else {
				$retval = sprintf($this->errorMessages[$errorKey], $label);
			}
		}
		else {
			exit("UploadValidator class: No defined error message for indice '$errorKey'.");
		}
		return $retval;
	}

	/**
	 * Initialize the error messages array
	 * @param string $lang The language of the error messages
	 */
	protected function initErrorMessages($lang)
	{
		// Empty previous error messages
		$this->errorMessages = array();

		// French error messages
		if ($lang == 'fr') {
			$this->errorMessages['INVALID_FILE_EXTENSION'] = '%sLe téléchargement de ce type de fichier n\'est pas permise. (Types permises: %s)';
			$this->errorMessages['FILE_UPLOAD_SIZE_TOO_LARGE'] = '%sLe poids du téléchargement dépasse la limite permise de %s. (Votre fichier: %s)';
			$this->errorMessages['FILE_SIZE_TOO_LARGE'] = '%sLe poids du téléchargement dépasse la limite permise de %s.';
			$this->errorMessages['FILE_SIZE_ZERO'] = '%sLe fichier n\'a pas été téléchargé parce qu\'il contient zéro octets.';
			$this->errorMessages['INVALID_IMAGE_DIMENSIONS'] = '%sLe serveur ne peut déterminer les dimensions de l\'image.';
			$this->errorMessages['IMAGE_DIMENSIONS_OOB'] = '%sL\'image doit avoir %s et %s. (Votre image: %dpx en largeur et %dpx en hauteur)';
			$this->errorMessages['INVALID_FILENAME'] = '%sLe nom du fichier doit contenir seulement des caractères alphanumériques ou les caractères suivants: ()._-';
			$this->errorMessages['FILENAME_TOO_LONG'] = '%sLe nom du fichier ne doit contenir plus de %s caractères. (Votre fichier: %d caractères)';
			$this->errorMessages['MOVE_UPLOADED_FILE_FAILED'] = '%sLe serveur n\'a pu copier le fichier du dossier temporaire.';
			$this->errorMessages['FILE_UPLOAD_PARTIAL'] = '%sLe fichier n\'a été que partiellement téléchargé.';
			$this->errorMessages['NO_FILE_UPLOADED'] = '%sAucun fichier n\'a été envoyé.';
			$this->errorMessages['MISSING_TEMPORARY_FOLDER'] = '%sIl manque un dossier temporaire pour stocker le fichier.';
			$this->errorMessages['FAILED_WRITE_TO_DISK'] = '%sLe fichier n\'a pu être copié.';
			$this->errorMessages['STOPPED_BY_EXT'] = '%sLe téléchargement a été stoppé à cause de l\'extension.';
			$this->errorMessages['UNKNOWN_ERROR'] = '%sUne erreur inconnue est survenue lors du téléchargement. (Code: %d)';
		} else {
			$this->errorMessages['INVALID_FILE_EXTENSION'] = '%sFile uploads are restricted to only the following types: (%s)';
			$this->errorMessages['FILE_UPLOAD_SIZE_TOO_LARGE'] = '%sThe file upload cannot exceed %s in size. (Your file: %s)';
			$this->errorMessages['FILE_SIZE_TOO_LARGE'] = '%sThe file upload cannot exceed %s in size.';
			$this->errorMessages['FILE_SIZE_ZERO'] = '%sThe file was not uploaded because it contains zero bytes.';
			$this->errorMessages['INVALID_IMAGE_DIMENSIONS'] = '%sThe server was unable to determine the image\'s dimensions.';
			$this->errorMessages['IMAGE_DIMENSIONS_OOB'] = '%sThe image must be %s and %s. (Your image: %dpx wide by %dpx high)';
			$this->errorMessages['INVALID_FILENAME'] = '%sThe name of the file you are trying to upload is invalid. File names can only contain letters, digits, underscores, hyphens, parentheses, and/or periods.';
			$this->errorMessages['FILENAME_TOO_LONG'] = "%sThe file name cannot exceed %d characters. (Your file: %d characters)";
			$this->errorMessages['MOVE_UPLOADED_FILE_FAILED'] = '%sThe server could not move the uploaded file from the temporary directory.';
			$this->errorMessages['FILE_UPLOAD_PARTIAL'] = '%sThe file was only partially uploaded.';
			$this->errorMessages['NO_FILE_UPLOADED'] = '%sNo File was uploaded.';
			$this->errorMessages['MISSING_TEMPORARY_FOLDER'] = '%sA temporary folder for the uploaded file is missing.';
			$this->errorMessages['FAILED_WRITE_TO_DISK'] = '%sThe file could not be written to disk.';
			$this->errorMessages['STOPPED_BY_EXT'] = '%sThe upload was stopped by imgExtension.';
			$this->errorMessages['UNKNOWN_ERROR'] = '%sAn unhandled error occured. (Code: %d)';
		}
	}

	/**
	 * Convert byte count to a more readable form
	 * @param int $nbrOfBytes The nuumber of bytes
	 * @param string $lang The language to use for display
	 * @return string
	 */
	public function bytesToShorthand($nbrOfBytes, $lang = 'en')
	{
		$modifier = '';
		$nbrOfBytes = (int)$nbrOfBytes;

		// Test if the number of bytes is a power of 2
		// If it is, create a shorthand version
		if ((($nbrOfBytes & ($nbrOfBytes - 1)) == 0)) {
			$temp_nbr = $nbrOfBytes;

			if ($temp_nbr >= 1024) {
				$temp_nbr = $temp_nbr / 1024;
				$modifier = ' K';
			}

			if ($temp_nbr >= 1024) {
				$temp_nbr = $temp_nbr / 1024;
				$modifier = ' M';
			}

			if ($temp_nbr >= 1024) {
				$temp_nbr = $temp_nbr / 1024;
				$modifier = ' G';
			}
		} else {
			$temp_nbr = number_format($nbrOfBytes) . ' ';
		}

		if ($lang == 'fr') {
			$modifier .= 'o';
		}
		else {
			$modifier .= 'B';
		}

		return $temp_nbr . $modifier;
	}

	/**
	 * Return the error messages. The parameter indicates what format
	 * we want the returned errors to be in. Possible values are :
	 * - escaped text (addslashes)
	 * - text
	 * - html
	 * - json
	 * - php array
	 * @param string $type The type of output
	 * @return string
	 */
	public function getErrorMsgs($type = 'escaped_text')
	{
		if ($type == 'html') {
			//return implode("<br />\n", $this->errors);
			$retval = '';
			foreach ($this->errors as $key => &$error) {
				$retval .= htmlspecialchars($error) . "<br />\n";
			}
			unset($error);
			return $retval;
		}
		elseif ($type == 'escaped_text') {
			$retval = '';
			$i = 0;
			foreach ($this->errors as $key => &$error) {
				if ($i++ > 0) {
					$retval .= "\n";
				}
				$retval .= addslashes($error);
			}
			unset($error);
			return $retval;
		}
		elseif ($type == 'json') {
			return json_encode($this->errors);
		}
		elseif ($type == 'php_array') {
			return $this->errors;
		}
		elseif ($type == 'js_array') {
			$i = 0;
			$retval = '[';
			foreach ($this->errors as $key => &$error) {
				if ($i++ > 0) {
					$retval .= ",";
				}
				$retval .= "'" . addslashes($error) . "'";
			}
			unset($error);
			$retval .= ']';
			return $retval;
		}
		else {
			return (string)implode('\n', $this->errors);
		}
	}

	/**
	 * Convert a human readable string into a slug or dirified string
	 * @param string $str The string to convert
	 * @param string $whitespace_replacement The replacement character for whitespace
	 * @return string
	 */
	public function dirify($str, $whitespace_replacement = '-')
	{
		$str = utf8_decode($str);

		// Remove some funky whitespace characters
		$str = str_replace(array("\t", "\n", "\r", "\0", "\x0B"), '', strtolower(stripslashes(trim($str))));

		// Reduce multiple spaces to single hyphen, while trimming the string and stripping slashes
		$str = preg_replace('/(\s)+/', $whitespace_replacement, $str);

		//$placeholder = preg_replace('/(\.)+/', $whitespace_replacement, $placeholder);
		//$str = preg_replace("/[^a-z0-9_\)\(\-]*$/i", '', $str);
		$str = preg_replace("/[^a-z0-9_\)\(\-]*$/", '', $str);

		$str = utf8_encode($str);

		$replacements = array(
			'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
			'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
			'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ý' => 'Y',
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ý' => 'y',
			'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
			'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
			'Ã' => 'A', 'Ñ' => 'N', 'Õ' => 'O', 'ã' => 'a', 'ñ' => 'n', 'õ' => 'o',
			'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U', 'Ÿ' => 'Y',
			'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u', 'ÿ' => 'y',
			'Ç' => 'C', 'ç' => 'c', 'Æ' => 'AE', 'æ' => 'ae', 'Œ' => 'OE', 'œ' => 'oe',
			'β' => 'B', '&' => 'and', '@' => 'at', '.' => '_'
		);

		// And recreate a new one in the class variable
		$cleansed_filename = ''; //

		$str_len = mb_strlen($str, 'utf-8');
		for ($i = 0; $i < $str_len; $i++) {
			$char = mb_substr($str, $i, 1, 'utf-8');
			$cleansed_filename .= (isset($replacements[$char])) ? $replacements[$char] : $char;
		}

		return $cleansed_filename;
	}

	/**
	 * Remove Invisible Characters
	 *
	 * This prevents sandwiching null characters
	 * between ascii characters, like Java\0script.
	 *
	 * @param string $str
	 * @param bool $url_encoded
	 * @return mixed
	 */
	public function removeInvisibleCharacters($str, $url_encoded = true)
	{
		$non_displayables = array();

		// every control character except newline (dec 10)
		// carriage return (dec 13), and horizontal tab (dec 09)

		if ($url_encoded) {
			$non_displayables[] = '/%0[0-8bcef]/'; // url encoded 00-08, 11, 12, 14, 15
			$non_displayables[] = '/%1[0-9a-f]/'; // url encoded 16-31
		}

		$non_displayables[] = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S'; // 00-08, 11, 12, 14-31, 127

		do {
			$str = preg_replace($non_displayables, '', $str, -1, $count);
		} while ($count);

		return $str;
	}

	/**
	 * Validate a file upload
	 * @param array $config The slew of config options
	 * @return array
	 */
	public function runValidation($config = array())
	{
		/*		echo '<pre>';
				print_r($config);
				echo '</pre>';
				exit();*/

		// Make sure we're dealing with an array
		if (!is_array($config)) {
			trigger_error('UploadValidator class: Function run($config) requires an array as argument', E_USER_ERROR);
		}

		// Merge the user config with the class defaults
		$this->config = $config + $this->defaults;

		// Initialize other variables
		$retval = array();
		$retval['errors'] = array();
		$retval['file_extension'] = ''; // the file extension, without the leading period
		$retval['filename_base'] = ''; // The base file name. No path, no extension.
		$retval['filename_count_modifier'] = ''; // The number to add to a filename that exists already
		$retval['filename_img_modifier'] = ''; // the dimensions of the img, appendable to the base filename
		$retval['filename_raw'] = ''; // The raw, unsanitized filename, as given by the form
		$retval['is_image_upload'] = false; // true if the upload is an image
		$retval['mime_type'] = ''; // the file's mime type
		$retval['upload_dir'] = ''; // The path from root to the folder where the file gets uploaded
		$retval['upload_exists'] = false; // Should be true if a file was uploaded, regardless if it was valid

		// These variables are just here for reference and should not be initialized yet
		// $retval['img_height'] = '';
		// $retval['img_width'] = '';

		// Determine the language of the returned errors and set the default to english if the config language is not supported
		if (!isset($this->supportedLanguages[$this->config['language']])) {
			trigger_error('UploadValidator class: Language \'' . htmlspecialchars($this->config['language']) . '\' not supported - reverting to english.', E_USER_NOTICE);
			$this->config['language'] = 'en';
		}

		// Initialize custom error messages based on the rules in $config
		$this->initErrorMessages($this->config['language']);

		// ****************************************
		// If no fieldname was submitted, try to grab the first available one from the $_FILES array
		if (empty($this->config['fieldname'])) {
			// Check if we have at least 1 file uploaded in the $_FILES global and grab the name attribute as fieldname
			if (isset($_FILES) && is_array($_FILES) && reset($_FILES) !== false) {
				$this->config['fieldname'] = key($_FILES);
			}
			else {
				trigger_error('UploadValidator class: There are no uploads to validate.', E_USER_NOTICE);
				return array();
			}
		}
		elseif (!isset($_FILES[$this->config['fieldname']])) {
			// A fieldname was provided in the $config. Check if it exists in the $_FILES array
			trigger_error('UploadValidator class: a fieldname was provided but cannot be found in the $_FILES array', E_USER_NOTICE);
		}

		// Signal that a file has been in fact uploaded
		$retval['upload_exists'] = !empty($_FILES[$this->config['fieldname']]['tmp_name']);

		// Make sure we're using the correct max file size
		// Store all limitations for uploads and use the lowest value
		$max_sizes = array();
		$max_sizes[] = $this->shorthandToBytes(ini_get('upload_max_filesize'));
		$max_sizes[] = $this->shorthandToBytes(ini_get('post_max_size'));
		if ($this->config['max_file_size'] > 0) {
			$max_sizes[] = $this->config['max_file_size'];
		}
		if (isset($_POST['MAX_FILE_SIZE']) && ctype_digit($_POST['MAX_FILE_SIZE'])) {
			$max_sizes[] = $_POST['MAX_FILE_SIZE'];
		}

		$this->config['max_file_size'] = min($max_sizes);

		// *********************
		// START VALIDATION
		// Now that we have a fieldname, validate the successfully uploaded file
		if ($_FILES[$this->config['fieldname']]['error'] == 0 && $retval['upload_exists']) {
			// Test if the upload was submitted via HTTP POST
			if (!is_uploaded_file($_FILES[$this->config['fieldname']]['tmp_name'])) {
				trigger_error('UploadValidator class: The upload is invalid.', E_USER_ERROR);
				return array();
			}
			elseif (!$this->ciFileExists($_FILES[$this->config['fieldname']]['tmp_name'])) {
				// Check if the file exists in the file system
				trigger_error('UploadValidator class: The file could not be found on the server.', E_USER_ERROR);
				return array();
			}

			// Get the mime type	
			if (!empty($_FILES[$this->config['fieldname']]['type'])) {
				$retval['mime_type'] = $_FILES[$this->config['fieldname']]['type'];
			}

			// Establish the upload's file type
			$segments = explode('.', $_FILES[$this->config['fieldname']]['name']);

			// If $segments[1] isn't set, it means that explode only returned 1 or no tokens, therefore, no file extension
			if (!isset($segments[1])) {
				trigger_error('UploadValidator class: Unable to establish a file type/extension', E_USER_ERROR);
				return array();
			}
			else {
				$retval['file_extension'] = $segments[count($segments) - 1];
			}

			// Determine the allowed upload types
			if (is_string($this->config['allowed_file_types'])) {
				// Pre-defined allowed types
				if ($this->config['allowed_file_types'] === 'images') {
					$this->config['allowed_file_types'] = $this->fileTypes['images'];
				}
				elseif ($this->config['allowed_file_types'] === 'documents') {
					$this->config['allowed_file_types'] = $this->fileTypes['documents'];
				}
				elseif ($this->config['allowed_file_types'] === 'videos') {
					$this->config['allowed_file_types'] = $this->fileTypes['videos'];
				}
				elseif ($this->config['allowed_file_types'] === 'music') {
					$this->config['allowed_file_types'] = $this->fileTypes['music'];
				}
				else {
					// No valid allowed type was specified so there will invariably be an error
					trigger_error('UploadValidator class: File validation for type \'' . htmlspecialchars($this->config['allowed_file_types']) . '\' not supported.', E_USER_ERROR);
					exit(-1);
				}
			}
			else if (empty($this->config['allowed_file_types'])) {
				$allAllowedTypes = array();
				foreach ($this->fileTypes as $cat => $types) {
					$allAllowedTypes = array_merge($allAllowedTypes, $types);
				}
				$this->config['allowed_file_types'] = $allAllowedTypes;
			}
			// Intersect the file types the user specified with the list of those which are supported
			//$this->config['allowed_file_types'] = array_intersect((array)$this->config['allowed_file_types'], $allAllowedTypes);

			// Swap values in case they are not set properly
			if ($this->config['min_img_width'] > $this->config['max_img_width']) {
				$temp = $this->config['min_img_width'];
				$this->config['min_img_width'] = $this->config['max_img_width'];
				$this->config['max_img_width'] = $temp;
			}
			if ($this->config['min_img_height'] > $this->config['max_img_height']) {
				$temp = $this->config['min_img_height'];
				$this->config['min_img_height'] = $this->config['max_img_height'];
				$this->config['max_img_height'] = $temp;
			}
			unset($temp);

			// ****************************************
			// Validate the directory for uploaded files, assuming we want to upload the file to the server in the first place
			if (!empty($this->config['upload_dir'])) {
				$this->config['upload_dir'] = realpath($this->config['upload_dir']);
				if (!$this->config['upload_dir'] || !is_dir($this->config['upload_dir'])) {
					trigger_error('UploadValidator class: Invalid upload directory - The path might not be a directory or the directory does not exist.', E_USER_ERROR);
					exit(-1);
				} else // If the path is valid, append a trailing slash to it
				{
					$this->config['upload_dir'] .= '/';
				}
			}
			$retval['upload_dir'] = $this->config['upload_dir'];

			//
			$this->config['filename_max_length'] = max($this->config['filename_max_length'], 20);

			// Validate the file extension/type
			if (!in_array($retval['file_extension'], $this->config['allowed_file_types'])) {
				$retval['errors']['INVALID_FILE_EXTENSION'] = $this->getErrorMsg('INVALID_FILE_EXTENSION');
			}

			// Validate the file's size
			if ($_FILES[$this->config['fieldname']]['size'] > $this->config['max_file_size']) {
				$retval['errors']['FILE_UPLOAD_SIZE_TOO_LARGE'] = $this->getErrorMsg('FILE_UPLOAD_SIZE_TOO_LARGE');
			} elseif ($_FILES[$this->config['fieldname']]['size'] < 1) {
				$retval['errors']['FILE_SIZE_ZERO'] = $this->getErrorMsg('FILE_SIZE_ZERO');
			}

			// Get the image dimensions, in case this is an image
			list($img_width, $img_height) = @getimagesize($_FILES[$this->config['fieldname']]['tmp_name']);

			// Validate the dimensions, if they are all integers
			if (isset($img_width) && is_int($img_width) && isset($img_height) && is_int($img_height)) {
				$retval['is_image_upload'] = true;

				// Check if any of the dimensions of the uploaded file are OOB with respect to our validation rules
				if ($img_width < $this->config['min_img_width'] || $img_width > $this->config['max_img_width'] || $img_height < $this->config['min_img_height'] || $img_height > $this->config['max_img_height']) {
					$retval['errors']['IMAGE_DIMENSIONS_OOB'] = $this->getErrorMsg('IMAGE_DIMENSIONS_OOB', array('img_width' => $img_width, 'img_height' => $img_height));
				}
			} // Otherwise, signal an error if image dimension validation failed
			elseif (in_array($retval['file_extension'], $this->fileTypes['images'])) {
				$retval['errors']['INVALID_IMAGE_DIMENSIONS'] = $this->getErrorMsg('INVALID_IMAGE_DIMENSIONS');
			}

			// Establish the exact filename
			// If no filename is set in $this->config, use the uploaded filename
			if (empty($this->config['filename'])) {
				$retval['filename_raw'] = $_FILES[$this->config['fieldname']]['name'];
			}
			else {
				$retval['filename_raw'] = "{$this->config['filename']}.{$retval['file_extension']}";
			}

			// Strip out any base paths and file extensions
			$retval['filename_base'] = $this->config['filename_prefix'] . basename($retval['filename_raw'], '.' . $retval['file_extension']);

			// Sanitize the filename a.k.a. dirify
			if ($this->config['filename_sanitize']) {
				$retval['filename_base'] = $this->dirify($retval['filename_base']);
			}

			$filename_img_modifier_length = 0;
			if ($retval['is_image_upload'] && $this->config['filename_img_dimensions']) {
				$retval['filename_img_modifier'] = "-{$img_width}x{$img_height}";
				$filename_img_modifier_length = strlen($retval['filename_img_modifier']);
			}

			$real_filename_max_length = $this->config['filename_max_length'] - $filename_img_modifier_length - 1 - strlen($retval['file_extension']);

			// Truncate the filename to the maximum set in the config minus any modifier lengths
			$tmp_filename = mb_substr($retval['filename_base'], 0, $real_filename_max_length, 'utf-8');

			// Determine a new filename if the file already exists already existing file and 
			if (!$this->config['overwrite'] && $this->ciFileExists($this->config['upload_dir'] . $tmp_filename . $retval['filename_img_modifier'] . '.' . $retval['file_extension'])) {
				$i = 0;
				//  Keep renaming the filename until it's unique
				do {
					$i++;
					$retval['filename_count_modifier'] = "({$i})";
					$tmp_real_filename_max_length = $real_filename_max_length - strlen($retval['filename_count_modifier']);
					if ($tmp_real_filename_max_length < strlen($tmp_filename)) {
						$tmp_filename = mb_substr($retval['filename_base'], 0, $tmp_real_filename_max_length, 'utf-8');
					}

				} while ($this->ciFileExists($this->config['upload_dir'] . $tmp_filename . $retval['filename_img_modifier'] . $retval['filename_count_modifier'] . '.' . $retval['file_extension']));
			}

			// Set the new filename parts
			$retval['filename_base'] = $tmp_filename;
			$retval['filename'] = $retval['filename_base'] . $retval['filename_count_modifier'] . $retval['filename_img_modifier'];
			$retval['filename_full'] = $retval['filename'] . '.' . $retval['file_extension'];

			// Validate the filename
			if ($this->config['filename_validate']) {
				//Test against illegal characters and multiple periods
				/*if (! preg_match("/^[a-z][a-z0-9_\)\(\-\.]*$/i", $this->filename))
				{
					$retval['errors']['INVALID_FILENAME'] = $this->getErrorMsg('INVALID_FILENAME');
				}*/
				if (!preg_match("/^[a-z0-9][a-z0-9_\)\(\-\.]*$/i", $retval['filename_base']) || strpos($retval['filename_base'], '..') !== false) {
					$retval['errors']['INVALID_FILENAME'] = $this->getErrorMsg('INVALID_FILENAME');
				}

				// Validate file name length
				$filename_length = strlen($retval['filename_full']);
				if ($filename_length > $this->config['filename_max_length']) {
					$retval['errors']['FILENAME_TOO_LONG'] = $this->getErrorMsg('FILENAME_TOO_LONG', array('filename_length' => $filename_length));
				}
			}

			// Upload the file

			// ***********************************************************
			// Copy the file from the tmp folder to upload_dir
			if ($this->config['move_file'] && count($retval['errors']) == 0) {
				$file_path = utf8_decode($this->config['upload_dir'] . $retval['filename_full']);

				if (move_uploaded_file($_FILES[$this->config['fieldname']]['tmp_name'], $file_path)) {
					if (!chmod($file_path, $this->config['file_permissions'])) {
						trigger_error('UploadValidator class: Could not change file permissions of the uploaded file.', E_USER_NOTICE);
					}
				}
				else {
					$retval['errors']['MOVE_UPLOADED_FILE_FAILED'] = $this->getErrorMsg('MOVE_UPLOADED_FILE_FAILED');
				}
			}
			// End validate filename related fields
			// ***********************************************************
		}
		else {
			// No valid upload found. Check for errors.
			// The uploaded file exceeds the upload_max_filesize directive in php.ini.
			if ($_FILES[$this->config['fieldname']]['error'] == 1) {
				$retval['errors']['FILE_SIZE_TOO_LARGE'] = $this->getErrorMsg('FILE_SIZE_TOO_LARGE');
			}
			// The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
			elseif ($_FILES[$this->config['fieldname']]['error'] == 2) {
				$retval['errors']['FILE_SIZE_TOO_LARGE'] = $this->getErrorMsg('FILE_SIZE_TOO_LARGE');
			}
			// The uploaded file was only partially uploaded.
			elseif ($_FILES[$this->config['fieldname']]['error'] == 3) {
				$retval['errors']['FILE_UPLOAD_PARTIAL'] = $this->getErrorMsg('FILE_UPLOAD_PARTIAL');
			}
			// No file was uploaded.
			elseif ($_FILES[$this->config['fieldname']]['error'] == 4) {
				if ($this->config['upload_is_required']) {
					$retval['errors']['NO_FILE_UPLOADED'] = $this->getErrorMsg('NO_FILE_UPLOADED');
				}
			}
			// Missing a temporary folder. Introduced in PHP 4.3.10 and PHP 5.0.3.
			elseif ($_FILES[$this->config['fieldname']]['error'] == 6) {
				$retval['errors']['MISSING_TEMPORARY_FOLDER'] = $this->getErrorMsg('MISSING_TEMPORARY_FOLDER');
			}
			// Failed to write file to disk. Introduced in PHP 5.1.0.
			elseif ($_FILES[$this->config['fieldname']]['error'] == 7) {
				$retval['errors']['FAILED_WRITE_TO_DISK'] = $this->getErrorMsg('FAILED_WRITE_TO_DISK');
			}
			// File upload stopped by imgExtension. Introduced in PHP 5.2.0.
			/*elseif ($_FILES[$this->config['fieldname']]['error'] == 8)
			{
				$retval['errors']['STOPPED_BY_EXT'] = $this->getErrorMsg('STOPPED_BY_EXT');
			}
			elseif (empty($_FILES[$this->config['fieldname']]['tmp_name'])) // Special case
			{
				$retval['errors']['UNKNOWN_ERROR'] = $this->getErrorMsg('UNKNOWN_ERROR');
			}*/
			else {
				$retval['errors']['UNKNOWN_ERROR'] = $this->getErrorMsg('UNKNOWN_ERROR');
			}
			// End if validate file upload
		}
		return $retval;
	}

	/**
	 * Filename security
	 * @param    string
	 * @param    bool
	 * @return    string
	 */
	public function sanitizeFilename($str, $relative_path = false)
	{
		$bad = array(
			"../",
			"<!--",
			"-->",
			"<",
			">",
			"'",
			'"',
			'&',
			'$',
			'#',
			'{',
			'}',
			'[',
			']',
			'=',
			';',
			'?',
			"%20",
			"%22",
			"%3c", // <
			"%253c", // <
			"%3e", // >
			"%0e", // >
			"%28", // (
			"%29", // )
			"%2528", // (
			"%26", // &
			"%24", // $
			"%3f", // ?
			"%3b", // ;
			"%3d" // =
		);

		if (!$relative_path) {
			$bad[] = './';
			$bad[] = '/';
		}

		$str = $this->removeInvisibleCharacters($str, false);
		return stripslashes(str_replace($bad, '', $str));
	}

	/**
	 * Convert a readable byte count to numeric value
	 * @param $val
	 * @return int|string
	 */
	public function shorthandToBytes($val)
	{
		$val = trim($val);
		$last = strtolower($val[strlen($val) - 1]);
		switch ($last) {
			// The 'G' modifier is available since PHP 5.1.0
			case 'g':
				$val *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$val *= 1024 * 1024;
				break;
			case 'k':
				$val *= 1024;
			default:
		}
		return $val;
	}
}

/* End file UploadValidator.php */
