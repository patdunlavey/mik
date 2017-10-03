<?php

namespace mik\filegetters;

use GuzzleHttp\Client;
use mik\exceptions\MikErrorException;
use Monolog\Logger;

/**
 * File Getter Class for CONTENTdm monographs
 */
class CdmBooks extends FileGetter
{
    /**
     * @var string $inputDirectory - path to book collection.
     */
    //public $inputDirectory;

    /**
     * @var array $inputDirectories - array of paths to files for book collection.
     */
    public $inputDirectories;

    /**
     * @var array (dict) $OBJFilePaths - paths to OBJ files for collection
     */
    public $OBJFilePaths;

    /**
     * @var string $utilsUrl - CDM utils url.
     */
    public $utilsUrl;

    /**
     * @var string $alias - CDM alias
     */
    public $alias;

    /**
     * @var object $thumbnail - filemanipulators class for helping
     * create thumbnails from CDM
     */
    private $thumbnail;

    /**
     * @var object cdmSingleFileGetter - filegetter class for
     * getting files related to CDM single file objects.
     */
    public $cdmSingleFileGetter;

    /**
     * Create a new CONTENTdm Fetcher Instance
     * @param array $settings configuration settings.
     */
    public function __construct($settings)
    {
        parent::__construct($settings);
        $this->utilsUrl = $this->settings['utils_url'];
        $this->alias = $this->settings['alias'];

        $this->cdmSingleFileGetterSettings = $settings;
        $this->cdmSingleFileGetter = new \mik\filegetters\CdmSingleFile($this->cdmSingleFileGetterSettings);

        $this->inputDirectories = !empty($this->settings['input_directories']) ? $this->settings['input_directories'] : array();

        // interate over inputDirectories to create $potentialObjFiles array.
        $potentialObjFiles = array();
        foreach ($this->inputDirectories as $inputDirectory) {
            $potentialObjFilesPart = $this
                ->getBookMasterFiles($inputDirectory);
            $potentialObjFiles = array_merge($potentialObjFiles, $potentialObjFilesPart);
        }
        //var_dump($potentialObjFiles);
        //exit();
        $this->OBJFilePaths = $this->determineObjItems($potentialObjFiles);
        // information and methods for thumbnail minipulation
        $this->thumbnail = new \mik\filemanipulators\ThumbnailFromCdm($settings);

        if (!isset($this->settings['http_timeout'])) {
            // Seconds.
            $this->settings['http_timeout'] = 60;
        }
        // Default Mac PHP setups may use Apple's Secure Transport
        // rather than OpenSSL, causing issues with CA verification.
        // Allow configuration override of CA verification at users own risk.
        if (isset($settings['SYSTEM']['verify_ca'])) {
            if ($settings['SYSTEM']['verify_ca'] == false) {
                $this->verifyCA = false;
            }
        } else {
            $this->verifyCA = true;
        }

        // Set up logger.
        $this->pathToLog = $settings['LOGGING']['path_to_log'];
        $this->log = new \Monolog\Logger('CdmBooks filegetter');
        $this->logStreamHandler = new \Monolog\Handler\StreamHandler(
          $this->pathToLog,
          Logger::ERROR
        );
        $this->log->pushHandler($this->logStreamHandler);

    }

    /**
     * Gets a compound item's children pointers. $alias needs to include the leading '/'.
     * @ToDo - clerify whether this method should be part of filegetters or fetchers.
     */
    public function getChildren($pointer)
    {
        $alias = $this->settings['alias'];
        $ws_url = $this->settings['ws_url'];
        $query_url = $ws_url . 'dmGetCompoundObjectInfo/' . $alias . '/' .  $pointer . '/json';

        $item_structure = file_get_contents($query_url);
        $item_structure = json_decode($item_structure, true);
        $children_pointers = array();

        /* CONTENTdm supports hierarchical books.  "Flatten" structure of hierarchical
           source books for importing into Islandora since Islandora's Book Solution Pack
           currently only supports flat books.
        */
        if ($item_structure['type'] == 'Monograph') {
            // flatten document structure
            // hierarchy based on nodes
            // Iterator snippet below based on
            // http://stackoverflow.com/a/1019534/850828
            // @ToDo snippet produces duplicate pointers - why?
            $arrIt = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($item_structure));
            foreach ($arrIt as $sub) {
                $subArray = $arrIt->getSubIterator();
                if (isset($subArray['pageptr'])) {
                    $children_pointers[] = $subArray['pageptr'];
                }
            }
            // remove duplicate pointers
            $children_pointers = array_unique($children_pointers);
            // reindex the array.
            $children_pointers = array_values($children_pointers);
        }

        if ($item_structure['type'] == 'Document') {
            if (isset($item_structure['page'])) {
                $children = $item_structure['page'];
            } else {
                return array();
            }
            foreach ($children as $child) {
                $children_pointers[] = $child['pageptr'];
            }
        }

        return $children_pointers;
    }


    public function getIssueLocalFilesForOBJ($record_key)
    {
        // Get the paths to the master files (typically .TIFFs)
        // to use for the OBJ.tiff of each newspaper page.
        // Deal on an book-by-book bassis.

        $key = DIRECTORY_SEPARATOR . $record_key . DIRECTORY_SEPARATOR;
        if (!empty($this->OBJFilePaths[$key])) {
            return $this->OBJFilePaths[$key];
        }
        else {
            $this->log->addWarning("CdmBooks filegetter", array('getIssueLocalFilesForOBJ' => 'No object files path found for ' . $key));
            return array();
        }
    }

    private function getBookMasterFiles($pathToBook, $allowedFileTypes = array('tiff', 'tif'))
    {
        $potentialFilesArray = array();

        $iterator = new \RecursiveDirectoryIterator($pathToBook);
        $display = $allowedFileTypes;
        $iteratorIterator = new \RecursiveIteratorIterator($iterator);

        foreach ($iteratorIterator as $file) {
            $file_parts = explode('.', $file);
            if (in_array(strtolower(array_pop($file_parts)), $display)) {
                $potentialFilesArray[] = $file->__toString();
            }
        }

        return $potentialFilesArray;
    }

    private function determineObjItems($arrayOfFilesToPreserve)
    {
        // For book pages

        /*
            This regex will looks for a pattern like book_nick001 in the path for the files
            of a particular book.

            Pattern assumption:  ../record_pointer/file.extension
        */
        $regex_pattern = '%[/\\\\][0-9]*[/\\\\]%';

        $keyForIdentifierArray = array();
        foreach ($arrayOfFilesToPreserve as $path) {
            //print $path . "\n";
            preg_match($regex_pattern, $path, $matches);
            if ($matches) {
                array_push($keyForIdentifierArray, $matches[0]);
            }
        }
        $keyForIdentifierArray = array_unique($keyForIdentifierArray);

        $dictOfItems = array();
        foreach ($keyForIdentifierArray as $keyIdentifier) {
            $tempItemList = array();
            foreach ($arrayOfFilesToPreserve as $filepath) {
                if (stristr($filepath, $keyIdentifier)) {
                    array_push($tempItemList, $filepath);
                }
            }

            if (count($tempItemList) > 0) {
                $dictOfItems[$keyIdentifier] = $tempItemList;
            }
        }

        return $dictOfItems;
    }

    public function getThumbnailcontent($page_pointer, $thumbnail_height = 200)
    {
        // Get a JPEG to use as the Islandora thumbnail,
        // which should be 200 pixels high. The filename should be TN.jpg.
        // See http://www.contentdm.org/help6/custom/customize2aj.asp for CONTENTdm API docs.
        // Based on a target height of 200 pixels, get the scale value.

        $image_info = $this->thumbnail->getImageScalingInfo($page_pointer);

        if(!empty($image_info['width']) && !empty($image_info['height'])) {
            $scale = $thumbnail_height / $image_info['width'] * 100;
            $new_height = round($image_info['height'] * $scale / 100);
            $get_image_url_thumbnail = $this->utilsUrl . 'ajaxhelper/?CISOROOT=' .
              ltrim($this->alias, '/') . '&CISOPTR=' . $page_pointer .
              '&action=2&DMSCALE=' . $scale. '&DMWIDTH='. $thumbnail_height . 'DMHEIGHT=' . $new_height;
            $thumbnail_content = file_get_contents($get_image_url_thumbnail);

            return $thumbnail_content;
        }
        else {
            $get_thumbnail_url = $this->utilsUrl .'getthumbnail/collection/' . $this->alias . '/id/' . $page_pointer;

            $client = new Client();
            try {
                $response = $client->get(
                  $get_thumbnail_url,
                  ['timeout' => $this->settings['http_timeout'],
                    'connect_timeout' => $this->settings['http_timeout'],
                    'verify' => $this->verifyCA]
                );
                $content = $response->getBody();
                return $content;
            } catch (RequestException $e) {
                $this->log->addError("CdmNewspapers Guzzle error", array('HTTP request error' => $e->getRequest()));
                if ($e->hasResponse()) {
                    $this->log->addError("CdmNewspapers Guzzle error", array('HTTP request response' => $e->getResponse()));
                }
            }
        }
    }

    public function getPreviewJPGContent($page_pointer, $jpeg_height = 800)
    {
        // Get a JPEG to use as the Islandora preview image,
        // which should be 800 pixels high. The filename should be JPG.jpg.
        $image_info = $this->thumbnail->getImageScalingInfo($page_pointer);

        if(!empty($image_info['width']) && !empty($image_info['height'])) {
            $scale = $jpeg_height / $image_info['width'] * 100;
            $new_height = round($image_info['height'] * $scale / 100);
            $get_image_url_jpg = $this->utilsUrl . 'ajaxhelper/?CISOROOT='
              . ltrim($this->alias, '/') . '&CISOPTR=' . $page_pointer
              . '&action=2&DMSCALE=' . $scale . '&DMWIDTH=' . $jpeg_height
              . '&DMHEIGHT=' . $new_height;
            $jpg_content = file_get_contents($get_image_url_jpg);
            return $jpg_content;
        }

    }

    public function getChildLevelFileContent($page_pointer, $page_object_info)
    {
        // Retrieve the file associated with the child-level object. In the case of
        // the Chinese Times and some other newspapers, this is a JPEG2000 file.
        $get_file_url = $this->utilsUrl .'getfile/collection/'
          . $this->alias . '/id/' . $page_pointer . '/filename/'
          . $page_object_info['find'];

        $client = new Client();
        try {
            $response = $client->get(
              $get_file_url,
              ['timeout' => $this->settings['http_timeout'],
                'connect_timeout' => $this->settings['http_timeout'],
                'verify' => $this->verifyCA]
            );
            $content = $response->getBody();
            return $content;
        } catch (RequestException $e) {
            $this->log->addError("CdmNewspapers Guzzle error", array('HTTP request error' => $e->getRequest()));
            if ($e->hasResponse()) {
                $this->log->addError("CdmNewspapers Guzzle error", array('HTTP request response' => $e->getResponse()));
            }
        }
    }

    public function getPageOBJfileContent($pathToFile, $page_number)
    {
        // Check path page tiffs should be in the format yyyy-mm-dd-pp.
        // @ToDo - move this method to FileGetter parent class
        // to be extended in child classes such as CdmNewspapers

        //$regex_pattern = '%[/\\\\][0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9]*' . $page_number . '%';
        //$result = preg_match($regex_pattern, $pathToFile);
        $result = 1;
        if ($result === 1) {
            // file_get_contents returns false on failure.
            $obj_content = file_get_contents($pathToFile);
        } else {
            // log
            // file_get_contents returns false on failure.
            $obj_content = false;
        }

        return $obj_content;
    }

    public function checkBookPageFilePath($pathToFile, $page_number)
    {
        // Check path page tiffs should be in the format yyyy-mm-dd-pp.
        // @ToDo - move this method to FileGetter parent class
        // to be extended in child classes such as CdmNewspapers

        //$regex_pattern = '%[/\\\\][0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9]*' . $page_number . '%';
        //$result = preg_match($regex_pattern, $pathToFile);
        $result = 1;
        if ($result === 1) {
            return true;
        } else {
            return false;
        }
    }

    public function getCpdFile($pointer)
    {
        $ws_url = $this->settings['ws_url'];
        $alias = $this->alias;
        $query_url = $ws_url . 'dmGetCompoundObjectInfo/' . $alias . '/' .  $pointer . '/xml';
        $cpd_content = file_get_contents($query_url);
        return $cpd_content;
    }
}
