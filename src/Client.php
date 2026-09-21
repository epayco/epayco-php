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
    const BASE_URL_SECURE = "https://eks-rest-pagos-service.epayco.io";
    const ENTORNO = "/restpagos";
    const BASE_URL_APIFY = "https://eks-apify-service.epayco.io";
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
        $data,
        $private_key,
        $test,
        $switch,
        $lang,
        $cash = null,
        $card = null,
        $apify = false
    ) {

        /**
         * Resources ip, traslate keys
         */
        $util = new Util();
        if ($method == "POST" && !is_null($data) && is_array($data) && !isset($data['extras_epayco'])) {
            $data['extras_epayco'] = ["extra5" => "P42"];
        }
        /**
         * Switch traslate keys array petition in secure
         */
        if ($apify && is_array($data)) {
            $data = $util->setKeys_apify($data);
        } else if ($switch && is_array($data)) {
            $data = $util->setKeys($data);
        }
        try {
            /**
             * Set heaToken bearer
             */

            $cookie_name = $api_key . ($apify ? "_apify" : "");
            if (!isset($_COOKIE[$cookie_name])) {
                //  echo "Cookie named '" . $cookie_name . "' is not set!";
                $dataAuth = $this->authentication($api_key, $private_key, $apify);
                $json = json_decode($dataAuth);
                if (!is_object($json)) {
                    throw new ErrorException("Error get bearer_token.", 106);
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
                    throw new ErrorException($msj, 422);
                }
                $cookie_value = $bearer_token;
                setcookie($cookie_name, $cookie_value, time() + (60 * 14), "/");
                //  echo "token con login".$bearer_token;
            } else {
                $bearer_token = $_COOKIE[$cookie_name];
            }
        } catch (\Exception $e) {
            $data = array(
                "status" => false,
                "message" => "Error get bearer_token: " ? "No autorizado, revisa tus credenciales" : $e->getMessage(),
                "status_code" => $e->getCode(),
                "data" => isset($errors['data']) ? $errors['data'] : [],
            );
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
                if ($apify) {
                    $_url = $this->getEpaycoBaseApify(Client::BASE_URL_APIFY) . $url;
                } elseif ($switch) {
                    $_url = $this->getEpaycoSecureBaseUrl(Client::BASE_URL_SECURE) . $this->getEpaycoEntorno(Client::ENTORNO) .  $url;
                } else {
                    $_url = $this->getEpaycoBaseUrl(Client::BASE_URL) . $url;
                }

                $response = Requests::get($_url, $headers, $options);
            } elseif ($method == "POST") {
                if ($apify) {
                    $response = Requests::post($this->getEpaycoBaseApify(Client::BASE_URL_APIFY) . $url, $headers, json_encode($data), $options);
                } elseif ($switch) {
                    $data = $util->mergeSet($data, $test, $lang, $private_key, $api_key, $cash);

                    $response = Requests::post($this->getEpaycoSecureBaseUrl(Client::BASE_URL_SECURE) . $this->getEpaycoEntorno(Client::ENTORNO) . $url, $headers, json_encode($data), $options);
                } else {

                    if (!$card) {
                        $data["ip"] = isset($data["ip"]) ? $data["ip"] : getHostByName(getHostName());
                        $data["test"] = $test;
                    }
                    $response = Requests::post($this->getEpaycoBaseUrl(Client::BASE_URL) . $url, $headers, json_encode($data), $options);
                }
            } elseif ($method == "DELETE") {
                $response = Requests::delete($this->getEpaycoBaseUrl(Client::BASE_URL) . $url, $headers, $options);
            }
        } catch (\Exception $e) {
            throw new ErrorException($e->getMessage(), $e->getCode());
        }
        try {
            if ($response->status_code >= 200 && $response->status_code <= 206) {
                if ($method == "DELETE") {
                    return $response->status_code == 204 || $response->status_code == 200;
                }
                // Return decoded response body instead of $response->data->raw_body
                return json_decode($response->body);
            }

            if ($response->status_code >= 400 && $response->status_code < 600) {
                $body = $response->body;


                if (empty($body)) {
                    $responseDataBody = array(
                        "status" => false,
                        "message" => "La respuesta del servidor está vacía o no es válida.",
                        "data" =>  isset($errors['data']) ? $errors['data'] : [],
                        
                    );
                    return json_encode($responseDataBody, JSON_PRETTY_PRINT);
                }

                $errors = (array)json_decode($body);


                $error = "Ocurrió un error, por favor contactar con soporte.";

                switch ($response->status_code) {
                    case 400:
                        $error = isset($errors['message'])
                            ? $errors['message']
                            : (isset($errors['errors'][0])
                                ? $errors['errors'][0]
                                : "Solicitud incorrecta, por favor verifica los datos enviados");
                        break;
                    case 401:
                        $error = isset($errors['message'])
                            ? $errors['message']
                            : (isset($errors['errors'][0])
                                ? $errors['errors'][0]
                                : "No autorizado, revisa tus credenciales");
                        break;
                    case 403:
                        $error = isset($errors['message'])
                            ? $errors['message']
                            : (isset($errors['errors'][0])
                                ? $errors['errors'][0]
                                : "Acceso prohibido, no tienes permisos para esta acción");
                        break;
                    case 404:
                        $error = "La ruta en la que estás realizando la petición no existe";
                        break;
                    case 405:
                        $error = isset($errors['message'])
                            ? $errors['message']
                            : (isset($errors['errors'][0])
                                ? $errors['errors'][0]
                                : "Método no permitido en esta ruta");
                        break;
                    default:
                        $error = "Error inesperado del servidor (HTTP {$response->status_code})";
                        break;
                }

                $responseData = array(
                    "status" => false,
                    "message" => $error,
                    "data" => isset($errors['data']) ? $errors['data'] : [],
                    "status_code" => $response->status_code
                );

                return json_encode($responseData, JSON_PRETTY_PRINT);
            }
        } catch (\Exception $e) {
            throw new ErrorException($e->getMessage(), $e->getCode());
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
        $url = $apify ? $this->getEpaycoBaseApify(Client::BASE_URL_APIFY) . "/login" : $this->getEpaycoBaseUrl(Client::BASE_URL) . "/v1/auth/login";
        $response = Requests::post($url, $headers, json_encode($data), $options);

        return isset($response->body) ? $response->body : false;
    }


    protected function getEpaycoSecureBaseUrl($default)
    {
        return getenv('BASE_URL_SECURE_SDK') ?: $default;
    }

    protected function getEpaycoEntorno($default)
    {
        return getenv('BASE_URL_ENTORNO_SDK') ?: $default;
    }

    protected function getEpaycoBaseApify($default)
    {
        return getenv('BASE_APIFY_SDK') ?: $default;
    }
}
