<?php

namespace Epayco;


use Epayco\Utils\PaycoAes;
use Epayco\Util;
use Epayco\Exceptions\ErrorException;

/**
 * Client conection api epayco graphql
 */
class GraphqlClient
{
  public function validate($query){

      //Inicializar parametros requeridos para wrapper
      $action = $query->action;
      $selector = $query->selector;
      $selectorOr = $query->selectorOr;
      $wildCard = $query->wildCard;
      $byDates = $query->byDates;

    //Comprobacion: El query tiene un action: find o findOne?
      if (!($query->action === "find" || $query->action === "findOne")) {
        throw new ErrorException("Parameter required, please specify action: find or findOne and try again.",102);
      }

    //Comprobacion: El query tiene un atributo selector o selectorOr, si el action es igual a "find" el atributo puede ser nulo, este caso es para listar todos los registros de un modelo     
    if (  $selector === null && $selectorOr === null) {
      throw new ErrorException("Parameter required, selector is empty or invalid please fill and try again.",103);
    }else{
      if ($selector !== null && count($selector) > 0){
          throw new ErrorException("Parameter required, please specify action: find or findOne and try again.",103);
      }
    }

    //Comprobacion: El query requiere un comodin de busqueda
    if ($wildCard !== null) {
        if (gettype($wildCard)!== "string") {
          throw new ErrorException("Parameter required, wildCard is empty or invalid please fill and try again.",104);
        }else{
          if (!($wildCard === "contains" || $wildCard === "startsWith")) {
              throw  new ErrorException('Parameter invalid, please specify wildCard: "contains" or "startsWith" and try again.',104);
          }
      }
    }

      //Comprobación: El query solicita rango de fechas de busqueda
    if ($byDates !== null){
        if (gettype($byDates) !== "array"){
            throw  new ErrorException("Parameter required, byDates is empty or invalid please fill and try again.",105);
        }else{
            echo "comprobar fechas";
            echo $d = $byDates["start"];
            if (!($this->validateDateFormat($byDates["start"],'YYYY-MM-DD')
                && $this->validateDateFormat($byDates["end"],'YYYY-MM-DD')
                ) ) {
                throw  new ErrorException("Parameter required, byDates is empty or invalid please fill and try again.",105);
            }else{
                echo "fechas correctas";
            }
        }
    }else{
        echo "no requiere fecha";
    }

      /* $query = '
      query getPaginatedCustomers {
          paginatedCustomers(
            input: {
              wildCard: "startsWith"
              byDate: { start: "2019-03-01", end: "2019-04-30" }
              selectorOr: [
              
              ]
            }
            limit: 5
            pageNumber: '.$page.'
            #cursor: "MjAxOS0wNC0yNVQxNDoyOTo1Mi45MDJa"
          ) {
            totalRows
           totalRowsByPage
            customers{
              name
              createdAt
            }
            pageInfo {    	
              hasNextPage
              actualPage
              nextPages{
                page
              }
             previousPages{
                page
              }
            }
          }
        }'; */

      //return $query;
    }
    public function sendRequest(string $query, array $variables = [], $api_key)
    {
        $headers = [
            "Content-Type: application/json",
            "Accept" => "application/json", 
            "type" => "sdk",
            "authorization" => "Basic " . base64_encode($api_key)
        ];
        try {

            $body = [
                'query' => $query
            ];

            $response = \Requests::post(Client::BASE_URL . '/graphql', $headers, $body);

        } catch (\Throwable $th) {
            return $th->getMessage();
        }
        return json_decode($response->body,true);
    }

    public function validateDateFormat($date, $format = 'Y-m-d'){
        $d = date_create_from_format($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return $d &&  date_format($date, $format) === $date;
    }

    
}
