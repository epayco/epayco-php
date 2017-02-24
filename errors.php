<?php
      /**
       * Epayco Library Exceptions
       */

      namespace Epayco;

      /**
       * Base Epayco Exception
       */
       class EpaycoException extends \Exception
       {
           protected $message = "Base Epayco Exception";
       }


       /**
        * Base Epayco Exception
        */
        class TestException extends \Exception
        {
            protected $message = "Test value invalid";
        }


      /**
       * Input validation error
       */
      class InputValidationError extends EpaycoException
      {
          protected $message = "Input validation error";
      }


      /**
       * Authentication error
       */
      class AuthenticationError extends EpaycoException
      {
          protected $message = "Authentication error";
      }


      /**
       * Resource not found
       */
      class NotFound extends EpaycoException
      {
          protected $message = "Resource not found";
      }


      /**
       * Method not allowed
       */
      class MethodNotAllowed extends EpaycoException
      {
          protected $message = "Method not allowed";
      }


      /**
       * Unhandled error
       */
      class UnhandledError extends EpaycoException
      {
          protected $message = "Unhandled error";
      }


      /**
       * Invalid API Key
       */
      class InvalidApiKey extends EpaycoException
      {
          protected $message = "Invalid API Key";
      }


      /**
       * Unable to connect to Epayco API
       */
      class UnableToConnect extends EpaycoException
      {
          protected $message = "Unable to connect to Epayco API";
      }
