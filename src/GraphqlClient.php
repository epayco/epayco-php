<?php

namespace Epayco;


use Epayco\Utils\PaycoAes;
use Epayco\Util;
use Epayco\Exceptions\ErrorException;

/**
 * Client conection api epayco
 */
class GraphqlClient
{
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

    public function queryStringCreator($page){
        $query = '
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
          }';

        return $query;
    }
}
