<?php

namespace App\Integrations;

use Microsoft\Graph\Exception\GraphException;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;

trait MicrosoftGraph
{
    public static function connect(): Graph
    {
        $guzzle = new \GuzzleHttp\Client;
        $url = 'https://login.microsoftonline.com/'.env('MS_TENANT_ID').'/oauth2/token?api-version='.env('MS_GRAPH_API_VERSION');

        $response = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => env('MS_CLIENT_ID'),
                'client_secret' => env('MS_CLIENT_SECRET'),
                'resource' => 'https://graph.microsoft.com/',
                'grant_type' => 'client_credentials',
            ],
        ])->getBody()->getContents());

        dd($response);

        $graph = new Graph;

        return $graph->setBaseUrl('https://graph.microsoft.com')
            ->setApiVersion(env('MS_GRAPH_API_VERSION'))
            ->setAccessToken($response->access_token);
    }

    /**
     * Get all the users in the tenant
     *
     * @return mixed
     *
     * @throws GraphException
     */
    public static function users()
    {
        $graph = self::connect();

        $query = '/users';

        return $graph->createRequest('get', $query)
            ->addHeaders(['Content-Type' => 'application/json'])
            ->setReturnType(Model\User::class)
            ->setTimeout('1000')
            ->execute();
    }
}
