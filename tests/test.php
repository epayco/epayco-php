<?php

require "../epayco.php";

$epayco = new Epayco\Epayco(array(
          "apiKey" => "491d6a0b6e992cf924edd8d3d088aff1",
          "privateKey" => "268c8e0162990cf2ce97fa7ade2eff5a",
          "lenguage" => "ES",
          "test" => true
      ));

  // $testCard = array(
  //     "card[number]" => '4575623182290326',
  //     "card[exp_year]" => "2017",
  //     "card[exp_month]" => "07",
  //     "card[cvc]" => "123"
  // );
  //
  // $token = $epayco->token->create($testCard);
  //
  // var_dump($token);

  // $customer = $epayco->customer->create(array(
  //     "token_card" => "oadJSK4PzCykvG9Zr",
  //     "name" => "Joe Doe",
  //     "email" => "joe" . rand() . "@payco.co",
  //     "phone" => "3005234321",
  //     "default" => true
  // ));

// $customer = $epayco->customer->get("8ShPpuPmPF6rHRBme");
// $customer = $epayco->customer->getList();
// $customer = $epayco->customer->update("3gFCbw6bfj2EZF7Av", array('name' => 'julianc'));
//
//   var_dump($customer);

// $plan = $epayco->plan->create(array(
//       "id_plan" => "coursereact",
//       "name" => "Course react js",
//       "description" => "Course react and redux",
//       "amount" => 30000,
//       "currency" => "cop",
//       "interval" => "month",
//       "interval_count" => 1,
//       "trial_days" => 30
// ));

// $plan = $epayco->plan->get("coursereact");
// $plan = $epayco->plan->getList();
// $plan = $epayco->plan->remove("coursereact");

// var_dump($plan);

// $subscription_info = array(
//   "id_plan" => "coursereact",
//   "customer" => "3gFCbw6bfj2EZF7Av",
//   "token_card" => "oadJSK4PzCykvG9Zr",
//   "doc_type" => "CC",
//   "doc_number" => "5234567"
// );

// $sub = $epayco->subscriptions->create($subscription_info);
// $sub = $epayco->subscriptions->get("3RHChi5dv8axFBJ9W");
// $sub = $epayco->subscriptions->getList();
// $sub = $epayco->subscriptions->cancel("3RHChi5dv8axFBJ9W");
// $sub = $epayco->subscriptions->charge($subscription_info);
//
// var_dump($sub);

// $pse = $epayco->bank->pse(array(
//         "bank" => "1007",
//         "invoice" => "1472050778",
//         "description" => "Pago pruebas",
//         "value" => "10000",
//         "tax" => "0",
//         "tax_base" => "0",
//         "currency" => "COP",
//         "type_person" => "0",
//         "doc_type" => "CC",
//         "doc_number" => "10358519",
//         "name" => "PRUEBAS",
//         "last_name" => "PAYCO",
//         "email" => "no-responder@payco.co",
//         "country" => "CO",
//         "cell_phone" => "3010000001",
//         "ip" => "186.116.10.133",
//         "url_response" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
//         "url_confirmation" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
//         "method_confirmation" => "GET",
// ));

// $pse = $epayco->bank->pseTransaction("249005850");
//
// var_dump($pse);


// $cash = $epayco->cash->create("efecty", array(
//     "invoice" => "1472050778",
//     "description" => "pay test",
//     "value" => "20000",
//     "tax" => "0",
//     "tax_base" => "0",
//     "currency" => "COP",
//     "type_person" => "0",
//     "doc_type" => "CC",
//     "doc_number" => "10358519",
//     "name" => "testing",
//     "last_name" => "PAYCO",
//     "email" => "test@mailinator.com",
//     "cell_phone" => "3010000001",
//     "end_date" => "2017-12-05",
//     "ip" => "186.116.10.133",
//     "url_response" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
//     "url_confirmation" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
//     "method_confirmation" => "GET",
// ));

// $cash = $epayco->cash->transaction("249005850");

// $pay = $epayco->charge->create(array(
//     "token_card" => "oadJSK4PzCykvG9Zr",
//     "customer_id" => "3gFCbw6bfj2EZF7Av",
//     "doc_type" => "CC",
//     "doc_number" => "1035851980",
//     "name" => "John",
//     "last_name" => "Doe",
//     "email" => "example@email.com",
//     "ip" => "192.198.2.114",
//     "bill" => "OR-1234",
//     "description" => "Test Payment",
//     "value" => "116000",
//     "tax" => "16000",
//     "tax_base" => "100000",
//     "currency" => "COP",
//     "dues" => "12"
// ));

// $pay = $epayco->charge->transaction("249005850");
//
// var_dump($pay);
