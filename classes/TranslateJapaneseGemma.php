<?php

class TranslateJapaneseGemma extends AbstractTranslateJapanese
{
    public function translateString() : string
    {
        $keys = getenv('GEMINI_API_KEYS');
        $gemini_api_keys = array_map('trim', explode(',', $keys ?: ''));

        //$gemma_model = "gemma-4-31b-it";
        $gemma_model = "gemma-4-26b-a4b-it";
        $system_prompt = $this->getSystemPrompt(); 
        $value = htmlspecialchars($this->value);

        $user_prompt = "# htmlコード\n```\n${value}\n```";

        $data =
        [
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
                            'text' => $user_prompt 
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.6,
                'thinkingConfig' => [
                    'thinkingLevel' => 'minimal'
                ]
            ]
        ];

        $MAX_COUNT = 2;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $key = array_rand($gemini_api_keys);
            $gemini_api_key = $gemini_api_keys[$key];
            $url = "https://generativelanguage.googleapis.com/v1beta/models/$gemma_model:generateContent?key=$gemini_api_key";
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
                echo "responce false\n";
                $sleep_time = ($i + 1) * 8;
                sleep($sleep_time);
                continue;
            }
            //var_dump($response);
            $response_data = json_decode($response, true);
            $generated_text = $response_data['candidates'][0]['content']['parts'][1]['text'];

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
            $cleaned_text .= "<p style='font-size:8px;'>model: ${gemma_model}</p>";
            return htmlspecialchars_decode($cleaned_text);
        }
        return "";
    }
}
