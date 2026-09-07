<?php

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once __DIR__ . '/BlueskyContents.php';

echo "=== BlueskyContents 単体テスト開始 ===\n\n";

// 1. URL判定テスト
$testUrls = [
    'https://bsky.app/profile/forbes.com/post/3muhh6wemzu2m' => true,
    'http://bsky.app/profile/user.bsky.social/post/3l7example' => true,
    'https://www.bsky.app/profile/did:plc:2w45zyhuklwihpdc7oj3mi63/post/3muhh6wemzu2m?ref_src=embed' => true,
    'https://bsky.app/profile/forbes.com' => false,
    'https://twitter.com/user/status/12345' => false,
    'https://example.com/test' => false,
];

$urlCheckSuccess = true;
foreach ($testUrls as $url => $expected) {
    $actual = BlueskyContents::isBlueskyUrl($url);
    if ($actual !== $expected) {
        echo "[FAILED] URL判定エラー: {$url} (期待値: " . ($expected ? 'true' : 'false') . ", 実際: " . ($actual ? 'true' : 'false') . ")\n";
        $urlCheckSuccess = false;
    }
}
if ($urlCheckSuccess) {
    echo "[PASSED] URL判定テスト\n";
}

// 2. ローカルJSONを用いたレンダリングテスト
$jsonFile = '/pub/dump-dom-php/forbes_data.json';
if (file_exists($jsonFile)) {
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    $postData = $jsonData['thread']['post'] ?? null;
    if ($postData) {
        $bsky = new BlueskyContents('https://bsky.app/profile/forbes.com/post/3muhh6wemzu2m');
        $postHtml = $bsky->renderPost($postData, '3muhh6wemzu2m');
        
        $postAssertions = [
            ['Forbes', '表示名が含まれていること'],
            ['@forbes.com', 'ハンドルが含まれていること'],
            ['bsky-post__badge', '認証バッジが含まれていること'],
            ['USPS Whistleblower', '埋め込みカードのタイトルが含まれていること'],
            ['www.forbes.com', '埋め込みカードのドメインが含まれていること'],
            ['3111', 'リポスト数が含まれていること'],
            ['5267', 'いいね数が含まれていること'],
        ];

        $postSuccess = true;
        foreach ($postAssertions as $item) {
            $needle = (string)$item[0];
            $desc   = $item[1];
            if (strpos($postHtml, $needle) === false) {
                echo "[FAILED] ローカルデータ検証: {$desc} ('{$needle}' が見つかりません)\n";
                $postSuccess = false;
            }
        }
        if ($postSuccess) {
            echo "[PASSED] ローカルJSONデータによる投稿レンダリング検証\n";
        }
    }
}

// 3. 引用ポスト・画像ギャラリーのレンダリングテスト
$mockGalleryEmbed = [
    '$type' => 'app.bsky.embed.images#view',
    'images' => [
        [
            'thumb' => 'https://cdn.bsky.app/img/thumb1.jpg',
            'fullsize' => 'https://cdn.bsky.app/img/full1.jpg',
            'alt' => 'テスト画像1',
        ],
        [
            'thumb' => 'https://cdn.bsky.app/img/thumb2.jpg',
            'fullsize' => 'https://cdn.bsky.app/img/full2.jpg',
            'alt' => 'テスト画像2',
        ]
    ]
];
$mockPostWithGallery = [
    'author' => ['handle' => 'photo.user', 'displayName' => 'Photo User'],
    'record' => ['text' => 'ギャラリーテスト投稿', 'createdAt' => '2026-09-01T10:00:00Z'],
    'embed' => $mockGalleryEmbed,
    'likeCount' => 10,
];
$bskyMock = new BlueskyContents('https://bsky.app/profile/photo.user/post/3testgallery');
$galleryHtml = $bskyMock->renderPost($mockPostWithGallery, '3testgallery');
if (strpos($galleryHtml, 'bsky-embed-gallery') !== false && strpos($galleryHtml, 'thumb1.jpg') !== false) {
    echo "[PASSED] 画像ギャラリー埋め込みレンダリング検証\n";
} else {
    echo "[FAILED] 画像ギャラリー埋め込みレンダリング検証に失敗\n";
}

// 4. 実APIからの取得テスト
$sampleUrl = 'https://bsky.app/profile/forbes.com/post/3muhh6wemzu2m';
$bskyReal = new BlueskyContents($sampleUrl);

echo "実API取得テスト対象: {$sampleUrl}\n";
$html = $bskyReal->getContents();

if (empty($html)) {
    echo "[FAILED] APIからのHTML取得に失敗しました。\n";
    exit(1);
}

// 5. HTML構造検証
$assertions = [
    'Forbes' => '表示名または著者名が含まれていること',
    '@forbes.com' => 'ハンドルが含まれていること',
    'bsky-thread' => 'スレッドクラスが含まれていること',
    'bsky-post' => '投稿クラスが含まれていること',
    'style type="text/css"' => 'CSSスタイルシートが含まれていること',
    'bsky-reply-wrapper' => '返信スレッドが含まれていること',
    '<!DOCTYPE html>' => 'DOCTYPE宣言が含まれていること',
];

$htmlSuccess = true;
foreach ($assertions as $keyword => $desc) {
    if (strpos($html, $keyword) === false) {
        echo "[FAILED] {$desc} ('{$keyword}' がHTML内に見つかりません)\n";
        $htmlSuccess = false;
    } else {
        echo "[PASSED] {$desc}\n";
    }
}

// 6. 不正URLテスト
$invalidBsky = new BlueskyContents('https://example.com/invalid');
$invalidHtml = $invalidBsky->getContents();
if ($invalidHtml === '') {
    echo "[PASSED] 不正URL時に空文字が返ることを確認\n";
} else {
    echo "[FAILED] 不正URL時に空文字が返りませんでした\n";
}

echo "\n=== 全ての単体テストが完了しました ===\n";
