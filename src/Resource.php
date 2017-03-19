<?php

namespace Epayco;


class Resource extends Client
{
    /**
     * Instance epayco class
     * @param array $epayco
     */
    public function __construct($epayco)
    {
        $this->epayco = $epayco;
    }
}