<?php
require_once('SiteHeadInfoHtml.php');

$renderer = new SiteHeadInfoHtml();
$errors = [];

// フルデータ
$full = [
    'url' => 'https://example.com/article',
    'final_url' => 'https://example.com/article',
    'title' => 'Example Article Title',
    'description' => 'A short description of the page.',
    'canonical' => 'https://example.com/article',
    'favicon' => 'https://example.com/favicon.ico',
    'lang' => 'en',
    'og' => [
        'title' => 'OG Title',
        'image' => 'https://example.com/og-image.jpg',
    ],
];
$html = $renderer->render($full);
if (strpos($html, '<style type="text/css">') === false) {
    $errors[] = 'full: missing style tag';
}
if (strpos($html, 'Example Article Title') === false) {
    $errors[] = 'full: missing title';
}
if (strpos($html, 'A short description of the page.') === false) {
    $errors[] = 'full: missing description';
}
if (strpos($html, 'example.com') === false) {
    $errors[] = 'full: missing domain';
}
if (strpos($html, 'https://example.com/favicon.ico') === false) {
    $errors[] = 'full: missing favicon src';
}
if (strpos($html, 'https://example.com/og-image.jpg') === false) {
    $errors[] = 'full: missing og:image src';
}
if (strpos($html, 'fm-site-head-info') === false) {
    $errors[] = 'full: missing wrapper class';
}

// error のみ
$error_data = [
    'url' => 'https://example.com/missing',
    'error' => 'fetch_failed',
];
$error_html = $renderer->render($error_data);
if (strpos($error_html, 'ページ情報を取得できませんでした') === false) {
    $errors[] = 'error: missing error message';
}
if (strpos($error_html, 'https://example.com/missing') === false) {
    $errors[] = 'error: missing link url';
}

// 空に近い配列
$empty_html = $renderer->render([]);
if ($empty_html !== '') {
    $errors[] = 'empty: expected empty string, got output';
}

// XSS 対策
$xss = [
    'url' => 'https://example.com/xss',
    'final_url' => 'https://example.com/xss',
    'title' => '<script>alert(1)</script>',
    'description' => 'safe desc',
];
$xss_html = $renderer->render($xss);
if (strpos($xss_html, '<script>alert(1)</script>') !== false) {
    $errors[] = 'xss: title was not escaped';
}
if (strpos($xss_html, '&lt;script&gt;alert(1)&lt;/script&gt;') === false) {
    $errors[] = 'xss: expected escaped title';
}

// renderFromUrl は手動実行用:
// $live = (new SiteHeadInfoHtml())->renderFromUrl('https://example.com/');
// echo $live;

if ($errors !== []) {
    fwrite(STDERR, "FAILED:\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}

var_dump($html);

echo "OK\n";
