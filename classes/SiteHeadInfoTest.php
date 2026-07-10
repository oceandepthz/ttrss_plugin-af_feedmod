<?php
require_once('SiteHeadInfo.php');

// URL を差し替えて実行: php SiteHeadInfoTest.php
//$url = 'https://t.co/sRwr1jaLLJ';
//$url = 'https://t.co/1BS2z2NgMK';
$url = 'https://t.co/CyXnruOvDR';

echo "Checking URL: {$url}\n";

$info = new SiteHeadInfo($url);
$data = $info->toArray();
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo $json . "\n";

echo "\n--- as array ---\n";
print_r($data);

$errors = [];
if (isset($data['error'])) {
    $errors[] = "unexpected error: {$data['error']}";
}
if (!isset($data['final_url']) || strpos($data['final_url'], 't.co') !== false) {
    $errors[] = 'final_url should not stay on t.co';
}
if (!isset($data['title']) || preg_match('#^https?://#i', $data['title'])) {
    $errors[] = 'title should not be a URL string';
}

if ($errors !== []) {
    fwrite(STDERR, "FAILED:\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}

echo "\nOK\n";
