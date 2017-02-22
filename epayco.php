<?php

namespace Epayco;

require_once "client.php";

class Epayco
{
    public $api_key;

    public function __construct($options)
    {
        $this->api_key = $options["apiKey"];

        if (!$this->api_key) {
            throw new InvalidApiKey();
        }

        $this->token = new Token($this);
        $this->customer = new Customers($this);
        $this->plan = new Plan($this);
        $this->subscriptions = new Subscriptions($this);
    }
}

class Resource extends Client
{
    public function __construct($epayco)
    {
        $this->epayco = $epayco;
    }
}

class Token extends Resource
{
    public function create($options = null)
    {
        return $this->request("POST", "/v1/tokens", $api_key = $this->epayco->api_key, $options);
    }
}

class Customers extends Resource
{
    public function create($options = null)
    {
        return $this->request("POST", "/payment/v1/customer/create", $api_key = $this->epayco->api_key, $options);
    }

    public function get($uid)
    {
        return $this->request("GET", "/payment/v1/customer/" . $uid . "/", $api_key = $this->epayco->api_key);
    }

    public function getList()
    {
        return $this->request("GET", "/payment/v1/customers/" . $this->epayco->api_key . "/", $api_key = $this->epayco->api_key);
    }
}

class Plan extends Resource
{
    public function create($options = null)
    {
        return $this->request("POST", "/recurring/v1/plan/create", $api_key = $this->epayco->api_key, $options);
    }

    public function get($uid)
    {
        return $this->request("GET", "/recurring/v1/plan/" . $this->epayco->api_key . "/" . $uid . "/", $api_key = $this->epayco->api_key);
    }

    public function getList()
    {
        return $this->request("GET", "/recurring/v1/plans/" . $this->epayco->api_key, $api_key = $this->epayco->api_key);
    }

    public function update($uid, $options = null)
    {
        return $this->request("POST", "/recurring/v1/plan/edit/" . $this->epayco->api_key . "/" . $uid . "/", $api_key = $this->epayco->api_key, $options);
    }
}

class Subscriptions extends Resource
{
    public function create($options = null)
    {
        return $this->request("POST", "/recurring/v1/subscription/create", $api_key = $this->epayco->api_key, $options);
    }

    public function get($uid)
    {
        return $this->request("GET", "/recurring/v1/subscription/" . $uid . "/" . $this->epayco->api_key  . "/", $api_key = $this->epayco->api_key);
    }

    public function getList()
    {
        return $this->request("GET", "/recurring/v1/subscriptions/" . $this->epayco->api_key, $api_key = $this->epayco->api_key);
    }

    public function cancel()
    {
        return $this->request("POST", "/recurring/v1/subscription/cancel", $api_key = $this->epayco->api_key, $options);
    }
}
