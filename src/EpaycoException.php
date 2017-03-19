<?php
/**
 * Created by PhpStorm.
 * User: anvargear
 * Date: 19/03/17
 * Time: 12:55 PM
 */

namespace Epayco;


class EpaycoException extends \Exception
{
    const ERRORS_URL = "https://s3-us-west-2.amazonaws.com/epayco/message_api/errors.json";
}