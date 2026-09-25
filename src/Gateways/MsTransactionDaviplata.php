<?php

namespace Epayco\Gateways;

use Epayco\Client;
use Epayco\Exceptions\ErrorException;
use Epayco\Utils\PaycoAes;
use WpOrg\Requests\Requests;

/**
 * Gateway for the new "ms-transaction" microservice (apiflow.epayco.io) used to
 * create/query Daviplata transactions, as of SDK-1367, replacing the legacy
 * apify flow used by Epayco\Resources\Daviplata (POST
 * eks-apify-service.epayco.io/payment/process/daviplata, i.e. the pre-SDK-1367
 * Resource::request(..., $apify = true) call) for merchants that don't opt back
 * into it.
 *
 * Mirrors Epayco\Gateways\MsTransactionBank (SDK-1365) and
 * Epayco\Gateways\MsTransactionCash (SDK-1366) field-for-field for the
 * encryption/auth/generic-transaction-endpoint plumbing shared across
 * ms-transaction payment methods -- only buildBody's paymentMethod/
 * paymentMethodData and mapToLegacyShape's field mapping are
 * Daviplata-specific. Kept as its own self-contained class (not sharing helpers
 * with those two) on purpose, same criterion already applied there and in the
 * sibling Node SDK's lib/gateways/msTransactionDaviplata.js -- every method
 * here is a static, side-effect-free helper besides the three that make actual
 * HTTP calls: login(), createTransaction() and getTransaction().
 *
 * Auth handshake: same as MsTransactionBank (HTTP Basic auth,
 * base64(apiKey:privateKey), against eks-apify-service.epayco.io/login,
 * Epayco\Client::BASE_URL_APIFY), NOT MsTransactionCash's OAuth2
 * client_credentials login against apiflow.epayco.io. Verified empirically in
 * the sibling Node SDK's migration of this same flow (SDK-1353, real pre-prod,
 * merchant 630339 -- the same merchant this SDK's own QA uses), and it is also
 * the same host the legacy Daviplata flow already authenticated against
 * (Client::authentication()'s $apify = true branch).
 *
 * Endpoints: the GENERIC ms-transaction transaction endpoints, the same ones
 * MsTransactionCash/MsTransactionBank already use --
 * POST /payment/api/v1/transactions and
 * GET /payment/api/v1/transactions/{refPayco}. Unlike SDK-1365's (PSE) and
 * SDK-1368's (SafetyPay) Jira descriptions, which documented payment-method-
 * specific query paths that actually 404, SDK-1367's own description documents
 * exactly this generic query endpoint -- confirmed empirically in the sibling
 * Node SDK's SDK-1353 QA, where it returned 200 with the Daviplata transaction.
 *
 * IMPORTANT for callers, and unlike MsTransactionCash/MsTransactionBank: the
 * legacy response shape this class has to reproduce is camelCase (success,
 * titleResponse, textResponse, lastAction, data.refPayco, data.value,
 * data.idSessionToken...), NOT the snake_case/Spanish shape (title_response,
 * ref_payco, valor...) those two reproduce. That is not an inconsistency
 * introduced here: Resources/Daviplata already went through the apify backend
 * pre-SDK-1367 (Resource::request's $apify = true branch,
 * eks-apify-service.epayco.io) rather than the older secure.payco.co/restpagos
 * flow Cash/Bank used, and that backend's real response is camelCase -- see
 * Utils/key_lang_apify.json, the legacy flow's own field-name map, which is
 * camelCase throughout (refPayco, idSessionToken, docType, lastName,
 * indCountry...). `data.extras_epayco` really is snake_case inside an otherwise
 * camelCase data object -- reproduced verbatim, not normalized.
 *
 * The legacy field names/shape are NOT independently reverse-engineered here:
 * this repo has no captured real legacy Daviplata response to pair against, so
 * mapToLegacyShape mirrors the already-shipped Python SDK's own migration
 * field-for-field (epayco-python, epaycosdk/mappers/daviplata.py's
 * `DaviplataResponseMapper`, built against a real legacy Daviplata response)
 * and the sibling Node SDK's msTransactionDaviplata.js, which agree with each
 * other. Every deviation from them is called out explicitly in the relevant
 * docblock below.
 *
 * Both createTransaction() and getTransaction() resolve to that same shape --
 * see getTransaction()'s docblock for why the query path remaps too, unlike the
 * sibling Node SDK's equivalent.
 *
 * NOT migrated: Resources/Daviplata::confirm() (the OTP-confirmation step,
 * legacy POST /payment/confirm/daviplata). SDK-1367 only asks for create +
 * query, and ms-transaction's generic transactions endpoint has no equivalent
 * for confirming a session's OTP. Both sibling SDKs made the same call and left
 * confirm() permanently on the legacy endpoint (epayco-node's
 * lib/resources/daviplata.js, epayco-python's epaycosdk/resources.py) --
 * corroborated, not a guess. See Resources/Daviplata::confirm()'s own docblock.
 */
class MsTransactionDaviplata
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
     * MsTransactionBank::REF_PAYCO_REGEX and assertValidRefPayco in the sibling
     * Node SDK's msTransactionDaviplata.js. Reuses error code 103 (the same
     * code encryptBody() already throws for a malformed private key) rather
     * than introducing a new one, matching MsTransactionBank.
     */
    const REF_PAYCO_REGEX = '/^[1-9][0-9]*$/';

    /**
     * Map the legacy Daviplata options (see README.md's Daviplata section and
     * Utils/key_lang_apify.json for the legacy field names) into the
     * ms-transaction plaintext body shape verified against the real API by the
     * sibling Node SDK's migration of this same flow (SDK-1353) and by the
     * already-shipped Python SDK's DaviplataRequestMapper.
     *
     * Daviplata-specific decisions, each one a deliberate deviation from the
     * sibling gateways in this same SDK:
     *
     * - `paymentMethod` is "DP" and `paymentMethodData` is EMPTY (`{}`),
     *   exactly as SDK-1367's own curl example, the sibling Node SDK and the
     *   Python SDK all send it. Daviplata needs no per-method payload at all
     *   (unlike SafetyPay's country/expirationDate or PSE's bank code). It is
     *   built as `new \stdClass()`, not `array()`, because only the former
     *   json_encodes to a real empty OBJECT `{}` -- see encryptObject() for why
     *   that distinction has to be made here, at the call site, instead of in
     *   the encryption helper.
     *
     * - The body carries NO `quotes`, NO `address` and NO `city`, unlike
     *   MsTransactionCash/MsTransactionBank. Neither the ticket's example body
     *   nor either sibling SDK sends them for Daviplata, so they are not
     *   invented here -- note the caller's `address`/`city` options are still
     *   honoured, they just travel back out through mapToLegacyShape (which
     *   reproduces the legacy response's own `data.address`/`data.city`)
     *   instead of into the request.
     *
     * - `document` is read from `document` FIRST, falling back to `doc_number`:
     *   `document` is Daviplata's own documented legacy field name (README.md's
     *   Daviplata example uses `document`, and Utils/key_lang_apify.json's
     *   `"doc_number": "document"` entry only fires if a caller happens to use
     *   Bank's/Cash's `doc_number` naming instead). Both are accepted so
     *   callers migrating between payment methods don't silently send a null
     *   document.
     *
     * - `taxBase` is the field name SDK-1367's own request-body example, the
     *   sibling Node SDK and the Python SDK all agree on for Daviplata.
     *
     * - `confirmationMethod` defaults to "POST", NOT "GET" like
     *   MsTransactionBank/MsTransactionCash -- matches both sibling SDKs'
     *   Daviplata defaults. Still overridable via `method_confirmation`/
     *   `metodoconfirmacion`.
     *
     * - `integrationType` is `{tipo_checkout: "api", modo_pago: "payment"}`.
     *   Here SDK-1367's Jira description, the sibling Node SDK and the Python
     *   SDK all agree, so the ticket's own values are used as-is.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/test)
     * @param  array  $options caller-supplied options (legacy field names)
     * @return array plaintext ms-transaction body
     */
    public static function buildBody($epayco, $options)
    {
        $options = is_array($options) ? $options : array();

        $document = null;
        if (isset($options["document"])) {
            $document = $options["document"];
        } elseif (isset($options["doc_number"])) {
            $document = $options["doc_number"];
        }

        $body = array(
            "invoice" => isset($options["invoice"]) ? $options["invoice"] : null,
            "documentType" => isset($options["doc_type"]) ? $options["doc_type"] : null,
            "document" => $document,
            "names" => isset($options["name"]) ? $options["name"] : null,
            "lastNames" => isset($options["last_name"]) ? $options["last_name"] : null,
            "phone" => isset($options["phone"]) ? $options["phone"] : null,
            "cellphone" => isset($options["cell_phone"]) ? $options["cell_phone"] : null,
            "email" => isset($options["email"]) ? $options["email"] : null,
            "amount" => isset($options["value"]) ? $options["value"] : null,
            "tax" => isset($options["tax"]) ? $options["tax"] : 0,
            "ico" => isset($options["ico"]) ? $options["ico"] : 0,
            "taxBase" => isset($options["tax_base"]) ? $options["tax_base"] : 0,
            "currency" => isset($options["currency"]) ? $options["currency"] : "COP",
            "testMode" => $epayco->test === "TRUE" || $epayco->test === true,
            "uniqueTransactionPerBill" => isset($options["unique_transaction_per_bill"]) && $options["unique_transaction_per_bill"] === true,
            "paymentMethod" => "DP",
            "paymentMethodData" => new \stdClass(),
            "country" => isset($options["country"]) ? $options["country"] : "CO",
            "ip" => isset($options["ip"]) ? $options["ip"] : null,
            "responseUrl" => isset($options["url_response"]) ? $options["url_response"] : null,
            "confirmationUrl" => isset($options["url_confirmation"]) ? $options["url_confirmation"] : null,
            "confirmationMethod" => isset($options["method_confirmation"])
                ? $options["method_confirmation"]
                : (isset($options["metodoconfirmacion"]) ? $options["metodoconfirmacion"] : "POST"),
            "description" => isset($options["description"]) ? $options["description"] : null,
            "integrationType" => array("tipo_checkout" => "api", "modo_pago" => "payment"),
            "publicKey" => $epayco->api_key,
            "extras" => self::buildExtras($options),
            // extra5 "P42" mirrors the internal-tracking marker Client::request
            // already auto-injects (data['extras_epayco'] = ['extra5' => 'P42'])
            // for every legacy POST in this PHP SDK specifically, and is the
            // same literal MsTransactionCash/MsTransactionBank already ship.
            // SDK-1367's Jira description says "P43" and the sibling Node SDK
            // uses "P44" -- both are those other codebases' own markers (Python
            // uses "P43" uniformly, Node "P44" uniformly), so neither is a
            // precedent for this repo. Resolved by consistency with this SDK's
            // own Cash/PSE migrations, not left pending.
            "extrasEpayco" => self::buildExtrasEpayco($options),
        );

        $splitPayment = self::buildSplitPayment($options);
        if ($splitPayment !== null) {
            $body["splitPayment"] = $splitPayment;
        }

        return $body;
    }

    /**
     * Bucket the legacy extra1..extra6 options into the `extras` object the new
     * contract expects. Duplicated from MsTransactionBank rather than shared,
     * matching this SDK's (and the sibling Node SDK's) existing convention of
     * each ms-transaction gateway class being self-contained.
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
     * whether the caller actually opted into split payments at all. Duplicated
     * from MsTransactionBank -- see buildExtras()'s docblock.
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
     * `split_receivers` may arrive as a JSON string or an already-decoded array
     * -- accept both instead of assuming one shape. Duplicated from
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
     * payment-method-specific; the sibling Node SDK's Daviplata gateway builds
     * the exact same object). Duplicated rather than shared -- see
     * buildExtras()'s docblock.
     *
     * Returns null (not an empty/default array) when the caller didn't pass any
     * split-payment option, so buildBody() only adds `splitPayment` to the
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
     * Note an EMPTY array answers true here (it is indistinguishable from an
     * empty list), which is why encryptObject() checks emptiness separately --
     * see its docblock.
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
     * `publicKey` stays plaintext, null values are omitted.
     *
     * ONE deliberate addition over MsTransactionBank::encryptObject /
     * MsTransactionCash::encryptObject, and it is load-bearing for Daviplata
     * specifically: a stdClass VALUE is recursed into and re-cast to stdClass,
     * so json_encode emits `{}` for an empty one. Arrays keep the exact
     * behaviour of the two sibling gateways (associative -> nested object,
     * sequential or empty -> JSON-encoded and encrypted as a leaf).
     *
     * This is how buildBody() can express Daviplata's always-empty
     * `paymentMethodData` as a real empty JSON OBJECT -- what both sibling SDKs
     * send there: the Node gateway (`typeof value === 'object' &&
     * !Array.isArray(value)` -> recurse -> `{}`) and the Python gateway
     * (`isinstance(value, dict)` -> recurse -> `{}`). PHP's `array()` cannot
     * carry that distinction (`json_encode(array())` is `[]`, not `{}`), which
     * is why buildBody() uses `new \stdClass()` for that one field rather than
     * this method special-casing every empty array.
     *
     * Special-casing empty arrays here instead would silently reach two other
     * fields and change them for the worse: `splitPayment.splitReceivers`,
     * which buildSplitPayment() defaults to `array()` (an empty LIST -- it
     * would go out as `{}` where every other gateway and both sibling SDKs send
     * an encrypted "[]"), and `extras` when the caller passes no
     * extra1..extra6. Keeping the rule keyed on the PHP type the caller
     * actually chose leaves both of those byte-identical to
     * MsTransactionBank/MsTransactionCash, which is what real QA has already
     * exercised.
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
            if (is_object($value)) {
                $out[$key] = (object)self::encryptObject((array)$value, $aes);
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
     * AES-encrypted (nested objects encrypted leaf-by-leaf, same shape), except
     * `publicKey` which stays plaintext, plus the "i" (base64 iv) and encrypted
     * "language" fields ms-transaction expects. Duplicated from
     * MsTransactionBank -- see buildExtras()'s docblock.
     *
     * Guards against a misconfigured merchant key silently producing wrong
     * ciphertext: the AES key is `private_key`'s raw bytes with no
     * transformation, so AES-256-CBC requires it to be exactly 32 bytes -- fail
     * fast instead of letting openssl_encrypt silently produce ciphertext the
     * backend can't decrypt.
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
     * getTransaction(). Not cached (the JWT is short-lived, so callers re-login
     * per request), mirroring MsTransactionBank::login(), whose handshake this
     * is identical to -- see this class' own docblock for why Daviplata uses
     * Basic auth instead of MsTransactionCash's OAuth2 client_credentials.
     *
     * Responds with `{token: "..."}` directly; `{data: {token: "..."}}` is also
     * tolerated defensively, same as MsTransactionBank::login().
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
     * The real failure detail of a rejected ms-transaction request lives in
     * `data.errors[].message` (a ValidationException shape: `{errorType,
     * errorTypeDescription, errors: [{code, message}]}`), NOT in the top-level
     * `message`, which is a generic, unhelpful "Transaction request" for every
     * validation failure observed. Found and fixed exactly this way in
     * SDK-1368's SafetyPay migration of this same SDK (captured live: a split
     * whose receivers did not add up to the transaction amount came back as
     * `message: "Transaction request"` with the real reason only inside
     * `data.errors[0].message`) and documented identically in the sibling Node
     * SDK's own gateways -- applied here from the start instead of being
     * discovered a third time.
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
     * Legacy-shaped failure response. The legacy apify endpoints do NOT return
     * their rich transaction-shaped `data` on a rejection -- verified with a
     * real paired call (same merchant, same options, both backends) in
     * SDK-1368's SafetyPay QA against this very SDK, which answered
     *
     *   {"success": false, "titleResponse": "Error",
     *    "textResponse": "<real reason>",
     *    "lastAction": "validation transaction",
     *    "data": {"totalErrors": 1,
     *             "errors": [{"codError": "E033",
     *                         "errorMessage": "<real reason>"}]}}
     *
     * so that exact shape is reproduced here, with ms-transaction's
     * `errors[].code`/`errors[].message` feeding `codError`/`errorMessage`.
     * Known value-level difference (not shape): legacy's `codError` is a real
     * catalogued code ("E033") while ms-transaction sends a UUID -- passed
     * through as-is rather than invented.
     *
     * Without this, a rejection would be mapped through the success path and
     * come back as a transaction-shaped object with every field null and that
     * useless generic message -- looking like a partial transaction record when
     * in fact nothing was ever created. Daviplata rejections are not rare in
     * practice (the sibling Node SDK's SDK-1353 QA hit "Daviplata no disponible
     * para iniciar la transaccion" on its very first real call), which is why
     * this is wired in from the first commit here rather than added later.
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

    /**
     * Map a ms-transaction response into the exact response shape the legacy
     * apify Daviplata endpoint
     * (eks-apify-service.epayco.io/payment/process/daviplata) returns today, so
     * callers get the identical shape regardless of which backend actually
     * served the request, AND regardless of whether they called
     * createTransaction() or getTransaction() for the same refPayco.
     *
     * That legacy shape is camelCase -- see this class' own docblock for why it
     * differs from MsTransactionCash's/MsTransactionBank's snake_case mappings,
     * and for why the field list below mirrors the already-shipped Python SDK's
     * `DaviplataResponseMapper` and the sibling Node SDK's Daviplata gateway
     * (which agree with each other) instead of being reverse-engineered here.
     *
     * Field-level notes, each one load-bearing:
     *
     * - `titleResponse` is the literal "SUCCESS" (uppercase). Both sibling SDKs
     *   use "SUCCESS" for Daviplata specifically -- these literals reproduce
     *   what each legacy endpoint really answered, so they are deliberately not
     *   normalized across payment methods.
     *
     * - `lastAction` is "Registrar pago en daviplata", the legacy endpoint's
     *   own wording (both sibling SDKs use this exact string).
     *
     * - `estatus` (sic, "estatus", NOT "status") reproduces the real legacy
     *   response's own key, read from the new response's `data.status`. Same
     *   category of reproduced quirk as `autorization` below -- renaming either
     *   would be the breaking change.
     *
     * - `autorization` (sic, single "h") reproduces the real legacy response's
     *   own typo, read from the new response's correctly-spelled
     *   `data.authorization`.
     *
     * - `bank` is the constant literal "DaviPlata" (that exact casing): the
     *   legacy response hardcoded it, since the paying "bank" is never anything
     *   else on this payment method. Both sibling SDKs hardcode it too.
     *
     * - `netoValue` is `data.amount`, the same source as `value`: the legacy
     *   response carried both keys with the same number for Daviplata.
     *
     * - `extras_epayco` really is snake_case inside an otherwise camelCase
     *   `data` object in the real legacy response. Reproduced verbatim.
     *
     * - `codResponse` reads `data.responseCode`, defaulting to "" when absent;
     *   `codError` is always "" (error codes travel through
     *   buildLegacyErrorShape instead, on the failure path).
     *
     * - `daviplataOtpLab` has no equivalent field in the new response, here or
     *   in either sibling SDK, so it is left `null` rather than invented. It is
     *   kept in the shape because dropping a key a legacy caller may read would
     *   itself be a breaking change.
     *
     * - `idSessionToken`/`tokenExpirationDate` come from
     *   `paymentProviderData.paymentSessionId`/`paymentSessionExpirationDate`.
     *   This is the mapping that matters most to callers: the legacy
     *   `idSessionToken` is the value Resources/Daviplata::confirm() needs to
     *   confirm the OTP (see README.md's Daviplata "Confirm" example), so a
     *   caller that creates via ms-transaction and then confirms via the legacy
     *   endpoint reads it from exactly the same place as before.
     *
     * - `paymentProviderData` is normalized away when it arrives as a JSON list
     *   instead of an object (PHP-decoded: a sequential array), which the
     *   backend does emit when there's no provider data -- otherwise
     *   `idSessionToken` would read an index off a list. Mirrors the equivalent
     *   `Array.isArray(providerData)` guard in both sibling SDKs.
     *
     * - `docType`/`document`/`name`/`lastName`/`email`/`address`/`indCountry`
     *   are read from the caller's original `$options`: the new response's
     *   `data` carries payer information only in masked form. Both sibling SDKs
     *   do the same. Consequence, documented rather than papered over: on the
     *   getTransaction() path there are no caller options, so these come back
     *   null -- the same known gap MsTransactionBank's query path already has.
     *
     * - `city` is the one field read from `data.city` FIRST (the new response
     *   does carry it unmasked), falling back to the caller's `$options`. The
     *   sibling Node SDK reads only `data.city` and Python reads only
     *   `options["city"]`; taking both, in that order, matches Node on the
     *   create path and still returns something on a query.
     *
     * When `success === false` this delegates to buildLegacyErrorShape()
     * instead -- see that method's docblock.
     *
     * @param  array $raw ms-transaction response body ({success, message, data})
     * @param  array $options the original caller-supplied options (may be empty)
     * @return object legacy-shaped response
     */
    public static function mapToLegacyShape($raw, $options = array())
    {
        $raw = is_array($raw) ? $raw : array();
        $options = is_array($options) ? $options : array();

        if (empty($raw["success"])) {
            return self::buildLegacyErrorShape($raw);
        }

        $message = isset($raw["message"]) ? $raw["message"] : null;
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();

        $providerData = isset($data["paymentProviderData"]) ? $data["paymentProviderData"] : null;
        if (!is_array($providerData) || self::isList($providerData)) {
            $providerData = array();
        }

        $extrasEpaycoNew = isset($data["extrasEpayco"]) && is_array($data["extrasEpayco"]) ? $data["extrasEpayco"] : array();

        $amount = isset($data["amount"]) ? $data["amount"] : null;

        if (isset($data["city"])) {
            $city = $data["city"];
        } elseif (isset($options["city"])) {
            $city = $options["city"];
        } else {
            $city = null;
        }

        $document = null;
        if (isset($options["document"])) {
            $document = $options["document"];
        } elseif (isset($options["doc_number"])) {
            $document = $options["doc_number"];
        }

        $mapped = array(
            "success" => true,
            "titleResponse" => "SUCCESS",
            "textResponse" => $message,
            "lastAction" => "Registrar pago en daviplata",
            "data" => array(
                "refPayco" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "invoice" => isset($data["invoice"]) ? $data["invoice"] : null,
                "description" => isset($data["description"]) ? $data["description"] : null,
                "value" => $amount,
                "tax" => isset($data["tax"]) ? $data["tax"] : null,
                "ico" => isset($data["ico"]) ? $data["ico"] : null,
                "taxBase" => isset($data["taxBase"]) ? $data["taxBase"] : null,
                "netoValue" => $amount,
                "currency" => isset($data["currency"]) ? $data["currency"] : null,
                "bank" => "DaviPlata",
                "estatus" => isset($data["status"]) ? $data["status"] : null,
                "response" => isset($data["response"]) ? $data["response"] : null,
                "autorization" => isset($data["authorization"]) ? $data["authorization"] : null,
                "receipt" => isset($data["receipt"]) ? $data["receipt"] : null,
                "date" => isset($data["date"]) ? $data["date"] : null,
                "franchise" => isset($data["franchise"]) ? $data["franchise"] : null,
                "codResponse" => isset($data["responseCode"]) ? $data["responseCode"] : "",
                "codError" => "",
                "ip" => isset($data["ip"]) ? $data["ip"] : null,
                "testMode" => isset($data["testMode"]) ? $data["testMode"] : null,
                "docType" => isset($options["doc_type"]) ? $options["doc_type"] : null,
                "document" => $document,
                "name" => isset($options["name"]) ? $options["name"] : null,
                "lastName" => isset($options["last_name"]) ? $options["last_name"] : null,
                "email" => isset($options["email"]) ? $options["email"] : null,
                "city" => $city,
                "address" => isset($options["address"]) ? $options["address"] : null,
                "indCountry" => isset($options["ind_country"]) ? $options["ind_country"] : "",
                "idSessionToken" => isset($providerData["paymentSessionId"]) ? $providerData["paymentSessionId"] : null,
                "tokenExpirationDate" => isset($providerData["paymentSessionExpirationDate"]) ? $providerData["paymentSessionExpirationDate"] : null,
                "daviplataOtpLab" => null,
                "extras" => isset($data["extras"]) ? $data["extras"] : array(),
                "extras_epayco" => array("extra5" => isset($extrasEpaycoNew["extra5"]) ? $extrasEpaycoNew["extra5"] : null),
            ),
        );

        return json_decode(json_encode($mapped));
    }

    /**
     * Create a Daviplata transaction against the ms-transaction generic
     * transactions endpoint. Resolves with the same response shape the legacy
     * apify endpoint returns (see mapToLegacyShape) -- SDK-1367 requires
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
     * Query a Daviplata transaction by `refPayco` against the ms-transaction
     * generic transactions endpoint -- the SAME endpoint
     * MsTransactionCash/MsTransactionBank already use, and the exact one
     * SDK-1367's own description documents (unlike SDK-1365's and SDK-1368's
     * descriptions, which documented payment-method-specific query paths that
     * 404). Confirmed empirically against real pre-prod in the sibling Node
     * SDK's SDK-1353 migration.
     *
     * This is a brand-new capability for Daviplata in this SDK: the legacy
     * apify flow never had a query endpoint for it (Resources/Daviplata only
     * ever exposed create() and confirm()). So there is no legacy behavior to
     * preserve and no `transactionMethods` opt-out for it either -- opting out
     * of the migration only affects create().
     *
     * Deliberate deviation from the sibling Node SDK, which returns the raw
     * ms-transaction body from its equivalent getTransaction(): here the
     * response IS remapped through mapToLegacyShape(), so a caller polling a
     * transaction sees the same field names create() just handed them. Same
     * standard already applied to MsTransactionBank::getTransaction in SDK-1365
     * at the user's explicit request.
     *
     * Known gaps on this path, both documented rather than invented:
     * `lastAction` is the create-specific literal "Registrar pago en
     * daviplata", and the PII fields sourced from caller options come back null
     * -- see mapToLegacyShape()'s docblock.
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
     * host, since every one of these gateways calls the exact same generic
     * transactions endpoint.
     *
     * @return string
     */
    public static function baseUrl()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION");
        return $env ? $env : "https://apiflow-green.epayco.co";
    }

    /**
     * Base host for the Daviplata Basic-auth login endpoint. Deliberately its
     * OWN env var (`BASE_URL_MS_TRANSACTION_AUTH_DAVIPLATA`), separate from
     * MsTransactionCash's `BASE_URL_MS_TRANSACTION_AUTH` and
     * MsTransactionBank's `BASE_URL_MS_TRANSACTION_AUTH_PSE`, for the same
     * reason MsTransactionBank gave for splitting its own: an operator
     * redirecting one payment method's auth endpoint must not silently redirect
     * another's. Defaults to Client::BASE_URL_APIFY (not a duplicated literal)
     * -- the exact host/constant the legacy Daviplata flow already
     * authenticated against via Client::authentication()'s $apify = true
     * branch.
     *
     * @return string
     */
    public static function baseUrlAuth()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION_AUTH_DAVIPLATA");
        return $env ? $env : Client::BASE_URL_APIFY;
    }
}
