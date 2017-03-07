<?php

    namespace Epayco;

    const ERRORS_URL = "https://s3-us-west-2.amazonaws.com/epayco/message_api/errors.json";

    class EpaycoException extends \Exception
    {

    }

    class ErrorException extends EpaycoException
    {
        public function __toString()
        {
            $errors = json_decode(file_get_contents(ERRORS_URL), true);
            return __CLASS__ . ": {$errors[(string)$this->code][$this->message]}\n";
        }
    }
