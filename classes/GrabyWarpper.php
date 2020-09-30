<?php

require __DIR__ . "/../vendor/autoload.php";
use Graby\Graby;

class GrabyWarpper {
    function get_html($url) : string{
        if(!filter_var($url, FILTER_VALIDATE_URL)){
            return "";
        }

        $graby = new Graby();
        $result = $graby->fetchContent($url);
        if($result['status'] === 200){
            return $result['html'];
        }
        return "";
    }
}
