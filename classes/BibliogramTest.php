<?php

error_reporting(E_ERROR);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 1);
ini_set('log_errors', 0);

require_once('Bibliogram.php');

$url = "https://www.instagram.com/p/CjGMzMYv4Z9/";
$bib = new Bibliogram($url);
$html = $bib->getInstagramHtml();
var_dump($html);

