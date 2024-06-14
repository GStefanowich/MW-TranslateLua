<?php

namespace MediaWiki\Extension\TranslateLua\Hooks;

use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;
use MediaWiki\Extension\TranslateLua\TranslateLuaLibrary;

class ScribuntoHooks implements ScribuntoExternalLibrariesHook {
    /**
     * Register our library with the Scribunto Extension
     * 
     * @param string $engine         The engine being used, normally 'lua'
     * @param array  $extraLibraries Array of libraries to load
     * @return void
     */
    public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ) {
        $extraLibraries['mw.ext.translate'] = TranslateLuaLibrary::class;
    }
}