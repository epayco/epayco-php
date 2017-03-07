<?php

    require "epayco.php";

    class EpaycoTest extends PHPUnit_Framework_TestCase
    {
        /**
         * Init sdk epayco
         */
        protected function setUp()
        {
            $this->apiKey = "491d6a0b6e992cf924edd8d3d088aff1";
            $this->privateKey = "491d6a0b6e992cf924edd8d3d088aff1";
            $this->lenguage = "ES";
            $this->test = true;

            $this->epayco = new Epayco\Epayco(array(
                  "apiKey" => $this->apiKey,
                  "privateKey" => $this->privateKey,
                  "lenguage" => $this->lenguage,
                  "test" => $this->test
              ));
            $this->client = new Epayco\Client();
        }

        /**
         * Create token credit card form tokenization
         * @return object
         */
        protected function createToken()
        {
            $testCard = array(
                "card[number]" => '4575623182290326',
                "card[exp_year]" => "2017",
                "card[exp_month]" => "07",
                "card[cvc]" => "123"
            );
            $response = $this->client->request(
                "POST",
                "/v1/tokens",
                $api_key = $this->apiKey,
                $options,
                $private_key = $this->privateKey,
                $test = $this->test,
                $switch = false,
                $lang = $this->lenguage
            );
            return $response->id;
        }

        /**
         * Create clien and token credit card
         * @return object
         */
        protected function createClient()
        {
            var_dump(rand());
            $token = $this->createToken();
            $client = $this->epayco->customer->create(array(
                "token_card" => $token,
                "name" => "Joe Doe",
                "email" => "joe" . rand() . "@payco.co",
                "phone" => "3005234321",
                "default" => true
            ));
            return array(
                "token" => $token,
                "clientId" => $client->data->customerId
            );
        }

        /* Customers */
        public function testCreateClient()
        {
            $token = $this->createToken();
            $client = $this->epayco->customer->create(array(
                "token_card" => $token,
                "name" => "Joe Doe",
                "email" => "joe@payco.co",
                "phone" => "3005234321",
                "default" => true
            ));
            $this->assertTrue(strlen($client->data->customerId) > 0);
        }

        public function testGetClient()
        {
            $customers = $this->epayco->customer->getList();
            $customerId = $customers->customers[0]->id_customer;
            $response = $this->epayco->customer->get($customerId);
            $this->assertGreaterThanOrEqual(1, count($response));
        }

        public function testGetClients()
        {
            $response = $this->epayco->customer->getList();
            $this->assertGreaterThanOrEqual(1, count($response));
        }

        /* Plans */
        public function testCreatePlan()
        {
            $plan = $this->epayco->plan->create(array(
                  "id_plan" => "coursereact",
                  "name" => "Course react js",
                  "description" => "Course react and redux",
                  "amount" => 30000,
                  "currency" => "cop",
                  "interval" => "month",
                  "interval_count" => 1,
                  "trial_days" => 30
            ));
            $this->assertTrue(strlen($plan->data->id) > 0);
        }

        public function testGetPlan()
        {
            $response = $this->epayco->plan->getList();
            $this->assertGreaterThanOrEqual(1, count($response));
        }

        public function testEditPlan()
        {
            $response = $this->epayco->plan->update("coursereact", array("interval_count" => 4));
            $this->assertGreaterThanOrEqual(1, count($response));
        }

        /* Subscriptions */
        public function testCreateSubscription()
        {
            $data = $this->createClient();
            $sub = $this->epayco->subscriptions->create(array(
                  "id_plan" => "coursereact",
                  "customer" => $data["clientId"],
                  "token_card" => $data["token"]
            ));
            $this->assertTrue(strlen($sub->data->suscription) > 0);
        }

        public function testGetSuscription()
        {
            $subs = $this->epayco->subscriptions->getList();
            $subId = $subs->plans[0]->_id;
            $request = $this->epayco->subscriptions->get($subId);
            $this->assertTrue(strlen($request) > 0);
        }

        public function testListSubscriptions()
        {
            $subs = $this->epayco->subscriptions->getList();
            $this->assertGreaterThanOrEqual(1, count($response));
        }

        public function testCancelSubscription()
        {
            $response = $this->epayco->subscriptions->cancel(array(
                "id" => "TxmRjbKWFbsNaNtRW"
            ));
            $this->assertGreaterThanOrEqual(1, count($response));
        }

        public function testPseCreate()
        {
            $response = $this->epayco->bank->pse(array(
                    "bank" => "1007",
                    "invoice" => "1472050778",
                    "description" => "Pago pruebas",
                    "value" => "10000",
                    "tax" => "0",
                    "tax_base" => "0",
                    "currency" => "COP",
                    "type_person" => "0",
                    "doc_type" => "CC",
                    "doc_number" => "10358519",
                    "name" => "PRUEBAS",
                    "last_name" => "PAYCO",
                    "email" => "no-responder@payco.co",
                    "country" => "CO",
                    "cell_phone" => "3010000001",
                    "ip" => "186.116.10.133",
                    "url_response" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
                    "url_confirmation" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
                    "method_confirmation" => "GET",
            ));
            $this->assertGreaterThanOrEqual(1, count($response));
        }
    }
