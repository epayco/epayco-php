<?php
/**
 * Created by PhpStorm.
 * User: anvargear
 * Date: 19/03/17
 * Time: 12:50 PM
 */

namespace Epayco\Resources;


use Epayco\Resource;

class Bank extends Resource
{
    /**
     * Return list all banks
     * @return object
     */
    public function pseBank()
    {
        return $this->request(
            "GET",
            "/restpagos/pse/bancos.json",
            $api_key = $this->epayco->api_key,
            $options = null,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = true,
            $lang = $this->epayco->lang
        );
    }

    /**
     * Create transaction in ACH
     * @param  Object $options data transaction
     * @return object
     */
    public function pse($options = null)
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
    public function pseTransaction($uid = null)
    {
        return $this->request(
            "GET",
            "/restpagos/pse/transactioninfomation.json",
            $api_key = $this->epayco->api_key,
            $uid,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = true,
            $lang = $this->epayco->lang
        );
    }
}