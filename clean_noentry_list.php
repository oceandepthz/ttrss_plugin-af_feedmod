#!/usr/bin/php
<?php
if(count($argv) !=2){
  echo "引数エラー\n";
  die;
}

$file_name = $argv[1];
$file_full = dirname(__FILE__).'/'.$file_name;
$entry_list = array();

$lines = file($file_full);
foreach($lines as $line){
  $line_array = explode("\t", $line);
  $key_hash = hash("sha256", $line_array[2]);
  if(!array_key_exists($key_hash, $entry_list)){
    $entry_list[$key_hash] = $line_array;
  }
}

rename($file_full, $file_full.".".date("YmdHis"));
foreach($entry_list as $key => $line_array){
  file_put_contents($file_full,implode("\t", $line_array), FILE_APPEND);
}
chown($file_full,'apache');
chgrp($file_full,'apache');

