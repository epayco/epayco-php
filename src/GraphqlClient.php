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
     $pagination = $query->pagination;
     $customFields = $query->customFields;

     //Comprobacion: El query tiene un action: find o findOne?
     if (!($query->action === "find" || $query->action === "findOne")) {
         throw new ErrorException("Parameter required, please specify action: find or findOne and try again.",102);
     }

     //Comprobacion: El query tiene un atributo selector o selectorOr, si el action es igual a "find" el atributo puede ser nulo, este caso es para listar todos los registros de un modelo
     if (  $selector === null && $selectorOr === null) {
      throw new ErrorException("Parameter required, selector is empty or invalid please fill and try again.",103);
     }else{

      if ($selector !== null && isset( $selector[0])){
          throw new ErrorException("Parameter required, selector is empty or invalid please fill and try again.",103);
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
            if (!($this->validateDateFormat($byDates["start"],'YYYY-MM-DD')
                && $this->validateDateFormat($byDates["end"],'YYYY-MM-DD')
                ) ) {
                throw  new ErrorException("Parameter required, byDates is empty or invalid please fill and try again.",105);
            }
        }
    }

      //Comprobación: El query solicita una paginacion de registros
    if ($pagination !== null) {
          if (gettype($pagination) !== "array") {
              throw  new ErrorException("Parameter required, pagination is empty or invalid please fill and try again.",106);
          }else{
              if($pagination["limit"] === null || $pagination["pageNumber"] === null){
                  throw new ErrorException("Parameter required, pagination limit or pageNumber is empty or invalid please fill and try again.",106);
              }else{
                  if (gettype($pagination["limit"]) !== "integer" || gettype($pagination["pageNumber"]) !== "integer") {
                      throw new ErrorException("Parameter required, pagination limit or pageNumber has a invalid value type please fill and try again.",106);
                  }
              }
        }
    }
     //Comprobación: El query solicita campos personalizados
    if ($customFields !== null) {
        if (gettype($customFields) !== "string") {
            throw new ErrorException("Parameter required, customFields is empty or invalid please fill and try again.",107);
        }else{
            if( empty($customFields)){
                throw new ErrorException("Parameter required, customFields is empty or invalid please fill and try again.",107);
            }
        }
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

    /**
     * @description Verificar si el esquema consultado va a listar un conjunto de registros
     * @param String action tipo de busqueda, Find o FindOne
     * @param Object pagination informacion de paginacion solicitada
     * @param String schema nombre de esquema a consultar
     */
    public function canPaginateSchema($action,$pagination,$schema){
        if ($pagination !== null) {
            if ($action === "findOne" && $pagination["limit"] !== null) {
                throw  new ErrorException("Can't paginate this schema ${schema}, because this query has only one rows to show, please add a valid query and try again.",108);
            }
        }
    }

    public function paramsBuilder($query){

        $selector = $query->selector;
        $selectorOr = $query->selectorOr;
        $options = [];

        if ($selector !== null){
            foreach ($selector as $key => $item) {
                $options["selector"] = [
                  "type" => $key,
                  "value" => $item
                ];
            }
            $optionsToJson = json_encode((object)$options);
        }else if ($selectorOr !== null){
            foreach ($selectorOr as $key => $SelectorItem) {
                foreach ($SelectorItem as $key => $item) {
                    $options["selectorOr"] = [
                        "type" => $key,
                        "value" => $item
                    ];
                }
            }
        }
        return $options;
    }

    public function queryString(
        $selectorParams,
        $schema,
        $wildCard,
        $byDates,
        $customFields,
        $paginationInfo)
    {
        $wildCardOption = ($wildCard === null) ? "default": $wildCard;
        $byDatesOptions = ($byDates === null) ? (object)[]: (object)$byDates;
        $fields = ($customFields === null) ? $this->fields($schema): $customFields;
        $selectorName = key($selectorParams[0]); //Get selector name

    }

    public function fields($type){
        switch ($type){
            case "subscriptions":
                return `_id
        periodStart
        periodEnd
        status
        customer {
          _id
          name
          email
          phone
          doc_type
          doc_number
          cards {
            data {
              token
              lastNumbers
              franquicie
            }
          }
        }
        plan {
          name
          description
          amount
          currency
          interval
          interval_count
          status
          trialDays
        }`;
            case "customer":
                return `name
              _id
              email
              cards {
                token
                data {
                  franquicie
                  lastNumbers
                }
              }
              subscriptions {
                _id
                periodStart
                periodEnd
                status
                plan {
                  _id
                  idClient
                  amount
                  currency
                }
        }`;
            case "customers":
                return `name
              _id
              email
              cards {
                token
                data {
                  franquicie
                  lastNumbers
                }
              }
              subscriptions {
                _id
                periodStart
                periodEnd
                status
                plan {
                  _id
                  idClient
                  amount
                  currency
                }
        }`;
        }

    }

    public function queryTemplates(){
        //Convert selectorParams with json_encode
        //$optionsToJson = json_encode((object)$options);
    }

    public function validateDateFormat($date){
        if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/",$date)) {
            return true;
        } else {
            return false;
        }
    }

    
}
