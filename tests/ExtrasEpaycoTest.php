<?php

include __DIR__ . '/../vendor/autoload.php';

if (!class_exists('PHPUnit_Framework_TestCase')) {
    class_alias('PHPUnit\Framework\TestCase', 'PHPUnit_Framework_TestCase');
}

class ExtrasEpaycoTest extends PHPUnit_Framework_TestCase
{
    public function gatewayProvider()
    {
        return array(
            array('Epayco\Gateways\MsTransactionBank'),
            array('Epayco\Gateways\MsTransactionCash'),
            array('Epayco\Gateways\MsTransactionDaviplata'),
            array('Epayco\Gateways\MsTransactionSafetypay'),
        );
    }

    /**
     * @dataProvider gatewayProvider
     */
    public function testExtra5DefaultsToP42WhenNotSent($gateway)
    {
        $this->assertSame(
            array("extra1" => "", "extra2" => "", "extra3" => "", "extra5" => "P42"),
            $gateway::buildExtrasEpayco(array())
        );
    }

    /**
     * @dataProvider gatewayProvider
     */
    public function testExtra5DefaultsToP42WhenSentEmpty($gateway)
    {
        $extras = $gateway::buildExtrasEpayco(array("extrasEpayco" => array("extra1" => "a", "extra5" => "")));

        $this->assertSame("P42", $extras["extra5"]);
        $this->assertSame("a", $extras["extra1"]);
    }

    /**
     * @dataProvider gatewayProvider
     */
    public function testSentExtra5IsKept($gateway)
    {
        $extras = $gateway::buildExtrasEpayco(array("extrasEpayco" => array("extra5" => "mi-valor")));

        $this->assertSame("mi-valor", $extras["extra5"]);
        $this->assertSame("", $extras["extra1"]);
    }
}
