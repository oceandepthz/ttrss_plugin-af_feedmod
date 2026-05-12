<?php

class TranslateJapaneseOpenCodeZen
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
        $keys = getenv('OPENCODEZEN_API_KEYS');
        $opencodezen_api_keys = array_map('trim', explode(',', $keys ?: ''));

        //$model = 'minimax-m2.5-free';
        $model = 'deepseek-v4-flash-free';
        $system_prompt = $this->getSystemPrompt(); 
        $value = htmlspecialchars($this->value);

        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt
                ],
                [
                    'role' => 'user',
                    'content' => $value
                ]
            ],
            'temperature' => 0.6
        ];

        $MAX_COUNT = 2;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $key_index = array_rand($opencodezen_api_keys);
            $api_key = $opencodezen_api_keys[$key_index];
            $url = "https://opencode.ai/zen/v1/chat/completions";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [ 
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            
            $response = curl_exec($ch);
            if($response === false) {
                $sleep_time = ($i + 1) * 5;
                sleep($sleep_time);
                continue;
            }

            $response_data = json_decode($response, true);
            $generated_text = $response_data['choices'][0]['message']['content'] ?? null;
            curl_close($ch);

            if(!$generated_text)
            {
                $sleep_time = ($i + 1) * 8;
                sleep($sleep_time);
                continue;
            }

            $cleaned_text = preg_replace('/^```html\s*/', '', trim($generated_text));
            $cleaned_text = preg_replace('/```$/', '', $cleaned_text);
            $cleaned_text .= "<p style='font-size:8px;'>model: ${model} (OpenCodeZen)</p>";
            return htmlspecialchars_decode($cleaned_text);
        }
        return "";
    }
}
