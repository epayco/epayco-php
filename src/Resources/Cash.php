<?php

namespace Epayco\Resources;

use Epayco\Resource;
use Epayco\Exceptions\ErrorException;
use Epayco\Gateways\MsTransactionCash;

/**
 * Cash payment methods
 */
class Cash extends Resource
{
    /**
     * Return data payment cash
     *
     * As of SDK-1366, this goes through the new ms-transaction microservice
     * (apiflow.epayco.io) by default instead of the legacy
     * secure.payco.co/restpagos/v2/efectivo/{medio} endpoint -- see
     * Epayco\Gateways\MsTransactionCash for the request-building/encryption
     * details. A merchant can opt back into the legacy backend for cash
     * specifically by passing `transactionMethods: ["cash"]` to the Epayco
     * constructor, mirroring the equivalent `transactionMethods` option
     * already used by this SDK's own ms-transaction migration in the
     * sibling Node/Python SDKs.
     *
     * The public signature (`$type`, `$options`, legacy option names) and
     * the resolved response's shape (see MsTransactionCash::mapToLegacyShape)
     * are unchanged either way: this method never reshapes the legacy
     * response, and it reshapes the new backend's response back into that
     * same legacy shape -- it only changes which backend is called.
     *
     * @param  String $type method payment
     * @param  String $options data transaction
     * @return object
     */
    public function create($type = null, $options = null)
    {
        $medio = strtolower($type);

        if ($this->epayco->usesLegacyFlow('cash')) {
            return $this->legacyCreate($medio, $options);
        }

        if (!isset(MsTransactionCash::$FRANCHISE_MAP[$medio])) {
            throw new ErrorException($this->epayco->lang, 109);
        }

        return MsTransactionCash::createTransaction(
            $this->epayco,
            MsTransactionCash::$FRANCHISE_MAP[$medio],
            $medio,
            $options
        );
    }

    /**
     * Legacy cash creation, unchanged from the pre-SDK-1366 implementation:
     * the secure.payco.co/restpagos/v2/efectivo/{medio} endpoint, through
     * the shared Resource::request (field-name translation + AES
     * encryption, same as every other legacy resource in this repo).
     *
     * @param  String $medio method payment, already lower-cased
     * @param  String $options data transaction
     * @return object
     */
    private function legacyCreate($medio, $options)
    {
        if($medio == "baloto"){
            throw new ErrorException($this->epayco->lang, 109);
        }

        $methods_payment = $this->request(
            "GET",
            "/payment/cash/entities",
            $api_key = $this->epayco->api_key,
            null,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = false,
            $lang = $this->epayco->lang,
            $cash = false,
            false,
            true
        );

        if(!isset($methods_payment->data) || !is_array($methods_payment->data) || count($methods_payment->data) == 0){
            throw new ErrorException($this->epayco->lang, 106);
        }
        $entities = array_map(function($item){
            return strtolower(str_replace(" ","", $item->name));
        }, $methods_payment->data);

        if(!in_array($medio,  $entities)){
            throw new ErrorException($this->epayco->lang, 109);
        }

        return $this->request(
                "POST",
                "/v2/efectivo/{$medio}",
                $api_key = $this->epayco->api_key,
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
