# Epayco PHP

Esta librería incluye los métodos necesarios para integrar el API de [Epayco](https://epayco.co/)

## Instalación

### Dependencias

    * PHP 5.3+

### Instalación con composer

Si estás usando [Composer](https://github.com/composer/composer), agrega la dependencia en `require`:

```javascript
{
  "require" : {
    "epayco/epayco-php" : "dev-master"
  }
}
```

Agregando el `autoload` de composer

```php
require 'vendor/autoload.php';
```

### Instalación desde GitHub

```bash
$ git clone https://github.com/epayco/epayco-php.git
```

## Documentación

Documentación disponible en [epayco.co](https://epayco.co/docs/introduction/)

## Uso

```php
  $epayco = new Epayco\Epayco(array(
      "apiKey" => "YOU_PUBLIC_API_KEY",
      "privateKey" => "YOU_PRIVATE_API_KEY",
      "lenguage" => "ES",
      "test" => true
  ));
```
