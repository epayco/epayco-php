<?php

namespace Epayco\Resources;

use Epayco\Resource;
use Epayco\Gateways\MsTransactionBank;

/**
 * Bank methods
 */
class Bank extends Resource
{
    /**
     * Return list all banks
     * @return object
     */
    public function pseBank($testMode = null)
    {
        if ($testMode === null) {
            $test = $this->epayco->test === "TRUE" || $this->epayco->test === true;
        } else {
            $test = (bool)$testMode;
        }
        $url = "/payment/pse/banks?test=" . ($test ? "true" : "false");
        return $this->request(
            "GET",
            $url,
            $this->epayco->api_key,
            null,
            $this->epayco->private_key,
            $test,
            false,
            $this->epayco->lang,
            null,
            null,
            true
        );
    }

    /**
     * Create transaction in PSE (bank debit).
     *
     * As of SDK-1365, this goes through the new ms-transaction microservice
     * (apiflow.epayco.io) by default instead of the legacy
     * secure.payco.co/restpagos/pagos/debitos.json endpoint -- see
     * Epayco\Gateways\MsTransactionBank for the request-building/encryption
     * details. A merchant can opt back into the legacy backend for PSE
     * specifically by passing `transactionMethods: ["bank"]` to the Epayco
     * constructor, mirroring the equivalent opt-out Resources/Cash.php
     * already uses for SDK-1366 (`transactionMethods: ["cash"]`).
     *
     * The public signature (`$options`, legacy option names) and the
     * resolved response's shape (see MsTransactionBank::mapToLegacyShape)
     * are unchanged either way: this method never reshapes the legacy
     * response, and it reshapes the new backend's response back into that
     * same legacy shape -- it only changes which backend is called.
     *
     * @param  Object $options data transaction
     * @return object
     */
    public function create($options = null)
    {
        if ($this->epayco->usesLegacyFlow('bank')) {
            return $this->legacyCreate($options);
        }

        return MsTransactionBank::createTransaction($this->epayco, $options);
    }

    /**
     * Legacy PSE creation, unchanged from the pre-SDK-1365 implementation:
     * the secure.payco.co/restpagos/pagos/debitos.json endpoint, through the
     * shared Resource::request (field-name translation + AES encryption,
     * same as every other legacy resource in this repo).
     *
     * @param  Object $options data transaction
     * @return object
     */
    private function legacyCreate($options)
    {
        return $this->request(
            "POST",
            "/pagos/debitos.json",
            $api_key = $this->epayco->api_key,
            $options,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = true,
            $lang = $this->epayco->lang
        );
    }

    /**
     * Return data transaction.
     *
     * As of SDK-1365, this queries the new ms-transaction microservice by
     * default instead of the legacy
     * secure.payco.co/restpagos/pse/transactioninfomation.json endpoint --
     * see Epayco\Gateways\MsTransactionBank::getTransaction. Same
     * `transactionMethods: ["bank"]` opt-out as create() above applies here
     * too.
     *
     * Like create(), this reshapes the new backend's response into the same
     * legacy shape (see MsTransactionBank::getTransaction's own docblock --
     * verified field-by-field against a real GET response, SDK-1365 QA
     * follow-up).
     *
     * @param  String $uid id transaction (ref_payco)
     * @return object
     */
    public function get($uid = null)
    {
        if ($this->epayco->usesLegacyFlow('bank')) {
            return $this->legacyGet($uid);
        }

        return MsTransactionBank::getTransaction($this->epayco, $uid);
    }

    /**
     * Legacy PSE retrieval, unchanged from the pre-SDK-1365 implementation.
     *
     * @param  String $uid id transaction
     * @return object
     */
    private function legacyGet($uid)
    {
        return $this->request(
            "GET",
            "/pse/transactioninfomation.json?transactionID=" . $uid . "&&public_key=" . $this->epayco->api_key,
            $api_key = $this->epayco->api_key,
            $uid,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = true,
            $lang = $this->epayco->lang
        );
    }
}