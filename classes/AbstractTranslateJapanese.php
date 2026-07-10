<?php

abstract class AbstractTranslateJapanese implements TranslateJapaneseInterface
{
    protected string $value;
    protected string $url;

    public function __construct(string $value, string $url) {
        $this->value = $value;
        $this->url = $url;
    }

    /**
     * 特定のドメインが含まれているかチェックします。
     */
    public function containsSpecificDomain(): bool
    {
        $domains_to_check = [
            '//www.nhk.jp/',
        ];

        foreach ($domains_to_check as $domain_pattern) {
            if (strpos($this->url, $domain_pattern) !== false) {
                return true;
            }
        }

       return false;
    }

    /**
     * 翻訳対象であるか判定します。
     */
    public function isTranslate() : bool
    {
        if (preg_match('/\.pdf(\?.*)?$/i', $this->url)) {
            return false;
        }
        if($this->containsSpecificDomain())
        {
            return false;
        }
        if(!$this->value)
        {
            return false;
        }

        $pattern = '/[\x{3040}-\x{30FF}]/u';

        $scanValue = $this->getTextContains();
        if(is_null($scanValue)){
            return false;
        }
        $cleanScanText = trim(preg_replace('/\s+/', ' ', $scanValue));
        $thresholdLength = 100;
        if(strpos($this->url, '//nitter.kozono.org/') !== false){
            $thresholdLength = 150;
        }
        if(strlen($cleanScanText) < $thresholdLength){
            return false;
        }
        $firstScanValue = mb_strcut($cleanScanText, 0, 1000);
        return preg_match($pattern, $firstScanValue) === 0;
    }

    /**
     * HTMLからテキスト部分を抽出します。
     */
    public function getTextContains() : string
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>".$this->value."</body></html>";
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $text = $dom->textContent;
        return $text;        
    }

    /**
     * システムプロンプトを取得します。
     */
    public function getSystemPrompt() : string
    {
        $path = dirname(__FILE__)."/system_prompt.txt";
        return file_get_contents($path);
    }

    /**
     * 文字列を翻訳します。
     */
    abstract public function translateString() : string;
}
