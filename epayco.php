<?php

namespace Epayco;

require_once "client.php";

class Epayco
{
    public $api_key;

    // Constructor
    public function __construct($options)
    {
        $this->api_key = $options["api_key"];

        if (!$this->api_key) {
            throw new InvalidApiKey();
        }

        
    }
}
