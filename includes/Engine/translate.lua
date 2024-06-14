local translate = {}
local php

local function getTitleString(title)
    if title == nil then
        return nil
    end

    local t = type(title);

    if t == 'table' then
        -- Extract regular values to prevent circular serialization to PHP
        local set = {}

        for k, v in pairs(title) do
            local vt = type(v)
            if vt ~= 'table' and vt ~= 'function' then
                set[k] = v
            end
        end

        return set
    elseif t == 'string' then
        return title
    end

    return nil
end

function translate.setupInterface(options)
    -- Remove this method from being callable
    translate.setupInterface = nil
    
    -- Copy the php callbacks to a local variable
    php = mw_interface
    mw_interface = nil
    
    -- Install to lua
    mw = mw or {}
    mw.ext = mw.ext or {}
    mw.ext.translate = translate
    
    -- Indicate that we're loaded
    package.loaded['mw.ext.translate'] = translate
end

function translate.getBundle(title)
    local serialized = getTitleString( title )

    -- Check if we successfully parsed a title before reverting to default value on nil
    if serialized == nil and title ~= nil then
        error('Invalid value "' .. type(title) .. '" for title', 2)
    end

    return {
        ['getKeys'] = (function( self )
            return php.getBundleKeys( serialized )
        end),
        ['get'] = (function( self, key, code )
            return php.getBundleValue( serialized, key, code )
        end),
        ['getAll'] = (function( self, code )
            return php.getBundleValues( serialized, code )
        end)
        ['getMetadata'] = (function( self )
            return php.getBundleMetadata( serialized )
        end)
    }
end

function translate.getCurrentLanguage()
    return php.getCurrentLanguage()
end

function translate.getAvailableLanguages(title)
    local serialized = getTitleString(title)

    -- Check if we successfully parsed a title before reverting to default value on nil
    if serialized == nil and title ~= nil then
        error('Invalid value "' .. type(title) .. '" for title', 2)
    end

    return php.getAvailableLanguages(serialized)
end

function translate.getLanguageProgress(title)
    local serialized = getTitleString(title)

    -- Check if we successfully parsed a title before reverting to default value on nil
    if serialized == nil and title ~= nil then
        error('Invalid value "' .. type(title) .. '" for title', 2)
    end

    return php.getLanguageProgress(serialized)
end

return translate