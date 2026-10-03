<?php

namespace Epayco\Gateways;

use Epayco\Client;
use Epayco\Exceptions\ErrorException;
use Epayco\Utils\PaycoAes;
use WpOrg\Requests\Requests;

/**
 * Gateway for the new "ms-transaction" microservice (apiflow.epayco.io) used to
 * create cash (Efectivo) transactions, as of SDK-1366, replacing the legacy
 * secure.payco.co/restpagos/v2/efectivo/{medio} flow used by
 * Epayco\Resources\Cash for merchants that don't opt back into it.
 *
 * Kept isolated from Resource::request on purpose: this flow talks to a
 * different host, a different auth handshake (OAuth2 client_credentials
 * against apiflow.epayco.io/authentication -> short-lived JWT bearer token,
 * instead of Resource::request's own public_key/private_key login+cookie
 * flow) and the same per-field AES-256-CBC encryption scheme already proven
 * for this exact migration in the sibling Node/Python SDKs (epayco-node
 * lib/gateways/msTransactionCash.js, epayco-python
 * epaycosdk/gateways/ms_transaction.py + epaycosdk/mappers/cash.py) --
 * folding it into Resource::request's already-overloaded flag signature
 * ($switch/$cash/$card/$apify) would make that method harder to reason
 * about. Every method here is a static, side-effect-free helper (besides the
 * two that make the actual HTTP calls: login() and createTransaction()), on
 * purpose, so the request-building/encryption/response-mapping logic can be
 * unit-tested without any network access -- see tests/MsTransactionCashTest.php.
 *
 * IMPORTANT for callers: createTransaction() resolves to the exact same
 * response shape the legacy secure.payco.co/restpagos/v2/efectivo/{medio}
 * endpoint returns today (see mapToLegacyShape) -- SDK-1366 requires
 * consumers of this SDK (e.g. cms-backend-platforms) to see one consistent
 * shape regardless of which backend actually served the request.
 */
class MsTransactionCash
{
    /**
     * Legacy `Cash::create($type, $options)` first-argument -> ms-transaction
     * `paymentMethodData.franchise` code. Mirrors FRANCHISE_MAP in
     * epayco-node's lib/gateways/msTransactionCash.js, verified there
     * empirically against the real API (each returned success:true with the
     * matching nameBank).
     *
     * @var array
     */
    public static $FRANCHISE_MAP = array(
        "efecty" => "EF",
        "baloto" => "BA",
        "gana" => "GA",
        "redservi" => "RS",
        "puntored" => "PR",
        "sured" => "SR",
    );

    /**
     * AES-256-CBC IV literal used by ms-transaction (mirrors the equivalent
     * constant already verified empirically in the Node/Python migrations of
     * this same flow). Intentionally the same literal value as Client::IV
     * (kept as an independent constant rather than `const IV = Client::IV;`
     * to avoid forcing Client to be class-loaded at the time this class
     * itself is declared) -- it's required as-is by the ms-transaction
     * backend, which decrypts every request assuming this exact value.
     */
    const IV = "0000000000000000";

    /**
     * Default per-request timeout (seconds), matching Client::request's own
     * existing 120s timeout/connect_timeout for every other resource in this
     * SDK.
     */
    const REQUEST_TIMEOUT = 120;

    /**
     * Map the legacy snake_case cash options (see Resources/Cash.php's
     * public `create($type, $options)` and Utils/key_lang.json for the
     * equivalent legacy field names) into the ms-transaction plaintext body
     * shape verified against the real API by the sibling Node/Python
     * migrations of this same flow.
     *
     * Known, deliberate gap vs. the legacy /v2/efectivo/{medio} flow (no
     * verified equivalent field in the new contract): `type_person` is not
     * forwarded.
     *
     * `end_date` (the pin's expiration date, which the legacy flow sent as
     * `fechaexpiracion` via Utils/key_lang.json) travels as
     * `paymentMethodData.expirationDate`, next to the franchise:
     * `"paymentMethodData": {"franchise": "EF", "expirationDate": "2025-05-16"}`
     * (SDK-1366 QA, BUG-02). Without it ms-transaction expires every pin at
     * creation + 5 days, while the legacy flow honoured the date the merchant
     * asked for. It is sent as given (the legacy format is Y-m-d) and left out
     * when the caller does not send it, so the backend keeps its default.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/test)
     * @param  string $franchise mapped franchise code (see $FRANCHISE_MAP)
     * @param  array  $options caller-supplied options (legacy field names)
     * @return array plaintext ms-transaction body
     */
    public static function buildBody($epayco, $franchise, $options)
    {
        $options = is_array($options) ? $options : array();

        $paymentMethodData = array("franchise" => $franchise);
        if (isset($options["end_date"]) && $options["end_date"] !== "") {
            $paymentMethodData["expirationDate"] = $options["end_date"];
        }
        if (isset($options["credits"]) && $options["credits"] !== null) {
            $paymentMethodData["credits"] = $options["credits"];
        }

        $body = array(
            "invoice" => isset($options["invoice"]) ? $options["invoice"] : null,
            "quotes" => "1",
            "documentType" => isset($options["doc_type"]) ? $options["doc_type"] : null,
            "document" => isset($options["doc_number"]) ? $options["doc_number"] : null,
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
            "paymentMethod" => "CASH",
            "paymentMethodData" => $paymentMethodData,
            "country" => isset($options["country"]) ? $options["country"] : "CO",
            "ip" => isset($options["ip"]) ? $options["ip"] : null,
            "responseUrl" => isset($options["url_response"]) ? $options["url_response"] : null,
            "confirmationUrl" => isset($options["url_confirmation"]) ? $options["url_confirmation"] : null,
            "confirmationMethod" => isset($options["method_confirmation"])
                ? $options["method_confirmation"]
                : (isset($options["metodoconfirmacion"]) ? $options["metodoconfirmacion"] : "GET"),
            "description" => isset($options["description"]) ? $options["description"] : null,
            "integrationType" => array("tipo_checkout" => "api", "modo_pago" => "cash"),
            "publicKey" => $epayco->api_key,
            "extras" => self::buildExtras($options),
            // extra5 mirrors the internal-tracking marker Client::request already
            // auto-injects (data['extras_epayco'] = ['extra5' => 'P42']) for every
            // legacy POST, so ePayco's backend keeps identifying this SDK's traffic
            // the same way after the migration.
            "extrasEpayco" => self::buildExtrasEpayco($options),
        );

        $splitPayment = self::buildSplitPayment($options);
        if ($splitPayment !== null) {
            $body["splitPayment"] = $splitPayment;
        }

        return $body;
    }

    /**
     * Bucket the legacy extra1..extra10 options into the `extras` object the
     * new contract expects. The legacy flow keeps all ten (it answers
     * `data.extras.extra1` to `extra10` with the values sent) and the README
     * documents them; only copying extra1..extra6, as this did before, lost
     * extra7..extra10 silently (SDK-1366 QA, BUG-03).
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
     * Whether `options` carries any of the legacy split-payment fields
     * (see Utils/key_lang.json), i.e. whether the caller actually opted into
     * split payments at all.
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
     * array -- accept both instead of assuming one shape.
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
     * Map the legacy snake_case split-payment options (`splitpayment`,
     * `split_app_id`, `split_merchant_id`, `split_type`,
     * `split_primary_receiver`, `split_primary_receiver_fee`, `split_rule`,
     * `split_receivers` -- same names already used by Utils/key_lang.json for
     * the legacy flow) into the root-level `splitPayment` object
     * ms-transaction expects. Mirrors the already-completed Node/Python
     * migrations' equivalent mapping for this same flow.
     *
     * Returns null (not an empty/default array) when the caller didn't pass
     * any split-payment option, so buildBody() only adds `splitPayment` to
     * the request when split payments were actually requested.
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
     * associative array -- PHP has no distinct list/array types like JS'
     * Array vs. Object, so encryptObject() needs this to decide whether a
     * nested array is a "nested object" (encrypt leaf-by-leaf, e.g.
     * paymentMethodData) or a "leaf value" (encrypt as a single JSON blob,
     * e.g. splitPayment.splitReceivers) -- mirrors
     * !Array.isArray(value)/Array.isArray(value) in the Node migration of
     * this same flow.
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
     * Encrypt a single value with AES-256-CBC/PKCS7 via Utils\PaycoAes (same
     * algorithm/padding this SDK's own legacy secure.payco.co flow already
     * uses via Utils\Util::mergeSet -- reused here, not reimplemented).
     * Non-string values are JSON-encoded first (booleans become the literal
     * "true"/"false" strings ms-transaction expects), mirroring the
     * equivalent step in the Node/Python migrations of this same flow.
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
     * Recursively encrypt every leaf value of a plain array, preserving
     * shape. `publicKey` stays plaintext, null values are omitted (mirrors
     * the equivalent step in the Node/Python migrations of this same flow).
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
     * encrypted "language" fields ms-transaction expects.
     *
     * Guards against a misconfigured merchant key silently producing wrong
     * ciphertext: the AES key is `private_key`'s raw bytes with no
     * transformation (no hashing/derivation), so AES-256-CBC requires it to
     * be exactly 32 bytes -- fail fast instead of letting openssl_encrypt
     * silently produce ciphertext the backend can't decrypt.
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
     * Log in against the ms-transaction OAuth2 endpoint and return the JWT
     * to use as a Bearer token. Not cached: the JWT is short-lived, so
     * callers re-login per request, mirroring the equivalent step in the
     * Node/Python migrations of this same flow.
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
     * `status`/`estado` text (Spanish, case-insensitive) -> legacy
     * `cod_respuesta` numeric code. Mirrors the equivalent mapping already
     * verified (for the "Pendiente" -> 3 case, against a real paired
     * legacy/ms-transaction call, see SDK-1352 QA notes referenced by the
     * Node migration of this same flow) in the sibling Node/Python SDKs.
     *
     * @param  string $estado e.g. "Pendiente", "Rechazada"
     * @return int
     */
    public static function codRespuestaFromEstado($estado)
    {
        switch (strtolower((string)$estado)) {
            case "aprobada":
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
     * Whether $raw is a ms-transaction field-validation error response
     * (`{success:false, data:{errorType, errors:[...]}}`), as opposed to a
     * regular success/business-rejection response -- mirrors
     * is_validation_error() in the Python migration of this same flow.
     *
     * @param  array $raw ms-transaction response body
     * @return bool
     */
    public static function isValidationError($raw)
    {
        return isset($raw["data"]) && is_array($raw["data"]) && array_key_exists("errorType", $raw["data"]);
    }

    /**
     * El detalle real de un rechazo de ms-transaction vive en
     * `data.errors[].message`, NO en el `message` de primer nivel, que es
     * generico e inutil para el comercio ("Transaction request", "Error
     * occurred type exception"). Mismo helper que MsTransactionBank y
     * MsTransactionDaviplata ya tienen; duplicado aca en vez de compartirlo,
     * siguiendo la convencion de gateways autocontenidos de este SDK.
     *
     * @param  array $raw cuerpo de la respuesta de ms-transaction
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
        return isset($raw["message"]) && $raw["message"] !== "" ? $raw["message"] : null;
    }

    /**
     * Map a ms-transaction field-validation error response into the shape the
     * legacy endpoint returns for a failed validation (verified against the
     * real legacy PHP flow, pre-prod, 2026-10-02): `success: false`,
     * `title_response` "Error", `last_action` "validation transaction" and a
     * thin `data` of `{totalerrores, errores: [{codError, errorMessage}]}`
     * (no `idfactura`), in that envelope order.
     *
     * `text_response` keeps the backend's own detail (extractErrorMessage()),
     * so the integrator sees what failed; legacy's generic text is only the
     * fallback when the backend sends no detail at all.
     *
     * Compatibility: before SDK-1366 this method returned `data` as
     * `{totalErrors, errors: [{cod_error, error_message}]}` (develop only, never
     * tagged). Those keys are kept after the legacy ones, with the same
     * content, so an integrator already reading them keeps working. Only
     * `title_response` ("ERROR" -> "Error") and `last_action` ("validation
     * data" -> "validation transaction") could not be kept both ways.
     *
     * @param  array $raw ms-transaction response body
     * @return object
     */
    public static function legacyValidationErrorResponse($raw)
    {
        $raw = is_array($raw) ? $raw : array();
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $errors = isset($data["errors"]) && is_array($data["errors"]) ? $data["errors"] : array();

        // El mensaje real arriba, no el generico. El generico (el del legacy)
        // queda solo como ultimo recurso, cuando el backend no manda detalle.
        $texto = self::extractErrorMessage($raw);
        if ($texto === null) {
            $texto = "Algunos campos son invalidos, por favor corrija los errores y vuelva a intentarlo";
        }

        $mapped = array(
            "success" => false,
            "title_response" => "Error",
            "text_response" => $texto,
            "last_action" => "validation transaction",
        );

        // `data` solo cuando hay errores estructurados que poner ahi. Sin esto,
        // un fallo sin `errors` devolvia `data: {totalerrores: 0, errores: []}`,
        // que no aporta nada, o -- peor, por el enrutado viejo -- un objeto con
        // forma de transaccion y todo en null. Mismo criterio que
        // MsTransactionDaviplata::buildLegacyErrorShape().
        if (count($errors) > 0) {
            $errores = array();
            $erroresAnteriores = array();
            foreach ($errors as $error) {
                $code = (is_array($error) && isset($error["code"])) ? $error["code"] : null;
                $message = (is_array($error) && isset($error["message"])) ? $error["message"] : null;
                $errores[] = array("codError" => $code, "errorMessage" => $message);
                $erroresAnteriores[] = array("cod_error" => $code, "error_message" => $message);
            }
            $mapped["data"] = array(
                "totalerrores" => count($errors),
                "errores" => $errores,
                // Names used before SDK-1366, kept so existing readers don't break.
                "totalErrors" => count($errors),
                "errors" => $erroresAnteriores,
            );
        }

        return json_decode(json_encode($mapped));
    }

    /**
     * Map a ms-transaction response into the exact response shape the
     * legacy secure.payco.co/restpagos/v2/efectivo/{medio} endpoint returns
     * today (see Resources/Cash.php), so callers get the identical shape
     * regardless of which backend actually served the request -- mirrors
     * mapToLegacyShape() in the Node migration and CashResponseMapper in the
     * Python migration of this same flow.
     *
     * PII fields (`documento`/`nombres`/`apellidos`/`email`) are read from
     * the original $options the caller passed, not from the ms-transaction
     * response: that response's payer information is masked for privacy.
     *
     * `cod_respuesta` is derived from `data.status` via
     * codRespuestaFromEstado() above (ms-transaction itself has no numeric
     * equivalent of this field -- only `responseCode`, a string like "P004"
     * that lines up with legacy's separate `cod_error` field instead, mapped
     * below).
     *
     * A `Rechazada`/`Fallida` transaction (cod_respuesta 2/4) comes back with
     * `success: false`, `title_response: "FAIL"` and the backend's reason
     * (`data.response`) in `text_response`, like legacy (SDK-1366 QA); `data`
     * stays complete, since ms-transaction did create the transaction (it has
     * a `ref_payco`). Any other status keeps `success: true` / "SUCCESS".
     *
     * `valorneto` is the amount without tax (`data.subtotal`), like legacy
     * (25000 of 29750); it falls back to `data.amount` when not sent.
     *
     * `cc_network_response` is included below despite having no equivalent
     * field in the ms-transaction response, mirroring what the Node
     * migration verified empirically for `efecty`'s success path: a fixed
     * "not applicable" placeholder the legacy endpoint returns for every
     * non-card cash franchise.
     *
     * @param  array  $raw ms-transaction response body ({success, message, data})
     * @param  array  $options the original caller-supplied options
     * @param  string $medio the lower-cased $type Cash::create($type, $options) was called with
     * @return object legacy-shaped response
     */
    public static function mapToLegacyShape($raw, $options, $medio)
    {
        // Cualquier respuesta con success false significa que NO se creo
        // transaccion: se mapea por la ruta de error, no solo cuando el backend
        // manda la forma de ValidationException (data.errorType). Antes solo se
        // miraba isValidationError(), asi que un fallo sin `errors`
        // estructurados (p.ej. "Error occurred type exception" cuando falta
        // `value`) caia por la ruta de EXITO y volvia como un objeto con forma
        // de transaccion y todos los campos en null, ademas de `last_action:
        // "Crear pin <medio>"` -- pareciendo un registro parcial de transaccion
        // cuando en realidad no se creo nada. Es el mismo bug que se corrigio en
        // SDK-1368 para SafetyPay y que MsTransactionBank/MsTransactionDaviplata
        // ya evitan. Los rechazos de NEGOCIO no entran aca: el backend los manda
        // con success true y la transaccion ya creada (`status` Fallida/Rechazada,
        // p.ej. "Amount must be greater than 20000"); se mapean mas abajo, con
        // success false como el legacy.
        if (self::isValidationError($raw) || empty($raw["success"])) {
            return self::legacyValidationErrorResponse($raw);
        }

        $options = is_array($options) ? $options : array();
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $providerData = isset($data["paymentProviderData"]) && is_array($data["paymentProviderData"]) ? $data["paymentProviderData"] : array();
        $extrasEpaycoNew = isset($data["extrasEpayco"]) && is_array($data["extrasEpayco"]) ? $data["extrasEpayco"] : array();
        $codRespuesta = self::codRespuestaFromEstado(isset($data["status"]) ? $data["status"] : null);
        $failed = $codRespuesta === 2 || $codRespuesta === 4;
        $message = isset($raw["message"]) ? $raw["message"] : null;

        $mapped = array(
            "success" => !$failed,
            "title_response" => $failed ? "FAIL" : "SUCCESS",
            "text_response" => $failed ? (isset($data["response"]) ? $data["response"] : $message) : $message,
            "last_action" => "Crear pin " . $medio,
            "data" => array(
                "ref_payco" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "factura" => isset($data["invoice"]) ? $data["invoice"] : null,
                "descripcion" => isset($data["description"]) ? $data["description"] : null,
                "valor" => isset($data["amount"]) ? $data["amount"] : null,
                "iva" => isset($data["tax"]) ? $data["tax"] : null,
                "ico" => isset($data["ico"]) ? $data["ico"] : null,
                "baseiva" => isset($data["taxBase"]) ? $data["taxBase"] : null,
                "valorneto" => isset($data["subtotal"]) ? $data["subtotal"] : (isset($data["amount"]) ? $data["amount"] : null),
                "moneda" => isset($data["currency"]) ? $data["currency"] : null,
                "banco" => strtoupper($medio),
                "estado" => isset($data["status"]) ? $data["status"] : null,
                "respuesta" => isset($data["response"]) ? $data["response"] : null,
                "autorizacion" => isset($data["authorization"]) ? $data["authorization"] : null,
                "recibo" => isset($data["receipt"]) ? $data["receipt"] : null,
                "fecha" => isset($data["date"]) ? $data["date"] : null,
                "franquicia" => isset($data["franchise"]) ? $data["franchise"] : null,
                "cod_respuesta" => $codRespuesta,
                "cod_error" => isset($data["responseCode"]) ? $data["responseCode"] : null,
                "ip" => isset($data["ip"]) ? $data["ip"] : null,
                "enpruebas" => isset($data["testMode"]) ? $data["testMode"] : null,
                "tipo_doc" => isset($options["doc_type"]) ? $options["doc_type"] : null,
                "documento" => isset($options["doc_number"]) ? $options["doc_number"] : null,
                "nombres" => isset($options["name"]) ? $options["name"] : null,
                "apellidos" => isset($options["last_name"]) ? $options["last_name"] : null,
                "email" => isset($options["email"]) ? $options["email"] : null,
                "ciudad" => isset($options["city"]) ? $options["city"] : "",
                "direccion" => isset($options["address"]) ? $options["address"] : "NA",
                "ind_pais" => isset($options["ind_country"]) ? $options["ind_country"] : null,
                "country_card" => "",
                "extras" => isset($data["extras"]) ? $data["extras"] : null,
                "cc_network_response" => array("code" => "0000", "message" => "Franquicia no registrada"),
                "extras_epayco" => array("extra5" => isset($extrasEpaycoNew["extra5"]) ? $extrasEpaycoNew["extra5"] : null),
                "pin" => isset($providerData["pin"]) ? $providerData["pin"] : null,
                "codigoproyecto" => isset($providerData["agreementCode"]) ? $providerData["agreementCode"] : null,
                "fechapago" => isset($data["date"]) ? $data["date"] : null,
                "fechaexpiracion" => isset($providerData["expirationDate"]) ? $providerData["expirationDate"] : null,
                "factor_conversion" => isset($providerData["trm"]) ? $providerData["trm"] : null,
                "valor_pesos" => isset($data["amount"]) ? $data["amount"] : null,
            ),
        );

        return json_decode(json_encode($mapped));
    }

    /**
     * Create a cash (Efectivo) transaction against the ms-transaction
     * generic transactions endpoint. Resolves with the same response shape
     * the legacy secure.payco.co endpoint returns (see mapToLegacyShape) --
     * SDK-1366 requires callers to see one consistent shape regardless of
     * which backend actually served the request.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/test/lang)
     * @param  string $franchise mapped franchise code (see $FRANCHISE_MAP)
     * @param  string $medio the lower-cased $type Cash::create($type, $options) was called with
     * @param  array  $options caller-supplied options (legacy field names)
     * @return object legacy-shaped response (see mapToLegacyShape)
     */
    public static function createTransaction($epayco, $franchise, $medio, $options)
    {
        $options = is_array($options) ? $options : array();
        if (empty($options["ip"])) {
            $options["ip"] = self::resolveIp();
        }

        $body = self::buildBody($epayco, $franchise, $options);
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

        return self::mapToLegacyShape($raw, $options, $medio);
    }

    /**
     * Query a cash transaction by `ref_payco` against the ms-transaction
     * generic endpoint (GET /payment/api/v1/transactions/{refPayco}, the same
     * one Bank/Daviplata/SafetyPay already query) and answer the shape of the
     * legacy cash query, /restpagos/transaction/response.json -- see
     * mapQueryToLegacyShape(). SDK-1366 QA, BUG-01.
     *
     * A `ref_payco` that is not a plain positive integer answers the legacy
     * "Transacción no existe" response without any request, as the legacy
     * endpoint answered it (and so nothing but digits ever reaches the path).
     *
     * @param  object     $epayco the Epayco instance (api_key/private_key/lang)
     * @param  string|int $refPayco
     * @return object legacy-shaped query response
     */
    public static function getTransaction($epayco, $refPayco)
    {
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$refPayco)) {
            return self::mapQueryToLegacyShape(array("success" => false, "message" => "no encontrada"));
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

        return self::mapQueryToLegacyShape($raw, self::merchantIdFromToken($token));
    }

    /**
     * The merchant id (P_CUST_ID_CLIENTE) an ms-transaction JWT was issued
     * for: its `sub` claim (e.g. "630339"). The token is the one login() just
     * received from apiflow over TLS, so its payload is read without
     * verifying the signature: it only fills `x_cust_id_cliente` in
     * mapQueryToLegacyShape() and must never be used to authorize anything.
     *
     * @internal
     * @param  string $token
     * @return int|null
     */
    public static function merchantIdFromToken($token)
    {
        $parts = explode(".", (string)$token);
        if (count($parts) < 2) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], "-_", "+/")), true);
        if (!is_array($payload) || !isset($payload["sub"]) || !preg_match('/^[0-9]+$/D', (string)$payload["sub"])) {
            return null;
        }
        return (int)$payload["sub"];
    }

    /**
     * Map a ms-transaction query response into the shape of the legacy cash
     * query (/restpagos/transaction/response.json), which is NOT the create()
     * shape: an `x_*` data object. Every key, its order and its type come
     * from the real legacy responses captured in pre-prod on 2026-10-02
     * (gana and efecty, created through the legacy flow and through
     * ms-transaction, queried through the legacy endpoint):
     *
     *   {"success": true, "title_response": "Correcto",
     *    "text_response": "Transacción consultada existosamente",
     *    "last_action": "Consultar Transaccion",
     *    "data": {"x_cust_id_cliente": 630339, "x_ref_payco": 1000015148, ...}}
     *
     * ("existosamente" is the legacy's own typo, reproduced like
     * `autorization` in the Daviplata gateway.) An unknown or invalid
     * reference answered `success: false`, "Error", "Transacción no existe",
     * "Consultar Transaccion" and `data: []`; ms-transaction's
     * "Transacción # N no encontrada." maps to exactly that. Any other failure
     * keeps the same envelope with the backend's own message, or "No se pudo
     * consultar la transacción" when it sends none, so an outage never reads
     * as a transaction that does not exist.
     *
     * Field notes, all from that same comparison:
     *
     * - `x_cust_id_cliente` is the merchant the ms-transaction token was
     *   issued for (its `sub` claim, see merchantIdFromToken()), since the
     *   GET response does not carry the owner. That is the owner only while
     *   ms-transaction answers each merchant its own transactions: today it
     *   does not check it (SDK-1366 QA, BUG-04), which is the ms-transaction
     *   team's fix, not the SDK's (decision of 2026-10-02, kept so the
     *   response stays the legacy one).
     * - `x_signature`, `x_business` (the merchant's name),
     *   `x_customer_phone`, `x_customer_movil` and `x_customer_ind_pais` have
     *   no source in the ms-transaction response and are `null`.
     *   `x_signature` is the legacy's sha256 of the merchant's P_KEY among
     *   other fields, which this SDK does not have.
     * - The payer fields (`x_customer_doctype`, `x_customer_document`,
     *   `x_customer_name`, `x_customer_lastname`, `x_customer_email`,
     *   `x_customer_country`, `x_customer_address`) come from
     *   `payerInformation`, which ms-transaction masks. The legacy masked
     *   most of them too, but not the same way (e.g. "C*" vs "CC").
     * - `x_cod_respuesta`, `x_cod_response` and `x_cod_transaction_state` are
     *   the numeric code of the status (codRespuestaFromEstado()).
     * - `x_mpd_points` 0, `x_cardnumber` "*******" and `x_quotas` "" are what
     *   the legacy answered for every cash transaction.
     * - `x_test_request` is "TRUE" when ms-transaction's `testMode` is 1.
     * - `x_extra1`..`x_extra10` come from `extras`, and one
     *   `x_extraN_epayco` per key of `extrasEpayco`, in the same order: the
     *   legacy answered only `x_extra5_epayco` for a transaction created
     *   through the legacy flow and `x_extra1_epayco`, `x_extra2_epayco`,
     *   `x_extra3_epayco`, `x_extra5_epayco` for one created through
     *   ms-transaction, which is exactly what each one has in `extrasEpayco`.
     *
     * @param  array    $raw ms-transaction GET /transactions/{refPayco} body
     * @param  int|null $merchantId the token's merchant (x_cust_id_cliente)
     * @return object legacy-shaped query response
     */
    public static function mapQueryToLegacyShape($raw, $merchantId = null)
    {
        $raw = is_array($raw) ? $raw : array();

        if (empty($raw["success"])) {
            $message = self::extractErrorMessage($raw);
            $message = is_string($message) && $message !== "" ? $message : null;
            $notFound = $message !== null && preg_match('/no encontrad/i', $message);
            return json_decode(json_encode(array(
                "success" => false,
                "title_response" => "Error",
                "text_response" => $notFound
                    ? "Transacción no existe"
                    : ($message !== null ? $message : "No se pudo consultar la transacción"),
                "last_action" => "Consultar Transaccion",
                "data" => array(),
            )));
        }

        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $get = function ($source, $key, $default = null) {
            return (is_array($source) && array_key_exists($key, $source) && $source[$key] !== null) ? $source[$key] : $default;
        };
        $payer = $get($data, "payerInformation", array());
        $payer = (is_array($payer) && !self::isList($payer)) ? $payer : array();
        $extras = $get($data, "extras", array());
        $extras = (is_array($extras) && !self::isList($extras)) ? $extras : array();
        $extrasEpayco = $get($data, "extrasEpayco", array());
        $extrasEpayco = (is_array($extrasEpayco) && !self::isList($extrasEpayco)) ? $extrasEpayco : array();

        $status = $get($data, "status");
        $code = self::codRespuestaFromEstado($status);
        $amount = self::queryNumber($get($data, "amount"));
        $date = $get($data, "date");

        $x = array(
            "x_cust_id_cliente" => $merchantId,
            "x_ref_payco" => $get($data, "refPayco"),
            "x_id_factura" => $get($data, "invoice"),
            "x_id_invoice" => $get($data, "invoice"),
            "x_description" => $get($data, "description"),
            "x_mpd_points" => 0,
            "x_amount" => $amount,
            "x_amount_country" => $amount,
            "x_amount_ok" => $amount,
            "x_tax" => self::queryNumber($get($data, "tax")),
            "x_tax_ico" => self::queryNumber($get($data, "ico")),
            "x_amount_base" => self::queryNumber($get($data, "taxBase")),
            "x_currency_code" => $get($data, "currency"),
            "x_bank_name" => $get($data, "nameBank"),
            "x_cardnumber" => "*******",
            "x_quotas" => "",
            "x_respuesta" => $status,
            "x_response" => $status,
            "x_approval_code" => $get($data, "authorization"),
            "x_transaction_id" => $get($data, "receipt") === null ? null : (string)$get($data, "receipt"),
            "x_fecha_transaccion" => $date,
            "x_transaction_date" => $date,
            "x_cod_respuesta" => $code,
            "x_cod_response" => $code,
            "x_response_reason_text" => $get($data, "response"),
            "x_cod_transaction_state" => $code,
            "x_transaction_state" => $status,
            "x_errorcode" => $get($data, "responseCode"),
            "x_franchise" => $get($data, "franchise"),
            "x_business" => null,
            "x_customer_doctype" => $get($payer, "documentType"),
            "x_customer_document" => $get($payer, "document"),
            "x_customer_name" => $get($payer, "names"),
            "x_customer_lastname" => $get($payer, "lastNames"),
            "x_customer_email" => $get($payer, "email"),
            "x_customer_phone" => null,
            "x_customer_movil" => null,
            "x_customer_ind_pais" => null,
            "x_customer_country" => $get($payer, "country"),
            "x_customer_city" => $get($data, "city"),
            "x_customer_address" => $get($payer, "address"),
            "x_customer_ip" => $get($data, "ip"),
            "x_signature" => null,
            "x_test_request" => ((string)$get($data, "testMode") === "1") ? "TRUE" : "FALSE",
            "x_transaction_cycle" => null,
        );
        for ($i = 1; $i <= 10; $i++) {
            $x["x_extra" . $i] = $get($extras, "extra" . $i, "");
        }
        $epaycoKeys = array_keys($extrasEpayco);
        sort($epaycoKeys, SORT_NATURAL);
        foreach ($epaycoKeys as $key) {
            if (preg_match('/^extra[0-9]+$/', (string)$key)) {
                $x["x_" . $key . "_epayco"] = $extrasEpayco[$key] === null ? "" : $extrasEpayco[$key];
            }
        }

        return json_decode(json_encode(array(
            "success" => true,
            "title_response" => "Correcto",
            "text_response" => "Transacción consultada existosamente",
            "last_action" => "Consultar Transaccion",
            "data" => $x,
        )));
    }

    /**
     * A numeric string ("0.00", "4750") as the number the legacy query
     * answered (0, 4750); anything else unchanged. Same rule as
     * MsTransactionBank::asNumber().
     *
     * @param  mixed $value
     * @return mixed
     */
    public static function queryNumber($value)
    {
        if (!is_string($value) || !is_numeric($value)) {
            return $value;
        }
        $number = (float)$value;
        return (floor($number) == $number && abs($number) < PHP_INT_MAX) ? (int)$number : $number;
    }

    /**
     * Resolve the caller's public IP the same way the Node/Python migrations
     * of this same flow do (verified empirically): if `options.ip` wasn't
     * provided, ask https://api.ipify.org for the outbound public IP, instead
     * of a host-local lookup like `gethostbyname(gethostname())` -- in a
     * containerized/server environment that resolves to an internal/private
     * IP (e.g. a Docker bridge address), never the actual customer-facing IP
     * ms-transaction expects for fraud/geo checks. Best-effort: falls back to
     * null (same as the field simply being omitted) if the call fails, rather
     * than sending a wrong IP.
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
     * matching the BASE_URL_SECURE_SDK/BASE_APIFY_SDK pattern Client.php
     * already uses for its own base hosts.
     *
     * @return string
     */
    public static function baseUrl()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION");
        return $env ? $env : "https://apiflow-green.epayco.co";
    }

    /**
     * Base host for the ms-transaction auth API. Kept as a separate,
     * independently env-overridable constant from baseUrl() even though they
     * resolve to the same default host today (mirrors the equivalent
     * separation in the Node/Python migrations of this same flow, after
     * their own auth-host consolidation).
     *
     * @return string
     */
    public static function baseUrlAuth()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION_AUTH");
        return $env ? $env : "https://apiflow-green.epayco.co";
    }
}
