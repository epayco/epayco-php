<?php

namespace Epayco;


class EpaycoException extends \Exception
{
    const ERRORS_URL = "https://s3-us-west-2.amazonaws.com/epayco/message_api/errors.json";
}