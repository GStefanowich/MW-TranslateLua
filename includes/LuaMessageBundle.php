<?php

namespace MediaWiki\Extension\TranslateLua;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Extension\Translate\MessageBundleTranslation\MalformedBundle;
use MediaWiki\Extension\Translate\MessageBundleTranslation\MessageBundle;
use MediaWiki\Extension\Translate\MessageBundleTranslation\MessageBundleContent;
use MediaWiki\Extension\Translate\MessageBundleTranslation\MessageBundleMetadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Parser;

class LuaMessageBundle {
    private WikiPageFactory $pageFactory;

    private Title $title;
    private ?MessageBundleContent $content;

    private array $languages;

    public function __construct(
        WikiPageFactory $pageFactory,
        Title $title
    ) {
        $this -> pageFactory = $pageFactory;
        $this -> title = $title;

        // Parse the json content
        $content = $this -> getWikitext( $title );
        $this -> content = $content ? new MessageBundleContent( $content ) : null;

        $this -> languages = [];
    }

    /**
     * @return bool
     */
    public function isValid(): bool {
        return $this -> content !== null && $this -> content -> isValid();
    }

    /**
     * Get the CacheKey for this Bundle
     * @return string
     */
    public function getCacheKey(): string {
        return $this -> title -> getFullText();
    }

    /**
     * Create a throwable error for this bundle
     * @return LuaError
     */
    public function getError(): LuaError {
        return new LuaError('The MessageBundle "' . $this -> getCacheKey() . '" contains invalid JSON');
    }

    /**
     * @return string[]
     * @throws LuaError
     */
    public function getMessages( ?string $languageCode = null ): array {
        assert($this -> content !== null);
        try {
            if ( $this -> languageEquals( $languageCode ) ) {
                return $this -> content -> getMessages();
            }

            if ( array_key_exists( $languageCode, $this -> languages ) ) {
                $lazy = $this -> languages[ $languageCode ];
            } else {
                $languageNames = MediaWikiServices::getInstance() -> getLanguageNameUtils();
                if ( !$languageNames -> isValidBuiltInCode( $languageCode ) ) {
                    throw new LuaError('Invalid language code: ' . $languageCode);
                }

                $lazy = $this -> getMessagesLazily( $languageCode );
                $this -> languages[ $languageCode ] = $lazy;
            }

            $out = [];

            // Get the value of each message
            foreach ($lazy as $key => $func) {
                $out[ $key ] = $func();
            }

            return $out;
        } catch ( MalformedBundle ) {
            throw $this -> getError();
        }
    }

    /**
     * @param ?string $languageCode
     * @throws LuaError
     * @return callable[]
     */
    private function getMessagesLazily( ?string $languageCode = null ): array {
        $lazy = [];
        $cache = [];

        // Iterate all of the titles of a message bundle
        foreach ($this -> getTitles( $languageCode ) as $key => $title) {
            $lazy[$key] = (function() use (&$cache, $key, $title) {
                if ( array_key_exists( $key, $cache ) ) {
                    return $cache[ $key ];
                } else {
                    // Get the wikitext for the key
                    $value = $this -> getWikitext( $title );

                    // Store the value in the cache used among the foreach loop
                    $cache[$key] = $value ?? '';

                    // Return the value
                    return $value;
                }
            });
        }

        return $lazy;
    }

    /**
     * @param string  $key
     * @param ?string $languageCode
     * @return string
     * @throws LuaError
     */
    public function getMessage( string $key, ?string $languageCode = null ): string {
        $messages = $this -> getMessagesLazily( $languageCode );
        $lazy = $messages[ $key ] ?? null;

        if ( $lazy ) {
            return $lazy();
        }

        return '';
    }

    /**
     * @return string[]
     * @throws LuaError
     */
    public function getKeys(): array {
        assert($this -> content !== null);
        try {
            return array_keys( $this -> content -> getMessages() );
        } catch ( MalformedBundle ) {
            throw $this -> getError();
        }
    }

    /**
     * Get an assoc array of Keys with their associated Title object
     * @throws LuaError
     * @return Title[]
     */
    private function getTitles( ?string $languageCode = null ): array {
        // Fallback to the default language if not provided
        $languageCode ??= $this -> getLanguageCode();

        $titles = [];
        $prefix = $this -> title -> getFullText();

        foreach ($this -> getKeys() as $key) {
            $titles[$key] = Title::makeTitle( NS_TRANSLATIONS, $prefix . '/' . $key . '/' . $languageCode );
        }

        return $titles;
    }

    /**
     * @return MessageBundleMetadata
     * @throws LuaError
     */
    public function getMetadata(): MessageBundleMetadata {
        assert($this -> content !== null);
        try {
            return $this -> content -> getMetadata();
        } catch ( MalformedBundle ) {
            throw $this -> getError();
        }
    }

    /**
     * @param ?string $languageCode
     * @return bool
     * @throws LuaError
     */
    public function languageEquals( ?string $languageCode ): bool {
        $metadata = $this -> getMetadata();
        $languageSource = $metadata -> getSourceLanguageCode();

        // If the languageCode is not provided, fallback to the metadata language.
        //   Otherwise check that the metadata language is defined, and does equal the language
        return $languageCode === null || ($languageSource !== null && $languageCode === $languageSource);
    }

    /**
     * @return string
     * @throws LuaError
     */
    public function getLanguageCode(): string {
        $metadata = $this -> getMetadata();
        $languageCode = $metadata -> getSourceLanguageCode();

        if ( !$languageCode ) {
            global $wgLanguageCode;
            $languageCode = $wgLanguageCode;
        }

        return $languageCode ?? 'en';
    }

    /**
     * Safely get the wikitext for a page.
     * @param Title $title
     * @return string|null
     */
    private function getWikitext( Title $title ): ?string {
        $page = $this -> pageFactory -> newFromTitle( $title );
        $content = $page -> getContent(RevisionRecord::FOR_PUBLIC);

        if ( $content ) {
            $raw = $content -> getWikitextForTransclusion();
            if ( $raw ) {
                return $raw;
            }
        }

        return null;
    }
}