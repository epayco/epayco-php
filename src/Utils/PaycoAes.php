<?php

namespace Epayco\Utils;

use Epayco\Utils\McryptEncrypt;
use Epayco\Utils\OpensslEncrypt;

/**
 * Epayco library encrypt based in AES
 */
try {
    
    $_cipher = MCRYPT_RIJNDAEL_128;
    $_mode = MCRYPT_MODE_CBC;

    class PaycoAes extends McryptEncrypt {}
} catch (\Exception $e) {
    
    class PaycoAes extends OpensslEncrypt {}
}


?>