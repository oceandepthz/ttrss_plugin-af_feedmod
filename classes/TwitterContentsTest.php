<?php

error_reporting(E_ERROR);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 1);
ini_set('log_errors', 0);

require_once('TwitterContents.php');

//define("USER_AGENT_FEEDMOD", "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:72.0) Gecko/20100101 Firefox/72.0");
//ini_set('user_agent', USER_AGENT_FEEDMOD);

//$url = "https://twitter.com/sanoji318/status/1480137806580092928";
$url = "https://twitter.com/BaseballkingJP/status/1578287602771558400?t=iYKAIrEaDzPupNDjrWuI9A&amp;s=19";

$p = new TwitterContents($url);

$a = $p->getContents();

var_dump($a);
