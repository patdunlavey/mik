<?php
// src/metadatamanipulators/AddCdmMigratedFromUrl.php

namespace mik\metadatamanipulators;
use \Monolog\Logger;

/**
 * AddCdmMigratedFromUrl - Adds an identifier element to the MODS
 * document containing the URL of the object in CONTENTdm.
 *
 * Note that this manipulator doesn't add the <identifier> fragment, it
 * only populates it with data from CONTENTdm. The mappings file
 * must contain a row that adds the following element to your MODS:
 * '<identifier type="uri" invalid="yes"/>', e.g.,
 * null6,<identifier type="uri" invalid="yes"/>. The fragment can
 * contain other attributes as well, such as DisplayLabel.
 *
 * This metadata manipulator takes one configuration parameter,
 * the hostname (and if required, port number) of your CONTENTdm
 * server.
 */
class AddCdmMigratedFromUrl extends MetadataManipulator
{
    /**
     * @var string $record_key - the unique identifier for the metadata
     *    record being manipulated.
     */
    private $record_key;

    /**
     * Create a new metadata manipulator Instance.
     */
    public function __construct($settings = null, $paramsArray, $record_key)
    {
        parent::__construct($settings, $paramsArray, $record_key);
        $this->record_key = $record_key;
        $this->alias = $this->settings['METADATA_PARSER']['alias'];

        // Set up logger.
        $this->pathToLog = $this->settings['LOGGING']['path_to_manipulator_log'];
        $this->log = new \Monolog\Logger('config');
        $this->logStreamHandler = new \Monolog\Handler\StreamHandler($this->pathToLog,
            Logger::INFO);
        $this->log->pushHandler($this->logStreamHandler);

        // This manipulator expects only one parameter.
        if (count($paramsArray) == 1) {
            $this->CdmBaseUrl = $paramsArray[0];
        } else {
            $this->log->addError("AddCdmMigratedFromUrl",
                array('Wrong number of manuipulator parameters(expected 1)' => count($paramsArray)));
        }
    }

    /**
     * General manipulate wrapper method.
     *
     *  @param string $input The XML fragment to be manipulated. We are only
     *     interested in the <identifier type="uri" invalid="yes"/> fragment
     *     added in the MIK mappings file.
     *
     * @return string
     *     One of the manipulated XML fragment, the original input XML if the
     *     input is not the fragment we are interested in, or an empty string,
     *     which has the effect of removing the empty <identifier type="uri" invalid="yes"/>
     *     fragement from our MODS.
     */
    public function manipulate($input)
    {
        $dom = new \DomDocument();
        $dom->loadxml($input, LIBXML_NSCLEAN);

        // Test to see if the current fragment is <identifier type="uri" invalid="yes"/>.
        $xpath = new \DOMXPath($dom);
        $uri_identifiers = $xpath->query("//identifier[@type='uri' and @invalid='yes']");
        // There should only be one <identifier type="uri" invalid="yes"/> element
        // in the incoming XML fragment, defined in the mappings file. If there is 0,
        // return the original.
        if ($uri_identifiers->length === 1) {
            $uri_identifier = $uri_identifiers->item(0);
            // If our incoming fragment is already an identifier with the same
            // attributes and a node value that mathes our CONTENTdm hostname,
            // return it as is. Note that if a identifier matching our Xpath query
            // is added later in the document, this manipulator will still add a
            // new one, since we are processing the MODS on an element by element
            // basis, not the entire MODS document.
            if (strlen($uri_identifier->nodeValue) &&
                (0 === strpos($uri_identifier->nodeValue, $this->CdmBaseUrl))) {
                    $this->log->addError("AddCdmMigratedFromUrl",
                        array('Migrated-from URL already present' => $uri_identifier->nodeValue));
                return $input;
            }
            // If our incoming fragment is the template element from the mappings file,
            // populate it and return it.
            else {
                $uri_identifier->nodeValue = $this->CdmBaseUrl .
                    '/cdm/ref/collection/'. $this->alias . '/id/'. $this->record_key;
                return $dom->saveXML($dom->documentElement);
            }
        }
        else {
            // If current fragment is not <identifier type="uri" invalid="yes">,
            // return it unmodified.
            return $input;
        }
    }

}
