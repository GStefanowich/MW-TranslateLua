# Translate Lua

This repo is for an Extension for [MediaWiki](https://www.mediawiki.org/wiki/MediaWiki), which adds some additional functionality for [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto) to provide interaction with the [Translate Extension](https://www.mediawiki.org/wiki/Extension:Translate).

# Available Methods

The extension is available through Lua modules at `mw.ext.translate`

## Content Language

- **mw.ext.translate.getCurrentLanguage**

```lua
mw.ext.translate.getCurrentLanguage()
```

Returns the pages language of `mw.title.getCurrentTitle()` as opposed to `mw.language.getContentLanguage()` which returns the language of the overall wiki

----

- **mw.ext.translate.getAvailableLanguages**

```lua
mw.ext.translate.getAvailableLanguages('Page')
```

Providing a *Page* is optional and will default to `mw.title.getCurrentTitle()`.

Returns a table of languages that the current page is translated in. Eg; `{"en", "fr", "de"}`

----

- **mw.ext.translate.getLanguageProgress**

```lua
mw.ext.translate.getLanguageProgress('Page')
```

Providing a *Page* is optional and will default to `mw.title.getCurrentTitle()`.

Returns a table of languages that the current page is translated in, including the percentage that the translation is complete. Eg; `{en = 1, fr = .95, de = .45}`

## MessageBundles

> :warning: **If `$wgTranslateEnableMessageBundleIntegration` is set to `false` then MessageBundle functions will emit an error.**

[Message Bundles](https://www.mediawiki.org/wiki/Help:Extension:Translate/Message_Bundles) are a Translate feature that allows creating translated content that isn't stored in a regular wiki page. Instead it introduces a new Page content model, "Translatable message bundle".

MessageBundles are stored in JSON format using key-value pairs:

```json
{
  "@metadata": {
    "sourceLanguage": "en",
    "description": "This is an example"
  },
  "key-one": "One",
  "key-two": "Two"
}
```

----

- **mw.ext.translate.getBundle**

```lua
mw.ext.translate.getBundle('Page/Message_Bundle')
```

Parses the JSON MessageBundle from the Page `'Page/Message_Bundle'` and returns a table of callable methods for interacting with the bundle.

----

- **bundle:getKeys**

```lua
local bundle = mw.ext.translate.getBundle('Page/Message_Bundle')

bundle:getKeys()
```

Returns a table with all of the current message bundles keys, minus `@metadata`

----

- **bundle:getMetadata**

```lua
local bundle = mw.ext.translate.getBundle('Page/Message_Bundle')

bundle:getMetadata()
```

Returns the `@metadata` table from the bundle.

----

- **bundle:get**

```lua
local bundle = mw.ext.translate.getBundle('Page/Message_Bundle')

bundle:get('key', 'languageCode')
```

Returns a specific MessageBundle value in the current page language (Or using `languageCode`)

----

- **bundle:getAll**

```lua
local bundle = mw.ext.translate.getBundle('Page/Message_Bundle')

bundle:getAll('languageCode')
```

Returns a table of the message bundle keys and its values in the current page language (Or using `languageCode`)