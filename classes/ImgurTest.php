<?php

error_reporting(E_ERROR);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 1);
ini_set('log_errors', 0);

require_once('Imgur.php');

$url = "//imgur.com/a/5Rt9mRu";
$imgur = new Imgur($url);
$urls = $imgur->getImgurUrls();
var_dump($urls);

