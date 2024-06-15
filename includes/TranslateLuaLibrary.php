<?php

namespace MediaWiki\Extension\TranslateLua;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Extension\Translate\MessageBundleTranslation\MessageBundle;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\Title;
use MessageHandle;
use Scribunto_LuaLibraryBase;

class TranslateLuaLibrary extends Scribunto_LuaLibraryBase {
    /**
     * Register the methods we have here and pass them to the 'php' variable in 'translate.lua'
     * 
     * @return ?array Returns an array of registered interface methods
     */
    public function register(): ?array {
        return $this -> getEngine() -> registerInterface(
            // The module file
            join(DIRECTORY_SEPARATOR, [ __DIR__, 'Engine', 'translate.lua' ]),
            // Methods accessible by the 'php' global in 'translate.lua'
            [
                'getBundleKeys' => [
                    $this,
                    'getBundleKeys'
                ],
                'getBundleValue' => [
                    $this,
                    'getBundleValue'
                ],
                'getBundleValues' => [
                    $this,
                    'getBundleValues'
                ],
                'getCurrentLanguage' => [
                    $this,
                    'getCurrentLanguage'
                ],
                'getAvailableLanguages' => [
                    $this,
                    'getAvailableLanguages'
                ],
                'getLanguageProgress' => [
                    $this,
                    'getLanguageProgress'
                ]
            ],
            // Array passed into 'setupInterface' method of 'translate.lua'
            []
        );
    }

    private array $bundleStore = [];

    /**
     * Get information about a MessageBundle
     * @param Title $title
     * @return LuaMessageBundle
     * @throws LuaError If the title given by the user is an invalid syntax, or there is an issue with the MessageBundle
     */
    private function getMessageBundle( Title $title ): LuaMessageBundle {
        $key = $title -> getFullText();

        if ( array_key_exists( $key, $this -> bundleStore ) ) {
            $cache = $this -> bundleStore[ $key ];
        } else {
            $services = MediaWikiServices::getInstance();
            $config = $services -> getMainConfig();

            // If MessageBundles aren't even enabled
            if ( !$config -> get('TranslateEnableMessageBundleIntegration') ) {
                $cache = new LuaError('MessageBundleIntegration is disabled');
            } elseif ( !MessageBundle::isSourcePage( $title ) ) {
                // Get the content model of the title
                $model = $title -> getContentModel();

                $error = '"' . $key . '" is not a message bundle, '
                    . ($model === 'translate-messagebundle' ? 'may be missing a revision after being enabled' : 'invalid content model "' . $model . '"');

                $cache = new LuaError( $error );
            } else {
                $pageFactory = $services -> getWikiPageFactory();

                $bundle = new LuaMessageBundle( $pageFactory, $title );
                $cache = $bundle -> isValid() ? $bundle : $bundle -> getError();
            }

            // Store the read value into the cache
            $this -> bundleStore[$key] = $cache;
        }

        // Lua errors are also cached to prevent repeat lookups
        if ( $cache instanceof LuaError ) {
            throw $cache;
        }

        return $cache;
    }

    /**
     * Get all of the keys that are set for the MessageBundle requested by the script
     * @param mixed $title
     * @return array
     * @throws LuaError If the title given by the user is an invalid syntax
     */
    public function getBundleKeys( mixed $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );

        return [
            array_keys( $bundle -> getMessages() )
        ];
    }

    /**
     * Get a singular value from a MessageBundle using a given key
     * @param mixed $title
     * @param mixed $key
     * @param mixed $languageCode
     * @return string[]
     * @throws LuaError If the title given by the user is an invalid syntax, or the languageCode is invalid
     */
    public function getBundleValue( mixed $title, mixed $key, mixed $languageCode ): array {
        $this -> checkType( 'key', 2, $key, 'string' );
        $this -> checkTypeOptional( 'code', 3, $languageCode, 'string', null );

        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );

        return [ $bundle -> getMessage( $key ) ];
    }

    /**
     * @param mixed $title
     * @param mixed $languageCode
     * @return array
     * @throws LuaError If the title given by the user is an invalid syntax, or the languageCode is invalid
     */
    public function getBundleValues( mixed $title, mixed $languageCode ): array {
        $this -> checkTypeOptional( 'code', 2, $languageCode, 'string', null );

        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );

        return [
            $bundle -> getMessages( $languageCode )
        ];
    }

    /**
     * Returns the Metadata for a given MessageBundle
     * @param mixed $title The page holding the MessageBundle
     * @return array
     * @throws LuaError If the title given by the user is an invalid syntax
     */
    public function getBundleMetadata( mixed $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );
        $metadata = $bundle -> getMetadata();

        return [
            [
                'sourceLanguage' => $metadata -> getSourceLanguageCode(),
                'priorityLanguages' => $metadata -> getPriorityLanguages(),
                'allowOnlyPriorityLanguages' => $metadata -> areOnlyPriorityLanguagesAllowed(),
                'description' => $metadata -> getDescription(),
                'label' => $metadata -> getLabel()
            ]
        ];
    }

    /**
     * Get the language code of the current page that the Module is running in
     *   Will return 'null'/'nil' if the page is not marked for translation, or if the page is a source page
     *   A language code will be returns *only* on the translated subpages, including '/en'
     *   This can be used for generating links and operates on the same premise as the {{#translation:}} Parser Function
     * @return string[] A singleton array with the language code, or null
     */
    public function getCurrentLanguage(): array {
        $title = $this -> getTitle();
        $page = TranslateHelper::getPage( $title );

        // Return the page language code if is a translatable page (and NOT the source page), otherwise null
        return [ $page && !$title -> equals( $page -> getTitle() ) ? $title -> getPageLanguage() -> getCode() : null ];
    }

    /**
     * Get an array of language codes for a given page, where the languageCodes are variants available for the page
     * @param mixed $title
     * @return array
     * @throws LuaError
     */
    public function getAvailableLanguages( mixed $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        $page = TranslateHelper::getPage( $parsed );
        $out = [];

        if ( $page ) {
            $titles = $page -> getTranslationPages();

            foreach ( $titles as $t ) {
                // Parse the language code used in the title
                $handle = new MessageHandle( $t );
                $code = $handle -> getCode();

                // Add the language code to the output table
                $out[] = $code;
            }
        }

        return [ $out ];
    }

    /**
     * Get an array of language codes and the associated percentage completion that those translated pages are
     *   The source page ('en' or <code>$wgLanguageCode</code>) will be 1.00
     * @param mixed $title A title provided by the user
     * @return array
     * @throws LuaError
     */
    public function getLanguageProgress( mixed $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        $page = TranslateHelper::getPage( $parsed );
        $out = [];

        // Page is translatable
        if ( $page ) {
            $percentages = $page -> getTranslationPercentages();

            // Add the percentage of each language to the array (Convert from string)
            foreach ($percentages as $page => $percentage) {
                $out[$page] = ((float)$percentage);
            }
        }

        return [ $out ];
    }

    /**
     * Parse a Title object using User input
     * @param  mixed $input A string value or a table representing the Title object
     * @return Title        Parsed version of the input
     * @throws LuaError     If the user input is invalid, errors to the script
     */
    private function parseUserInputTitle( mixed $input ): Title {
        $output = null;

        // No title given, return the current page Title
        if ( !$input ) {
            $output = $this -> getTitle();
        } else {
            $parser = MediaWikiServices::getInstance() -> getTitleParser();

            try {
                // Parse the title using the string value
                if ( is_string( $input ) ) {
                    $output = $parser -> parseTitle( $input, NS_MAIN );
                } elseif ( is_array( $input ) && !array_is_list( $input ) ) {
                    // Try building the Title using the array data
                    $ns = $input['namespace'] ?? NS_MAIN;
                    $title = $input['text'] ?? null;
                    $fragment = $input['fragment'] ?? '';
                    $interwiki = $input['interwiki'] ?? '';

                    if ( is_numeric( $ns ) && is_string( $title ) && is_string( $fragment ) && is_string( $interwiki ) ) {
                        $output = $parser -> makeTitleValueSafe(
                            $ns,
                            $title,
                            $fragment,
                            $interwiki
                        );
                    }
                }
            } catch ( MalformedTitleException ) {
                throw new LuaError('Failed to parse title');
            }
        }

        if ( $output === null ) {
            throw new LuaError('Failed to parse title');
        }

        return Title::newFromLinkTarget( $output );
    }
}