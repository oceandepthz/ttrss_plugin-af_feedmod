<?php

$url = 'https://togetter.com/li/2420778';

        require_once('Togetter.php');
        $to = new Togetter($url);
	$html =  $to->get_html();
	var_dump($html);
