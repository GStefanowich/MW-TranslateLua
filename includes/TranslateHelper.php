<?php

namespace MediaWiki\Extension\TranslateLua;

use RuntimeException;
use Title;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Linker\LinkTarget;

class TranslateHelper {
    public static function getPage( LinkTarget $title ): ?TranslatablePage {
        // TranslatePage::newFromTitle requires a 'Title', 'TitleValue' not allowed
        if ( !( $title instanceof Title ) ) {
            $title = Title::newFromLinkTarget( $title );
        }

        $page = TranslatablePage::newFromTitle( $title );
        if ( $page -> getMarkedTag() === null ) {
            $page = TranslatablePage::isTranslationPage( $title );
        }

        // Page isn't returned
        if ( $page === false || $page -> getMarkedTag() === null ) {
            return null;
        }

        // MessageGroup will return null if not marked
        try {
            $group = $page -> getMessageGroup();
        } catch ( RuntimeException ) {
            return null;
        }

        if ( !$group ) {
            return null;
        }

        return $page;
    }
}