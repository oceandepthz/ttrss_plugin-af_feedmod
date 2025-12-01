<?php

require_once('NitterContents.php');
$n = new NitterContents('https://www.php.net/');
var_dump('https://www.php.net/', $n->isNitter());

$u = 'https://nitter.kozono.org/mnishi41/status/1898909767319347549';
$n = new NitterContents($u);
var_dump($u, $n->isNitter());
var_dump($u, $n->getContent());


