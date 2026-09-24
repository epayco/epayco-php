<?php

namespace Epayco\Resources;

use Epayco\Resource;
use Epayco\Gateways\MsTransactionSafetypay;

/**
 * Safetypay payment methods
 */
class Safetypay extends Resource
{
    /**
     * Create safetypay trx.
     *
     * As of SDK-1368, this goes through the new ms-transaction microservice
     * (apiflow.epayco.io) by default instead of the legacy apify endpoint
     * eks-apify-service.epayco.io/payment/process/safetypay -- see
     * Epayco\Gateways\MsTransactionSafetypay for the request-building/
     * encryption details. A merchant can opt back into the legacy backend for
     * SafetyPay specifically by passing `transactionMethods: ["safetypay"]` to
     * the Epayco constructor, mirroring the equivalent opt-out
     * Resources/Cash.php (SDK-1366, `["cash"]`) and Resources/Bank.php
     * (SDK-1365, `["bank"]`) already use.
     *
     * The public signature (`$options`, legacy option names) and the resolved
     * response's shape (see MsTransactionSafetypay::mapToLegacyShape) are
     * unchanged either way: this method never reshapes the legacy response,
     * and it reshapes the new backend's response back into that same legacy
     * shape -- it only changes which backend is called.
     *
     * @param  object $options data
     * @return object
     */
    public function create($options = null)
    {
        if ($this->epayco->usesLegacyFlow('safetypay')) {
            return $this->legacyCreate($options);
        }

        return MsTransactionSafetypay::createTransaction($this->epayco, $options);
    }

    /**
     * Legacy SafetyPay creation, unchanged from the pre-SDK-1368
     * implementation: the apify /payment/process/safetypay endpoint, through
     * the shared Resource::request with `$apify = true` (field-name
     * translation via Utils/key_lang_apify.json + AES encryption).
     *
     * @param  object $options data
     * @return object
     */
    private function legacyCreate($options)
    {
        return $this->request(
               "POST",
               "/payment/process/safetypay",
               $api_key = $this->epayco->api_key,
               $options,
               $private_key = $this->epayco->private_key,
               $test = $this->epayco->test,
               $switch = false,
               $lang = $this->epayco->lang,
               false,
               false,
               $apify = true
        );
    }

    /**
     * Return data transaction (SafetyPay query), new in SDK-1368.
     *
     * The legacy apify SafetyPay flow never exposed a query endpoint, so this
     * method has no legacy counterpart and therefore no
     * `transactionMethods: ["safetypay"]` opt-out: it always goes to
     * ms-transaction (GET apiflow.epayco.io/payment/api/v1/transactions/
     * {refPayco}). It is purely additive -- no existing caller can break
     * because no existing caller could call it.
     *
     * The response is remapped into the same shape create() returns (see
     * Epayco\Gateways\MsTransactionSafetypay::mapToLegacyShape), so a caller
     * polling a transaction reads the exact same field names it just got back
     * from create() -- same standard applied to Resources/Bank.php's get() in
     * SDK-1365.
     *
     * @param  String $uid id transaction (ref_payco)
     * @return object
     */
    public function get($uid = null)
    {
        return MsTransactionSafetypay::getTransaction($this->epayco, $uid);
    }
}
