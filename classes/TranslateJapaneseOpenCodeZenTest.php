<?php
// テスト用のHTMLファイルを読み込む（既存のファイルを再利用）
$s = file_get_contents('TranslateJapaneseGeminiTestHtml.txt'); 
// テスト用のURL
$u = "https://www.example.com/";

require_once('TranslateJapaneseOpenCodeZen.php');

echo "--- TranslateJapaneseOpenCodeZen Test Start ---\n";

$tj = new TranslateJapaneseOpenCodeZen($s, $u);

// 翻訳が必要かどうかの判定テスト
echo "isTranslate: ";
$needs_translate = $tj->isTranslate();
var_dump($needs_translate);

if($needs_translate){
    echo "translateString execution...\n";
    // 注意: 有効なAPIキーが環境変数 'OPENCODEZEN_API_KEYS' に設定されている必要があります
    $result = $tj->translateString();
    
    if ($result) {
        echo "Success! Result sample:\n";
        echo $result . "...\n";
    } else {
        echo "Result is empty. (Check your API keys or network connection)\n";
    }
} else {
    echo "Skip translation: Content is already in Japanese or doesn't meet criteria.\n";
}

echo "--- Test End ---\n";
