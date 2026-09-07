<?php

class TranslateJapaneseOpenRouter extends AbstractTranslateJapanese
{
    /**
     * OpenRouter APIを使用して文字列を翻訳します。
     */
    public function translateString() : string
    {
        // 環境変数からAPIキーを取得。複数ある場合はカンマ区切りに対応。
        $keys = getenv('OPENROUTER_API_KEYS') ?: getenv('OPENROUTER_API_KEY');
        if (!$keys) {
            return "";
        }
        $openrouter_api_keys = array_map('trim', explode(',', $keys));

        // 環境変数からモデル名を取得。ない場合はデフォルトのモデルを使用。
        $models = 'nvidia/nemotron-3.5-lightning:free';
        $openrouter_models = array_map('trim', explode(',', $models));
        
        $system_prompt = $this->getSystemPrompt(); 
        $value = htmlspecialchars($this->value);

        $MAX_COUNT = 2;
        for ($i = 0; $i < $MAX_COUNT; $i++) {
            $key_index = array_rand($openrouter_api_keys);
            $api_key = $openrouter_api_keys[$key_index];
            
            $model_index = array_rand($openrouter_models);
            $model = $openrouter_models[$model_index];

            $url = "https://openrouter.ai/api/v1/chat/completions";

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

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
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

            // ```html の囲みがあれば除去
            $cleaned_text = preg_replace('/^```html\s*/', '', trim($generated_text));
            $cleaned_text = preg_replace('/```$/', '', $cleaned_text);
            $cleaned_text .= "<p style='font-size:8px;'>model: ${model} (OpenRouter)</p>";
            return htmlspecialchars_decode($cleaned_text);
        }
        return "";
    }
}
