<?php

class TranslateJapaneseGemini
{
    protected string $value;
    protected string $url;

    function __construct(string $value, string $url) {
        $this->value = $value;
        $this->url = $url;
    }

    function containsSpecificDomain(): bool
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

    function isTranslate() : bool
    {
        if($this->containsSpecificDomain())
        {
            return false;
        }

        $pattern = '/[\x{3040}-\x{30FF}]/u';

        $scanValue = $this->getTextContains();
        if(is_null($scanValue) || strlen($scanValue) < 50){
            return false;
        }
        $firstScanValue = mb_strcut($scanValue, 0, 1000);
        $firstScanValue = str_replace(array("\r", "\n"), '', $firstScanValue);
        return preg_match($pattern, $firstScanValue) === 0;
    }

    function getTextContains() : string
    {
        $dom = new DOMDocument();
        $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>".$this->value."</body></html>";
        @$dom->loadHTML($html);
        $text = $dom->textContent;
        return $text;        
    }
    function getSystemPrompt() : string
    {
        $path = dirname(__FILE__)."/system_prompt.txt";
        return file_get_contents($path);
    }

    function translateString() : string
    {
        $gemini_api_keys = array_map('trim', explode(',', getenv('GEMINI_LLM_KEYS') ?: ''));
        $gemini_model = "gemini-flash-lite-latest";
        $system_prompt = $this->getSystemPrompt(); 

        //$value = str_replace(array("\r", "\n"), '', $this->value);
        $value = htmlspecialchars($this->value);
        $data = [
            'systemInstruction' => [ 
                'parts' => [
                    [
                        'text' => $system_prompt
                    ]
                ]
            ],
            'contents' => [
                [
                    'parts' => [ 
                        [
                            'text' => $value
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.6,
                'thinkingConfig' => [
                    'thinkingBudget' => -1,
                ],
            ],
        ];
        $MAX_COUNT = 10;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $key = array_rand($gemini_api_keys);
            $gemini_api_key = $gemini_api_keys[$key];
            $url = "https://generativelanguage.googleapis.com/v1beta/models/$gemini_model:generateContent?key=$gemini_api_key";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // レスポンスを文字列として取得
            curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ]);
            curl_setopt($ch, CURLOPT_POST, true); // POSTリクエストを指定
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // 送信するデータを設定
            $response = curl_exec($ch);
            if($response === false) {
                $sleep_time = ($i + 1) * 8;
                sleep($sleep_time);
                continue;
            }
            //var_dump($response);
            $response_data = json_decode($response, true);
            $generated_text = $response_data['candidates'][0]['content']['parts'][0]['text'];
            curl_close($ch);
            $cleaned_text = preg_replace('/^```html\s*/', '', trim($generated_text));
            $cleaned_text = preg_replace('/```$/', '', $cleaned_text);
            return htmlspecialchars_decode($cleaned_text);
        }
        return "";
    }
}

