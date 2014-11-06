<?php

/**
 * @file
 * This file contains special implementation of json_encode, which doesn't convert all cyrillic characters to unicode.
 */

function getEscapeSequence_callback($regExMatches){
    $charToJSON = array(
        '\\'   => '\\\\',
        '"'    => '\\"',
        '/'    => '\\/',
        "\x08" => '\\b',
        "\x09" => '\\t',
        "\x0A" => '\\n',
        "\x0C" => '\\f',
        "\x0D" => '\\r'
    );
    if(isset($charToJSON[$regExMatches[0]]))
        return $charToJSON[$regExMatches[0]];
    return sprintf('\\u00%02x', ord($regExMatches[0]));
}

function isVector(&$array){
    $next = 0;
    foreach($array as $k=>$v){
        if($k !== $next)
            return false;
        $next++;
    }
    return true;
}

function encodeJson($value){
    $JSONDateFormat = 'ISO8601';
    //switch(gettype($value)){

    if($value === null)
        //case 'NULL';
        return 'null';

    ## Array ###############################
    //case 'array':
    else if(is_array($value)){
        if(!count($value))
            return "[]";
        #inspired by sean at awesomeplay dot com (26-May-2007 07:21) in the PHP user contributed notes for json_encode
        if(isVector($value)){
            $json = '[';
            for($i = 0; $i < count($value); $i++){
                if($i)
                    $json .= ',';
                $json .= encodeJson($value[$i]);
            }
            return $json . ']';
            #return '[' . join(",", array_map('self::encodeJson', $value)) . ']';
        }
        else {
            $json = '{';
            $count = 0;
            foreach ($value as $k=>$v) {
                if($count++)
                    $json .= ',';
                $json .= encodeJson((string)$k) . ':' . encodeJson($v);
            }
            return $json . '}';
        }
    }
    ## Object ###############################
    else if(is_object($value)){
        //case 'object':
        $className = get_class($value);
        switch($className){
            case 'DateTime':
                $ticks = $value->format("U") . substr($value->format("u"), 0, 3); //round($value->format("u")/1000);
                switch($JSONDateFormat){
                    case 'classHinting':
                        return '{"__jsonclass__":["Date", [' . $ticks . ']]}'; #json_encode(str_replace("+0000", "Z", $value->format(DATE_ISO8601)))
                    case '@ticks@':
                        return '"@' . $ticks . '@"'; #str_replace("+0000", "Z", $value->format(DATE_ISO8601)))
                    case 'ASP.NET':
                        return '"\\/Date(' . $ticks . ')\\/"'; #json_encode(str_replace("+0000", "Z", $value->format(DATE_ISO8601)))
                    default: #case 'ISO8601':
                        return '"' . $value->format('Y-m-d\TH:i:s.u') . '"';
                }
            default:
                $json = '{';
                $members = get_object_vars($value);
                if(count($members)){
                    $count = 0;
                    foreach($members as $k=>$v){
                        if($count++)
                            $json .= ',';
                        $json .= encodeJson($k) . ':' . encodeJson($v);
                    }
                }
                $json .= '}';
                return $json;
        }
    }
    else if(is_double($value))
        //case 'double': #json_encode is croaking on long ints encoded as doubles
        return preg_replace("/\.0+$/", '', sprintf("%f", $value));
    else if(is_bool($value))
        //case 'boolean':
        return $value ? 'true' : 'false';
    else if(is_int($value))
        //case 'integer':
        return $value;
    else if(is_string($value)){
        //case 'string':
        //Note: in PHP 5.1.2, using 'RPCServer' for &$this raises strict error: Non-static method cannot not be called statically, even though it is declared statically.
        $value = preg_replace_callback('/([\\\\\/"\x00-\x1F])/', 'getEscapeSequence_callback', $value);
        return '"' . /*utf8_encode*/($value) . '"';
    }
    trigger_error("Unable to convert type " . gettype($value) . " to JSON.");
}