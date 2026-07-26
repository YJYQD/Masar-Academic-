<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '');
}

if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash');
}

function build_gemini_question_prompt(string $question): string
{
    $safeQuestion = trim($question);
    if ($safeQuestion === '') {
        $safeQuestion = 'أريد مساعدة أكاديمية عامة.';
    }

    return "أنت مساعد أكاديمي ذكي لمنصة جامعة جازان. أجب بالعربية بصياغة واضحة ومختصرة، وكن دقيقاً ومهنيّاً.\n" .
        "السؤال: {$safeQuestion}\n\n" .
        "إذا لم تتوفر معلومات كافية، فقل ذلك بوضوح وقدم إرشاداً عاماً مناسباً للسياق الأكاديمي في الجامعة.";
}

function build_gemini_learning_prompt(array $payload): string
{
    $department = trim((string) ($payload['department'] ?? ''));
    $skill = trim((string) ($payload['skill'] ?? ''));
    $currentLevel = trim((string) ($payload['current_level'] ?? ''));
    $goal = trim((string) ($payload['goal'] ?? ''));
    $timeAvailable = trim((string) ($payload['time_available'] ?? ''));
    $contextText = trim((string) ($payload['context_text'] ?? ''));

    return "أنت مرشد أكاديمي ذكي لمنصة جامعة جازان. أعط توصية أكاديمية قصيرة ومحددة من 3 خطوات فقط بصياغة عربية واضحة.\n" .
        "القسم: {$department}\n" .
        "المهارة أو المادة: {$skill}\n" .
        "المستوى الحالي: {$currentLevel}\n" .
        "الهدف: {$goal}\n" .
        "الوقت المتاح: {$timeAvailable}\n" .
        "السياق المحلي: {$contextText}\n\n" .
        "الرجاء استخدام الصيغة التالية:\n" .
        "1. الأساسيات: ...\n" .
        "2. التطبيق العملي: ...\n" .
        "3. مشروع مصغر: ...\n" .
        "اجعل الإجابة عملية ومناسبة لسوق العمل.";
}

function call_gemini_api(string $prompt, string $model = ''): array
{
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $modelName = $model !== '' ? $model : (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash');

    if ($apiKey === '') {
        return [
            'answer' => '',
            'source' => 'missing_key',
            'model' => $modelName,
        ];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelName) . ':generateContent?key=' . rawurlencode($apiKey);
    $body = [
        'contents' => [[
            'role' => 'user',
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
        'generationConfig' => [
            'temperature' => 0.2,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 512,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false || $httpCode >= 400) {
        return [
            'answer' => '',
            'source' => 'error',
            'model' => $modelName,
        ];
    }

    $decoded = json_decode($responseBody, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = trim((string) $text);

    if ($text === '') {
        return [
            'answer' => '',
            'source' => 'empty',
            'model' => $modelName,
        ];
    }

    return [
        'answer' => $text,
        'source' => 'gemini',
        'model' => $modelName,
    ];
}

function build_fallback_learning_recommendation(array $payload): string
{
    $skill = trim((string) ($payload['skill'] ?? 'المادة'));
    return "1. الأساسيات: ابدأ بمراجعة المصادر الأساسية في {$skill} وركز على المفاهيم الأساسية.\n2. التطبيق العملي: حل تمارين عملية أو مشاريع صغيرة على منصة تعليمية معتمدة.\n3. مشروع مصغر: أنشئ مشروعاً بسيطاً يربط بين المفهوم والأهداف الأكاديمية التي حددتها.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $question = trim((string) ($_POST['question'] ?? ''));
    if ($question !== '') {
        $prompt = build_gemini_question_prompt($question);
        $result = call_gemini_api($prompt);
        $answer = trim((string) ($result['answer'] ?? ''));
        if ($answer === '') {
            $answer = 'أشكرك على سؤالك. هذه تفاصيل عامة ومناسبة لسياق منصة جامعة جازان، وإذا أردت يمكنني مساعدتك بشكل أدق عند توضيح الموضوع أو المادة.';
        }

        echo json_encode([
            'success' => true,
            'answer' => $answer,
            'recommendation' => $answer,
            'source' => $result['source'] ?? 'fallback',
            'model' => $result['model'] ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash'),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $payload = [
        'department' => trim((string) ($_POST['department'] ?? '')),
        'skill' => trim((string) ($_POST['skill'] ?? '')),
        'current_level' => trim((string) ($_POST['current_level'] ?? '')),
        'goal' => trim((string) ($_POST['goal'] ?? '')),
        'time_available' => trim((string) ($_POST['time_available'] ?? '')),
        'context_text' => trim((string) ($_POST['context_text'] ?? '')),
    ];

    $prompt = build_gemini_learning_prompt($payload);
    $result = call_gemini_api($prompt);
    $recommendation = trim((string) ($result['answer'] ?? ''));
    if ($recommendation === '') {
        $recommendation = build_fallback_learning_recommendation($payload);
    }

    echo json_encode([
        'success' => true,
        'recommendation' => $recommendation,
        'answer' => $recommendation,
        'source' => $result['source'] ?? 'fallback',
        'model' => $result['model'] ?? (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash'),
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>مساعد التعلم الذكي</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="panel">
        <h2>مساعد التعلم الذكي</h2>
        <p>أدخل متطلباتك الأكاديمية الخمسة وسيتولى المساعد صياغة خطة تعلم قصيرة من 3 خطوات فقط.</p>
        <form id="learning-form" method="post" class="form-card">
            <div class="form-grid--2">
                <label>القسم
                    <input type="text" name="department" required placeholder="مثال: علوم الحاسب">
                </label>
                <label>المهارة أو المادة
                    <input type="text" name="skill" required placeholder="مثال: برمجة بايثون">
                </label>
            </div>
            <div class="form-grid--2">
                <label>المستوى الحالي
                    <input type="text" name="current_level" required placeholder="مثال: مبتدئ">
                </label>
                <label>الهدف
                    <input type="text" name="goal" required placeholder="مثال: التوظيف في تطوير الويب">
                </label>
            </div>
            <label>الوقت المتاح
                <input type="text" name="time_available" required placeholder="مثال: 3 أسابيع">
            </label>
            <label>سياق محلي أو ملاحظات
                <textarea name="context_text" rows="3" placeholder="مثال: تتوفر لوائح الجامعة وأنظمة الدراسة..."></textarea>
            </label>
            <button type="submit" class="btn btn--accent">إنشاء الخطة</button>
        </form>
        <div id="learning-result" style="margin-top:16px;"></div>
    </section>
</main>
<script>
(function(){
    const form = document.getElementById('learning-form');
    const result = document.getElementById('learning-result');
    function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const formData = new FormData(form);
        result.innerHTML = '<p>جارٍ توليد الخطة...</p>';
        fetch('ai_learning_helper.php', {
            method: 'POST',
            body: formData
        }).then(function (response) { return response.json(); }).then(function (data) {
            const text = data.recommendation || data.answer || 'تعذر توليد الخطة حالياً.';
            result.innerHTML = '<div class="doctor-card"><pre style="white-space:pre-wrap;">' + escapeHtml(text) + '</pre></div>';
        }).catch(function () {
            result.innerHTML = '<p class="empty">تعذر الاتصال بالخدمة الآن.</p>';
        });
    });
})();
</script>
</body>
</html>
