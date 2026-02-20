<?php

//$url="https://posfie.com/@petaritape/p/dibaYf9";
$url="https://posfie.com/@easykinoko/p/o6wZCKH";

require_once("PosfieCom.php");
$pc = new PosfieCom($url);
var_dump($pc->is_posfie());
$html = $pc->get_html();
var_dump($html);
