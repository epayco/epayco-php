<?php
/**
 * Created by PhpStorm.
 * User: anvargear
 * Date: 19/03/17
 * Time: 12:53 PM
 */

namespace Epayco;


class Util
{
    public function setKeys($array)
    {
        $aux = array();
        $file = dirname(__FILE__). "/utils/key_lang.json";
        $values = json_decode(file_get_contents($file), true);
        foreach ($array as $key => $value) {
            if (array_key_exists($key, $values)) {
                $aux[$values[$key]] = $value;
            } else {
                $aux[$key] = $value;
            }
        }
        return $aux;
    }
}