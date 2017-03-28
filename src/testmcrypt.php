
<?php

if(function_exists('mcrypt_encrypt')) {
    echo "mcrypt is loaded!";
    $size=mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128,MCRYPT_MODE_CBC);
    echo $size;
} else {
    echo "mcrypt isn't loaded!";
}

?>