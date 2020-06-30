<?php

namespace Epayco;


use Epayco\Utils\PaycoAes;
use Epayco\Util;
use Epayco\Exceptions\ErrorException;

/**
 * Client conection api epayco
 */
class Client extends GraphqlClient
{

    const BASE_URL = "https://api.secure.payco.co";
    const BASE_URL_SECURE = "https://secure.payco.co";
    const IV = "0000000000000000";
    const LENGUAGE = "php";

    /**
     * Request api epayco
     * @param String $method method petition
     * @param String $url url request api epayco
     * @param String $api_key public key commerce
     * @param Object $data data petition
     * @param String $private_key private key commerce
     * @param String $test type petition production or testing
     * @param Boolean $switch type api petition
     * @return Object
     */
    public function request(
        $method,
        $url,
        $api_key,
        $data = null,
        $private_key,
        $test,
        $switch,
        $lang,
        $cash = null,
        $safetyp = null,
        $card = null
    )
    {

        /**
         * Resources ip, traslate keys
         */
        $util = new Util();

        /**
         * Switch traslate keys array petition in secure
         */
        if ($switch && is_array($data)) {
            if ($safetyp) {
                $data = $util->setKeys($data, $safetyp);
            } else {
                $data = $util->setKeys($data);
            }
        }
        try {
            /**
             * Set heaToken bearer
             */
         
        if(!isset($_COOKIE[$api_key])) {
            //  echo "Cookie named '" . $cookie_name . "' is not set!";
              $dataAuth =$this->authentication($api_key,$private_key);
              $auth=gettype($dataAuth);
              $json = json_decode($dataAuth);
              if(!is_object($json)) {
                  throw new ErrorException("Error get bearer_token.", 106);
              }
              if(!$json->status)
              {
                  throw new ErrorException($json->message);
              }
              $bearer_token=$json->bearer_token;
              $cookie_name = $api_key;
              $cookie_value = $bearer_token;
              setcookie($cookie_name, $cookie_value, time() + (60 * 14), "/"); 
            //  echo "token con login".$bearer_token;
              }else{
                $bearer_token = $_COOKIE[$api_key];
             //   echo "token sin login".$bearer_token;
              } 

        } catch (\Exception $e) {
            $data = [
                "status" => false,
                "message" => $e->getMessage(),
                "data" => []
            ];
            $objectReturnError = (object)$data;
            return $objectReturnError;
        }

        try {

            /**
             * Set headers
             */
            $headers = array("Content-Type" => "application/json", "Accept" => "application/json", "Type" => 'sdk-jwt', "Authorization" => 'Bearer ' . $bearer_token, "lang" => "PHP");

            $options = array(
                'timeout' => 120,
                'connect_timeout' => 120,
            );

            if ($method == "GET") {
                if ($switch) {
                    if ($test) {
                        $test = "TRUE";
                    } else {
                        $test = "FALSE";
                    }

                    $response = \Requests::get(Client::BASE_URL_SECURE . $url, $headers, $options);
                } else {
                    $response = \Requests::get(Client::BASE_URL . $url, $headers, $options);
                }
            } elseif ($method == "POST") {

                if ($switch) {
                    $data = $util->mergeSet($data, $test, $lang, $private_key, $api_key, $cash);

                    $response = \Requests::post(Client::BASE_URL_SECURE . $url, $headers, json_encode($data), $options);
                } else {

                    if ($card) {

                        $response = \Requests::post(Client::BASE_URL . $url, $headers, json_encode($data), $options);
                    } else {

                        $data["ip"] = isset($data["ip"]) ? $data["ip"] : getHostByName(getHostName());
                        $data["test"] = $test;

                        $response = \Requests::post(Client::BASE_URL . $url, $headers, json_encode($data), $options);
                    }

                }
                if ($safetyp) {
                    $headers2 = array("Accept" => "multipart/form-data");
                    $data = $util->mergeSet($data, $test, $lang, $private_key, $api_key, $cash);
                    $response = \Requests::post(Client::BASE_URL_SECURE . $url, $headers2, $data, $options);

                }
            } elseif ($method == "DELETE") {
                $response = \Requests::delete(Client::BASE_URL . $url, $headers, $options);
            }
        } catch (\Exception $e) {
            throw new ErrorException($e->getMessage(), $e->getCode());
        }
        try {
            if ($response->status_code >= 200 && $response->status_code <= 206) {
                if ($method == "DELETE") {
                    return $response->status_code == 204 || $response->status_code == 200;
                }
                return json_decode($response->body);
            }
            if ($response->status_code == 400) {
                $code = 0;
                $message = "Bad request";
                try {
                    $error = (array)json_decode($response->body)->errors[0];
                    $code = key($error);
                    $message = current($error);
                } catch (\Exception $e) {
                    throw new ErrorException($e->getMessage(), $e->getCode());
                }
                throw new ErrorException($message , 103);
            }
            if ($response->status_code == 401) {
                throw new ErrorException('Unauthorized', 104);
            }
            if ($response->status_code == 404) {
                throw new ErrorException('Not found', 105);
            }
            if ($response->status_code == 403) {
                throw new ErrorException('Permission denegated', 106);
            }
            if ($response->status_code == 405) {
                throw new ErrorException('Not allowed', 107);
            }
            throw new ErrorException('Internal error', 102);
        } catch (\Exception $e) {
            throw new ErrorException($e->getMessage(), $e->getCode() );
        }
    }

    public function graphql(
        $query,
        $schema,
        $api_key,
        $type,
        $custom_key)
    {
        try {
            $queryString = "";
            $initial_key = "";
            switch ($type) {
                case "wrapper":
                    $this->validate($query); //query validator
                    $schema = $query->action === "find" ? $schema . "s" : $schema;
                    $this->canPaginateSchema($query->action, $query->pagination, $schema);
                    $selectorParams = $this->paramsBuilder($query);

                    $queryString = $this->queryString(
                        $selectorParams,
                        $schema,
                        $query); //rows returned
                    $initial_key = $schema;
                    break;
                case "fixed":
                    $queryString = $query;
                    $initial_key = $custom_key;
                    break;
            }
            $result = $this->sendRequest($queryString, $api_key);
            return $this->successResponse($result, $initial_key);
        } catch (\Exception $e) {
            throw new ErrorException($e->getMessage(), 301);
        }

    }

    public function authentication($api_key, $private_key)
    {
        $data = array(
            'public_key' => $api_key,
            'private_key' => $private_key
        );

        $headers = array("Content-Type" => "application/json", "Accept" => "application/json", "Type" => 'sdk-jwt', "lang" => "PHP");

        $options = array(
            'timeout' => 120,
            'connect_timeout' => 120,
        );

        $url = "/v1/auth/login";

        $response = \Requests::post(Client::BASE_URL . $url, $headers, json_encode($data), $options);


        return isset($response->body) ? $response->body : false;
    }
}

