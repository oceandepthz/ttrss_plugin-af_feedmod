<?php
require __DIR__ . "/vendor/autoload.php";
use JonnyW\PhantomJs\Client;

class PhantomJsWarpper {
    function get_html($url) : string{
        $client = Client::getInstance();
        $client->getEngine()->setPath(__DIR__.'/bin/phantomjs');
        $request = $client->getMessageFactory()->createRequest($url,'GET');
        $response = $client->getMessageFactory()->createResponse();
        $client->send($request, $response);
        if($response->getStatus() === 200) {
            return $response->getContent();
        }
        return "";
    }
}

