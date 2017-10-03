<?php
// src/filegettermanipulators/ColgateYB01FilePath.php

namespace mik\filegettermanipulators;

use \Monolog\Logger;

/**
 * CdmSingleFile - Get the path to the master (OBJ) file for the current object.
 *
 * This manipulator expects four or five parameters, a metadata field name,
 * a comma-separated list of file extensions, regex to pull values out
 * of the metadata field, and a replacement string, into which the matches
 * will be inserted to produce the master file paths.
 * If a fifth parameter is supplied, it will be taken as a function name
 * to run on the completed file path. The idea is to use simple functions
 * like 'strtolower'.
 * Replacement string syntax is written out as the original replacement
 * string, with matches identified by a number inside curly braces. Non-numeric
 * characters inside the curly braces will be prepended or appended to the
 * matched value only if the match itself is present.
 * Example:
 * Metadata field value: "Salmagundi - 1934 Junior"
 * identifier_regex = '.*(\d{4})\W*(\w*)'
 * this will produce array(0 => "Salmagundi - 1934 Junior", 1 => "1934", 2 => "Junior")
 * filepath_replace = 'colgate-{1}{-2}/colgate-{1}{-2}'
 * Using the replacement values from the identifier values, it will produce
 * 'colgate-1934-Junior/colgate-1934-Junior'
 * Finally, with the last parameter being "strtolower", it will produce
 * 'colgate-1934-junior/colgate-1934-junior'
 */
class ColgateYB01FilePath extends Filegettermanipulator
{
    private $sourceField;
    private $extensions;
    private $identifierRegEx;
    private $replacementString;
    private $filestringCallback;


    /**
     * Create a new CdmSingleFile instance
     */
    public function __construct($settings, $paramsArray, $record_key)
    {
        parent::__construct($settings, $paramsArray, $record_key);

        // Set up logger.
        $this->pathToLog = $settings['LOGGING']['path_to_manipulator_log'];
        $this->log = new \Monolog\Logger('config');
        $this->logStreamHandler = new \Monolog\Handler\StreamHandler(
            $this->pathToLog,
            Logger::INFO
        );
        $this->log->pushHandler($this->logStreamHandler);

        if (count($paramsArray) >= 4) {
            list($this->sourceField, $this->extensions, $this->identifierRegEx, $this->replacementString, $this->filestringCallback) = $paramsArray;
            $this->extensions = explode(',', $this->extensions);
            if(!empty($paramsArray[4])) {
                $this->filestringCallback = $paramsArray[4];
            }

        } else {
            $this->log->addError(
                "CdmSingleFile",
                array('Incorrect number of parameters' => count($paramsArray))
            );
        }
    }

    /*
     * Generates possible filepaths for master files.
     *
     * @return mixed
     *    An array of possible file paths, or false if none can be generated.
     */
    public function getMasterFilePaths() {

//        static $return = NULL;
//
//        if ($return === NULL) {

            $metadata_path = $this->settings['FETCHER']['temp_directory'] . DIRECTORY_SEPARATOR .
              $this->record_key . '.metadata';
            $metadata = unserialize(file_get_contents($metadata_path));

            if (isset($this->settings['FILE_GETTER']['input_directories'])) {
                if (isset($metadata[$this->sourceField]) && is_string($metadata[$this->sourceField])) {
                    $possibleMasterFilePaths = array();
                    
                    // Get the filename from the value of $this->sourceField.
                    $identifier = $metadata[$this->sourceField];
                    
                    // Initialize the 
                    $file_path_base = $this->replacementString;
                    
                    // Pull values from metadata field using identifier regex.
                    preg_match("/" . $this->identifierRegEx . "/", $identifier, $identifier_tokens);

                    // Pull out portions of replacement string that contain tokens.
                    preg_match('/({\W*\d+})/', $this->replacementString, $replacementStringTokens);
                    $replacementStringTokens = array_unique($replacementStringTokens);

                    // Loop through the tokens, getting their corresponding value
                    // from the metadata identifier field, and insert them into
                    // the base_name.
                    foreach ($replacementStringTokens as $replacementStringToken) {
                        $string = trim($replacementStringToken, '{}');
                        // Pull out the part of the token that identifies the offset for
                        // the identifier matches.
                        preg_match('/(\D*)(\d*)(\D*)/', $string, $parsed_string);
                        // Replace the value from identifier string into the token.
                        array_shift($parsed_string);
                        $parsed_string[1] = $identifier_tokens[$parsed_string[1]];
                        $value = implode('', $parsed_string);
                        // Find the original tokens in $file_path_base and replace them.
                        str_replace($replacementStringToken, $value, $file_path_base);
                        if (!empty($this->filestringCallback) && function_exists($this->filestringCallback)) {
                            $file_path_base = $this->filestringCallback($file_path_base);
                        }
                    }

                    foreach ($this->settings['FILE_GETTER']['input_directories'] as $input_directory) {
                        foreach ($this->extensions as $ext) {
                            $master_file_path = $input_directory . DIRECTORY_SEPARATOR .
                              $file_path_base . '.' . $ext;
                            $possibleMasterFilePaths[$this->record_key][] = $master_file_path;
                        }
                    }
                    $return = $possibleMasterFilePaths;
                }
                else {
                    // Log that we can't get the sourcefield.
                    $this->log->addError(
                      "CdmSingleFile",
                      array('Metadata error' => "Can't get value of source field")
                    );
                    $return = FALSE;
                }
            }
            else {
                // Log that there is no input directory.
                $this->log->addError(
                  "CdmSingleFile",
                  array('Configuration error' => "No input directory is defined.")
                );
                $return = FALSE;
            }
//        }
        return $return;
    }
}
