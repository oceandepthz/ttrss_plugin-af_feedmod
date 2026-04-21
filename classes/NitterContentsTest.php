<?php

require_once('NitterContents.php');
#$n = new NitterContents('https://www.php.net/');
#var_dump('https://www.php.net/', $n->isNitter());

//$u = 'https://nitter.kozono.org/RuairiRobinson/status/2021394940757209134';
//$u = 'https://nitter.kozono.org/mazzo/status/2024265208139845729';
//$u = 'https://nitter.kozono.org/shibayan/status/2024306581320733100';
//$u = 'https://nitter.kozono.org/pal9999/status/2029470940384641524';
//$u = 'https://nitter.kozono.org/claudeai/status/2032509548297343196';
$u = "https://nitter.kozono.org/MLB/status/2038049178308223397";
$n = new NitterContents($u);
var_dump($u, $n->isNitter());
var_dump($u, $n->getContent());


