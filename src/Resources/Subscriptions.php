<?php

namespace Epayco\Resources;

use Epayco\Resource;

/**
 * Subscriptions methods
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
               $switch = false,
               $lang = $this->epayco->lang
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
               $switch = false,
               $lang = $this->epayco->lang
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
               $switch = false,
               $lang = $this->epayco->lang
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
               $switch = false,
               $lang = $this->epayco->lang
        );
    }

    /**
     * Create subscription
     * @param  object $options data client and plan
     * @return object
     */
    public function charge($options = null)
    {
        return $this->request(
               "POST",
               "/payment/v1/charge/subscription/create",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false,
               $lang = $this->epayco->lang
        );
    }
}