Epayco
=====

PHP wrapper for Epayco API

## Description

API to interact with Epayco
https://epayco.co/docs/api/

### Dependencias

    * PHP 5.3+

## Installation

```javascript
{
  "require" : {
    "epayco/epayco-php" : "dev-master"
  }
}
```

Add `autoload` to composer

```php
require 'vendor/autoload.php';
```

### From GitHub

```bash
$ git clone https://github.com/epayco/epayco-php.git
```

## Usage

```php
$epayco = new Epayco\Epayco(array(
    "apiKey" => "YOU_PUBLIC_API_KEY",
    "privateKey" => "YOU_PRIVATE_API_KEY",
    "lenguage" => "ES",
    "test" => true
));
```

### Create Token

```php

$token = $epayco->token->create(array(
    "card[number]" => '4575623182290326',
    "card[exp_year]" => "2017",
    "card[exp_month]" => "07",
    "card[cvc]" => "123"
));
```

### Customers

#### Create

```php
$customer = $epayco->customer->create(array(
    "token_card" => $token->id,
    "name" => "Joe",
    "last_name" => "Doe", //This parameter is optional
    "email" => "joe@payco.co",
    "default" => true,
    //Optional parameters: These parameters are important when validating the credit card transaction
    "city" => "Bogota",
    "address" => "Cr 4 # 55 36",
    "phone" => "3005234321",
    "cell_phone"=> "3010000001",
));
```

#### Retrieve

```php
$customer = $epayco->customer->get("id_client");
```

#### List

```php
$customer = $epayco->customer->getList();
```

#### Update

```php
$customer = $epayco->customer->update("id_client", array('name' => 'julianc'));
```

#### Delete Customer's token

```php
$customer = $epayco->customer->delete(array(
    "franchise"  => "visa",
    "mask" => "457562******0326",
    "customer_id"=>"id_client"
    ));
```

### Plans

#### Create

```php
$plan = $epayco->plan->create(array(
     "id_plan" => "coursereact",
     "name" => "Course react js",
     "description" => "Course react and redux",
     "amount" => 30000,
     "currency" => "cop",
     "interval" => "month",
     "interval_count" => 1,
     "trial_days" => 30
));
```

#### Retrieve

```php
$plan = $epayco->plan->get("coursereact");
```

#### List

```php
$plan = $epayco->plan->getList();
```

#### Remove

```php
$plan = $epayco->plan->remove("coursereact");
```

### Subscriptions

#### Create

```php
$sub = $epayco->subscriptions->create(array(
  "id_plan" => "coursereact",
  "customer" => "id_client",
  "token_card" => "id_token",
  "doc_type" => "CC",
  "doc_number" => "5234567"
));
```

#### Retrieve

```php
$sub = $epayco->subscriptions->get("id_subscription");
```

#### List

```php
$sub = $epayco->subscriptions->getList();
```

#### Cancel

```php
$sub = $epayco->subscriptions->cancel("id_subscription");
```

#### Pay Subscription

```php
$sub = $epayco->subscriptions->charge(array(
  "id_plan" => "coursereact",
  "customer" => "id_client",
  "token_card" => "id_token",
  "doc_type" => "CC",
  "doc_number" => "5234567",
  "address" => "cr 44 55 66",
  "phone"=> "2550102",
  "cell_phone"=> "3010000001",
   "ip" => "190.000.000.000"  // This is the client's IP, it is required
));
```

### PSE

#### Create

```php
$pse = $epayco->bank->create(array(
        "bank" => "1022",
        "invoice" => "1472050778",
        "description" => "Pago pruebas",
        "value" => "10000",
        "tax" => "0",
        "tax_base" => "0",
        "currency" => "COP",
        "type_person" => "0",
        "doc_type" => "CC",
        "doc_number" => "numero_documento_cliente",
        "name" => "PRUEBAS",
        "last_name" => "PAYCO",
        "email" => "no-responder@payco.co",
        "country" => "CO",
        "cell_phone" => "3010000001",
        "url_response" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
        "url_confirmation" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
        "method_confirmation" => "GET",
        //Extra params: These params are optional and can be used by the commerce
        "extra1" => "",
        "extra2" => "",
        "extra3" => "",
        "extra4" => "",
        "extra5" => "",
        "extra6" => "",
        "extra7" => "",
));
```

#### Retrieve

```php
$pse = $epayco->bank->get("transactionID");
```

#### Split Payments

Previous requirements:
https://docs.epayco.co/tools/split-payment

```php
$split_bank_pay = $epayco->bank->create(array(
    //Other customary parameters...
    "splitpayment" => "true",
    "split_app_id" => "P_CUST_ID_CLIENTE APPLICATION",
    "split_merchant_id" => "P_CUST_ID_CLIENTE COMMERCE",
    "split_type" => "02",
    "split_primary_receiver" => "P_CUST_ID_CLIENTE APPLICATION",
    "split_primary_receiver_fee" => "10",
    "split_receivers" => json_encode(array(array('id'=>'P_CUST_ID_CLIENTE 1ST RECEIVER','fee'=>'1000','fee_type' => '01')))
));
```

### Cash

#### Create

```php
$cash = $epayco->cash->create("efecty", array(
    "invoice" => "1472050778",
    "description" => "pay test",
    "value" => "20000",
    "tax" => "0",
    "tax_base" => "0",
    "currency" => "COP",
    "type_person" => "0",
    "doc_type" => "CC",
    "doc_number" => "numero_documento_cliente",
    "name" => "testing",
    "last_name" => "PAYCO",
    "email" => "test@mailinator.com",
    "cell_phone" => "3010000001",
    "end_date" => "data_max_5_days",
    "url_response" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
    "url_confirmation" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
    "method_confirmation" => "GET",
));
```

#### Retrieve

```php
$cash = $epayco->cash->transaction("id_transaction");
```


#### Split Payments

Previous requirements:
https://docs.epayco.co/tools/split-payment

```php
$split_cash_pay = $epayco->cash->create("efecty", array(
    //Other customary parameters...
    "splitpayment" => "true",
    "split_app_id" => "P_CUST_ID_CLIENTE APPLICATION",
    "split_merchant_id" => "P_CUST_ID_CLIENTE COMMERCE",
    "split_type" => "02",
    "split_primary_receiver" => "P_CUST_ID_CLIENTE APPLICATION",
    "split_primary_receiver_fee" => "10",
    "split_receivers" => json_encode(array(array('id'=>'P_CUST_ID_CLIENTE 1ST RECEIVER','fee'=>'1000','fee_type' => '01')))
));
```

### Payment

#### Create

```php
$pay = $epayco->charge->create(array(
    "token_card" => $token->id,
    "customer_id" => $customer->data->customerId,
    "doc_type" => "CC",
    "doc_number" => "numero_documento_cliente",
    "name" => "John",
    "last_name" => "Doe",
    "email" => "example@email.com",
    "bill" => "OR-1234",
    "description" => "Test Payment",
    "value" => "116000",
    "tax" => "16000",
    "tax_base" => "100000",
    "currency" => "COP",
    "dues" => "12",
    "address" => "cr 44 55 66",
    "phone"=> "2550102",
    "cell_phone"=> "3010000001",
    "url_response" => "https://tudominio.com/respuesta.php",
    "url_confirmation" => "https://tudominio.com/confirmacion.php",
    "ip" => "190.000.000.000"  // This is the client's IP, it is required
));
```

#### Retrieve

```php
$pay = $epayco->charge->transaction("id_transaction");
```

#### Split Payments

Previous requirements:
https://docs.epayco.co/tools/split-payment

```php
$split_pay = $epayco->charge->create(array(
    //Other customary parameters...
    "splitpayment" => "true",
    "split_app_id" => "P_CUST_ID_CLIENTE APPLICATION",
    "split_merchant_id" => "P_CUST_ID_CLIENTE COMMERCE",
    "split_type" => "02",
    "split_primary_receiver" => "P_CUST_ID_CLIENTE APPLICATION",
    "split_primary_receiver_fee"=>"10",
    "split_receivers" => array(array('id'=>'P_CUST_ID_CLIENTE 1ST RECEIVER','fee'=>'1000','fee_type' => '01'))
));
```
