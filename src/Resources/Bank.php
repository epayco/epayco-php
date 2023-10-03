<?php

namespace Epayco\Resources;

use Epayco\Resource;

/**
 * Bank methods
 */
class Bank extends Resource
{
    /**
     * Return list all banks
     * @return object
     */
    public function pseBank($testMode = null)
    {
        $url = "/restpagos/pse/bancos.json?public_key=" . $this->epayco->api_key;
        if(isset($testMode) && gettype($testMode) === "boolean"){
            $test = $testMode  ? "1" : "2";     
            $url = $url."&test=".$test;
        }
        return $this->request(
               "GET",
               $url,
               $api_key = $this->epayco->api_key,
               $options = null,
               $private_key = $this->epayco->private_key,
               $this->epayco->test,
               $switch = true,
               $lang = $this->epayco->lang
        );
    }

    /**
     * Create transaction in ACH
     * @param  Object $options data transaction
     * @return object
     */
    public function create($options = null)
    {
        return $this->request(
               "POST",
               "/restpagos/pagos/debitos.json",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = true,
               $lang = $this->epayco->lang
        );
    }

    /**
     * Return data transaction
     * @param  String $uid id transaction
     * @return object
     */
    public function get($uid = null)
    {
        return $this->request(
                "GET",
                "/restpagos/pse/transactioninfomation.json?transactionID=" . $uid . "&&public_key=" . $this->epayco->api_key,
                $api_key = $this->epayco->api_key,
                $uid,
                $private_key = $this->epayco->private_key,
                $test = $this->epayco->test,
                $switch = true,
                $lang = $this->epayco->lang
        );
    }
}
