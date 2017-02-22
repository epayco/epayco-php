<?php

    require_once '../epayco.php';

    $epayco = new Epayco\Epayco(array("apiKey" => "491d6a0b6e992cf924edd8d3d088aff1"));

    //Tokenization
    // $token = $epayco->token->create(array(
    //     'card[number]' => '4575623182290326',
    //     'card[exp_year]' => '2017',
    //     'card[exp_month]' => '07',
    //     'card[cvc]' => '123'
    // ));
    //
    // var_dump($token->id);

    //Create client
    // $client = $epayco->customer->create(array(
    //        "token_card" => "u6tJMKuc6PWuC96u2",
    //        "name" => "Joe Doe",
    //        "email" => "joe@payco.co",
    //        "phone" => "3005234321",
    //        "default" => true
    // ));
    //
    // var_dump($client->data->customerId);


    //Get cleint list
    // $client = $epayco->customer->getList();
    //
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

    //Get plan
    // $plan = $epayco->plan->get("cursocarpinteria");

    //List plans
    // $plan = $epayco->plan->getList();

    //Edit plan
    // $plan = $epayco->plan->update("cursocarpinteria", array(
    //     "interval_count" => 3
    // ));
    //
    // var_dump($plan);

    //Create subscriptions
    // $sub = $epayco->subscriptions->create(array(
    //       "id_plan" => "cursocarpinteria",
    //       "customer" => "okrgCHvwXdWAE8joq",
    //       "token_card" => "u6tJMKuc6PWuC96u2"
    // ));

    //Get subscription
    // $sub = $epayco->subscriptions->get("TxmRjbKWFbsNaNtRW");

    //List subscriptions
    // $sub = $epayco->subscriptions->getList();

    //Cancel subscriptions
    // $sub = $epayco->subscriptions->cancel(array(
    //       "id" => "TxmRjbKWFbsNaNtRW"
    // ));
    //
    // var_dump($sub);

?>
