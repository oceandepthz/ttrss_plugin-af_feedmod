<?php

error_reporting(E_ERROR);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 1);
ini_set('log_errors', 0);

require_once('PicTwitterImageUrls.php');

//define("USER_AGENT_FEEDMOD", "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:72.0) Gecko/20100101 Firefox/72.0");
//ini_set('user_agent', USER_AGENT_FEEDMOD);

//$url = "pic.twitter.com/81uDdcPnIS";
//$url = "pic.twitter.com/0G1njgXN1U";
$url = "pic.twitter.com/ErCdG8mkRC";

$p = new PicTwitterImageUrls($url);

$a = $p->getImageUrls();

var_dump($a);
