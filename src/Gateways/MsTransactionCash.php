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
     * @param  object $epayco the Epayco instance (api_key/private_key/test)
     * @param  string $franchise mapped franchise code (see $FRANCHISE_MAP)
     * @param  array  $options caller-supplied options (legacy field names)
     * @return array plaintext ms-transaction body
     */
    public static function buildBody($epayco, $franchise, $options)
    {
        $options = is_array($options) ? $options : array();

        $paymentMethodData = array("franchise" => $franchise);
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
            "integrationType" => array("tipo_checkout" => "smart_checkout", "modo_pago" => "cash"),
            "publicKey" => $epayco->api_key,
            "extras" => self::buildExtras($options),
            // extra5 mirrors the internal-tracking marker Client::request already
            // auto-injects (data['extras_epayco'] = ['extra5' => 'P42']) for every
            // legacy POST, so ePayco's backend keeps identifying this SDK's traffic
            // the same way after the migration.
            "extrasEpayco" => array_merge(
                array("extra1" => "", "extra2" => "", "extra3" => ""),
                (isset($options["extrasEpayco"]) && is_array($options["extrasEpayco"])) ? $options["extrasEpayco"] : array(),
                array("extra5" => "P42")
            ),
        );

        $splitPayment = self::buildSplitPayment($options);
        if ($splitPayment !== null) {
            $body["splitPayment"] = $splitPayment;
        }

        return $body;
    }

    /**
     * Bucket the legacy extra1..extra6 options into the `extras` object the
     * new contract expects.
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
     * Whether `options` carries any of the legacy split-payment fields
     * (see Utils/key_lang.json), i.e. whether the caller actually opted into
     * split payments at all.
     *
     * @param  array $options
     * @return bool
     */
    public static function hasSplitPaymentOptions($options)
    {
        return !empty($options["splitpayment"]) || !empty($options["split_app_id"]) ||
            !empty($options["split_merchant_id"]) || !empty($options["split_type"]) ||
            !empty($options["split_primary_receiver"]) || isset($options["split_primary_receiver_fee"]) ||
            !empty($options["split_rule"]) || !empty($options["split_receivers"]);
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
        if (!self::hasSplitPaymentOptions($options)) {
            return null;
        }
        return array(
            "splitMethod" => "multiple",
            "splitAppId" => isset($options["split_app_id"]) ? $options["split_app_id"] : null,
            "splitMerchantId" => isset($options["split_merchant_id"]) ? $options["split_merchant_id"] : null,
            "splitType" => isset($options["split_type"]) ? $options["split_type"] : "02",
            "splitPrimaryReceiver" => isset($options["split_primary_receiver"]) ? $options["split_primary_receiver"] : null,
            "splitPrimaryReceiverFee" => isset($options["split_primary_receiver_fee"]) ? $options["split_primary_receiver_fee"] : "0",
            "splitRule" => isset($options["split_rule"]) ? $options["split_rule"] : "multiple",
            "splitReceivers" => self::parseSplitReceivers(isset($options["split_receivers"]) ? $options["split_receivers"] : null),
        );
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
     * Map a ms-transaction field-validation error response into a
     * legacy-shaped error response, so callers see the same top-level
     * shape (`success`/`title_response`/`text_response`/`last_action`/`data`)
     * regardless of which kind of error was returned. Best-effort (not
     * verified against a real legacy validation-error response for this
     * SDK specifically), mirroring the equivalent best-effort mapping
     * already in the Python migration of this same flow.
     *
     * @param  array $raw ms-transaction response body
     * @return object
     */
    public static function legacyValidationErrorResponse($raw)
    {
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $errors = isset($data["errors"]) && is_array($data["errors"]) ? $data["errors"] : array();

        $mapped = array(
            "success" => false,
            "title_response" => "ERROR",
            "text_response" => "Algunos campos son obligatorios, corrija los errores e intente nuevamente",
            "last_action" => "validation data",
            "data" => array(
                "totalErrors" => count($errors),
                "errors" => array_map(function ($error) {
                    return array(
                        "cod_error" => isset($error["code"]) ? $error["code"] : null,
                        "error_message" => isset($error["message"]) ? $error["message"] : null,
                    );
                }, $errors),
            ),
        );

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
        if (self::isValidationError($raw)) {
            return self::legacyValidationErrorResponse($raw);
        }

        $options = is_array($options) ? $options : array();
        $success = !empty($raw["success"]);
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $providerData = isset($data["paymentProviderData"]) && is_array($data["paymentProviderData"]) ? $data["paymentProviderData"] : array();
        $extrasEpaycoNew = isset($data["extrasEpayco"]) && is_array($data["extrasEpayco"]) ? $data["extrasEpayco"] : array();

        $mapped = array(
            "success" => $success,
            "title_response" => $success ? "SUCCESS" : "ERROR",
            "text_response" => isset($raw["message"]) ? $raw["message"] : null,
            "last_action" => "Crear pin " . $medio,
            "data" => array(
                "ref_payco" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "factura" => isset($data["invoice"]) ? $data["invoice"] : null,
                "descripcion" => isset($data["description"]) ? $data["description"] : null,
                "valor" => isset($data["amount"]) ? $data["amount"] : null,
                "iva" => isset($data["tax"]) ? $data["tax"] : null,
                "ico" => isset($data["ico"]) ? $data["ico"] : null,
                "baseiva" => isset($data["taxBase"]) ? $data["taxBase"] : null,
                "valorneto" => isset($data["amount"]) ? $data["amount"] : null,
                "moneda" => isset($data["currency"]) ? $data["currency"] : null,
                "banco" => strtoupper($medio),
                "estado" => isset($data["status"]) ? $data["status"] : null,
                "respuesta" => isset($data["response"]) ? $data["response"] : null,
                "autorizacion" => isset($data["authorization"]) ? $data["authorization"] : null,
                "recibo" => isset($data["receipt"]) ? $data["receipt"] : null,
                "fecha" => isset($data["date"]) ? $data["date"] : null,
                "franquicia" => isset($data["franchise"]) ? $data["franchise"] : null,
                "cod_respuesta" => self::codRespuestaFromEstado(isset($data["status"]) ? $data["status"] : null),
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
            $options["ip"] = @gethostbyname(gethostname());
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
     * Base host for the ms-transaction transactions API. Env-overridable,
     * matching the BASE_URL_SECURE_SDK/BASE_APIFY_SDK pattern Client.php
     * already uses for its own base hosts.
     *
     * @return string
     */
    public static function baseUrl()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION");
        return $env ? $env : "https://apiflow.epayco.io";
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
        return $env ? $env : "https://apiflow.epayco.io";
    }
}
