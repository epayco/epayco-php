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
 * here is a static, side-effect-free helper besides the four that make actual
 * HTTP calls: login(), createTransaction(), getTransaction() and
 * confirmTransaction().
 *
 * Auth: the same as every other ms-transaction payment method in this SDK
 * (MsTransactionCash, MsTransactionBank, MsTransactionSafetypay): OAuth2
 * client_credentials against apiflow.epayco.io/authentication/api/v2/login.
 * The ms-transaction flow is the same for every payment method; only the
 * payment method itself (`paymentMethod`, `integrationType.modo_pago`)
 * changes. The earlier Basic-auth login against eks-apify-service.epayco.io/
 * login (copied from the sibling Node SDK's SDK-1353 migration) is no longer
 * used -- PSE dropped it the same way in SDK-1365 (QA BUG-04).
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
 * Since 2026-10-02 there IS a captured real legacy Daviplata response to pair
 * against: the create and OTP-confirmation matrices run in green with this SDK
 * (both flows, same input). The field-level notes in mapToLegacyShape() and
 * mapConfirmToLegacyShape() that cite "the legacy response" come from there.
 *
 * Also migrated: Resources/Daviplata::confirm() (the OTP-confirmation step),
 * through ms-transaction's finishTransaction operation
 * (POST /payment/api/v1/transaction/finish) -- see confirmTransaction(). The
 * sibling Node and Python SDKs still leave confirm() on the legacy endpoint;
 * they predate finding that operation.
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
     * - The body carries `address` and `city`, like MsTransactionCash/
     *   MsTransactionBank (but still NO `quotes`). They were left out at first
     *   because neither the ticket's example body nor either sibling SDK sends
     *   them, and the green matrix (2026-10-02) showed the cost: without
     *   `city` the backend stores and answers "SIN CIUDAD", while the legacy
     *   flow answered the caller's own city.
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
     * Bucket the legacy extra1..extra10 options into the `extras` object the new
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
        for ($i = 1; $i <= 10; $i++) {
            if (isset($options["extra" . $i])) {
                $extras["extra" . $i] = $options["extra" . $i];
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
     * extra1..extra10. Keeping the rule keyed on the PHP type the caller
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
     * Log in against the ms-transaction OAuth2 endpoint and return the JWT to
     * use as a Bearer token for createTransaction(), getTransaction() and
     * confirmTransaction(). Same flow as MsTransactionCash::login() and
     * MsTransactionBank::login(): client_credentials against
     * {baseUrlAuth}/authentication/api/v2/login, token in `data.token` (a bare
     * `token` is tolerated too). Not cached: the JWT is short-lived, so callers
     * re-login per request.
     *
     * @param  string $apiKey
     * @param  string $privateKey
     * @param  string $lang 'ES'|'EN'
     * @return string JWT
     */
    public static function login($apiKey, $privateKey, $lang)
    {
        $headers = array("Content-Type" => "application/json", "Accept" => "application/json");
        $body = array(
            "client_id" => $apiKey,
            "client_secret" => $privateKey,
            "grant_type" => "client_credentials",
        );
        $options = array(
            "timeout" => self::REQUEST_TIMEOUT,
            "connect_timeout" => self::REQUEST_TIMEOUT,
        );

        try {
            $response = Requests::post(self::baseUrlAuth() . "/authentication/api/v2/login", $headers, json_encode($body), $options);
        } catch (\Exception $e) {
            throw new ErrorException($lang, 101);
        }

        $json = json_decode($response->body, true);
        $token = null;
        if (is_array($json)) {
            if (isset($json["data"]["token"])) {
                $token = $json["data"]["token"];
            } elseif (isset($json["token"])) {
                $token = $json["token"];
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
     * `lastAction` follows legacy's two variants, the same as
     * MsTransactionSafetypay (verified against the real legacy Daviplata flow,
     * green, 2026-10-02, on both create() and confirm()): "validation data"
     * when a required field is missing (legacy A001, e.g. "El campo 'document'
     * es requerido", "El campo 'otp' es requerido") and "validation
     * transaction" when a value is invalid (E014 minimum amount).
     * ms-transaction does not send legacy's codes, so a missing field is
     * recognised by its message ("El campo document es obligatorio.", "El
     * campo payment method data.otp es obligatorio.").
     *
     * Used for both createTransaction() and confirmTransaction() failures: the
     * legacy create and confirm endpoints answer a validation error with this
     * same shape.
     *
     * @param  array $raw ms-transaction response body
     * @return object legacy-shaped error response
     */
    public static function buildLegacyErrorShape($raw)
    {
        $raw = is_array($raw) ? $raw : array();
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $errors = (isset($data["errors"]) && is_array($data["errors"])) ? $data["errors"] : array();

        $missingField = false;
        foreach ($errors as $error) {
            if (is_array($error) && isset($error["message"]) && preg_match('/\bcampo\b.*\bes (obligatori|requerid)/i', (string)$error["message"])) {
                $missingField = true;
                break;
            }
        }

        $mapped = array(
            "success" => false,
            "titleResponse" => "Error",
            "textResponse" => self::extractErrorMessage($raw),
            "lastAction" => $missingField ? "validation data" : "validation transaction",
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
     * `status` text (Spanish, case-insensitive) -> legacy numeric
     * `codResponse`. Duplicated from MsTransactionCash::codRespuestaFromEstado
     * rather than shared -- see buildExtras()'s docblock. The legacy Daviplata
     * flow answered 3 for a Pendiente (green, 2026-10-02). Adds "aprobado":
     * a Daviplata payment confirmed through finishTransaction is stored with
     * that status, not "Aceptada" (green, 2026-10-02).
     *
     * @param  string $estado e.g. "Pendiente", "Rechazada"
     * @return int 0 for a status this mapping does not know
     */
    public static function codRespuestaFromEstado($estado)
    {
        switch (strtolower(trim((string)$estado))) {
            case "aprobada":
            case "aprobado":
            case "aceptada":
                return 1;
            case "rechazada":
                return 2;
            case "pendiente":
                return 3;
            case "fallida":
                return 4;
            case "reversada":
            case "reversado":
                return 6;
            case "retenido":
                return 7;
            case "abandonada":
                return 10;
            case "cancelada":
                return 11;
            default:
                return 0;
        }
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
     * - A Fallida or Rechazada transaction answers `success: false`,
     *   `titleResponse: "FAIL"` and the backend's reason (`data.response`) in
     *   `textResponse`, keeping the full `data` (the transaction does exist,
     *   with its refPayco). Same rule MsTransactionSafetypay/MsTransactionCash/
     *   MsTransactionBank apply (ADR-002 in the AI workspace). Real case, green,
     *   2026-10-02: with `test: true` ms-transaction creates the transaction
     *   Fallida, "Daviplata no disponible para iniciar la transacción", and it
     *   used to come back as `success: true`.
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
     * - `netoValue` is the value without tax, `data.subtotal` (falling back to
     *   `data.amount` when absent): the real legacy response answers
     *   `value - tax` (10000 for value 11900 with tax 1900, green,
     *   2026-10-02), not the total. Same fix as MsTransactionCash's
     *   `valorneto`.
     *
     * - `extras_epayco` really is snake_case inside an otherwise camelCase
     *   `data` object in the real legacy response. Reproduced verbatim.
     *
     * - `codResponse` is the legacy numeric code derived from the status
     *   (codRespuestaFromEstado: Pendiente 3, Aceptada 1, Rechazada 2,
     *   Fallida 4...), like MsTransactionCash/MsTransactionBank: the legacy
     *   answered 3 for a Pendiente, while ms-transaction's `responseCode` is an
     *   HTTP-like 201/200/"500". `data.responseCode` is only the fallback for a
     *   status the mapping does not know. `codError` is always "" (error codes
     *   travel through buildLegacyErrorShape instead, on the failure path);
     *   the legacy "P004" of a Pendiente has no source in the new response.
     *
     * - `daviplataOtpLab` has no equivalent field in the new response, here or
     *   in either sibling SDK, so it is left `null` rather than invented. It is
     *   kept in the shape because dropping a key a legacy caller may read would
     *   itself be a breaking change.
     *
     * - `idSessionToken`/`tokenExpirationDate` come from
     *   `paymentProviderData.paymentSessionId`/`expirationDateToken`. This is
     *   the mapping that matters most to callers: the legacy `idSessionToken`
     *   is the value Resources/Daviplata::confirm() needs to confirm the OTP
     *   (see README.md's Daviplata "Confirm" example), and
     *   `tokenExpirationDate` tells them until when that OTP is valid. The real
     *   backend names the latter `expirationDateToken` (green, 2026-10-02);
     *   the `paymentSessionExpirationDate` name the sibling SDKs read never
     *   came, so it is kept only as a fallback.
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
     *   create path and still returns something on a query. buildBody() sends
     *   the caller's `city`, so `data.city` is that same city (it used to be
     *   the backend's "SIN CIUDAD" default).
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

        $status = isset($data["status"]) ? strtolower(trim((string)$data["status"])) : "";
        $failed = $status === "fallida" || $status === "rechazada";
        $response = isset($data["response"]) ? $data["response"] : null;

        $codResponse = self::codRespuestaFromEstado($status);
        if ($codResponse === 0) {
            $codResponse = isset($data["responseCode"]) ? $data["responseCode"] : "";
        }

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
            "success" => !$failed,
            "titleResponse" => $failed ? "FAIL" : "SUCCESS",
            "textResponse" => ($failed && $response !== null && $response !== "") ? $response : $message,
            "lastAction" => "Registrar pago en daviplata",
            "data" => array(
                "refPayco" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "invoice" => isset($data["invoice"]) ? $data["invoice"] : null,
                "description" => isset($data["description"]) ? $data["description"] : null,
                "value" => $amount,
                "tax" => isset($data["tax"]) ? $data["tax"] : null,
                "ico" => isset($data["ico"]) ? $data["ico"] : null,
                "taxBase" => isset($data["taxBase"]) ? $data["taxBase"] : null,
                "netoValue" => isset($data["subtotal"]) ? $data["subtotal"] : $amount,
                "currency" => isset($data["currency"]) ? $data["currency"] : null,
                "bank" => "DaviPlata",
                "estatus" => isset($data["status"]) ? $data["status"] : null,
                "response" => $response,
                "autorization" => isset($data["authorization"]) ? $data["authorization"] : null,
                "receipt" => isset($data["receipt"]) ? $data["receipt"] : null,
                "date" => isset($data["date"]) ? $data["date"] : null,
                "franchise" => isset($data["franchise"]) ? $data["franchise"] : null,
                "codResponse" => $codResponse,
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
                "tokenExpirationDate" => isset($providerData["expirationDateToken"])
                    ? $providerData["expirationDateToken"]
                    : (isset($providerData["paymentSessionExpirationDate"]) ? $providerData["paymentSessionExpirationDate"] : null),
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
     * of the migration only affects create() and confirm().
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
     * Map the legacy confirm() options into ms-transaction's
     * finishTransaction body:
     *
     *   {"refPayco": 101659993,
     *    "paymentMethodData": {"otp": "1234", "idSessionToken": "acb"}}
     *
     * Plain JSON, NOT encrypted like createTransaction()'s body: the
     * ms-transaction contract (docs/v2.yaml, FinishTransactionRequest) documents
     * it in clear, and that is what green answered to (2026-10-02).
     *
     * Reads the legacy option names `ref_payco`/`id_session_token`/`otp`
     * (README.md's Daviplata "Confirm" example) and, as a fallback, their
     * apify names `refPayco`/`idSessionToken`, which the legacy flow also
     * accepted (Utils/key_lang_apify.json passes unknown keys through).
     * A numeric `ref_payco` is sent as an integer (the contract types it so);
     * anything else is sent as-is so the backend answers its own validation
     * error. A missing field is left out, not sent as null, for the same
     * reason. `paymentMethodData` is built as `new \stdClass()` when empty so
     * it encodes as `{}`, not `[]` -- see buildBody().
     *
     * @param  array $options caller-supplied options (legacy field names)
     * @return array plaintext finishTransaction body
     */
    public static function buildConfirmBody($options)
    {
        $options = is_array($options) ? $options : array();

        $refPayco = isset($options["ref_payco"]) ? $options["ref_payco"] : (isset($options["refPayco"]) ? $options["refPayco"] : null);
        if ($refPayco !== null && preg_match(self::REF_PAYCO_REGEX, (string)$refPayco)) {
            $refPayco = (int)$refPayco;
        }

        $paymentMethodData = array();
        if (isset($options["otp"])) {
            $paymentMethodData["otp"] = (string)$options["otp"];
        }
        $sessionToken = isset($options["id_session_token"]) ? $options["id_session_token"] : (isset($options["idSessionToken"]) ? $options["idSessionToken"] : null);
        if ($sessionToken !== null) {
            $paymentMethodData["idSessionToken"] = (string)$sessionToken;
        }

        $body = array(
            "paymentMethodData" => count($paymentMethodData) > 0 ? $paymentMethodData : new \stdClass(),
        );
        if ($refPayco !== null) {
            $body = array_merge(array("refPayco" => $refPayco), $body);
        }

        return $body;
    }

    /**
     * Map a finishTransaction response into the shape the legacy
     * /payment/confirm/daviplata endpoint returns. That shape is NOT the
     * create() one: same envelope (success, titleResponse, textResponse,
     * lastAction), but a 7-key `data`. Every literal below was read from the
     * real legacy confirm() responses captured in green on 2026-10-02 (same
     * merchant, same payer, real OTP):
     *
     *   Approved:  {"success": true, "titleResponse": "SUCCESS",
     *               "textResponse": "Aprobada",
     *               "lastAction": "Confirmar pago en daviplata",
     *               "data": {"refPayco": "388205367", "status": "Aprobado",
     *                        "date": "2026-10-02T13:40:17",
     *                        "numApproval": "017134",
     *                        "idTransactionDaviplata": 238867,
     *                        "idTransactionAutorization": "000000238867",
     *                        "response": "Aprobado"}}
     *   Wrong OTP: {"success": false, "titleResponse": "FAILED",
     *               "textResponse": "Código de confirmación incorrecto",
     *               "lastAction": "Confirmar pago en daviplata",
     *               "data": {"refPayco": 388205603, "status": "Rechazada",
     *                        ..., "numApproval": null,
     *                        "idTransactionDaviplata": null,
     *                        "idTransactionAutorization": null,
     *                        "response": "Código de confirmación incorrecto"}}
     *
     * Field-level notes:
     *
     * - `success` is true ONLY for an approved status (Aprobado/Aprobada/
     *   Aceptada) -- a whitelist, not the Fallida/Rechazada blacklist the
     *   create path uses: a confirmation that did not approve the payment, for
     *   whatever reason, is not a success. finishTransaction itself answers
     *   HTTP 200 and `success: true` for a wrong OTP (the transaction is
     *   Rechazada); reporting that as a success is exactly what ADR-002 rules
     *   out.
     * - `refPayco` is a string on approval and an integer otherwise, as the
     *   legacy answered. Reproduced, not normalized, like `autorization`'s
     *   typo in mapToLegacyShape().
     * - `status` on approval is `paymentProviderData.status` when the backend
     *   sends it (the contract's example: data.status "Aceptada",
     *   paymentProviderData.status "Aprobado"), else `data.status` (green sends
     *   "Aprobado" there).
     * - `date` is `paymentProviderData.transactionDate` (the contract), else
     *   `data.date`. Value-level difference: green only sends `data.date`, the
     *   creation date ("2026-10-02 13:48:24"), where the legacy answered the
     *   confirmation date in ISO format.
     * - `numApproval` is `data.authorization`: in the legacy, `numApproval`
     *   ("017134") is the authorization ePayco stores for the transaction (the
     *   later query answered `autorization` 017134).
     *   `paymentProviderData.numApproval` is NOT used: green fills it with a
     *   concatenation (refPayco + date + another number), not an approval
     *   number.
     * - `idTransactionAutorization` is
     *   `paymentProviderData.authorizerTransactionId` and
     *   `idTransactionDaviplata` is that same id as an integer, as the legacy's
     *   pair ("000000238867" / 238867). Green sends the authorization number
     *   there too, unpadded.
     * - `response` is `data.response` ("Aprobada"; the legacy said "Aprobado").
     *
     * A failed request (`success: false`: a missing field, an unknown
     * refPayco) goes through buildLegacyErrorShape(), the same shape the legacy
     * confirm endpoint answers its validation errors with.
     *
     * @param  array $raw finishTransaction response body ({success, message, data})
     * @return object legacy-shaped confirm response
     */
    public static function mapConfirmToLegacyShape($raw)
    {
        $raw = is_array($raw) ? $raw : array();

        if (empty($raw["success"])) {
            return self::buildLegacyErrorShape($raw);
        }

        $message = isset($raw["message"]) ? $raw["message"] : null;
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $providerData = isset($data["paymentProviderData"]) ? $data["paymentProviderData"] : null;
        if (!is_array($providerData) || self::isList($providerData)) {
            $providerData = array();
        }

        $status = isset($data["status"]) ? $data["status"] : null;
        $approved = in_array(strtolower(trim((string)$status)), array("aprobado", "aprobada", "aceptada"), true);
        $response = isset($data["response"]) ? $data["response"] : null;
        $refPayco = isset($data["refPayco"]) ? $data["refPayco"] : null;
        $date = isset($providerData["transactionDate"]) ? $providerData["transactionDate"] : (isset($data["date"]) ? $data["date"] : null);

        if (!$approved) {
            return json_decode(json_encode(array(
                "success" => false,
                "titleResponse" => "FAILED",
                "textResponse" => ($response !== null && $response !== "") ? $response : $message,
                "lastAction" => "Confirmar pago en daviplata",
                "data" => array(
                    "refPayco" => is_numeric($refPayco) ? (int)$refPayco : $refPayco,
                    "status" => $status,
                    "date" => $date,
                    "numApproval" => null,
                    "idTransactionDaviplata" => null,
                    "idTransactionAutorization" => null,
                    "response" => $response,
                ),
            )));
        }

        $authorizerId = isset($providerData["authorizerTransactionId"]) ? $providerData["authorizerTransactionId"] : null;

        return json_decode(json_encode(array(
            "success" => true,
            "titleResponse" => "SUCCESS",
            "textResponse" => $message,
            "lastAction" => "Confirmar pago en daviplata",
            "data" => array(
                "refPayco" => $refPayco === null ? null : (string)$refPayco,
                "status" => isset($providerData["status"]) ? $providerData["status"] : $status,
                "date" => $date,
                "numApproval" => isset($data["authorization"]) ? $data["authorization"] : null,
                "idTransactionDaviplata" => ($authorizerId !== null && ctype_digit((string)$authorizerId)) ? (int)$authorizerId : null,
                "idTransactionAutorization" => $authorizerId,
                "response" => $response,
            ),
        )));
    }

    /**
     * The legacy confirm endpoint's answer when the transaction is no longer
     * Pendiente (confirmed twice, or already rejected), captured in green on
     * 2026-10-02 -- the wording's "parámeros" typo is the legacy's own,
     * reproduced like `autorization` in mapToLegacyShape():
     *
     *   {"success": false, "titleResponse": "Error",
     *    "textResponse": "Los parámeros enviados no son válidos o la
     *                     transacción que intenta procesar ya tiene una
     *                     respuesta",
     *    "lastAction": "Validaciones generales Confirmar Pago DaviPlata",
     *    "data": {"refPayco": 388205367, "status": "Aceptada", ...,
     *             "numApproval": null, "idTransactionDaviplata": null,
     *             "idTransactionAutorization": null, "response": "Aprobada"}}
     *
     * built here from the transaction's current state (a ms-transaction GET).
     *
     * @param  array $raw ms-transaction GET /transactions/{refPayco} body
     * @return object legacy-shaped confirm response
     */
    public static function buildAlreadyAnsweredShape($raw)
    {
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $refPayco = isset($data["refPayco"]) ? $data["refPayco"] : null;

        return json_decode(json_encode(array(
            "success" => false,
            "titleResponse" => "Error",
            "textResponse" => "Los parámeros enviados no son válidos o la transacción que intenta procesar ya tiene una respuesta",
            "lastAction" => "Validaciones generales Confirmar Pago DaviPlata",
            "data" => array(
                "refPayco" => is_numeric($refPayco) ? (int)$refPayco : $refPayco,
                "status" => isset($data["status"]) ? $data["status"] : null,
                "date" => isset($data["date"]) ? $data["date"] : null,
                "numApproval" => null,
                "idTransactionDaviplata" => null,
                "idTransactionAutorization" => null,
                "response" => isset($data["response"]) ? $data["response"] : null,
            ),
        )));
    }

    /**
     * Confirm a Daviplata payment with the OTP the customer received, through
     * ms-transaction's finishTransaction operation
     * (POST /payment/api/v1/transaction/finish on the same apiflow host and
     * with the same Bearer token createTransaction() uses). Resolves with the
     * legacy confirm() shape -- see mapConfirmToLegacyShape().
     *
     * Before finishing, the transaction is read (GET
     * /payment/api/v1/transactions/{refPayco}) and only a Pendiente one is
     * sent to finishTransaction; any other status answers the legacy "already
     * has a response" error (buildAlreadyAnsweredShape) without calling it.
     * This is load-bearing, not a nicety: in green (2026-10-02) calling
     * finishTransaction a second time on an APPROVED transaction made
     * ms-transaction re-validate the already-used OTP with Daviplata, get
     * "Código de confirmación incorrecto", and overwrite the transaction to
     * Rechazada -- an approved, debited payment left rejected. The legacy
     * endpoint refuses that second call instead, and so does this method. It
     * narrows the window rather than closing it (two concurrent confirm()
     * calls can still both see Pendiente); the fix belongs in ms-transaction.
     *
     * The guard fails CLOSED: finishTransaction is only called once the GET
     * has positively answered Pendiente. A `ref_payco` that is not a plain
     * positive integer throws ErrorException 103 before any request (same as
     * getTransaction(); otherwise a value like "0388205367" would skip the
     * GET and could still be coerced to a real refPayco by the backend), a
     * GET error (e.g. "Transacción # N no encontrada.") is returned in the
     * legacy error shape, and a GET answer that is not JSON throws
     * ErrorException 106 -- none of them reaches finishTransaction. Only a
     * missing `ref_payco` goes straight to finishTransaction, which then
     * answers its own validation error without touching any transaction.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/lang)
     * @param  array  $options ref_payco, id_session_token, otp (legacy names)
     * @return object legacy-shaped confirm response
     */
    public static function confirmTransaction($epayco, $options)
    {
        $body = self::buildConfirmBody($options);
        $hasRefPayco = array_key_exists("refPayco", $body);
        if ($hasRefPayco && !is_int($body["refPayco"])) {
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

        if ($hasRefPayco) {
            try {
                $current = Requests::get(self::baseUrl() . "/payment/api/v1/transactions/" . rawurlencode((string)$body["refPayco"]), $headers, $requestOptions);
            } catch (\Exception $e) {
                throw new ErrorException($epayco->lang, 101);
            }
            $currentRaw = json_decode($current->body, true);
            if (!is_array($currentRaw)) {
                throw new ErrorException($epayco->lang, 106);
            }
            if (empty($currentRaw["success"])) {
                return self::buildLegacyErrorShape($currentRaw);
            }
            $currentStatus = isset($currentRaw["data"]["status"]) ? strtolower(trim((string)$currentRaw["data"]["status"])) : "";
            if ($currentStatus !== "pendiente") {
                return self::buildAlreadyAnsweredShape($currentRaw);
            }
        }

        try {
            $response = Requests::post(self::baseUrl() . "/payment/api/v1/transaction/finish", $headers, json_encode($body), $requestOptions);
        } catch (\Exception $e) {
            throw new ErrorException($epayco->lang, 101);
        }

        $raw = json_decode($response->body, true);
        if (!is_array($raw)) {
            throw new ErrorException($epayco->lang, 106);
        }

        return self::mapConfirmToLegacyShape($raw);
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
        return $env ? $env : "https://apiflow.epayco.io";
    }

    /**
     * Base host for the ms-transaction auth API. Same env var
     * (`BASE_URL_MS_TRANSACTION_AUTH`) and default host as
     * MsTransactionCash::baseUrlAuth() and MsTransactionBank::baseUrlAuth(),
     * since every ms-transaction payment method uses the same OAuth2 login.
     * The former Daviplata-only `BASE_URL_MS_TRANSACTION_AUTH_DAVIPLATA`
     * (which pointed at the apify Basic-auth login) is no longer read.
     *
     * @return string
     */
    public static function baseUrlAuth()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION_AUTH");
        return $env ? $env : "https://apiflow.epayco.io";
    }
}
