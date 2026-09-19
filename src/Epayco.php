<?php

namespace Epayco;

use Epayco\Resources\Bank;
use Epayco\Resources\Cash;
use Epayco\Resources\Charge;
use Epayco\Resources\Customers;
use Epayco\Resources\Plan;
use Epayco\Resources\Subscriptions;
use Epayco\Resources\Token;
use Epayco\Resources\Daviplata;
use Epayco\Resources\Safetypay;

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
     * test mode transaction
     * @var String
     */
    public $test;

    /**
     * lang client errors
     * @var String
     */
    public $lang;

    /**
     * Payment methods (e.g. "cash") kept on the legacy backend instead of
     * the new ms-transaction microservice (apiflow.epayco.io), opted into
     * via the `transactionMethods` constructor option. Mirrors the
     * equivalent `transactionMethods` option already used by this SDK's own
     * ms-transaction migration in the sibling Node/Python SDKs
     * (SDK-1352/SDK-1029/SDK-1030). Empty by default, i.e. every migrated
     * payment method (currently just "cash", see SDK-1366 and
     * Resources/Cash.php) uses the new backend unless explicitly opted out.
     * @var array
     */
    public $transactionMethods = array();

    /**
     * Constructor methods publics
     * @param array $options
     */
    public function __construct($options)
    {
        $this->api_key = $options["apiKey"];
        $this->private_key = $options["privateKey"];
        $this->test = $options["test"] ? "TRUE" : "FALSE";
        $this->lang = $options["lenguage"];
        $this->transactionMethods = (isset($options["transactionMethods"]) && is_array($options["transactionMethods"]))
            ? $options["transactionMethods"]
            : array();

        if (!$this->api_key && !$this->private_key && $this->test && $this->lang) {
            throw new ErrorException($this->lang, 100);
        }

        $this->token = new Token($this);
        $this->customer = new Customers($this);
        $this->plan = new Plan($this);
        $this->subscriptions = new Subscriptions($this);
        $this->bank = new Bank($this);
        $this->cash = new Cash($this);
        $this->charge = new Charge($this);
        $this->daviplata = new Daviplata($this);
        $this->safetypay = new Safetypay($this);
    }

    /**
     * Whether $paymentMethod was opted out of the ms-transaction migration
     * via the `transactionMethods` constructor option, i.e. whether it
     * should keep using its legacy backend instead of the new
     * apiflow.epayco.io microservice. See Resources/Cash.php for the first
     * consumer of this (SDK-1366).
     * @param  String $paymentMethod e.g. "cash"
     * @return bool
     */
    public function usesLegacyFlow($paymentMethod)
    {
        return in_array($paymentMethod, $this->transactionMethods, true);
    }
}