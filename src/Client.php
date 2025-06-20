<?php

namespace Epayco;


use Epayco\Utils\PaycoAes;
use Epayco\Util;
use Epayco\Exceptions\ErrorException;
use WpOrg\Requests\Requests;

/**
 * Client conection api epayco
 */
class Client extends GraphqlClient
{

  
    const BASE_URL = "https://eks-subscription-api-lumen-service.epayco.io";
    const BASE_URL_SECURE = "https://eks-rest-pagos-service.epayco.io/";
    public const ENTORNO = "/restpagos";
    const BASE_URL_APIFY = "https://eks-apify-service.epayco.io";
    public const IV = "0000000000000000";
    public const LENGUAGE = "php";

    public static function getBaseUrl()
    {
        $env = getenv('BASE_URL_SDK');
        return ($env !== false && $env !== '') ? $env : self::BASE_URL;
    }

    public static function getBaseUrlSecure()
    {
        $env = getenv('SECURE_URL_SDK');
      
        if ($env === false || $env === '') {
           
            $epaycoEnv = getenv('EPAYCO_PHP_SDK_ENV_REST');
            if ($epaycoEnv === false || $epaycoEnv === 'prod') {
                return self::BASE_URL_SECURE;
            } elseif ($epaycoEnv) {
             
                return $epaycoEnv;
            }
            return self::BASE_URL_SECURE;
        }
        return $env;
    }

    public static function getEntorno()
    {
        $env = getenv('ENTORNO_SDK');
        return ($env !== false && $env !== '') ? $env : self::ENTORNO;
    }

    public static function getBaseUrlApify()
    {
        $env = getenv('BASE_URL_APIFY');
        return ($env !== false && $env !== '') ? $env : self::BASE_URL_APIFY;
    }


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
        $data,
        $private_key,
        $test,
        $switch,
        $lang,
        $cash = null,
        $card = null,
        $apify = false
    ) {
        $util = new Util();

        if ($method == "POST" && !isset($data['extras_epayco'])) {
            $data['extras_epayco'] = ["extra5" => "P42"];
        }
        if ($apify && is_array($data)) {
            $data = $util->setKeys_apify($data);
        } else if ($switch && is_array($data)) {
            $data = $util->setKeys($data);
        }
        try {
            $cookie_name = $api_key . ($apify ? "_apify" : "");
            if (!isset($_COOKIE[$cookie_name])) {
                $dataAuth = $this->authentication($api_key, $private_key, $apify);
                $json = json_decode($dataAuth);
                if (!is_object($json)) {
                    throw new ErrorException("Error get bearer_token. Raw response: " . var_export($dataAuth, true), 106);
                }
                $bearer_token = false;
                if (isset($json->bearer_token)) {
                    $bearer_token = $json->bearer_token;
                } else if (isset($json->token)) {
                    $bearer_token = $json->token;
                }
                if (!$bearer_token) {
                    $msj = isset($json->message) ? $json->message : "Error get bearer_token";
                    if ($msj == "Error get bearer_token" && isset($json->error)) {
                        $msj = $json->error;
                    }
                    throw new ErrorException($msj . " | Raw response: " . var_export($dataAuth, true), 422);
                }
                $cookie_value = $bearer_token;
                setcookie($cookie_name, $cookie_value, time() + (60 * 14), "/");
            } else {
                $bearer_token = $_COOKIE[$cookie_name];
            }
        } catch (\Exception $e) {
            $data = array(
                "status" => false,
                "message" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
                "data" => array()
            );
            $objectReturnError = (object)$data;
            return $objectReturnError;
        }

        try {
            $headers = array(
                "Content-Type" => "application/json",
                "Accept" => "application/json",
                "Type" => 'sdk-jwt',
                "Authorization" => 'Bearer ' . $bearer_token,
                "lang" => "PHP"
            );
            $options = array(
                'timeout' => 120,
                'connect_timeout' => 120,
            );
            if ($method == "GET") {
                if ($apify) {
                    $_url = Client::getBaseUrlApify() . $url;
                } elseif ($switch) {
                    $_url = Client::getBaseUrlSecure() . Client::getEntorno() . $url;
                } else {
                    $_url = Client::getBaseUrl() . $url;
                }
                $response = Requests::get($_url, $headers, $options);
            } elseif ($method == "POST") {
                if ($apify) {
                    $response = Requests::post(Client::getBaseUrlApify() . $url, $headers, json_encode($data), $options);
                } elseif ($switch) {
                    $data = $util->mergeSet($data, $test, $lang, $private_key, $api_key, $cash);
                    $response = Requests::post(Client::getBaseUrlSecure() .  Client::getEntorno() . $url, $headers, json_encode($data), $options);
                } else {
                    if (!$card) {
                        $data["ip"] = isset($data["ip"]) ? $data["ip"] : getHostByName(getHostName());
                        $data["test"] = $test;
                    }
                    $response = Requests::post(Client::getBaseUrl() . $url, $headers, json_encode($data), $options);
                }
            } elseif ($method == "DELETE") {
                $response = Requests::delete(Client::getBaseUrl() . $url, $headers, $options);
            }
        } catch (\Exception $e) {
            throw new ErrorException("HTTP Request Exception: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString(), $e->getCode());
        }

        try {
            // Debug: log full response
            if (isset($response)) {
                // You can log $response->body, $response->status_code, $response->headers, etc.
                // For debugging, include them in the error message if needed
            }
            if ($response->status_code >= 200 && $response->status_code <= 206) {
                if ($method == "DELETE") {
                    return $response->status_code == 204 || $response->status_code == 200;
                }
                return json_decode($response->body);
            }
            $debugInfo = "Status: {$response->status_code} | Body: " . var_export($response->body, true) . " | Headers: " . var_export($response->headers, true);
            if ($response->status_code == 400) {
                try {
                    $errorObj = json_decode($response->body);
                    $error = isset($errorObj->errors[0]) ? (array)$errorObj->errors[0] : [];
                    $message = current($error);
                } catch (\Exception $e) {
                    throw new ErrorException("400 Error parse: " . $e->getMessage() . " | {$debugInfo}", $e->getCode());
                }
                throw new ErrorException("400 Bad Request: {$message} | {$debugInfo}", 103);
            }
            if ($response->status_code == 401) {
                throw new ErrorException('401 Unauthorized | ' . $debugInfo, 104);
            }
            if ($response->status_code == 404) {
                throw new ErrorException('404 Not found | ' . $debugInfo, 105);
            }
            if ($response->status_code == 403) {
                throw new ErrorException('403 Permission denied | ' . $debugInfo, 106);
            }
            if ($response->status_code == 405) {
                throw new ErrorException('405 Not allowed | ' . $debugInfo, 107);
            }
            throw new ErrorException('Internal error | ' . $debugInfo, 102);
        } catch (\Exception $e) {
            throw new ErrorException("Response parse Exception: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString(), $e->getCode());
        }
    }

    public function graphql(
        $query,
        $schema,
        $api_key,
        $type,
        $custom_key
    ) {
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
                        $query
                    ); //rows returned
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

    public function authentication($api_key, $private_key, $apify)
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

        if ($apify) {
            $token = base64_encode($api_key . ":" . $private_key);
            $headers["Authorization"] = "Basic " . $token;
            $data = [];
        }
        $url = $apify ?  Client::getBaseUrlApify() . "/login" : Client::getBaseUrl() . "/v1/auth/login";
        $response = Requests::post($url, $headers, json_encode($data), $options);

        return isset($response->body) ? $response->body : false;
    }

    protected function getEpaycoSecureBaseUrl($default)
    {
        $epaycoEnv = getenv('EPAYCO_PHP_SDK_ENV_REST');

        if (false === $epaycoEnv || 'prod' === $epaycoEnv) {
            return $default;
        } else if ($epaycoEnv) {
            return getenv('EPAYCO_PHP_SDK_ENV_REST');
        }

        return $default;
    }

   
}
