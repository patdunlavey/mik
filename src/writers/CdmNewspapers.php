<?php

namespace mik\writers;

use Monolog\Logger;

class CdmNewspapers extends Writer
{
    /**
     * @var array $settings - configuration settings from confugration class.
     */
    public $settings;

    /**
     * @var object $fetcher - fetcher class for item info methods.
     */
    private $fetcher;

    /**
     * @var object $thumbnail - filemanipulators class for helping
     * create thumbnails from CDM
     */
    private $thumbnail;

    /**
     * @var object cdmNewspapersFileGetter - filegetter class for
     * getting files related to CDM Newspaper issues.
     */
    private $cdmNewspapersFileGetter;

    /**
     *  @var $issueDate - newspaper issue date.
     */
    public $issueDate = '0000-00-00';

    public $issue_date_field;

    /**
     * @var string $outputSubdirectoryFields - comma-separated list of xpath queries
     * to the mods xml whose values define subdirectories of the output directory to write
     * to. E.g. "//*[local-name()='title' and string-length(@type)=0]" will create a subdirectory
     * based on the main title of the newspaper.
     */
    public $outputSubdirectoryFieldQueries = array();

    public $baseOutputDirectory;

    /**
     * @var $alias - collection alias
     */
    public $alias;

    /**
     * @var string $metadataFileName - file name for metadata file to be written.
     */
    public $metadataFileName;

    /**
     * @var object metadataparser - metadata parser object
     */
    public $metadataParser;

    /**
     * @var array $nickname_ocr - ordered list of nicknames
     * which may contain ocr text. The first one found will be used.
     */
    public $ocrNicknames = array('full', 'fullte');

    /**
     * @var boolean $serialPageNumbers - Whether pages should be numbered in the order received.
     */
    public $serialPageNumbers = 0;

    /**
     * Create a new newspaper writer Instance
     * @param array $settings configuration settings.
     */
    public function __construct($settings)
    {
        parent::__construct($settings);
        $this->baseOutputDirectory = $this->outputDirectory;
        $this->fetcher = new \mik\fetchers\Cdm($settings);
        $this->alias = $settings['WRITER']['alias'];
        // @Todo load manipulators someway based on those to be listed in config.
        $this->thumbnail = new \mik\filemanipulators\ThumbnailFromCdm($settings);
        $fileGetterClass = 'mik\\filegetters\\' . $settings['FILE_GETTER']['class'];
        $this->cdmNewspapersFileGetter = new $fileGetterClass($settings);
        if (isset($this->settings['metadata_filename'])) {
            $this->metadataFileName = $this->settings['metadata_filename'];
        } else {
            $this->metadataFileName = 'MODS.xml';
        }

        if (isset($this->settings['WRITER']['ocr_nickname'])) {
            array_unshift($this->ocrNicknames, $this->settings['WRITER']['ocr_nickname']);
        }

        if (isset($this->settings['WRITER']['serial_page_numbering'])) {
            $this->serialPageNumbers = $this->settings['WRITER']['serial_page_numbering'];
        }
        if (isset($this->settings['WRITER']['issue_date_field'])) {
            $this->issue_date_field = $this->settings['WRITER']['issue_date_field'];
        }

        // If OBJ_file_extension was not set in the configuration, default to tiff.
        if (!isset($this->OBJ_file_extension)) {
            $this->OBJ_file_extension = 'tiff';
        }

        if (isset($this->settings['WRITER']['output_subdirectory_field_queries'])) {
            $this->outputSubdirectoryFieldQueries = str_getcsv($this->settings['WRITER']['output_subdirectory_field_queries']);
        }

        $metadtaClass = 'mik\\metadataparsers\\' . $settings['METADATA_PARSER']['class'];
        $this->metadataParser = new $metadtaClass($settings);

        // Set up logger.
        $this->pathToLog = $this->settings['LOGGING']['path_to_log'];
        $this->log = new \Monolog\Logger('Writer');
        $this->logStreamHandler = new \Monolog\Handler\StreamHandler(
            $this->pathToLog,
            Logger::INFO
        );
        $this->log->pushHandler($this->logStreamHandler);
    }

    /**
     * Write folders and files.
     */
    public function writePackages($metadata, $pages, $record_key)
    {

        $this->alterOutputDirectory($metadata);

        // Create root output folder
        $this->createOutputDirectory();

        $issueObjectPath = $this->createIssueDirectory($metadata, $record_key);
        $this->writeMetadataFile($metadata, $issueObjectPath);


        $cpdData = $this->cdmNewspapersFileGetter->getCpdFile($record_key);
        $pagePtrPageTitleMap = $this->cpdPagePtrPageTitleMap($cpdData);

        // If there were no datastreams explicitly set in the configuration,
        // set flag so that all datastreams in the writer class are run.
        // $this->datareams is an empty array by default.
        $no_datastreams_setting_flag = false;
        if (count($this->datastreams) == 0) {
            $no_datastreams_setting_flag = true;
        }

        // filegetter for OBJ.tiff files for newspaper issue pages
        $OBJ_expected = in_array('OBJ', $this->datastreams);
        if ($OBJ_expected xor $no_datastreams_setting_flag) {
            $OBJFilesArray = $this->cdmNewspapersFileGetter
                    ->getIssueLocalFilesForOBJ($this->issueDate);
            // Array of paths to tiffs for OBJ for newspaper issue pages may not be sorted
            // on some systems.  Sort.
            sort($OBJFilesArray);
        } else {
            // No OBJ source files
            $OBJFilesArray = array();
        }

        $sub_dir_num = 0;
        foreach ($pages as $page_pointer) {
            $sub_dir_num++;

            // Create subdirectory for each page of newspaper issue
            $page_object_info = $this->fetcher->getItemInfo($page_pointer);

            $filekey = $sub_dir_num - 1;

            if (!empty($OBJFilesArray[$filekey]) && ($OBJ_expected xor $no_datastreams_setting_flag)) {
                $pathToFile = $OBJFilesArray[$filekey];
            }

            if($this->serialPageNumbers) {
                $directoryNumber = $sub_dir_num;
            }
            elseif (!empty($pathToFile)) {
                // Infer the numbered directory name from the OBJ file name.
                $directoryNumber = $this->directoryNameFromFileName($pathToFile);
            } else {
                // Infer the numbered directory name from  $page_object_info
                $directoryNumber = $this->directoryNameFromPageObjectInfo($pagePtrPageTitleMap, $page_object_info);
            }
            // left trim leading left zero padded numbers
            $directoryNumber = ltrim($directoryNumber, "0");

            $page_dir = $issueObjectPath  . DIRECTORY_SEPARATOR . $directoryNumber;

            if (!empty($page_object_info['page'])) {
                $page_title = (is_numeric($page_object_info['page']) ? 'Page ' : '') . $page_object_info['page'];
            }
            else {
                $page_title = 'Page ' . $directoryNumber;
            }

            // Create a directory for each day of the newspaper.
            if (!file_exists($page_dir)) {
                mkdir($page_dir, 0777, true);
            }

            if (isset($page_object_info['code']) && $page_object_info['code'] == '-2') {
                continue;
            }

            print "Exporting files for issue " . $this->issueDate
              . ', ' . $page_title . "\n";

            // Write out the OCR datastream.
            $OCR_expected = in_array('OCR', $this->datastreams);
            if ($OCR_expected xor $no_datastreams_setting_flag) {
                $ocr_output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'OCR.txt';
                $ocr_nickname_found = FALSE;
                foreach ($this->ocrNicknames as $ocrNickname) {
                    if (isset($page_object_info[$ocrNickname])) {
                        file_put_contents($ocr_output_file_path, $page_object_info[$ocrNickname]);
                        $ocr_nickname_found = TRUE;
                        break;
                    }
                }
                if (!$ocr_nickname_found) {
                    $this->log->addNotice(
                      "CdmNewspapers writer notice",
                      array('OCR was expected, but none found.  Possibly unknown Cdm nickname.' => array('ocr_nicknames' => $this->ocrNicknames))
                    );
//                    throw new \Exception("Problem creating OCR.txt.  Possibly unknown Cdm nickname.");
                }
            }

            // Retrieve the file associated with the child-level object. In the case of
            // the Chinese Times and some other newspapers, this is a JPEG2000 file.
            $JP2_expected = in_array('JP2', $this->datastreams);
            if ($JP2_expected xor $no_datastreams_setting_flag) {
                $jp2_content = $this->cdmNewspapersFileGetter
                    ->getChildLevelFileContent($page_pointer, $page_object_info);
                $jp2_output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'JP2.jp2';
                file_put_contents($jp2_output_file_path, $jp2_content);
            }

            // @ToDo: Determine if it's better to use $image_info as a parameter
            // in getThumbnailcontent and getPreviewJPGContent - as this
            // may reduce the number of API calls by 1.
            //$image_info = $this->thumbnail->getImageScalingInfo($page_pointer);

            // Get a JPEG to use as the Islandora thubnail,
            // which should be 200 pixels high. The filename should be TN.jpg.
            // See http://www.contentdm.org/help6/custom/customize2aj.asp for CONTENTdm API docs.
            // Based on a target height of 200 pixels, get the scale value.
            $TN_expected = in_array('TN', $this->datastreams);
            if ($TN_expected xor $no_datastreams_setting_flag) {
                $thumbnail_content = $this->cdmNewspapersFileGetter
                                      ->getThumbnailcontent($page_pointer);
                $thumbnail_output_file_path = $page_dir . DIRECTORY_SEPARATOR .'TN.jpg';
                file_put_contents($thumbnail_output_file_path, $thumbnail_content);
                if ($sub_dir_num == 1) {
                    // Use the first thumbnail for the first page as thumbnail for the
                    // entire newspaper issue.
                    $issue_thumbnail_path = $issueObjectPath  . DIRECTORY_SEPARATOR . 'TN.jpg';
                    copy($thumbnail_output_file_path, $issue_thumbnail_path);
                }
            }

            // Get a JPEG to use as the Islandora preview image,
            //which should be 800 pixels high. The filename should be JPG.jpg.
            $JPEG_expected = in_array('JPEG', $this->datastreams);
            if ($JPEG_expected xor $no_datastreams_setting_flag) {
                $jpg_content = $this->cdmNewspapersFileGetter
                                ->getPreviewJPGContent($page_pointer);
                $jpg_output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'JPEG.jpg';
                file_put_contents($jpg_output_file_path, $jpg_content);
            }

            $OBJ_expected = in_array('OBJ', $this->datastreams);
            if (!empty($pathToFile) && ($OBJ_expected xor $no_datastreams_setting_flag)) {
                // OBJ from source files - either TIFF, jpeg, or jp2
                $pathToPageOK = $this->cdmNewspapersFileGetter
                   ->checkNewspaperPageFilePath($pathToFile, $directoryNumber);

                if ($pathToPageOK) {
                    $obj_output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'OBJ.' . $this->OBJ_file_extension;
                    // assumes that the source destination is on a l
                    copy($pathToFile, $obj_output_file_path);
                } else {
                    // if the path to the page is NOT OK, throw an exception
                    throw new \Exception("The path $pathToFile for the OBJ file for page $directoryNumber" .
                      "did not pass the check.");
                }
            } elseif (!$this->skip_obj) {
                // The OBJ datastream is required:
                // see:
                // https://github.com/Islandora/islandora_paged_content/blob/7.x/xml/islandora_pageCModel_ds_composite_model.xml
                // Only skip if the skip_obj flag is set to true in the WRITER seection of the configuration file.
                // If using tiff datastream from external source, datastreams[] = OBJ
                // in the WRITER section of the configuration file or do not include datastreams[] option to create
                // all datastreams
                // MODS, OBJ, OCR, JPEG, JP2
                if (!empty($jp2_output_file_path)) {
                    print "Local source file not found. Using JP2.\n";
                    $obj_jp2_output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'OBJ.jp2';
                    copy($jp2_output_file_path, $obj_jp2_output_file_path);
                } elseif ($JPEG_expected) {
                    print "Local source file not found. Using JPG.\n";
                    $obj_jpeg_output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'OBJ.jpg';
                    copy($jpg_output_file_path, $obj_jpeg_output_file_path);
                }
                else {
                    print "Local source file not found. Attempting to pull from ContentDM.\n";
                    try {
                        $temp_file_path = $this->cdmNewspapersFileGetter->cdmSingleFileGetter->getFileContent($page_pointer);
                        // Get the filename used by CONTENTdm (stored in the 'find' field)
                        // so we can grab the extension.
                        $item_info = $this->fetcher->getItemInfo($page_pointer);
                        $source_file_extension = pathinfo($item_info['find'], PATHINFO_EXTENSION);
                        $output_file_path = $page_dir . DIRECTORY_SEPARATOR . 'OBJ' . '.' . $source_file_extension;
                        rename($temp_file_path, $output_file_path);
                    }
                    catch (Exception $e) {
                        $this->log->addError(
                          "CdmNewspapers writer error",
                          array('Error writing page content file' => $e->getMessage())
                        );
                        print "Source file download from ContentDM failed.\n";
                    }
                }

            }

            // Write outut page level MODS.XML
            $MODS_expected = in_array('MODS', $this->datastreams);
            if ($MODS_expected xor $no_datastreams_setting_flag) {
                $this->writePageLevelMetadaFile($page_pointer, $page_title, $page_dir);
            }
        }
    }

    /**
     * Infer the numbered name for the newspaper issue page subdirectory from the OJB file name.
     */
    public function directoryNameFromFileName($pathToOBJfile)
    {

          $path_parts = pathinfo($pathToOBJfile);
          //1988-07-13-01
          $filename = $path_parts['filename'];
          $regex = '%[0-9]*$%';
          $result = preg_match($regex, $filename, $matches);

        if ($matches[0] == '') {
            // $result evaluates to false
            // There is a problem with the filename
            throw new \Exception("CdmNewspaper Writer: The filename $filename did not match the regex " .
                "required for creating the issue page directory name.");
        }

          // remove possible left zero padded number.
          $pageNumber = ltrim($matches[0]);
          return $pageNumber;
    }

    public function alterOutputDirectory($metadata)
    {
        if (!empty($this->outputSubdirectoryFieldQueries)) {

            $doc = new \DomDocument('1.0');
            $doc->loadXML($metadata);
            $xpath = new \DOMXPath($doc);
            $this->outputDirectory = $this->baseOutputDirectory;
            foreach ($this->outputSubdirectoryFieldQueries as $query) {
                foreach ($xpath->query($query) as $node) {
                    if (!empty($node->nodeValue)) {
                        $this->outputDirectory .= DIRECTORY_SEPARATOR . preg_replace("/\W/", "", $node->nodeValue);
                    }
                }
            }
        }
    }

    /**
     * Create the output directory specified in the config file.
     */
    public function createOutputDirectory()
    {
        parent::createOutputDirectory();
    }

    public function createIssueDirectory($metadata, $record_key)
    {
        //value of dateIssued isuse is the the title for the directory

        $doc = new \DomDocument('1.0');
        $doc->loadXML($metadata);
//        var_dump(array('metadata' => $metadata));
        $issue_date_tag = !empty($this->issue_date_field) ? $this->issue_date_field : 'dateIssued';
        $nodes = $doc->getElementsByTagName($issue_date_tag);

        // There may be more than one 'dateIssued' node
        // use the one with keyDate and metadataminipulator to
        // manipulate date to yyyy-mm-dd format.
        if ($nodes->length == 1) {

            $this->issueDate = trim($nodes->item(0)->nodeValue);
        } else {
            foreach ($nodes as $item) {
                foreach ($item->attributes as $attribute) {
                    if ($attribute->name == 'keyDate' &&  $attribute->nodeValue == 'yes') {
                        $this->issueDate = $item->nodeValue;
                    }
                }
            }
        }


        $issueDateTime = strtotime($this->issueDate);
        if ($issueDateTime) {
            $this->issueDate = date('Y-m-d', $issueDateTime);
        }
        else {
            $parsed_date = array_intersect_key(date_parse($this->issueDate), array('year' => 1, 'month' => 1, 'day' => 1));
            if(count($parsed_date > 0)) {
                array_walk($parsed_date, array($this, 'pad_date_fields'));
                $this->issueDate = implode('-', $parsed_date);
            }
        }
        $issueObjectPath = $this->outputDirectory . DIRECTORY_SEPARATOR . $this->issueDate;

        // if the issue level directory already exists, we are dealing with a possible
        // duplicate (or more) upload into CDM.  Create additional directories with
        // #\d\d\d\d-\d\d\-\d\d\.\d# naming convention and log possible duplicate Cdm
        // object so that the best choice(s) for the issue are selected during QA prior
        // to batch ingest into Islandora or other systems.
        $multipleIssueNumber = 0;
        while (file_exists($issueObjectPath) == true) {
            // log that the issue directory already exists and may indicate that the newspaper
            // issue may already exit in the output directory or that more than one Cdm
            // pointer refers to the same newspaper issue (multiple Cdm upload for same
            // newspaper issue.
            $this->log->addInfo(
                "CdmNewspaperWriter",
                array(
                    'Newspaper issue already exits in output directory:' => $issueObjectPath,
                    'pointer:' => $record_key
                )
            );

            $multipleIssueNumber += 1;
            $issueObjectPath = $this->outputDirectory . DIRECTORY_SEPARATOR . $this->issueDate;
            $issueObjectPath .= "." . $multipleIssueNumber;
        }

        if (!file_exists($issueObjectPath)) {
            mkdir($issueObjectPath);
            // return issue_object_path for use when writing files.
            return $issueObjectPath;
        }
    }

    /**
     * Infer the number name for the newspaper page subdirectory from the page_object_info metadata
     */
    public function directoryNameFromPageObjectInfo($pagePtrPageTitleMap, $page_object_info)
    {
        static $last_pagenumber = NULL;

        $pageptr = $page_object_info['dmrecord'];
        $pagetitle = $pagePtrPageTitleMap[$pageptr];
        // Page 312
        $regex = '%[0-9]*$%';
        preg_match($regex, $pagetitle, $matches);
        $pageNumber = ltrim($matches[0]);
        if(empty($pageNumber)) {
            if (empty($last_pagenumber)) {
                $pageNumber = 0;
            }
            else {

            }
        }
//        var_dump(get_defined_vars());
        return $pageNumber;
    }

    /**
     *  Takes Cdm newspaper .cpd Data
     */
    public function cpdPagePtrPageTitleMap($cpdData)
    {
        $doc = new \DomDocument('1.0');
        $doc->loadXML($cpdData);
        $pages = $doc->getElementsByTagName('page');

        $returnMap = array();
        foreach ($pages as $page_info) {
            $childNodes = $page_info->childNodes;
            unset($pageptr);
            unset($pagetitle);
            foreach ($childNodes as $child) {
                if ($child->nodeName == 'pageptr') {
                    $pageptr = $child->nodeValue;
                }
                if ($child->nodeName == 'pagetitle') {
                    $pagetitle = $child->nodeValue;
                }
            }
            if (isset($pageptr) && isset($pagetitle)) {
                $returnMap[$pageptr] = $pagetitle;
            }
        }

        return $returnMap;
    }

    public function writeMetadataFile($metadata, $path)
    {
        // Add XML decleration
        $doc = new \DomDocument('1.0');
        $doc->loadXML($metadata);
        $doc->formatOutput = true;
        $metadata = $doc->saveXML();

        $filename = $this->metadataFileName;
        if ($path !='') {
            $filecreationStatus = file_put_contents($path . DIRECTORY_SEPARATOR . $filename, $metadata);
            if ($filecreationStatus === false) {
                echo "There was a problem exporting the metadata to a file.\n";
            } else {
                // echo "Exporting metadata file.\n";
            }
        }
    }

    public function writePageLevelMetadaFile($page_pointer, $page_title, $page_dir)
    {
        $metadata = $this->metadataParser->createPageLevelModsXML($page_pointer, $page_title);
        //$metadata = '<mods>'. gettype($this->metadataParser) . '</mods>';
        //$metadata = '<mods></mods>';

        // Add XML decleration
        $doc = new \DomDocument('1.0');
        $doc->loadXML($metadata);
        $doc->formatOutput = true;
        $metadata = $doc->saveXML();

        $filename = $this->metadataFileName;
        if ($page_dir != '') {
            $filecreationStatus = file_put_contents($page_dir . DIRECTORY_SEPARATOR . $filename, $metadata);

            if ($filecreationStatus === false) {
                echo "There was a problem exporting the metadata to a file.\n";
                return false;
            } else {
                // echo "Exporting metadata file.\n";
                return true;
            }
        }
    }

    private function pad_date_fields(&$item1) {
        $item1 = str_pad($item1, 2, '0', STR_PAD_LEFT);
    }

}
