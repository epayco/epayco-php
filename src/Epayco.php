<?php
/**
 * Created by PhpStorm.
 * User: anvargear
 * Date: 19/03/17
 * Time: 12:18 PM
 */

namespace Epayco;


use Epayco\Resources\Bank;
use Epayco\Resources\Cash;
use Epayco\Resources\Customers;
use Epayco\Resources\Plan;
use Epayco\Resources\Subscriptions;
use Epayco\Resources\Token;

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
        $this->lang = $options["lenguage"];

        if (!$this->api_key && !$this->private_key && $this->test && $this->lang) {
            throw new \ErrorException($this->lang, 100);
        }

        $this->token = new Token($this);
        $this->customer = new Customers($this);
        $this->plan = new Plan($this);
        $this->subscriptions = new Subscriptions($this);
        $this->bank = new Bank($this);
        $this->cash = new Cash($this);
    }
}