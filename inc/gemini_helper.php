<?php

if (!function_exists('build_gemini_prompt')) {
    function build_gemini_prompt(array $payload): string
    {
        $department = trim((string) ($payload['department'] ?? ''));
        $skill = trim((string) ($payload['skill'] ?? ''));
        $currentLevel = trim((string) ($payload['current_level'] ?? ''));
        $goal = trim((string) ($payload['goal'] ?? ''));
        $timeAvailable = trim((string) ($payload['time_available'] ?? ''));
        $context = trim((string) ($payload['context_text'] ?? ''));

        return "أنت مرشد أكاديمي ذكي لمنصة جامعة جازان. أعط توصية أكاديمية قصيرة ومحددة من 3 خطوات فقط بصياغة عربية واضحة.\n" .
            "القسم: {$department}\n" .
            "المهارة أو المادة: {$skill}\n" .
            "المستوى الحالي: {$currentLevel}\n" .
            "الهدف: {$goal}\n" .
            "الوقت المتاح: {$timeAvailable}\n" .
            "السياق المحلي: {$context}\n\n" .
            "الرجاء استخدام الصيغة التالية:\n" .
            "1. الأساسيات: ...\n" .
            "2. التطبيق العملي: ...\n" .
            "3. مشروع مصغر: ...\n" .
            "اجعل الإجابة عملية ومناسبة لسوق العمل.";
    }
}

if (!function_exists('request_gemini_recommendation')) {
    function request_gemini_recommendation(array $payload): array
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '';
        $prompt = build_gemini_prompt($payload);

        if ($apiKey !== '') {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode($apiKey);
            $body = [
                'contents' => [[
                    'parts' => [[
                        'text' => $prompt,
                    ]],
                ]],
                'system_instruction' => [
                    'parts' => [[
                        'text' => 'أنت مرشد أكاديمي ذكي لمنصة جامعة جازان التعليمية. قدم توصية أكاديمية قصيرة من 3 خطوات فقط بصياغة عربية واضحة ومختصرة.',
                    ]],
                ],
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode < 400) {
                $data = json_decode($response, true);
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text !== '') {
                    return [
                        'recommendation' => trim($text),
                        'source' => 'gemini',
                    ];
                }
            }
        }

        return [
            'recommendation' => "1. الأساسيات: ابدأ بمراجعة المصادر الأساسية في {$payload['skill']} وابدأ بأساسيات القسم.\n2. التطبيق العملي: حل تمارين عملية على منصة مثل HackerRank أو Coursera.\n3. مشروع مصغر: أنشئ مشروعاً بسيطاً يربط بين المفهوم والهدف الأكاديمي.",
            'source' => 'fallback',
        ];
    }
}
