<?php

namespace Epayco;


class Resource extends Client
{
    protected $epayco;
    /**
     * Instance epayco class
     * @param array $epayco
     */
    public function __construct($epayco)
    {
        $this->epayco = $epayco;
    }
}