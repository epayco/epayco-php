<?php

    namespace Epayco;

    require 'vendor/autoload.php';
    require_once "errors.php";

    class Client
    {
        const BASE_URL = "http://localhost:3000";
        public function request(
            $method,
            $url,
            $api_key,
            $data = null,
            $headers= array("Content-Type" => "application/json", "Accept" => "application/json", "type" => "sdk")
        ) {
            try {
                $options = array(
                    'auth' => new \Requests_Auth_Basic(array($api_key, ''))
                );
                if ($method == "GET") {
                    $url_params = is_array($data) ? '?' . http_build_query($data) : '';
                    $response = \Requests::get(Client::BASE_URL . $url . $url_params, $headers, $options);
                } elseif ($method == "POST") {
                    $response = \Requests::post(Client::BASE_URL . $url, $headers, json_encode($data), $options);
                } elseif ($method == "PATCH") {
                    $response = \Requests::patch(Client::BASE_URL . $url, $headers, json_encode($data), $options);
                } elseif ($method == "DELETE") {
                    $response = \Requests::delete(Client::BASE_URL . $url, $headers, $options);
                }
            } catch (\Exception $e) {
                throw new UnableToConnect();
            }
            if ($response->status_code >= 200 && $response->status_code <= 206) {
                if ($method == "DELETE") {
                    return $response->status_code == 204 || $response->status_code == 200;
                }
                return json_decode($response->body);
            }
            if ($response->status_code == 400) {
                $code = 0;
                $message = "";
                try {
                    $error = (array) json_decode($response->body)->errors[0];
                    $code = key($error);
                    $message = current($error);
                } catch (\Exception $e) {
                    throw new UnhandledError($response->body, $response->status_code);
                }
                throw new InputValidationError($message, $code);
            }
            if ($response->status_code == 401) {
                throw new AuthenticationError();
            }
            if ($response->status_code == 404) {
                throw new NotFound();
            }
            if ($response->status_code == 403) {
                throw new InvalidApiKey();
            }
            if ($response->status_code == 405) {
                throw new MethodNotAllowed();
            }
            throw new UnhandledError($response->body, $response->status_code);
        }
    }
