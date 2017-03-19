<?php

namespace Epayco\Resources;


use Epayco\Resource;

class Customers extends Resource
{
    /**
     * Create client and asocciate credit card
     * @param  array $options client and token id info
     * @return object
     */
    public function create($options = null)
    {
        return $this->request(
            "POST",
            "/payment/v1/customer/create",
            $api_key = $this->epayco->api_key,
            $options,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = false,
            $lang = $this->epayco->lang
        );
    }

    /**
     * Get client for id
     * @param  String $uid id client
     * @return object
     */
    public function get($uid)
    {
        return $this->request(
            "GET",
            "/payment/v1/customer/" . $uid . "/",
            $api_key = $this->epayco->api_key,
            $options = null,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = false,
            $lang = $this->epayco->lang
        );
    }

    /**
     * Get list customer rom client epayco
     * @return object
     */
    public function getList()
    {
        return $this->request(
            "GET",
            "/payment/v1/customers/" . $this->epayco->api_key . "/",
            $api_key = $this->epayco->api_key,
            $options = null,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = false,
            $lang = $this->epayco->lang
        );
    }
}