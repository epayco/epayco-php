<?php

namespace Epayco\Resources;

use Epayco\Resource;
use Epayco\Exceptions\ErrorException;

/**
 * Cash payment methods
 */
class Cash extends Resource
{
    /**
     * Return data payment cash
     * @param  String $type method payment
     * @param  String $options data transaction
     * @return object
     */
    public function create($type = null, $options = null)
    {
        $url = null;
        switch ($type) {
            case "efecty":
                $url = "/v2/efectivo/efecty";
                break;
            case "baloto":
                $url = "/v2/efectivo/baloto";
                break;
            case "gana":
                $url = "/v2/efectivo/gana";
                break;
            case 'redservi':
                $url = "/restpagos/v2/efectivo/redservi";
                break;
            case 'puntored':
                $url = "/restpagos/v2/efectivo/puntored";
                break;
            case 'sured':
                $url = "/restpagos/v2/efectivo/sured";
                break;
            case 'apostar':
                $url = "/restpagos/v2/efectivo/apostar";
                break;
            case 'susuerte':
                $url = "/restpagos/v2/efectivo/susuerte";
                break;
            default:
                throw new ErrorException($this->epayco->lang, 109);
                break;
        }
        return $this->request(
                "POST",
                $url,
                $this->epayco->api_key,
                $options,
                $this->epayco->private_key,
                $this->epayco->test,
                true,
                $this->epayco->lang,
                true
        );
    }

    /**
     * Return data transaction
     * @param  String $uid id transaction
     * @return object
     */
    public function transaction($uid = null)
    {
        return $this->request(
                "GET",
                "/transaction/response.json?ref_payco=" . $uid . "&public_key=" . $this->epayco->api_key,
                $this->epayco->api_key,
                $uid,
                $this->epayco->private_key,
                $this->epayco->test,
                true,
                $this->epayco->lang
        );
    }
}
