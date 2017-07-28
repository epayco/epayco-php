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
    "name" => "Joe Doe",
    "email" => "joe@payco.co",
    "phone" => "3005234321",
    "default" => true
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
  "doc_number" => "5234567"
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
        "doc_number" => "10358519",
        "name" => "PRUEBAS",
        "last_name" => "PAYCO",
        "email" => "no-responder@payco.co",
        "country" => "CO",
        "cell_phone" => "3010000001",
        "url_response" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
        "url_confirmation" => "https:/secure.payco.co/restpagos/testRest/endpagopse.php",
        "method_confirmation" => "GET",
));
```

#### Retrieve

```php
$pse = $epayco->bank->get("transactionID");
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
    "doc_number" => "10358519",
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

### Payment

#### Create

```php
$pay = $epayco->charge->create(array(
    "token_card" => $token->id,
    "customer_id" => $customer->data->customerId,
    "doc_type" => "CC",
    "doc_number" => "1035851980",
    "name" => "John",
    "last_name" => "Doe",
    "email" => "example@email.com",
    "bill" => "OR-1234",
    "description" => "Test Payment",
    "value" => "116000",
    "tax" => "16000",
    "tax_base" => "100000",
    "currency" => "COP",
    "dues" => "12"
));
```

#### Retrieve

```php
$pay = $epayco->charge->transaction("id_transaction");
```
