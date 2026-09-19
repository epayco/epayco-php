<?php

include __DIR__ . '/../vendor/autoload.php';

use Epayco\Gateways\MsTransactionCash;
use Epayco\Utils\PaycoAes;

/**
 * Pure-logic tests for the SDK-1366 ms-transaction cash migration
 * (Epayco\Gateways\MsTransactionCash). Deliberately network-free: only the
 * body-building/encryption/response-mapping helpers are exercised here,
 * mirroring the equivalent network-free unit tests already used for this
 * same migration in the sibling Node SDK (epayco-node/tests/cash.js).
 *
 * Uses a plain stdClass (not a full Epayco instance) as the "epayco
 * context" every helper expects ({api_key, private_key, test, lang}), same
 * minimal shape the sibling Node/Python migrations' own pure-logic tests
 * use, so these tests don't depend on Epayco::__construct's own validation.
 */
class MsTransactionCashTest extends PHPUnit_Framework_TestCase
{
    /**
     * 32-byte (AES-256) test private key, same length constraint
     * encryptBody() enforces.
     */
    protected $privateKey = "01234567890123456789012345678901"; // note: intentionally checked for length below

    protected function setUp()
    {
        // Guard the fixture itself: encryptBody() requires exactly 32 bytes.
        $this->privateKey = substr(str_repeat("a1B2", 8), 0, 32);
        $this->assertSame(32, strlen($this->privateKey));
    }

    protected function epaycoCtx($overrides = array())
    {
        $ctx = new stdClass();
        $ctx->api_key = "491d6a0b6e992cf924edd8d3d088aff1";
        $ctx->private_key = $this->privateKey;
        $ctx->test = "TRUE";
        $ctx->lang = "ES";
        foreach ($overrides as $key => $value) {
            $ctx->$key = $value;
        }
        return $ctx;
    }

    public function testFranchiseMapContainsExpectedFranchises()
    {
        $this->assertSame(
            array(
                "efecty" => "EF",
                "baloto" => "BA",
                "gana" => "GA",
                "redservi" => "RS",
                "puntored" => "PR",
                "sured" => "SR",
            ),
            MsTransactionCash::$FRANCHISE_MAP
        );
    }

    public function testBuildBodyMapsLegacyOptionNames()
    {
        $body = MsTransactionCash::buildBody($this->epaycoCtx(), "GA", array(
            "invoice" => "1472050778",
            "description" => "pay test",
            "value" => "20000",
            "doc_type" => "CC",
            "doc_number" => "12344545",
            "name" => "testing",
            "last_name" => "PAYCO",
            "email" => "test@test.com",
            "cell_phone" => "3010000001",
            "url_response" => "https://example.com/response",
            "url_confirmation" => "https://example.com/confirmation",
            "method_confirmation" => "GET",
        ));

        $this->assertSame("1472050778", $body["invoice"]);
        $this->assertSame("pay test", $body["description"]);
        $this->assertSame("20000", $body["amount"]);
        $this->assertSame("CC", $body["documentType"]);
        $this->assertSame("12344545", $body["document"]);
        $this->assertSame("testing", $body["names"]);
        $this->assertSame("PAYCO", $body["lastNames"]);
        $this->assertSame("test@test.com", $body["email"]);
        $this->assertSame("3010000001", $body["cellphone"]);
        $this->assertSame("GET", $body["confirmationMethod"]);
        $this->assertSame("CASH", $body["paymentMethod"]);
        $this->assertSame(array("franchise" => "GA"), $body["paymentMethodData"]);
        $this->assertSame("COP", $body["currency"]);
        $this->assertSame("CO", $body["country"]);
        $this->assertSame(0, $body["tax"]);
        $this->assertTrue($body["testMode"]);
        $this->assertSame("491d6a0b6e992cf924edd8d3d088aff1", $body["publicKey"]);
        $this->assertArrayNotHasKey("splitPayment", $body);
    }

    public function testBuildBodyForwardsCreditsInsidePaymentMethodData()
    {
        $body = MsTransactionCash::buildBody($this->epaycoCtx(), "EF", array("credits" => 3));
        $this->assertSame(array("franchise" => "EF", "credits" => 3), $body["paymentMethodData"]);
    }

    public function testBuildExtrasOnlyIncludesSetKeys()
    {
        $extras = MsTransactionCash::buildExtras(array("extra1" => "a", "extra3" => "c"));
        $this->assertSame(array("extra1" => "a", "extra3" => "c"), $extras);
    }

    public function testBuildSplitPaymentReturnsNullWithoutSplitOptions()
    {
        $this->assertNull(MsTransactionCash::buildSplitPayment(array()));
    }

    public function testBuildSplitPaymentMapsLegacySplitFields()
    {
        $splitPayment = MsTransactionCash::buildSplitPayment(array(
            "split_app_id" => "app-1",
            "split_merchant_id" => "merchant-1",
            "split_primary_receiver" => "merchant-1",
            "split_receivers" => json_encode(array(array("id" => "merchant-2", "fee" => "10"))),
        ));

        $this->assertSame("multiple", $splitPayment["splitMethod"]);
        $this->assertSame("app-1", $splitPayment["splitAppId"]);
        $this->assertSame("merchant-1", $splitPayment["splitMerchantId"]);
        $this->assertSame("02", $splitPayment["splitType"]);
        $this->assertSame("0", $splitPayment["splitPrimaryReceiverFee"]);
        $this->assertSame(array(array("id" => "merchant-2", "fee" => "10")), $splitPayment["splitReceivers"]);

        $body = MsTransactionCash::buildBody($this->epaycoCtx(), "EF", array("split_app_id" => "app-1"));
        $this->assertArrayHasKey("splitPayment", $body);
    }

    public function testIsListDistinguishesListsFromAssociativeArrays()
    {
        $this->assertTrue(MsTransactionCash::isList(array(1, 2, 3)));
        $this->assertTrue(MsTransactionCash::isList(array()));
        $this->assertFalse(MsTransactionCash::isList(array("franchise" => "EF")));
    }

    public function testEncryptBodyThrowsOnInvalidPrivateKeyLength()
    {
        $this->setExpectedException('Epayco\Exceptions\ErrorException');
        MsTransactionCash::encryptBody(array("invoice" => "1"), "too-short", "ES");
    }

    public function testEncryptBodyKeepsPublicKeyPlaintextAndAddsIvAndLanguage()
    {
        $body = array("invoice" => "1472050778", "publicKey" => "491d6a0b6e992cf924edd8d3d088aff1");
        $encrypted = MsTransactionCash::encryptBody($body, $this->privateKey, "ES");

        $this->assertSame("491d6a0b6e992cf924edd8d3d088aff1", $encrypted["publicKey"]);
        $this->assertNotSame("1472050778", $encrypted["invoice"]);
        $this->assertSame(base64_encode(MsTransactionCash::IV), $encrypted["i"]);
        $this->assertArrayHasKey("language", $encrypted);

        // Round-trip: decrypting the ciphertext with the same key/iv this
        // SDK's own Utils\PaycoAes primitive uses must yield the original
        // plaintext back.
        $aes = new PaycoAes($this->privateKey, MsTransactionCash::IV, "ES");
        $this->assertSame("1472050778", $aes->decrypt($encrypted["invoice"]));
        $this->assertSame("php", $aes->decrypt($encrypted["language"]));
    }

    public function testEncryptBodyRecursesIntoNestedObjectsButEncryptsListsAsOneBlob()
    {
        $body = array(
            "paymentMethodData" => array("franchise" => "EF"),
            "splitPayment" => array("splitReceivers" => array(array("id" => "m2"))),
        );
        $encrypted = MsTransactionCash::encryptBody($body, $this->privateKey, "ES");

        // Nested associative array -> recursed (own sub-keys, each encrypted).
        $this->assertArrayHasKey("franchise", $encrypted["paymentMethodData"]);

        $aes = new PaycoAes($this->privateKey, MsTransactionCash::IV, "ES");
        $this->assertSame("EF", $aes->decrypt($encrypted["paymentMethodData"]["franchise"]));

        // Nested list -> encrypted as a single JSON blob, not recursed.
        $this->assertArrayHasKey("splitReceivers", $encrypted["splitPayment"]);
        $decoded = json_decode($aes->decrypt($encrypted["splitPayment"]["splitReceivers"]), true);
        $this->assertSame(array(array("id" => "m2")), $decoded);
    }

    public function testCodRespuestaFromEstado()
    {
        $this->assertSame(1, MsTransactionCash::codRespuestaFromEstado("Aceptada"));
        $this->assertSame(2, MsTransactionCash::codRespuestaFromEstado("Rechazada"));
        $this->assertSame(3, MsTransactionCash::codRespuestaFromEstado("Pendiente"));
        $this->assertSame(0, MsTransactionCash::codRespuestaFromEstado("algo-desconocido"));
    }

    public function testMapToLegacyShapeMapsSuccessfulResponse()
    {
        $raw = array(
            "success" => true,
            "message" => "Transaccion creada",
            "data" => array(
                "refPayco" => "1000008905",
                "invoice" => "1472050778",
                "amount" => "20000",
                "currency" => "COP",
                "status" => "Pendiente",
                "franchise" => "EF",
                "testMode" => true,
                "paymentProviderData" => array(
                    "pin" => "123456",
                    "agreementCode" => "AG-1",
                    "expirationDate" => "2026-09-30",
                ),
            ),
        );
        $options = array("doc_type" => "CC", "doc_number" => "12344545", "name" => "testing", "email" => "test@test.com");

        $response = MsTransactionCash::mapToLegacyShape($raw, $options, "efecty");

        $this->assertTrue($response->success);
        $this->assertSame("SUCCESS", $response->title_response);
        $this->assertSame("Transaccion creada", $response->text_response);
        $this->assertSame("Crear pin efecty", $response->last_action);
        $this->assertSame("1000008905", $response->data->ref_payco);
        $this->assertSame("1472050778", $response->data->factura);
        $this->assertSame("20000", $response->data->valor);
        $this->assertSame("EFECTY", $response->data->banco);
        $this->assertSame(3, $response->data->cod_respuesta);
        $this->assertSame("12344545", $response->data->documento);
        $this->assertSame("123456", $response->data->pin);
        $this->assertSame("0000", $response->data->cc_network_response->code);
    }

    public function testMapToLegacyShapeMapsFailedResponse()
    {
        $raw = array("success" => false, "message" => "Transaccion rechazada", "data" => array("status" => "Rechazada"));
        $response = MsTransactionCash::mapToLegacyShape($raw, array(), "gana");

        $this->assertFalse($response->success);
        $this->assertSame("ERROR", $response->title_response);
        $this->assertSame(2, $response->data->cod_respuesta);
    }

    public function testIsValidationErrorDetectsFieldErrors()
    {
        $raw = array("success" => false, "data" => array("errorType" => "VALIDATION", "errors" => array(
            array("code" => "E1", "message" => "invoice es requerido"),
        )));
        $this->assertTrue(MsTransactionCash::isValidationError($raw));

        $response = MsTransactionCash::mapToLegacyShape($raw, array(), "efecty");
        $this->assertFalse($response->success);
        $this->assertSame(1, $response->data->totalErrors);
        $this->assertSame("E1", $response->data->errors[0]->cod_error);
    }
}
