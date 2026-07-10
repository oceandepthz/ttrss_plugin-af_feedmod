<?php

class TranslateJapaneseGemini extends AbstractTranslateJapanese
{
    public function translateString() : string
    {
        $keys = getenv('GEMINI_API_KEYS');
        $gemini_api_keys = array_map('trim', explode(',', $keys ?: ''));

        //$models = 'gemini-2.5-flash,gemini-2.5-flash-lite,gemini-3-flash-preview,gemini-3.1-flash-lite-preview,gemini-3.1-flash-lite-preview,gemini-3.1-flash-lite-preview';
        $models = 'gemini-3.1-flash-lite';
        $gemini_models = array_map('trim', explode(',', $models ?: ''));
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
        $MAX_COUNT = 2;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $key = array_rand($gemini_api_keys);
            $gemini_api_key = $gemini_api_keys[$key];
            $key = array_rand($gemini_models);
            $gemini_model = $gemini_models[$key];
            $url = "https://generativelanguage.googleapis.com/v1beta/models/$gemini_model:generateContent?key=$gemini_api_key";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // レスポンスを文字列として取得
            curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ]);
            curl_setopt($ch, CURLOPT_POST, true); // POSTリクエストを指定
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // 送信するデータを設定
            $response = curl_exec($ch);
            if($response === false) {
                //echo "responce false\n";
                $sleep_time = ($i + 1) * 5;
                sleep($sleep_time);
                continue;
            }
            //var_dump($response);
            $response_data = json_decode($response, true);
            $generated_text = $response_data['candidates'][0]['content']['parts'][0]['text'];
            curl_close($ch);
            if(!$generated_text)
            {
                //echo "empty text\n";
                $sleep_time = ($i + 1) * 8;
                sleep($sleep_time);
                continue;
            }
            $cleaned_text = preg_replace('/^```html\s*/', '', trim($generated_text));
            $cleaned_text = preg_replace('/```$/', '', $cleaned_text);
            $cleaned_text .= "<p style='font-size:8px;'>model: ${gemini_model}</p>";
            return htmlspecialchars_decode($cleaned_text);
        }
        return "";
    }
}
