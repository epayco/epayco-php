<?php

    require_once '../epayco.php';

    $epayco = new Epayco\Epayco(array(
        "apiKey" => "491d6a0b6e992cf924edd8d3d088aff1",
        "privateKey" => "268c8e0162990cf2ce97fa7ade2eff5a",
        "test" => true
    ));

    //Tokenization
    // $token = $epayco->token->create(array(
    //     'card[number]' => '4575623182290326',
    //     'card[exp_year]' => '2017',
    //     'card[exp_month]' => '07',
    //     'card[cvc]' => '123'
    // ));
    // var_dump($token->id);

    //Create client
    // $client = $epayco->customer->create(array(
    //        "token_card" => "LF9yDsZe6e6LP2L34",
    //        "name" => "Joe Doe",
    //        "email" => "joe@payco.co",
    //        "phone" => "3005234321",
    //        "default" => true
    // ));
    // var_dump($client->data->customerId);


    //Get client
    // $client = $epayco->customer->get("5Fp9AA94GRdc72Tah");
    // var_dump($client);

    //Get cleint list
    // $client = $epayco->customer->getList();
    // var_dump($client);


    //Create plan
    // $plan = $epayco->plan->create(array(
    //       "id_plan" => "cursocarpinteria",
    //       "name" => "Curso de carpintería",
    //       "description" => "En este curso aprenderás carpintería",
    //       "amount" => 30000,
    //       "currency" => "cop",
    //       "interval" => "month",
    //       "interval_count" => 1,
    //       "trial_days" => 30
    // ));
    // var_dump($plan->data->id);

    //Get plan
    // $plan = $epayco->plan->get("cursocarpinteria");
    // var_dump($plan->plan);

    //List plans
    // $plan = $epayco->plan->getList();
    // var_dump($plan);

    //Edit plan
    // $plan = $epayco->plan->update("cursocarpinteria", array(
    //     "interval_count" => 3
    // ));
    // var_dump($plan);

    //Create subscriptions
    // $sub = $epayco->subscriptions->create(array(
    //       "id_plan" => "cursocarpinteria",
    //       "customer" => "5Fp9AA94GRdc72Tah",
    //       "token_card" => "LF9yDsZe6e6LP2L34"
    // ));
    // var_dump($sub);

    //Get subscription
    // $sub = $epayco->subscriptions->get("7A4jg4xpPFLe9pvXa");
    // var_dump($sub);

    //List subscriptions
    // $sub = $epayco->subscriptions->getList();
    // var_dump($sub);

    //Cancel subscriptions
    // $sub = $epayco->subscriptions->cancel("TxmRjbKWFbsNaNtRW");
    // var_dump($sub);


    //Pse get banks
    // $bank = $epayco->bank->pseBank();
    // var_dump($bank->data);

    //Transaction pse
    // $transaction = $epayco->bank->pse(array(
    //         "banco" => "1007",
    //         "factura" => "1472050778",
    //         "descripcion" => "Pago pruebas",
    //         "valor" => "10000",
    //         "iva" => "0",
    //         "baseiva" => "0",
    //         "moneda" => "COP",
    //         "tipo_persona" => "0",
    //         "tipo_doc" => "CC",
    //         "documento" => "10358519",
    //         "nombres" => "PRUEBAS",
    //         "apellidos" => "PAYCO",
    //         "email" => "no-responder@payco.co",
    //         "pais" => "CO",
    //         "depto" => "Antioquia",
    //         "ciudad" => "Medellin",
    //         "telefono" => "0000000",
    //         "celular" => "3010000001",
    //         "direccion" => "Calle 10 # 40-30",
    //         "ip" => "186.116.10.133",
    //         "url_respuesta" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
    //         "url_confirmacion" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
    //         "metodoconfirmacion" => "GET",
    // ));
    // var_dump($transaction->data);

    //Get ransaction
    // $transaction = $epayco->bank->pseTransaction("1216019");
    // var_dump($transaction->data);
