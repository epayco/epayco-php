<?php
/**
 * Created by PhpStorm.
 * User: anvargear
 * Date: 19/03/17
 * Time: 12:51 PM
 */

namespace Epayco\Resources;


use Epayco\Resource;

class Cash extends Resource
{
    /**
     * Return data payment cash
     * @param  String $type method payment
     * @param  String $options data transaction
     * @return object
     */
    public function create($type = null, $options = null)
    {
        $url = null;
        switch ($type) {
            case "efecty":
                $url = "/restpagos/pagos/efecties.json";
                break;
            case "baloto":
                $url = "/restpagos/pagos/balotos.json";
                break;
            case "gana":
                $url = "/restpagos/pagos/ganas.json";
                break;
            default:
                throw new ErrorException($this->epayco->lang, 109);
                break;
        }
        return $this->request(
            "POST",
            $url,
            $api_key = $this->epayco->api_key,
            $options,
            $private_key = $this->epayco->private_key,
            $test = $this->epayco->test,
            $switch = true,
            $lang = $this->epayco->lang
        );
    }
}