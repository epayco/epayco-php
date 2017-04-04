<?php

namespace Epayco;


use Epayco\Utils\PaycoAes;
use Epayco\Util;
use Epayco\Exceptions\ErrorException;

/**
 * Client conection api epayco
 */
class Client
{
    const BASE_URL = "https://api.secure.payco.co";
    const BASE_URL_SECURE = "https://secure.payco.co";
    const IV = "0000000000000000";
    const LENGUAGE = "php";

    /**
     * Request api epayco
     * @param  String $method      method petition
     * @param  String $url         url request api epayco
     * @param  String $api_key     public key commerce
     * @param  Object $data        data petition
     * @param  String $private_key private key commerce
     * @param  String $test        type petition production or testing
     * @param  Boolean $switch     type api petition
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
        $lang
    ) {

        /**
         * Resources ip, traslate keys
         */
        $util = new Util();

        /**
         * Switch traslate keys array petition in secure
         */
        if ($switch && is_array($data)) {
            $data = $util->setKeys($data);
        }

        /**
         * Set headers
         */
        $headers= array("Content-Type" => "application/json", "Accept" => "application/json", "type" => "sdk");

        try {
            $options = array(
                'auth' => new \Requests_Auth_Basic(array($api_key, ''))
            );
            if ($method == "GET") {
                if ($switch) {
                    if($test){
                        $test="TRUE";
                    }else{
                        $test="FALSE";
                    }

                    $response = \Requests::get(Client::BASE_URL_SECURE . $url, $headers, $options);
                } else {
                    $response = \Requests::get(Client::BASE_URL . $url, $headers, $options);
                }
            } elseif ($method == "POST") {
                if ($switch) {
                   if($test){
                        $test="TRUE";
                    }else{
                        $test="FALSE";
                    }
                    $data = $util->mergeSet($data, $test, $lang, $private_key, $api_key);
                    $response = \Requests::post(Client::BASE_URL_SECURE . $url, $headers, json_encode($data), $options);
                } else {
                    $data["ip"] = getHostByName(getHostName());
                    $data["test"] = $test;
                    $response = \Requests::post(Client::BASE_URL . $url, $headers, json_encode($data), $options);
                }
            } elseif ($method == "DELETE") {
                $response = \Requests::delete(Client::BASE_URL . $url, $headers, $options);
            }
        } catch (\Exception $e) {
            throw new ErrorException($lang, 101);
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
                throw new ErrorException($lang, 102);
            }
            throw new ErrorException($lang, 103);
        }
        if ($response->status_code == 401) {
            throw new ErrorException($lang, 104);
        }
        if ($response->status_code == 404) {
            throw new ErrorException($lang, 105);
        }
        if ($response->status_code == 403) {
            throw new ErrorException($lang, 106);
        }
        if ($response->status_code == 405) {
            throw new ErrorException($lang, 107);
        }
        throw new ErrorException($lang, 102);
    }
}