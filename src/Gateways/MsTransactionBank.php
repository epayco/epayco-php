<?php

namespace Epayco\Gateways;

use Epayco\Client;
use Epayco\Exceptions\ErrorException;
use Epayco\Utils\PaycoAes;
use WpOrg\Requests\Requests;

/**
 * Gateway for the new "ms-transaction" microservice (apiflow.epayco.io) used to
 * create/query PSE (bank debit) transactions, as of SDK-1365, replacing the
 * legacy secure.payco.co/restpagos/pagos/debitos.json (create) and
 * .../pse/transactioninfomation.json (query) flows used by
 * Epayco\Resources\Bank for merchants that don't opt back into it.
 *
 * Mirrors Epayco\Gateways\MsTransactionCash (SDK-1366) field-for-field for the
 * encryption/generic-transaction-endpoint plumbing shared across
 * ms-transaction payment methods -- only buildBody's paymentMethod/
 * paymentMethodData, mapToLegacyShape's field mapping, and the auth handshake
 * differ. Kept as its own self-contained class (not sharing helpers with
 * MsTransactionCash) on purpose, mirroring the equivalent choice already made
 * in the sibling Node SDK's lib/gateways/msTransactionBank.js ("Kept as its
 * own self-contained module ... on purpose, matching [msTransactionCash.js]'s
 * own note about being independently unit-testable and isolated from
 * Resource#request") -- every method here is a static, side-effect-free
 * helper besides the three that make actual HTTP calls: login(),
 * createTransaction() and getTransaction().
 *
 * IMPORTANT, and the one thing that does NOT mirror MsTransactionCash: the
 * auth handshake. PSE does not use the OAuth2 client_credentials login
 * MsTransactionCash uses against apiflow.epayco.io/authentication/api/v2/login
 * -- it uses HTTP Basic auth (base64(apiKey:privateKey)) against
 * eks-apify-service.epayco.io/login (Epayco\Client::BASE_URL_APIFY, the same
 * host/constant Client::authentication()'s own $apify=true branch already
 * uses for Resources/Bank.php's still-legacy pseBank() listing), which
 * returns {token: "..."} directly (no {data: {token}} wrapper) -- verified
 * empirically in the sibling Node SDK's migration of this same flow
 * (SDK-1355, merchant 630339, bank code 1077).
 *
 * IMPORTANT for callers: both createTransaction() and getTransaction()
 * resolve to the exact same response shape the legacy
 * secure.payco.co/restpagos/pagos/debitos.json (create) /
 * .../pse/transactioninfomation.json (query) endpoints return today (see
 * mapToLegacyShape) -- SDK-1365 requires consumers of this SDK (e.g.
 * cms-backend-platforms) to see one consistent shape regardless of which
 * backend actually served the request, and regardless of whether they just
 * called create() or are polling get() for the same ref_payco afterwards.
 * getTransaction() reuses mapToLegacyShape() as-is (not a variant) --
 * verified field-by-field against a real paired pre-prod call (merchant
 * 630339, ref_payco 1000011709, bank code 1077): the GET response carries
 * the same field names create()'s raw response does (refPayco, invoice,
 * description, amount, tax, ico, taxBase, currency, status, response,
 * responseCode, authorization, receipt, date, extras, extrasEpayco,
 * paymentProviderData.cycle/ticketId/trazabilityCode), plus a handful of
 * extra fields GET carries that create()'s raw response doesn't
 * (subtotal, franchise, nameBank, city, testMode, ip, payerInformation) --
 * mapToLegacyShape() already ignores anything it doesn't explicitly map, so
 * these are silently and safely dropped, same as any other unmapped field.
 * `urlbanco` legitimately resolves to null on a GET response (no
 * `paymentProviderData.urlPayment` there -- the bank-redirect URL doesn't
 * apply once you're just checking status), which is the pre-existing
 * `isset()`-guarded behavior in mapToLegacyShape(), not special-cased here.
 */
class MsTransactionBank
{
    /**
     * AES-256-CBC IV literal used by ms-transaction (mirrors
     * MsTransactionCash::IV -- same rationale, required as-is by the
     * ms-transaction backend, which decrypts every request assuming this
     * exact value).
     */
    const IV = "0000000000000000";

    /**
     * Default per-request timeout (seconds), matching Client::request's own
     * existing 120s timeout/connect_timeout for every other resource in this
     * SDK, and MsTransactionCash::REQUEST_TIMEOUT.
     */
    const REQUEST_TIMEOUT = 120;

    /**
     * `refPayco` is interpolated directly into the request path (see
     * getTransaction below) -- validate strictly first, mirroring
     * assertValidRefPayco in the sibling Node SDK's msTransactionBank.js.
     * Reuses error code 103 (same code this class' own encryptBody() already
     * throws for a malformed private key) rather than introducing a new
     * error code, matching that same Node reference's own choice to reuse
     * its equivalent "invalid input" code for both cases.
     */
    const REF_PAYCO_REGEX = '/^[1-9][0-9]*$/';

    /**
     * Map the legacy snake_case PSE options (see Resources/Bank.php's public
     * `create($options)` and Utils/key_lang.json for the equivalent legacy
     * field names) into the ms-transaction plaintext body shape verified
     * against the real API by the sibling Node SDK's migration of this same
     * flow (SDK-1355, merchant 630339, bank code 1077): `paymentMethod`
     * "PSE" and `paymentMethodData: {typePerson, bankCode}`, with `bank`
     * (legacy bank code) mapping to `bankCode` and `type_person` mapping to
     * `typePerson`.
     *
     * @param  object $epayco the Epayco instance (api_key/private_key/test)
     * @param  array  $options caller-supplied options (legacy field names)
     * @return array plaintext ms-transaction body
     */
    public static function buildBody($epayco, $options)
    {
        $options = is_array($options) ? $options : array();

        $paymentMethodData = array(
            "typePerson" => isset($options["type_person"]) ? $options["type_person"] : null,
            "bankCode" => isset($options["bank"]) ? $options["bank"] : null,
        );

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
            "paymentMethod" => "PSE",
            "paymentMethodData" => $paymentMethodData,
            "country" => isset($options["country"]) ? $options["country"] : "CO",
            "ip" => isset($options["ip"]) ? $options["ip"] : null,
            "responseUrl" => isset($options["url_response"]) ? $options["url_response"] : null,
            "confirmationUrl" => isset($options["url_confirmation"]) ? $options["url_confirmation"] : null,
            "confirmationMethod" => isset($options["method_confirmation"])
                ? $options["method_confirmation"]
                : (isset($options["metodoconfirmacion"]) ? $options["metodoconfirmacion"] : "GET"),
            "description" => isset($options["description"]) ? $options["description"] : null,
            "integrationType" => array("tipo_checkout" => "smart_checkout", "modo_pago" => "pse"),
            "publicKey" => $epayco->api_key,
            "extras" => self::buildExtras($options),
            // extra5 "P42" mirrors the internal-tracking marker Client::request
            // already auto-injects (data['extras_epayco'] = ['extra5' => 'P42'])
            // for every legacy POST in this PHP SDK specifically -- NOT "P44",
            // which is the equivalent Node-SDK-specific marker used by that
            // sibling SDK's own lib/resources/index.js, and only correct there.
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
     * new contract expects. Duplicated from MsTransactionCash rather than
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
     * Whether `options` carries any of the legacy split-payment fields
     * (see Utils/key_lang.json), i.e. whether the caller actually opted into
     * split payments at all. Duplicated from MsTransactionCash -- see
     * buildExtras()'s docblock.
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
     * array -- accept both instead of assuming one shape. Duplicated from
     * MsTransactionCash -- see buildExtras()'s docblock.
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
     * `split_receivers`) into the root-level `splitPayment` object
     * ms-transaction expects -- identical shape/defaults to
     * MsTransactionCash::buildSplitPayment (same field names, same PSE
     * convention verified in the sibling Node SDK's msTransactionBank.js).
     * Duplicated rather than shared -- see buildExtras()'s docblock.
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
     * associative array. Duplicated from MsTransactionCash -- see
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
     * Duplicated from MsTransactionCash -- see buildExtras()'s docblock.
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
     * shape. `publicKey` stays plaintext, null values are omitted.
     * Duplicated from MsTransactionCash -- see buildExtras()'s docblock.
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
     * MsTransactionCash -- see buildExtras()'s docblock.
     *
     * Guards against a misconfigured merchant key silently producing wrong
     * ciphertext: the AES key is `private_key`'s raw bytes with no
     * transformation, so AES-256-CBC requires it to be exactly 32 bytes --
     * fail fast instead of letting openssl_encrypt silently produce
     * ciphertext the backend can't decrypt.
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
     * getTransaction(). Not cached: mirrors MsTransactionCash::login() (the
     * JWT is short-lived, so callers re-login per request) even though the
     * handshake itself differs -- see this class' own docblock for why PSE
     * uses Basic auth instead of MsTransactionCash's OAuth2
     * client_credentials.
     *
     * Verified empirically in the sibling Node SDK's migration of this same
     * flow (SDK-1355): responds with `{token: "..."}` directly, unlike
     * MsTransactionCash::login()'s `{data: {token: "..."}}` (also tolerated
     * here, defensively, the same way MsTransactionCash::login() tolerates
     * both shapes).
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
     * `status`/`estado` text (Spanish, case-insensitive) -> legacy
     * `cod_respuesta` numeric code. Duplicated from
     * MsTransactionCash::codRespuestaFromEstado -- see buildExtras()'s
     * docblock (same mapping, PSE has no equivalent numeric field of its
     * own either).
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
     * When ms-transaction rejects a request, the real failure detail lives in
     * `data.errors[].message` (a ValidationException shape: `{errorType,
     * errorTypeDescription, errors: [{code, message}]}`), not in the
     * top-level `message` field. Mirrors extractErrorMessage() in the
     * sibling Node SDK's msTransactionBank.js/msTransactionCash.js (found
     * there first, SDK-1352/SDK-1354 QA) -- not yet ported to this PHP SDK's
     * own MsTransactionCash, see this class' own top-level docblock /
     * the accompanying report for that gap.
     *
     * @param  array $raw ms-transaction response body ({success, message, data})
     * @return string|null
     */
    public static function extractErrorMessage($raw)
    {
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : null;
        if ($data && isset($data["errors"]) && is_array($data["errors"]) && count($data["errors"]) > 0) {
            $messages = array_filter(array_map(function ($error) {
                return isset($error["message"]) ? $error["message"] : null;
            }, $data["errors"]));
            if (count($messages) > 0) {
                return implode(" ", $messages);
            }
        }
        return isset($raw["message"]) ? $raw["message"] : null;
    }

    /**
     * When ms-transaction rejects a request outright (`raw.success ===
     * false`, i.e. no transaction record was ever created server-side),
     * legacy's own comparable failure response does NOT return a rich
     * transaction-shaped `data` object -- mirrors buildLegacyErrorShape() in
     * the sibling Node SDK's msTransactionBank.js, verified there
     * empirically for PSE specifically (a duplicate-invoice rejection
     * returns legacy's thin `{totalerrores, errores:[{codError,
     * errorMessage}]}` validation shape; a harder failure returns no `data`
     * key at all).
     *
     * @param  array       $raw ms-transaction response body ({success, message, data})
     * @param  string|null $plainAction last_action for the data-less shape;
     *         real paired example for PSE: "Ingresar pago debito Pse".
     * @return object legacy-shaped error response (no fabricated transaction data)
     */
    public static function buildLegacyErrorShape($raw, $plainAction = null)
    {
        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : null;
        $hasStructuredErrors = $data && isset($data["errors"]) && is_array($data["errors"]) && count($data["errors"]) > 0;

        if ($hasStructuredErrors) {
            $mapped = array(
                "success" => false,
                "title_response" => "Error",
                "text_response" => self::extractErrorMessage($raw),
                "last_action" => "validation transaction",
                "data" => array(
                    "totalerrores" => count($data["errors"]),
                    "errores" => array_map(function ($error) {
                        return array(
                            "codError" => isset($error["code"]) ? $error["code"] : null,
                            "errorMessage" => isset($error["message"]) ? $error["message"] : null,
                        );
                    }, $data["errors"]),
                ),
            );
        } else {
            $mapped = array(
                "success" => false,
                "title_response" => "Error",
                "text_response" => self::extractErrorMessage($raw),
                "last_action" => $plainAction ? $plainAction : "validation transaction",
            );
        }

        return json_decode(json_encode($mapped));
    }

    /**
     * Map a successful ms-transaction response into the exact response shape
     * the legacy secure.payco.co/restpagos/pagos/debitos.json (create) /
     * .../pse/transactioninfomation.json (query) endpoints return today (see
     * Resources/Bank.php), so callers get the identical shape regardless of
     * which backend actually served the request, AND regardless of whether
     * they call createTransaction() or getTransaction() for the same
     * ref_payco -- mirrors mapToLegacyShape() in the sibling Node SDK's
     * msTransactionBank.js (SDK-1355), verified there field-by-field against
     * a real paired pre-prod call (merchant 630339, bank code 1077), and
     * verified again directly in this PHP SDK (SDK-1365 QA follow-up)
     * against a real GET response for the same merchant/ref_payco -- same
     * field names, so this single function (no GET-specific variant) is
     * reused for both callers as-is. Any field present in a GET response but
     * not a create() response (subtotal, franchise, nameBank, city,
     * testMode, ip, payerInformation) is simply not read here and dropped,
     * same as any other unmapped field.
     *
     * Known gap, not addressed here: when ms-transaction rejects a GET
     * outright (raw.success === false, e.g. ref_payco not found), this falls
     * through to buildLegacyErrorShape()'s hardcoded `last_action:
     * "Ingresar pago debito Pse"` ("enter debit PSE payment"), which is
     * create()-specific wording -- no real failed-GET response was captured
     * to verify what legacy's own query endpoint says instead, so this is
     * left as-is rather than guessed.
     *
     * PII fields are deliberately NOT read from $options here (unlike
     * MsTransactionCash::mapToLegacyShape): a real legacy PSE response has no
     * `documento`/`nombres`/`apellidos`/`email`/`tipo_doc`/`direccion`/
     * `ind_pais` fields to mirror in the first place (confirmed by the same
     * Node SDK reference).
     *
     * `ticketId` is read from `data.receipt` (a string), NOT from
     * `paymentProviderData.ticketId` (a JSON number): the Node reference's
     * real paired call showed these two disagree in their last digits (a
     * large integer losing precision once JSON-decoded) -- `receipt` is the
     * value that matches legacy's own `recibo`/`ticketId` pair.
     *
     * `transactionID` is read from `data.authorization`: the real legacy
     * response shows `autorizacion` and `transactionID` as the exact same
     * value.
     *
     * Fields present in MsTransactionCash::mapToLegacyShape but NOT present
     * in a real legacy PSE response (`banco`, `franquicia`,
     * `cc_network_response`, `pin`, `codigoproyecto`, `fechapago`,
     * `fechaexpiracion`, `factor_conversion`, `valor_pesos`, `tipo_doc`,
     * `documento`, `nombres`, `apellidos`, `email`, `direccion`,
     * `ind_pais`, `country_card`) are deliberately omitted here rather than
     * guessed.
     *
     * When ms-transaction rejects the request outright (`raw.success ===
     * false`), this returns legacy's own thinner failure shape instead of a
     * hybrid data object -- see buildLegacyErrorShape().
     *
     * @param  array $raw ms-transaction response body ({success, message, data})
     * @return object legacy-shaped response
     */
    public static function mapToLegacyShape($raw)
    {
        $raw = is_array($raw) ? $raw : array();

        if (empty($raw["success"])) {
            return self::buildLegacyErrorShape($raw, "Ingresar pago debito Pse");
        }

        $data = isset($raw["data"]) && is_array($raw["data"]) ? $raw["data"] : array();
        $providerData = isset($data["paymentProviderData"]) && is_array($data["paymentProviderData"]) ? $data["paymentProviderData"] : array();
        $extrasEpaycoNew = isset($data["extrasEpayco"]) && is_array($data["extrasEpayco"]) ? $data["extrasEpayco"] : array();

        $mapped = array(
            "success" => true,
            "title_response" => "SUCCESS",
            "text_response" => isset($raw["message"]) ? $raw["message"] : null,
            "last_action" => "get bank url",
            "data" => array(
                "ref_payco" => isset($data["refPayco"]) ? $data["refPayco"] : null,
                "factura" => isset($data["invoice"]) ? $data["invoice"] : null,
                "descripcion" => isset($data["description"]) ? $data["description"] : null,
                "valor" => isset($data["amount"]) ? $data["amount"] : null,
                "iva" => isset($data["tax"]) ? $data["tax"] : null,
                "ico" => isset($data["ico"]) ? $data["ico"] : null,
                "baseiva" => isset($data["taxBase"]) ? $data["taxBase"] : null,
                "moneda" => isset($data["currency"]) ? $data["currency"] : null,
                "estado" => isset($data["status"]) ? $data["status"] : null,
                "respuesta" => isset($data["response"]) ? $data["response"] : null,
                "cod_respuesta" => self::codRespuestaFromEstado(isset($data["status"]) ? $data["status"] : null),
                "cod_error" => isset($data["responseCode"]) ? $data["responseCode"] : null,
                "autorizacion" => isset($data["authorization"]) ? $data["authorization"] : null,
                "ciudad" => "",
                "recibo" => isset($data["receipt"]) ? $data["receipt"] : null,
                "fecha" => isset($data["date"]) ? $data["date"] : null,
                "urlbanco" => isset($providerData["urlPayment"]) ? $providerData["urlPayment"] : null,
                "transactionID" => isset($data["authorization"]) ? $data["authorization"] : null,
                "ticketId" => isset($data["receipt"]) ? $data["receipt"] : null,
                "extras" => isset($data["extras"]) ? $data["extras"] : null,
                "extras_epayco" => array("extra5" => isset($extrasEpaycoNew["extra5"]) ? $extrasEpaycoNew["extra5"] : null),
                "ciclo" => isset($providerData["cycle"]) ? (string)$providerData["cycle"] : null,
            ),
        );

        return json_decode(json_encode($mapped));
    }

    /**
     * Create a PSE (bank debit) transaction against the ms-transaction
     * generic transactions endpoint. Resolves with the same response shape
     * the legacy endpoint returns (see mapToLegacyShape) -- SDK-1365
     * requires callers to see one consistent shape regardless of which
     * backend actually served the request.
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

        return self::mapToLegacyShape($raw);
    }

    /**
     * Query a PSE (bank debit) transaction by `refPayco` against the
     * ms-transaction generic transactions endpoint (the SAME endpoint
     * MsTransactionCash's sibling migration uses, and the same one the
     * sibling Node SDK's getTransaction() uses for PSE -- confirmed there
     * empirically against real pre-prod). The endpoint from SDK-1365's own
     * Jira description (`GET .../v1/pse/transactions?ref_payco=...`)
     * returned 404 in that same Node-SDK test and is deliberately NOT used.
     *
     * Like createTransaction(), this remaps the response into the exact
     * shape the legacy secure.payco.co/restpagos/pse/transactioninfomation.json
     * endpoint returns today (see mapToLegacyShape) -- SDK-1365 QA follow-up
     * found the GET response carries the same field names create()'s raw
     * response does (see mapToLegacyShape's own docblock for the field-by-
     * field verification), so mapToLegacyShape() is reused as-is here, not a
     * GET-specific variant.
     *
     * @param  object      $epayco the Epayco instance (api_key/private_key/test/lang)
     * @param  string|int  $refPayco must be a plain positive integer (see
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
     * Resolve the caller's public IP the same way MsTransactionCash::resolveIp
     * does -- see that method's docblock for the full rationale. Duplicated
     * rather than shared -- see buildExtras()'s docblock.
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
     * matching MsTransactionCash::baseUrl() -- deliberately the SAME env var
     * (`BASE_URL_MS_TRANSACTION`) and default host, since both gateways call
     * the exact same generic transactions endpoint.
     *
     * @return string
     */
    public static function baseUrl()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION");
        return $env ? $env : "https://apiflow.epayco.io";
    }

    /**
     * Base host for the PSE-specific Basic-auth login endpoint. Deliberately
     * a DIFFERENT env var (`BASE_URL_MS_TRANSACTION_AUTH_PSE`) from
     * MsTransactionCash::baseUrlAuth()'s `BASE_URL_MS_TRANSACTION_AUTH`, even
     * though today's defaults happen to be the two hosts this SDK already
     * has separate constants/knobs for (Client::BASE_URL_APIFY here vs.
     * apiflow.epayco.io there) -- this class' own auth handshake is a
     * genuinely different endpoint (Basic auth, not OAuth2
     * client_credentials), so an operator overriding one must not
     * accidentally redirect the other. Defaults to Client::BASE_URL_APIFY
     * (not a duplicated literal) since it's the exact same host/constant
     * Client::authentication()'s own $apify=true branch already uses for
     * Resources/Bank.php's still-legacy pseBank() listing.
     *
     * @return string
     */
    public static function baseUrlAuth()
    {
        $env = getenv("BASE_URL_MS_TRANSACTION_AUTH_PSE");
        return $env ? $env : Client::BASE_URL_APIFY;
    }
}
