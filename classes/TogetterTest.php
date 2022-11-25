<?php

$url = 'https://togetter.com/li/1976334';

        require_once('Togetter.php');
        $to = new Togetter();
	$html =  $to->get_html($url);
	var_dump($html);
