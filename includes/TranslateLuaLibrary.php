<?php

namespace MediaWiki\Extension\TranslateLua;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Extension\Translate\MessageBundleTranslation\MessageBundle;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\Title;
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

    private function getMessageBundle( Title $title ): LuaMessageBundle {
        $key = $title -> getFullText();
        $cache = null;

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
     * @param $title
     * @return array
     * @throws LuaError
     */
    public function getBundleKeys( $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );

        return [
            array_keys( $bundle -> getMessages() )
        ];
    }

    /**
     * @param $title
     * @param $key
     * @param $languageCode
     * @return string[]
     * @throws LuaError
     */
    public function getBundleValue( $title, $key, $languageCode ): array {
        $this -> checkType( 'key', 2, $key, 'string' );
        $this -> checkTypeOptional( 'code', 3, $languageCode, 'string', null );

        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );

        return [ $bundle -> getMessage( $key ) ];
    }

    /**
     * @param $title
     * @param $languageCode
     * @return array
     * @throws LuaError
     */
    public function getBundleValues( $title, $languageCode ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        $bundle = $this -> getMessageBundle( $parsed );

        return [
            $bundle->getMessages( $languageCode )
        ];
    }

    /**
     * @param $title
     * @return array
     * @throws LuaError
     */
    public function getBundleMetadata( $title ) {
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
     * @return string[]
     */
    public function getCurrentLanguage(): array {
        return [ 'something' ];
    }

    /**
     * @param $title
     * @return array
     * @throws LuaError
     */
    public function getAvailableLanguages( $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        return [];
    }

    /**
     * @param $title
     * @return array
     * @throws LuaError
     */
    public function getLanguageProgress( $title ): array {
        $parsed = $this-> parseUserInputTitle( $title );
        return [];
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