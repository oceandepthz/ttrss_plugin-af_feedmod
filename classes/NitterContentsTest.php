<?php

require_once('NitterContents.php');
#$n = new NitterContents('https://www.php.net/');
#var_dump('https://www.php.net/', $n->isNitter());

//$u = 'https://nitter.kozono.org/RuairiRobinson/status/2021394940757209134';
//$u = 'https://nitter.kozono.org/mazzo/status/2024265208139845729';
$u = 'https://nitter.kozono.org/shibayan/status/2024306581320733100';
$n = new NitterContents($u);
var_dump($u, $n->isNitter());
var_dump($u, $n->getContent());


