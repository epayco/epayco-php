<?php

namespace Epayco\Resources;

use Epayco\Resource;
use Epayco\Gateways\MsTransactionDaviplata;

/**
 * Daviplata payment methods
 */
class Daviplata extends Resource
{
    /**
     * Create daviplata trx.
     *
     * As of SDK-1367, this goes through the new ms-transaction microservice
     * (apiflow.epayco.io) by default instead of the legacy apify endpoint
     * eks-apify-service.epayco.io/payment/process/daviplata -- see
     * Epayco\Gateways\MsTransactionDaviplata for the request-building/
     * encryption details. A merchant can opt back into the legacy backend for
     * Daviplata specifically by passing `transactionMethods: ["daviplata"]` to
     * the Epayco constructor, mirroring the equivalent opt-out
     * Resources/Cash.php (SDK-1366, `["cash"]`) and Resources/Bank.php
     * (SDK-1365, `["bank"]`) already use.
     *
     * The public signature (`$options`, legacy option names) and the resolved
     * response's shape (see MsTransactionDaviplata::mapToLegacyShape) are
     * unchanged either way: this method never reshapes the legacy response,
     * and it reshapes the new backend's response back into that same legacy
     * shape -- it only changes which backend is called.
     *
     * @param  object $options data
     * @return object
     */
    public function create($options = null)
    {
        if ($this->epayco->usesLegacyFlow('daviplata')) {
            return $this->legacyCreate($options);
        }

        return MsTransactionDaviplata::createTransaction($this->epayco, $options);
    }

    /**
     * Legacy Daviplata creation, unchanged from the pre-SDK-1367
     * implementation: the apify /payment/process/daviplata endpoint, through
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
               "/payment/process/daviplata",
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
     * Return data transaction (Daviplata query), new in SDK-1367.
     *
     * The legacy apify Daviplata flow never exposed a query endpoint, so this
     * method has no legacy counterpart and therefore no
     * `transactionMethods: ["daviplata"]` opt-out: it always goes to
     * ms-transaction (GET apiflow.epayco.io/payment/api/v1/transactions/
     * {refPayco}). It is purely additive -- no existing caller can break
     * because no existing caller could call it.
     *
     * The response is remapped into the same shape create() returns (see
     * Epayco\Gateways\MsTransactionDaviplata::mapToLegacyShape), so a caller
     * polling a transaction reads the exact same field names it just got back
     * from create() -- same standard applied to Resources/Bank.php's get() in
     * SDK-1365 and Resources/Safetypay.php's in SDK-1368.
     *
     * @param  String $uid id transaction (ref_payco)
     * @return object
     */
    public function get($uid = null)
    {
        return MsTransactionDaviplata::getTransaction($this->epayco, $uid);
    }

    /**
     * Return data confirm.
     *
     * DELIBERATELY NOT MIGRATED to ms-transaction in SDK-1367, and left
     * byte-for-byte on the legacy apify /payment/confirm/daviplata endpoint.
     * Two independent reasons:
     *
     * - SDK-1367's scope is the create and query operations only.
     * - ms-transaction's generic transactions endpoint has no equivalent
     *   operation: confirming a Daviplata payment means submitting the OTP the
     *   customer received for a specific payment session (the
     *   `idSessionToken`/`tokenExpirationDate` create() returns), and the new
     *   contract exposes nothing that accepts it.
     *
     * The already-shipped Python SDK's own ms-transaction migration made the
     * same call -- on its `develop` it migrated `create()`/`get()` to its
     * ms-transaction gateway and left `confirm()` on
     * `payment/confirm/daviplata` (epaycosdk/resources.py's `Daviplata`,
     * lines 454-476) -- read from that source, not assumed.
     *
     * Consequence worth knowing when reading a confirm() response: its shape
     * comes from the legacy backend as it always has, so it is NOT produced by
     * MsTransactionDaviplata::mapToLegacyShape and does not have to match
     * create()'s mapped shape field-for-field.
     *
     * @param  object $options data
     * @return object
     */
    public function confirm($options = null)
    {
        return $this->request(
                "POST",
                "/payment/confirm/daviplata",
                $api_key = $this->epayco->api_key,
                $options,
                $private_key = $this->epayco->private_key,
                $test = $this->epayco->test,
                $switch = true,
                $lang = $this->epayco->lang,
                false,
                false,
                $apify = true
        );
    }
}
