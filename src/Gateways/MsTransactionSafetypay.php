<?php

namespace Epayco\Gateways;

use Epayco\Client;
use Epayco\Exceptions\ErrorException;
use Epayco\Utils\PaycoAes;
use WpOrg\Requests\Requests;

/**
 * Gateway for the new "ms-transaction" microservice (apiflow.epayco.io) used to
 * create/query SafetyPay transactions, as of SDK-1368, replacing the legacy
 * apify flow used by Epayco\Resources\Safetypay (POST
 * eks-apify-service.epayco.io/payment/process/safetypay, i.e. the
 * pre-SDK-1368 Resource::request(..., $apify = true) call) for merchants that
 * don't opt back into it.
 *
 * Mirrors Epayco\Gateways\MsTransactionBank (SDK-1365) field-for-field for the
 * encryption/auth/generic-transaction-endpoint plumbing shared across
 * ms-transaction payment methods -- only buildBody's paymentMethod/
 * paymentMethodData and mapToLegacyShape's field mapping are
 * SafetyPay-specific. Kept as its own self-contained class (not sharing
 * helpers with MsTransactionBank/MsTransactionCash) on purpose, same criterion
 * already applied in those two classes and in the sibling Node SDK's
 * lib/gateways/msTransactionSafetypay.js -- every method here is a static,
 * side-effect-free helper besides the three that make actual HTTP calls:
 * login(), createTransaction() and getTransaction().
 *
 * Auth handshake: same as MsTransactionBank (HTTP Basic auth,
 * base64(apiKey:privateKey), against eks-apify-service.epayco.io/login,
 * Epayco\Client::BASE_URL_APIFY), NOT MsTransactionCash's OAuth2
 * client_credentials login against apiflow.epayco.io. Verified empirically in
 * the sibling Node SDK's migration of this same flow (SDK-1354), and it is
 * also the same host the legacy SafetyPay flow already authenticated against
 * (Client::authentication()'s $apify = true branch).
 *
 * Endpoints: the GENERIC ms-transaction transaction endpoints, the same ones
 * MsTransactionCash/MsTransactionBank already use --
 * POST /payment/api/v1/transactions and
 * GET /payment/api/v1/transactions/{refPayco}. The SafetyPay-specific
 * endpoints documented in SDK-1368's own Jira description
 * (.../v1/safetypay/transactions) returned a plain 404 ("404 page not found")
 * when the sibling Node SDK's SDK-1354 migration tried them against real
 * pre-prod, while the generic ones returned 200 with a real SafetyPay checkout
 * URL. Corroborated independently by the already-shipped Python SDK's own
 * migration (epaycosdk/gateways/ms_transaction.py), which routes every payment
 * method including safetypay through this same generic endpoint.
 *
 * IMPORTANT for callers, and the one thing that differs most from
 * MsTransactionCash/MsTransactionBank: the legacy response shape this class
 * has to reproduce is camelCase (success, titleResponse, textResponse,
 * lastAction, data.refPayco, data.value, data.urlBank...), NOT the
 * snake_case/Spanish shape (title_response, ref_payco, valor...) those two
 * reproduce. That is not an inconsistency introduced here: Resources/Safetypay
 * already went through the apify backend pre-SDK-1368 (Resource::request's
 * $apify = true branch, eks-apify-service.epayco.io) rather than the older
 * secure.payco.co/restpagos flow Cash/Bank used, and that backend's real
 * response is camelCase. Verified field-by-field against a real paired call to
 * both backends in the sibling Node SDK's SDK-1354 QA, and cross-checked
 * against the already-shipped Python SDK's SafetypayResponseMapper.
 * `data.extras_epayco` really is snake_case inside an otherwise camelCase
 * data object in that real legacy response -- reproduced here verbatim, not
 * normalized.
 *
 * Both createTransaction() and getTransaction() resolve to that same shape --
 * see getTransaction()'s docblock for why the query path remaps too, unlike
 * the sibling Node SDK's equivalent.
 */
class MsTransactionSafetypay
{
    /**
     * AES-256-CBC IV literal used by ms-transaction (mirrors
     * MsTransactionBank::IV / MsTransactionCash::IV -- same rationale,
     * required as-is by the ms-transaction backend, which decrypts every
     * request assuming this exact value).
     */
    const IV = "0000000000000000";

    /**
     * Default per-request timeout (seconds), matching Client::request's own
     * existing 120s timeout/connect_timeout for every other resource in this
     * SDK, and MsTransactionBank::REQUEST_TIMEOUT.
     */
    const REQUEST_TIMEOUT = 120;

    /**
     * `refPayco` is interpolated directly into the request path (see
     * getTransaction below) -- validate strictly first, mirroring
     * MsTransactionBank::REF_PAYCO_REGEX and assertValidRefPayco in the
     * sibling Node SDK's msTransactionSafetypay.js. Reuses error code 103
     * (the same code encryptBody() already throws for a malformed private
     * key) rather than introducing a new one, matching MsTransactionBank.
     */
    const REF_PAYCO_REGEX = '/^[1-9][0-9]*$/';

    /**
     * Legacy `country` option (ISO alpha-2, e.g. "CO") -> the ISO alpha-3 code
     * ms-transaction's `paymentMethodData.country` field requires for
     * SafetyPay specifically. Verified empirically in the sibling Node SDK's
     * SDK-1354 migration: "CO" is rejected there with "El campo country no es
     * valido.", "COL" succeeds. Only Colombia is confirmed directly against
     * the real API; this single-entry map mirrors the already-shipped Python
     * SDK's own `SafetypayRequestMapper._ISO_ALPHA3` (same single entry) --
     * corroborated, not guessed. Other SafetyPay countries (Peru, Mexico...)
     * are absent from Python's map too, so they are not invented here either:
     * toIsoAlpha3() falls back to the value unchanged, same fallback both
     * sibling SDKs use.
     *
     * Note this applies ONLY to `paymentMethodData.country`; the root-level
     * `country` field keeps the plain ISO alpha-2 value every other
     * ms-transaction payment method uses. Both formats are required
     * simultaneously, on the two different fields (confirmed empirically in
     * that same SDK-1354 test).
     *
     * @var array
     */
    public static $isoAlpha3ByAlpha2 = array("CO" => "COL");

    /**
     * @param  string $country ISO alpha-2 country code (legacy `country` option)
     * @return string ISO alpha-3 equivalent if known, otherwise $country as-is
     */
    public static function toIsoAlpha3($country)
    {
        return isset(self::$isoAlpha3ByAlpha2[$country]) ? self::$isoAlpha3ByAlpha2[$country] : $country;
    }

    /**
     * Map the legacy SafetyPay options (see README.md's Safetypay section and
     * Utils/key_lang_apify.json for the legacy field names) into the
     * ms-transaction plaintext body shape verified against the real API by the
     * sibling Node SDK's migration of this same flow (SDK-1354).
     *
     * SafetyPay-specific decisions, all of them deliberate deviations from
     * MsTransactionBank/MsTransactionCash:
     *
     * - `paymentMethod` is "SP" and `paymentMethodData` is
     *   `{country: <ISO alpha-3>, expirationDate: <end_date>}` (see
     *   toIsoAlpha3). `end_date` is SafetyPay's own legacy expiration field --
     *   Utils/key_lang_apify.json already translates it to `expirationDate`
     *   for the legacy flow.
     *
     * - `document` is read from `document` FIRST, falling back to
     *   `doc_number`: `document` is SafetyPay's own documented legacy field
     *   name (README.md's Safetypay example uses `document`, and
     *   Utils/key_lang_apify.json's `"doc_number": "document"` entry only
     *   fires if a caller happens to use Bank's/Cash's `doc_number` naming
     *   instead). Both are accepted so callers migrating between payment
     *   methods don't silently send a null document.
     *
     * - `confirmationMethod` defaults to "POST", NOT "GET" like
     *   MsTransactionBank/MsTransactionCash -- matches both the sibling Node
     *   SDK's and the already-shipped Python SDK's SafetyPay defaults. Still
     *   overridable via `method_confirmation`/`metodoconfirmacion`.
     *
     * - `responseUrl` falls back to `url_confirmation` when `url_response`
     *   isn't supplied. The legacy apify backend tolerated a null
     *   `responseUrl`, ms-transaction rejects it -- found and fixed exactly
     *   this way in the sibling Node SDK (SDK-1354), whose own README example
     *   for SafetyPay never sets `url_response`. This SDK's README example
     *   does set it, so the fallback is a safety net for callers that don't,
     *   not the normal path.
     *
     * - `baseTax` (NOT `taxBase` like MsTransactionBank/MsTransactionCash
     *   send) is the field name both SDK-1368's own Jira request-body example
     *   and the sibling Node SDK's verified SafetyPay implementation use for
     *   the tax base on this payment method. Note the RESPONSE still comes
     *   back as `taxBase` (see mapToLegacyShape) -- asymmetric, but that is
     *   what both references show. The already-shipped Python SDK sends
     *   `taxBase` here instead; that disagreement is unresolved upstream and
     *   flagged in SDK-1368's report rather than papered over by sending both
     *   keys, which would risk a ValidationException on an unexpected field
     *   (ms-transaction does validate field names strictly -- see the
     *   alpha-2/alpha-3 country rejection above).
     *
     * - `integrationType` is `{tipo_checkout: "smart_checkout", modo_pago:
     *   "safetypay"}`. SDK-1368's Jira description shows
     *   `{tipo_checkout: "api", modo_pago: "payment"}` instead, but the values
     *   used here are the ones actually exercised against the real API in the
     *   sibling Node SDK's SDK-1354 QA, and they match the convention
     *   MsTransactionBank/MsTransactionCash already ship in this SDK.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/test)
     * @param  array  $options caller-supplied options (legacy field names)
     * @return array plaintext ms-transaction body
     */
    public static function buildBody($epayco, $options)
    {
        $options = is_array($options) ? $options : array();

        $country = isset($options["country"]) ? $options["country"] : "CO";

        $document = null;
        if (isset($options["document"])) {
            $document = $options["document"];
        } elseif (isset($options["doc_number"])) {
            $document = $options["doc_number"];
        }

        $responseUrl = null;
        if (isset($options["url_response"])) {
            $responseUrl = $options["url_response"];
        } elseif (isset($options["url_confirmation"])) {
            $responseUrl = $options["url_confirmation"];
        }

        $paymentMethodData = array(
            "country" => self::toIsoAlpha3($country),
            "expirationDate" => isset($options["end_date"]) ? $options["end_date"] : null,
        );

        $body = array(
            "invoice" => isset($options["invoice"]) ? $options["invoice"] : null,
            "quotes" => "1",
            "documentType" => isset($options["doc_type"]) ? $options["doc_type"] : null,
            "document" => $document,
            "names" => isset($options["name"]) ? $options["name"] : null,
            "lastNames" => isset($options["last_name"]) ? $options["last_name"] : null,
            "phone" => isset($options["phone"]) ? $options["phone"] : null,
            "cellphone" => isset($options["cell_phone"]) ? $options["cell_phone"] : null,
            "address" => isset($options["address"]) ? $options["address"] : null,
            "city" => isset($options["city"]) ? $options["city"] : null,
            "email" => isset($options["email"]) ? $options["email"] : null,
            "amount" => isset($options["value"]) ? $options["value"] : null,
            "tax" => isset($options["tax"]) ? $options["tax"] : 0,
            "ico" => isset($options["ico"]) ? $options["ico"] : 0,
            "taxBase" => isset($options["tax_base"]) ? $options["tax_base"] : 0,
            "currency" => isset($options["currency"]) ? $options["currency"] : "COP",
            "testMode" => $epayco->test === "TRUE" || $epayco->test === true,
            "uniqueTransactionPerBill" => isset($options["unique_transaction_per_bill"]) && $options["unique_transaction_per_bill"] === true,
            "paymentMethod" => "SP",
            "paymentMethodData" => $paymentMethodData,
            "country" => $country,
            "ip" => isset($options["ip"]) ? $options["ip"] : null,
            "responseUrl" => $responseUrl,
            "confirmationUrl" => isset($options["url_confirmation"]) ? $options["url_confirmation"] : null,
            "confirmationMethod" => isset($options["method_confirmation"])
                ? $options["method_confirmation"]
                : (isset($options["metodoconfirmacion"]) ? $options["metodoconfirmacion"] : "POST"),
            "description" => isset($options["description"]) ? $options["description"] : null,
            "integrationType" => array("tipo_checkout" => "smart_checkout", "modo_pago" => "safetypay"),
            "publicKey" => $epayco->api_key,
            "extras" => self::buildExtras($options),
            // extra5 "P42" mirrors the internal-tracking marker Client::request
            // already auto-injects (data['extras_epayco'] = ['extra5' => 'P42'])
            // for every legacy POST in this PHP SDK specifically, and is the
            // same literal MsTransactionCash/MsTransactionBank already ship.
            // SDK-1368's Jira description says "P43" and the sibling Node SDK
            // uses "P44" -- both are those other codebases' own markers
            // (Python uses "P43" uniformly, Node "P44" uniformly), so neither
            // is a precedent for this repo. Resolved by consistency with this
            // SDK's own Cash/PSE migrations, not left pending.
            "extrasEpayco" => self::buildExtrasEpayco($options),
        );

        $splitPayment = self::buildSplitPayment($options);
        if ($splitPayment !== null) {
            $body["splitPayment"] = $splitPayment;
        }

        return $body;
    }

    /**
     * Bucket the legacy extra1..extra6 options into the `extras` object the
     * new contract expects. Duplicated from MsTransactionBank rather than
     * shared, matching this SDK's (and the sibling Node SDK's) existing
     * convention of each ms-transaction gateway class being self-contained.
     *
     * @param  array $options
     * @return array
     */
    public static function buildExtras($options)
    {
        $extras = array();
        foreach (array("extra1", "extra2", "extra3", "extra4", "extra5", "extra6") as $key) {
            if (isset($options[$key])) {
                $extras[$key] = $options[$key];
            }
        }
        return $extras;
    }

    /**
     * Build the `extrasEpayco` object: the integrator's `extrasEpayco` values
     * win, and extra5 falls back to the "P42" marker only when it was not sent
     * (or was sent empty).
     * Duplicated from MsTransactionCash -- see buildExtras()'s docblock.
     *
     * @param  array $options
     * @return array
     */
    public static function buildExtrasEpayco($options)
    {
        $extras = array_merge(
            array("extra1" => "", "extra2" => "", "extra3" => ""),
            (isset($options["extrasEpayco"]) && is_array($options["extrasEpayco"])) ? $options["extrasEpayco"] : array()
        );
        if (!isset($extras["extra5"]) || $extras["extra5"] === "") {
            $extras["extra5"] = "P42";
        }
        return $extras;
    }

    /**
     * Whether `options` carries any of the legacy split-payment fields, i.e.
     * whether the caller actually opted into split payments at all.
     * Duplicated from MsTransactionBank -- see buildExtras()'s docblock.
     *
     * @param  array $options
     * @return bool
     */
    public static function hasSplitPaymentOptions($options)
    {
        $options = self::normalizeSplitOptions($options);
        return !empty($options["splitpayment"]) || !empty($options["split_app_id"]) ||
            !empty($options["split_merchant_id"]) || !empty($options["split_type"]) ||
            !empty($options["split_primary_receiver"]) || isset($options["split_primary_receiver_fee"]) ||
            !empty($options["split_rule"]) || !empty($options["split_receivers"]) ||
            !empty($options["split_method"]);
    }

    /**
     * Normalize the caller's split-payment options into the ONE flat shape this
     * SDK's README documents for every payment method (its "Split Payments"
     * sections): `splitpayment` plus the flat `split_*` keys at the root of
     * $options, where `split_receivers` is either a JSON string or a plain array
     * of `{id, total, iva, base_iva, fee}` receivers.
     *
     * That flat shape is the canonical, documented one and passes through
     * untouched. What this adds is tolerance for the NESTED shape the sibling
     * Python SDK documents and accepts -- everything bundled under one
     * `split_payment` key:
     *
     *     "split_payment" => array(
     *         "split_app_id" => "...", "split_merchant_id" => "...",
     *         "split_primary_receiver" => "...",
     *         "split_receivers" => array(array("id" => "...", "total" => "...")),
     *     )
     *
     * Why this exists: before it, a caller sending the nested payload to this
     * SDK got a transaction processed with NO split at all and NO error --
     * hasSplitPaymentOptions() only looked at the flat keys, so the entire
     * `split_payment` array was dropped and the response still came back
     * `success: true`. That is the worst failure mode a dispersion can have: the
     * money is not split and nothing says so. The exact mirror of this bug
     * exists in the Python SDK, which reads only the nested shape and silently
     * ignores the flat one -- found from both sides while migrating SafetyPay
     * (SDK-1032 in Python) and Daviplata (SDK-1367 here).
     *
     * A flat key wins over its nested counterpart when both are present, so an
     * explicit top-level value is never overridden by the bundle. `splitpayment`
     * is set to "true" when lifting a bundle that did not carry it, since the
     * nested convention has no equivalent flag.
     *
     * Idempotent -- running it over already-flat options is a no-op, which is
     * why hasSplitPaymentOptions() and buildSplitPayment() can each call it
     * without coordinating. Duplicated in each gateway rather than shared,
     * following this SDK's existing convention of self-contained gateway
     * classes.
     *
     * @param  array $options caller-supplied options, in either convention
     * @return array options in the flat, README-documented convention
     */
    public static function normalizeSplitOptions($options)
    {
        if (!is_array($options)) {
            return array();
        }
        if (!isset($options["split_payment"])) {
            return $options;
        }

        $nested = $options["split_payment"];
        if (is_string($nested)) {
            $decoded = json_decode($nested, true);
            $nested = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($nested) || self::isList($nested)) {
            // Not an associative bundle (empty, or a sequential list) -- there
            // is nothing to lift, so leave $options exactly as it came.
            return $options;
        }

        unset($options["split_payment"]);
        foreach ($nested as $key => $value) {
            if (!isset($options[$key])) {
                $options[$key] = $value;
            }
        }
        if (!isset($options["splitpayment"])) {
            $options["splitpayment"] = "true";
        }

        return $options;
    }

    /**
     * `split_receivers` may arrive as a JSON string or an already-decoded
     * array -- accept both instead of assuming one shape. Duplicated from
     * MsTransactionBank -- see buildExtras()'s docblock.
     *
     * @param  mixed $splitReceivers
     * @return array
     */
    public static function parseSplitReceivers($splitReceivers)
    {
        if ($splitReceivers === null) {
            return array();
        }
        if (is_string($splitReceivers)) {
            $decoded = json_decode($splitReceivers, true);
            return is_array($decoded) ? $decoded : array();
        }
        return is_array($splitReceivers) ? $splitReceivers : array();
    }

    /**
     * Map the legacy snake_case split-payment options into the root-level
     * `splitPayment` object ms-transaction expects -- identical shape and
     * defaults to MsTransactionBank::buildSplitPayment /
     * MsTransactionCash::buildSplitPayment (split payments are not
     * payment-method-specific; the sibling Node SDK's SafetyPay gateway
     * builds the exact same object). Duplicated rather than shared -- see
     * buildExtras()'s docblock.
     *
     * Returns null (not an empty/default array) when the caller didn't pass
     * any split-payment option, so buildBody() only adds `splitPayment` to the
     * request when split payments were actually requested.
     *
     * @param  array $options caller-supplied options (legacy field names)
     * @return array|null
     */
    public static function buildSplitPayment($options)
    {
        $options = self::normalizeSplitOptions($options);
        if (!self::hasSplitPaymentOptions($options)) {
            return null;
        }
        return array(
            "splitMethod" => isset($options["split_method"]) ? $options["split_method"] : "multiple",
            "splitAppId" => isset($options["split_app_id"]) ? $options["split_app_id"] : null,
            "splitMerchantId" => isset($options["split_merchant_id"]) ? $options["split_merchant_id"] : null,
            "splitType" => isset($options["split_type"]) ? $options["split_type"] : "02",
            "splitPrimaryReceiver" => isset($options["split_primary_receiver"]) ? $options["split_primary_receiver"] : null,
            "splitPrimaryReceiverFee" => isset($options["split_primary_receiver_fee"]) ? $options["split_primary_receiver_fee"] : "0",
            "splitRule" => isset($options["split_rule"]) ? $options["split_rule"] : "multiple",
            "splitReceivers" => self::normalizeSplitReceivers(
                self::parseSplitReceivers(isset($options["split_receivers"]) ? $options["split_receivers"] : null)
            ),
        );
    }

    /**
     * Translate each receiver's tax-base key from the name this SDK's README
     * documents (`base_iva`) to the one ms-transaction actually reads
     * (`baseTax`), leaving every other receiver field exactly as the caller
     * sent it (`id`, `total`, `iva`, `fee`).
     *
     * Verified live against pre-prod, and it is not cosmetic: ms-transaction
     * validates PER RECEIVER that `iva + baseTax == total`. Sending the
     * README's `base_iva` means the backend reads no base at all, treats it as
     * 0, and rejects the whole transaction with
     *
     *     "La suma del iva y base iva no concuerda con el monto total por
     *      receiver."
     *
     * so an integrator who follows the README verbatim cannot create a split
     * with `iva > 0`. (With `iva` at 0 the check does not fire, which is why
     * this went unnoticed in earlier QA runs -- they all used `iva: "0"`.) The
     * same probe confirmed `baseTax` is the only accepted spelling: `base_iva`,
     * `base_tax`, `baseIva` and `iva_base` were all rejected, `baseTax` was
     * accepted.
     *
     * Deliberately scoped to the ms-transaction flow only. The legacy backend
     * keeps receiving `base_iva` untouched -- Utils/key_lang.json passes
     * `split_receivers` straight through, so the legacy contract the README
     * documents stays exactly as it is and the opt-out path is unaffected.
     *
     * An explicit `baseTax` from the caller always wins, so callers already
     * sending the backend's own spelling are untouched. `base_tax` and
     * `baseIva` are accepted as aliases too, since both appear in the wild and
     * neither is read by the backend.
     *
     * @param  array $receivers receivers already decoded by parseSplitReceivers
     * @return array receivers with the tax base under `baseTax`
     */
    public static function normalizeSplitReceivers($receivers)
    {
        if (!is_array($receivers)) {
            return array();
        }

        $aliases = array("base_iva", "base_tax", "baseIva");
        $out = array();
        foreach ($receivers as $key => $receiver) {
            if (!is_array($receiver)) {
                $out[$key] = $receiver;
                continue;
            }
            if (!isset($receiver["baseTax"])) {
                foreach ($aliases as $alias) {
                    if (isset($receiver[$alias])) {
                        $receiver["baseTax"] = $receiver[$alias];
                        break;
                    }
                }
            }
            foreach ($aliases as $alias) {
                unset($receiver[$alias]);
            }
            $out[$key] = $receiver;
        }
        return $out;
    }

    /**
     * Whether $array is a plain sequential (0..n-1) list, as opposed to an
     * associative array. Duplicated from MsTransactionBank -- see
     * buildExtras()'s docblock.
     *
     * @param  array $array
     * @return bool
     */
    public static function isList($array)
    {
        if (!is_array($array) || empty($array)) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Encrypt a single value with AES-256-CBC/PKCS7 via Utils\PaycoAes.
     * Duplicated from MsTransactionBank -- see buildExtras()'s docblock.
     *
     * @param  mixed    $value
     * @param  PaycoAes $aes
     * @return string base64 ciphertext
     */
    public static function encryptValue($value, PaycoAes $aes)
    {
        $text = is_string($value) ? $value : json_encode($value);
        return $aes->encrypt($text);
    }

    /**
     * Recursively encrypt every leaf value of a plain array, preserving shape.
     * `publicKey` stays plaintext, null values are omitted. Duplicated from
     * MsTransactionBank -- see buildExtras()'s docblock.
     *
     * @param  array    $data
     * @param  PaycoAes $aes
     * @return array
     */
    public static function encryptObject($data, PaycoAes $aes)
    {
        $out = array();
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($key === "publicKey") {
                $out[$key] = $value;
                continue;
            }
            if (is_array($value) && !self::isList($value)) {
                $out[$key] = self::encryptObject($value, $aes);
                continue;
            }
            $out[$key] = self::encryptValue($value, $aes);
        }
        return $out;
    }

    /**
     * Encrypt a full ms-transaction request body: every field-value
     * AES-encrypted (nested objects encrypted leaf-by-leaf, same shape),
     * except `publicKey` which stays plaintext, plus the "i" (base64 iv) and
     * encrypted "language" fields ms-transaction expects. Duplicated from
     * MsTransactionBank -- see buildExtras()'s docblock.
     *
     * Guards against a misconfigured merchant key silently producing wrong
     * ciphertext: the AES key is `private_key`'s raw bytes with no
     * transformation, so AES-256-CBC requires it to be exactly 32 bytes --
     * fail fast instead of letting openssl_encrypt silently produce ciphertext
     * the backend can't decrypt.
     *
     * @param  array  $body plaintext body (see buildBody)
     * @param  string $privateKey
     * @param  string $lang 'ES'|'EN'
     * @return array
     */
    public static function encryptBody($body, $privateKey, $lang)
    {
        if (!is_string($privateKey) || strlen($privateKey) !== 32) {
            throw new ErrorException($lang, 103);
        }
        $aes = new PaycoAes($privateKey, self::IV, $lang);
        $encrypted = self::encryptObject($body, $aes);
        $encrypted["i"] = base64_encode(self::IV);
        $encrypted["language"] = self::encryptValue(Client::LENGUAGE, $aes);
        return $encrypted;
    }

    /**
     * Log in against the ms-transaction Basic-auth login endpoint and return
     * the JWT to use as a Bearer token for both createTransaction() and
     * getTransaction(). Not cached (the JWT is short-lived, so callers
     * re-login per request), mirroring MsTransactionBank::login(), whose
     * handshake this is identical to -- see this class' own docblock for why
     * SafetyPay uses Basic auth instead of MsTransactionCash's OAuth2
     * client_credentials.
     *
     * Responds with `{token: "..."}` directly; `{data: {token: "..."}}` is
     * also tolerated defensively, same as MsTransactionBank::login().
     *
     * @param  string $apiKey
     * @param  string $privateKey
     * @param  string $lang 'ES'|'EN'
     * @return string JWT
     */
    public static function login($apiKey, $privateKey, $lang)
    {
        $headers = array(
            "Content-Type" => "application/json",
            "Accept" => "application/json",
            "Authorization" => "Basic " . base64_encode($apiKey . ":" . $privateKey),
        );
        $options = array(
            "timeout" => self::REQUEST_TIMEOUT,
            "connect_timeout" => self::REQUEST_TIMEOUT,
        );

        try {
            $response = Requests::post(self::baseUrlAuth() . "/login", $headers, json_encode(array()), $options);
        } catch (\Exception $e) {
            throw new ErrorException($lang, 101);
        }

        $json = json_decode($response->body, true);
        $token = null;
        if (is_array($json)) {
            if (isset($json["token"])) {
                $token = $json["token"];
            } elseif (isset($json["data"]["token"])) {
                $token = $json["data"]["token"];
            }
        }

        if (!$token) {
            throw new ErrorException($lang, 104);
        }

        return $token;
    }

    /**
     * Map a ms-transaction response into the exact response shape the legacy
     * apify SafetyPay endpoint
     * (eks-apify-service.epayco.io/payment/process/safetypay) returns today,
     * so callers get the identical shape regardless of which backend actually
     * served the request, AND regardless of whether they called
     * createTransaction() or getTransaction() for the same refPayco.
     *
     * That legacy shape is camelCase -- see this class' own docblock for why
     * it differs from MsTransactionCash's/MsTransactionBank's snake_case
     * mappings. Verified field-by-field against a real paired call to both
     * backends in the sibling Node SDK's SDK-1354 QA, and cross-checked
     * against the already-shipped Python SDK's `SafetypayResponseMapper`,
     * which maps the same new fields into the same legacy names.
     *
     * Field-level notes, each one load-bearing:
     *
     * - `autorization` (sic, single "h") reproduces the real legacy response's
     *   own typo, read from the new response's correctly-spelled
     *   `data.authorization`. Not a typo introduced here; renaming it would be
     *   the breaking change.
     *
     * - `extras_epayco` really is snake_case inside an otherwise camelCase
     *   `data` object in the real legacy response. Reproduced verbatim.
     *
     * - `codResponse` reads `data.responseCode` (the new flow's own
     *   status/error code, e.g. "P004"), defaulting to "" when absent;
     *   `codError` is always "". The one real successful paired call observed
     *   had both as empty strings, with no error-path example to disambiguate
     *   them further, so this follows the already-shipped Python SDK's
     *   SafetypayResponseMapper rather than guessing independently.
     *
     * - `country` has no equivalent in the new response's `data` object (only
     *   a masked `payerInformation.country`, e.g. "C*"), so it is read from
     *   the caller's original `$options` -- same rationale
     *   MsTransactionCash::mapToLegacyShape uses for PII fields. Falls back to
     *   `data.country` when `$options` has none (the case for getTransaction(),
     *   which has no caller options at all), then to "CO". The sibling Node
     *   SDK only has the `$options`/"CO" pair because its own query path
     *   doesn't remap; the extra `data.country` step only ever fires where
     *   Node would have had nothing to read.
     *
     * - `transactionId`/`ticketId` read `data.refPayco`/`data.receipt`: the
     *   real paired legacy response showed those exact equalities.
     *
     * - `paymentProviderData` is normalized away when it arrives as a JSON
     *   list instead of an object (PHP-decoded: a sequential array), which the
     *   backend does emit when there's no provider data -- otherwise
     *   `urlBank` would read an index off a list. Mirrors the equivalent
     *   `Array.isArray(providerData)` guard in the sibling Node SDK.
     *
     * When `success === false` this delegates to buildLegacyErrorShape()
     * instead -- see that method's docblock for the real legacy failure
     * fixture that justifies it (SDK-1368 QA).
     *
     * @param  array $raw ms-transaction response body ({success, message, data})
     * @param  array $options the original caller-supplied options (may be empty)
     * @return object legacy-shaped response
     */
    /**
     * The real failure detail of a rejected ms-transaction request lives in
     * `data.errors[].message` (a ValidationException shape: `{errorType,
     * errorTypeDescription, errors: [{code, message}]}`), NOT in the top-level
     * `message`, which is a generic, unhelpful "Transaction request" for every
     * validation failure observed. Captured live in SDK-1368 QA: a split whose
     * receivers did not add up to the transaction amount came back as
     * `message: "Transaction request"` with the real reason ("El valor de la
     * transaccion no concuerda a la suma del split.") only inside
     * `data.errors[0].message`. Same gap the sibling Node SDK documents and
     * handles in its own ms-transaction gateways.
     *
     * @param  array $raw ms-transaction response body
     * @return string|null
     */
    public static function extractErrorMessage($raw)
    {
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        if (isset($data["errors"]) && is_array($data["errors"]) && count($data["errors"]) > 0) {
            $messages = array();
            foreach ($data["errors"] as $error) {
                if (is_array($error) && isset($error["message"]) && $error["message"] !== "") {
                    $messages[] = $error["message"];
                }
            }
            if (count($messages) > 0) {
                return implode(" ", $messages);
            }
        }
        return isset($raw["message"]) ? $raw["message"] : null;
    }

    /**
     * Legacy-shaped failure response. The legacy apify SafetyPay endpoint does
     * NOT return its rich transaction-shaped `data` on a rejection -- verified
     * with a real paired call in SDK-1368 QA (same merchant, same options,
     * both backends): legacy answered
     *
     *   {"success": false, "titleResponse": "Error",
     *    "textResponse": "Algunos campos son invalidos, por favor corrija los
     *                     errores y vuelva a intentarlo",
     *    "lastAction": "validation transaction",
     *    "data": {"totalErrors": 1,
     *             "errors": [{"codError": "E033",
     *                         "errorMessage": "La fecha de expiracion no debe
     *                                          superar los 15 dias"}]}}
     *
     * so that exact shape is reproduced here, with ms-transaction's
     * `errors[].code`/`errors[].message` feeding `codError`/`errorMessage`.
     * Known value-level difference (not shape): legacy's `codError` is a real
     * catalogued code ("E033") while ms-transaction sends a UUID -- passed
     * through as-is rather than invented.
     *
     * Without this, a rejection was mapped through the success path and came
     * back as a transaction-shaped object with every field null and the
     * useless generic message -- looking like a partial transaction record
     * when in fact nothing was ever created.
     *
     * A failure carrying no structured `errors` falls back to the same shape
     * minus `data`, mirroring the sibling Node SDK's equivalent fallback.
     *
     * @param  array $raw ms-transaction response body
     * @return object legacy-shaped error response
     */
    public static function buildLegacyErrorShape($raw)
    {
        $raw = is_array($raw) ? $raw : array();
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $errors = (isset($data["errors"]) && is_array($data["errors"])) ? $data["errors"] : array();

        $mapped = array(
            "success" => false,
            "titleResponse" => "Error",
            "textResponse" => self::extractErrorMessage($raw),
            "lastAction" => "validation transaction",
        );

        if (count($errors) > 0) {
            $mapped["data"] = array(
                "totalErrors" => count($errors),
                "errors" => array_map(function ($error) {
                    return array(
                        "codError" => (is_array($error) && isset($error["code"])) ? $error["code"] : null,
                        "errorMessage" => (is_array($error) && isset($error["message"])) ? $error["message"] : null,
                    );
                }, $errors),
            );
        }

        return json_decode(json_encode($mapped));
    }

    public static function mapToLegacyShape($raw, $options = array())
    {
        $raw = is_array($raw) ? $raw : array();
        $options = is_array($options) ? $options : array();

        if (empty($raw["success"])) {
            return self::buildLegacyErrorShape($raw);
        }

        $success = true;
        $message = isset($raw["message"]) ? $raw["message"] : null;
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();

        $providerData = isset($data["paymentProviderData"]) ? $data["paymentProviderData"] : null;
        if (!is_array($providerData) || self::isList($providerData)) {
            $providerData = array();
        }

        $extrasEpaycoNew = isset($data["extrasEpayco"]) && is_array($data["extrasEpayco"]) ? $data["extrasEpayco"] : array();

        if (isset($options["country"])) {
            $country = $options["country"];
        } elseif (isset($data["country"])) {
            $country = $data["country"];
        } else {
            $country = "CO";
        }

        $mapped = array(
            "success" => $success,
            "titleResponse" => $success ? "Ok" : ($message !== null ? $message : "Error"),
            "textResponse" => $message,
            "lastAction" => "Envio Transaction Safetypay",
            "data" => array(
                "refPayco" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "invoice" => isset($data["invoice"]) ? $data["invoice"] : null,
                "description" => isset($data["description"]) ? $data["description"] : null,
                "value" => isset($data["amount"]) ? $data["amount"] : null,
                "tax" => isset($data["tax"]) ? $data["tax"] : null,
                "ico" => isset($data["ico"]) ? $data["ico"] : null,
                "taxBase" => isset($data["taxBase"]) ? $data["taxBase"] : null,
                "currency" => isset($data["currency"]) ? $data["currency"] : null,
                "status" => isset($data["status"]) ? $data["status"] : null,
                "response" => isset($data["response"]) ? $data["response"] : null,
                "codResponse" => isset($data["responseCode"]) ? $data["responseCode"] : "",
                "codError" => "",
                "autorization" => isset($data["authorization"]) ? $data["authorization"] : null,
                "receipt" => isset($data["receipt"]) ? $data["receipt"] : null,
                "date" => isset($data["date"]) ? $data["date"] : null,
                "country" => $country,
                "city" => isset($data["city"]) ? $data["city"] : null,
                "urlBank" => isset($providerData["urlPayment"]) ? $providerData["urlPayment"] : "",
                "transactionId" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "ticketId" => isset($data["receipt"]) ? $data["receipt"] : null,
                "extras" => isset($data["extras"]) ? $data["extras"] : array(),
                "extras_epayco" => array("extra5" => isset($extrasEpaycoNew["extra5"]) ? $extrasEpaycoNew["extra5"] : null),
            ),
        );

        return json_decode(json_encode($mapped));
    }

    /**
     * Create a SafetyPay transaction against the ms-transaction generic
     * transactions endpoint. Resolves with the same response shape the legacy
     * apify endpoint returns (see mapToLegacyShape) -- SDK-1368 requires
     * callers to see one consistent shape regardless of which backend actually
     * served the request.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/test/lang)
     * @param  array  $options caller-supplied options (legacy field names)
     * @return object legacy-shaped response (see mapToLegacyShape)
     */
    public static function createTransaction($epayco, $options)
    {
        $options = is_array($options) ? $options : array();
        if (empty($options["ip"])) {
            $options["ip"] = self::resolveIp();
        }

        $body = self::buildBody($epayco, $options);
        $encryptedBody = self::encryptBody($body, $epayco->private_key, $epayco->lang);
        $token = self::login($epayco->api_key, $epayco->private_key, $epayco->lang);

        $headers = array(
            "Content-Type" => "application/json",
            "Accept" => "application/json",
            "Authorization" => "Bearer " . $token,
        );
        $requestOptions = array(
            "timeout" => self::REQUEST_TIMEOUT,
            "connect_timeout" => self::REQUEST_TIMEOUT,
        );

        try {
            $response = Requests::post(self::baseUrl() . "/payment/api/v1/transactions", $headers, json_encode($encryptedBody), $requestOptions);
        } catch (\Exception $e) {
            throw new ErrorException($epayco->lang, 101);
        }

        $raw = json_decode($response->body, true);
        if (!is_array($raw)) {
            throw new ErrorException($epayco->lang, 106);
        }

        return self::mapToLegacyShape($raw, $options);
    }

    /**
     * Query a SafetyPay transaction by `refPayco` against the ms-transaction
     * generic transactions endpoint (the SAME endpoint
     * MsTransactionCash/MsTransactionBank already use -- confirmed empirically
     * against real pre-prod in the sibling Node SDK's SDK-1354 migration,
     * where SDK-1368's own documented query endpoint,
     * `GET .../v1/safetypay/transactions?ref_payco=...`, returned 404).
     *
     * This is a brand-new capability for SafetyPay in this SDK: the legacy
     * apify flow never had a query endpoint for it (Resources/Safetypay only
     * ever exposed create()). So there is no legacy behavior to preserve and
     * no `transactionMethods` opt-out for it either -- opting out of the
     * migration only affects create().
     *
     * Deliberate deviation from the sibling Node SDK, which returns the raw
     * ms-transaction body from its equivalent getTransaction(): here the
     * response IS remapped through mapToLegacyShape(), so a caller polling a
     * transaction sees the same field names create() just handed them. Same
     * standard already applied to MsTransactionBank::getTransaction in
     * SDK-1365 at the user's explicit request.
     *
     * Because there are no caller options on a query, mapToLegacyShape()'s
     * `country` resolves from the response's own `data.country` when present,
     * then "CO" -- see its docblock.
     *
     * Known gap, same one MsTransactionBank::getTransaction documents:
     * `lastAction` is the create-specific literal "Envio Transaction
     * Safetypay" even on a query, since legacy never had a query response for
     * SafetyPay to copy the wording from. Left as-is rather than invented.
     *
     * @param  object     $epayco the Epayco instance (api_key/private_key/test/lang)
     * @param  string|int $refPayco must be a plain positive integer (see
     *         REF_PAYCO_REGEX)
     * @return object legacy-shaped response (see mapToLegacyShape)
     */
    public static function getTransaction($epayco, $refPayco)
    {
        if (!preg_match(self::REF_PAYCO_REGEX, (string)$refPayco)) {
            throw new ErrorException($epayco->lang, 103);
        }

        $token = self::login($epayco->api_key, $epayco->private_key, $epayco->lang);

        $headers = array(
            "Content-Type" => "application/json",
            "Accept" => "application/json",
            "Authorization" => "Bearer " . $token,
        );
        $requestOptions = array(
            "timeout" => self::REQUEST_TIMEOUT,
            "connect_timeout" => self::REQUEST_TIMEOUT,
        );

        try {
            $response = Requests::get(self::baseUrl() . "/payment/api/v1/transactions/" . rawurlencode((string)$refPayco), $headers, $requestOptions);
        } catch (\Exception $e) {
            throw new ErrorException($epayco->lang, 101);
        }

        $raw = json_decode($response->body, true);
        if (!is_array($raw)) {
            throw new ErrorException($epayco->lang, 106);
        }

        return self::mapToLegacyShape($raw);
    }

    /**
     * Resolve the caller's public IP the same way
     * MsTransactionBank::resolveIp/MsTransactionCash::resolveIp do -- see
     * MsTransactionCash::resolveIp's docblock for the full rationale (ipify,
     * not gethostbyname(gethostname()), which returns the server's own private
     * LAN address). Duplicated rather than shared -- see buildExtras()'s
     * docblock.
     *
     * @return string|null
     */
    public static function resolveIp()
    {
        try {
            $response = Requests::get("https://api.ipify.org?format=json", array(), array(
                "timeout" => self::REQUEST_TIMEOUT,
                "connect_timeout" => self::REQUEST_TIMEOUT,
            ));
            $json = json_decode($response->body, true);
            return isset($json["ip"]) ? $json["ip"] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Base host for the ms-transaction transactions API. Env-overridable,
     * matching MsTransactionCash::baseUrl()/MsTransactionBank::baseUrl() --
     * deliberately the SAME env var (`BASE_URL_MS_TRANSACTION`) and default
     * host, since all three gateways call the exact same generic transactions
     * endpoint.
     *
     * @return string
     */
    public static function baseUrl()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION");
        return $env ? $env : "https://apiflow.epayco.io";
    }

    /**
     * Base host for the SafetyPay Basic-auth login endpoint. Deliberately its
     * OWN env var (`BASE_URL_MS_TRANSACTION_AUTH_SAFETYPAY`), separate from
     * both MsTransactionCash's `BASE_URL_MS_TRANSACTION_AUTH` and
     * MsTransactionBank's `BASE_URL_MS_TRANSACTION_AUTH_PSE`, for the same
     * reason MsTransactionBank gave for splitting its own: an operator
     * redirecting one payment method's auth endpoint must not silently
     * redirect another's. Defaults to Client::BASE_URL_APIFY (not a
     * duplicated literal) -- the exact host/constant the legacy SafetyPay
     * flow already authenticated against via Client::authentication()'s
     * $apify = true branch.
     *
     * @return string
     */
    public static function baseUrlAuth()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION_AUTH_SAFETYPAY");
        return $env ? $env : Client::BASE_URL_APIFY;
    }
}
