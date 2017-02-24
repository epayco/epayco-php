<?php

namespace Epayco;

require_once "client.php";

/**
 * Global class constructor
 */
class Epayco
{
    /**
     * Public key client
     * @var String
     */
    public $api_key;
    /**
     * Private key client
     * @var String
     */
    public $private_key;

    /**
     * Constructor methods publics
     * @param array $options
     */
    public function __construct($options)
    {
        $this->api_key = $options["apiKey"];
        $this->private_key = $options["privateKey"];
        $this->test = $options["test"] ? "TRUE" : "FALSE";

        if (!$this->api_key) {
            throw new InvalidApiKey();
        }

        $this->token = new Token($this);
        $this->customer = new Customers($this);
        $this->plan = new Plan($this);
        $this->subscriptions = new Subscriptions($this);
        $this->bank = new Bank($this);
    }
}

/**
 * Constructor resource requests
 */
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

/**
 * Genrate token credit card tokenize
 */
class Token extends Resource
{
    /**
     * Return id tokenize credit card
     * @param  array $options credit card info
     * @return object
     */
    public function create($options = null)
    {
        return $this->request(
               "POST",
               "/v1/tokens",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }
}

/**
 * Customer methods
 */
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
               $switch = false
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
               $switch = false
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
               $switch = false
        );
    }
}

/**
 * Plan methods
 */
class Plan extends Resource
{
    /**
     * Create plan
     * @param  object $options data from plan
     * @return object
     */
    public function create($options = null)
    {
        return $this->request(
               "POST",
               "/recurring/v1/plan/create",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }

    /**
     * Get plan from id
     * @param   $uid id plan
     * @return object
     */
    public function get($uid)
    {
        return $this->request(
               "GET",
               "/recurring/v1/plan/" . $this->epayco->api_key . "/" . $uid . "/",
               $api_key = $this->epayco->api_key,
               $options = null,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }

    /**
     * Get list all plans from client epayco
     * @return object
     */
    public function getList()
    {
        return $this->request(
               "GET",
               "/recurring/v1/plans/" . $this->epayco->api_key,
               $api_key = $this->epayco->api_key,
               $options = null,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }
     /**
      * Update plan
      * @param  String $uid     id plan
      * @param  object $options contenten update
      * @return object
      */
    public function update($uid, $options = null)
    {
        return $this->request(
               "POST",
               "/recurring/v1/plan/edit/" . $this->epayco->api_key . "/" . $uid . "/",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }
}

/**
 * Create subcription from clients
 */
class Subscriptions extends Resource
{
    /**
     * Create subscription
     * @param  object $options data client and plan
     * @return object
     */
    public function create($options = null)
    {
        return $this->request(
               "POST",
               "/recurring/v1/subscription/create",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }

    /**
     * Get subscription from id
     * @param  String $uid id subscription
     * @return object
     */
    public function get($uid)
    {
        return $this->request(
               "GET",
               "/recurring/v1/subscription/" . $uid . "/" . $this->epayco->api_key  . "/",
               $api_key = $this->epayco->api_key,
               $options = null,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }

    /**
     * Get all subscriptions from client epayco
     * @return object
     */
    public function getList()
    {
        return $this->request(
               "GET",
               "/recurring/v1/subscriptions/" . $this->epayco->api_key,
               $api_key = $this->epayco->api_key,
               $options = null,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }

    /**
     * Cancel active subscription
     * @param  String $uid id subscription
     * @return object
     */
    public function cancel($uid)
    {
        return $this->request(
               "POST",
               "/recurring/v1/subscription/cancel",
               $api_key = $this->epayco->api_key,
               $options = array(
                    "id" => $uid
               ),
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false
        );
    }
}

/**
 * Pse methods
 */
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
               $switch = true
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
               $switch = true
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
                $switch = true
        );
    }
}
