<?php

$url = 'https://togetter.com/li/2397965';

        require_once('Togetter.php');
        $to = new Togetter($url);
	$html =  $to->get_html();
	var_dump($html);
