<?php
require_once(__DIR__ . '/SiteHeadInfo.php');

$url = 'https://t.co/CyXnruOvDR';


$info = new SiteHeadInfo($url);
$data = $info->toArray();

print_r($data);
