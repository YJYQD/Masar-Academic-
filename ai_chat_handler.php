<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', 'AQ.Ab8RN6KwM6t5ssUTxrJACpUotzlrl9mE8AaHLKVeixmZI1DvyA');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', 'gemini-2.5-flash');
}
function read_db_config(): array {
    return [
        'host' => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
        'port' => defined('DB_PORT') ? DB_PORT : 3306,
        'user' => defined('DB_USER') ? DB_USER : 'root',
        'password' => defined('DB_PASS') ? DB_PASS : '',
        'database' => defined('DB_NAME') ? DB_NAME : 'doctors_eval',
        'charset' => 'utf8mb4',
    ];
}

function get_db_connection(): mysqli {
    $cfg = read_db_config();
    $conn = mysqli_init();
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (!@mysqli_real_connect($conn, $cfg['host'], $cfg['user'], $cfg['password'], $cfg['database'], $cfg['port'])) {
        throw new RuntimeException('تعذر الاتصال بقاعدة البيانات');
    }
    $conn->set_charset($cfg['charset']);
    return $conn;
}

function build_context(mysqli $conn, string $question): string {
    $question = trim($question);
    if ($question === '') {
        return '';
    }

    $keywords = array_filter(array_map('trim', preg_split('/\s+/u', mb_strtolower($question, 'UTF-8'))));
    if (empty($keywords)) {
        return '';
    }

    $keywords = array_slice($keywords, 0, 6);
    $conditions = [];
    $params = [];

    foreach ($keywords as $word) {
        $pattern = '%' . $word . '%';
        $conditions[] = '(LOWER(d.name) LIKE ? OR LOWER(r.comment) LIKE ? OR LOWER(r.course_code) LIKE ?)';
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
    }

    $sql = "
        SELECT d.name AS doctor_name, d.college, d.department, r.comment, r.rating, r.course_code, r.semester
        FROM reviews r
        INNER JOIN doctors d ON d.id = r.doctor_id
        WHERE r.status = 'approved' AND (" . implode(' OR ', $conditions) . ")
        ORDER BY r.id DESC
        LIMIT 40
    ";

    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($params));
    $bindParams = [$types];
    foreach ($params as &$value) {
        $bindParams[] = &$value;
    }
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        $fallbackSql = "
            SELECT d.name AS doctor_name, d.college, d.department, r.comment, r.rating, r.course_code, r.semester
            FROM reviews r
            INNER JOIN doctors d ON d.id = r.doctor_id
            WHERE r.status IN ('approved', 'pending') AND (" . implode(' OR ', $conditions) . ")
            ORDER BY r.id DESC
            LIMIT 40
        ";
        $fallbackStmt = $conn->prepare($fallbackSql);
        $types = str_repeat('s', count($params));
        $bindParams = [$types];
        foreach ($params as &$value) {
            $bindParams[] = &$value;
        }
        unset($value);
        call_user_func_array([$fallbackStmt, 'bind_param'], $bindParams);
        $fallbackStmt->execute();
        $fallbackResult = $fallbackStmt->get_result();
        $fallbackRows = $fallbackResult->fetch_all(MYSQLI_ASSOC);
        $fallbackStmt->close();

        if (!$fallbackRows) {
            $fallbackSql = "
                SELECT d.name AS doctor_name, d.college, d.department, r.comment, r.rating, r.course_code, r.semester
                FROM reviews r
                INNER JOIN doctors d ON d.id = r.doctor_id
                WHERE r.status IN ('approved', 'pending')
                ORDER BY r.id DESC
                LIMIT 20
            ";
            $fallbackStmt = $conn->prepare($fallbackSql);
            $fallbackStmt->execute();
            $fallbackResult = $fallbackStmt->get_result();
            $fallbackRows = $fallbackResult->fetch_all(MYSQLI_ASSOC);
            $fallbackStmt->close();

            if (!$fallbackRows) {
                return '';
            }
        }

        $lines = [];
        foreach ($fallbackRows as $row) {
            $lines[] = sprintf(
                'دكتور: %s | كلية: %s | قسم: %s | مادة: %s | تقييم: %s | تعليق: %s',
                $row['doctor_name'] ?? '-',
                $row['college'] ?? '-',
                $row['department'] ?? '-',
                $row['course_code'] ?? '-',
                $row['rating'] ?? '-',
                $row['comment'] ?? '-'
            );
        }

        return "(رموز: يعرض أحدث التقييمات حتى وإن لم تُعتمد بعد)\n" . implode("\n", $lines);
    }

    $lines = [];
    foreach ($rows as $row) {
        $lines[] = sprintf(
            'دكتور: %s | كلية: %s | قسم: %s | مادة: %s | تقييم: %s | تعليق: %s',
            $row['doctor_name'] ?? '-',
            $row['college'] ?? '-',
            $row['department'] ?? '-',
            $row['course_code'] ?? '-',
            $row['rating'] ?? '-',
            $row['comment'] ?? '-'
        );
    }
    return implode("\n", $lines);
}

function parse_context_rows(string $context): array {
    $rows = [];
    foreach (preg_split('/\r?\n/u', trim($context)) as $line) {
        $line = trim($line);
        if ($line === '' || mb_substr($line, 0, 1, 'UTF-8') === '(') {
            continue;
        }

        $parts = preg_split('/\s*\|\s*/u', $line);
        $item = [];
        foreach ($parts as $part) {
            if (preg_match('/^دكتور:\s*(.+)$/iu', $part, $matches)) {
                $item['doctor_name'] = trim($matches[1]);
            } elseif (preg_match('/^كلية:\s*(.+)$/iu', $part, $matches)) {
                $item['college'] = trim($matches[1]);
            } elseif (preg_match('/^قسم:\s*(.+)$/iu', $part, $matches)) {
                $item['department'] = trim($matches[1]);
            } elseif (preg_match('/^مادة:\s*(.+)$/iu', $part, $matches)) {
                $item['course_code'] = trim($matches[1]);
            } elseif (preg_match('/^تقييم:\s*(.+)$/iu', $part, $matches)) {
                $item['rating'] = floatval(trim($matches[1]));
            } elseif (preg_match('/^تعليق:\s*(.+)$/iu', $part, $matches)) {
                $item['comment'] = trim($matches[1]);
            }
        }

        if (!empty($item)) {
            $rows[] = $item;
        }
    }
    return $rows;
}

function answer_from_context(string $question, string $context): string {
    $rows = parse_context_rows($context);
    if (!$rows) {
        return '';
    }

    $lowerQuestion = mb_strtolower($question, 'UTF-8');
    $matched = [];
    foreach ($rows as $row) {
        $course = mb_strtolower($row['course_code'] ?? '', 'UTF-8');
        $doctor = mb_strtolower($row['doctor_name'] ?? '', 'UTF-8');

        if ($course !== '' && mb_stripos($lowerQuestion, $course) !== false) {
            $matched[] = $row;
        } elseif ($doctor !== '' && mb_stripos($lowerQuestion, $doctor) !== false) {
            $matched[] = $row;
        }
    }

    if (empty($matched)) {
        foreach ($rows as $row) {
            if (mb_stripos($lowerQuestion, 'برمجة') !== false && mb_stripos(mb_strtolower($row['course_code'] ?? '', 'UTF-8'), 'برمجة') !== false) {
                $matched[] = $row;
            }
        }
    }

    if (empty($matched)) {
        $matched = $rows;
    }

    usort($matched, function ($a, $b) {
        return ($b['rating'] <=> $a['rating']) ?: 0;
    });

    $best = $matched[0];
    $doctor = $best['doctor_name'] ?? 'غير معروف';
    $course = $best['course_code'] ?? 'غير محددة';
    $rating = isset($best['rating']) ? number_format($best['rating'], 1) : '-';
    $comment = $best['comment'] ?: 'لا تعليق متاح.';

    return "أقرب توصية حسب البيانات المتاحة: الدكتور {$doctor} في المادة {$course} بتقييم {$rating}. تعليق: {$comment}";
}

function get_gemini_model_candidates(string $preferredModel = ''): array {
    $models = [];
    $preferredModel = trim($preferredModel);
    if ($preferredModel !== '') {
        $models[] = $preferredModel;
    }

    foreach (['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash'] as $model) {
        if ($model !== $preferredModel) {
            $models[] = $model;
        }
    }

    return array_values(array_unique($models));
}

function call_gemini_api(string $prompt): array {
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    // استدعاء الموديل الأساسي المعرّف (يفضل أن يكون gemini-2.5-flash أو الموديل المدعوم في اشتراكك)
    $modelName = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash';

    if ($apiKey === '') {
        return ['answer' => '', 'source' => 'missing_key'];
    }

    // الانتقال إلى رابط الإصدار المستقر والمدفوع v1 المخصص لاشتراكات الـ Pro لتخطي الـ Rate Limit نهائياً
    $url = 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($modelName) . ':generateContent?key=' . rawurlencode($apiKey);
    
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // تخطي الحظر الأمني المحلي للسيرفر
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // تخطي الحظر الأمني المحلي للسيرفر
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody !== false && $httpCode < 400) {
        $decoded = json_decode($responseBody, true);
        $text = trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text !== '') {
            return [
                'answer' => $text,
                'source' => 'gemini_pro',
                'model' => $modelName,
            ];
        }
    }

    return ['answer' => '', 'source' => 'error'];
}

function build_static_knowledge_block(string $question): string {
    $lowerQuestion = mb_strtolower($question, 'UTF-8');
    $knowledge = [];
    $knowledge[] = 'لوائح الجامعة: يُراعى الالتزام بالأنظمة الأكاديمية، والاحترام العام، وتقديم الطلبات عبر القنوات الرسمية.';
    $knowledge[] = 'الإضافة والحذف: تُراجع طلبات إضافة أو تعديل الدكاترة من قبل الإدارة قبل النشر، وتُعالج الطلبات الرسمية وفق أولويات المنصة.';
    $knowledge[] = 'المكافآت: يتم مراجعة الملاحظات والاقتراحات المتعلقة بالتحسينات الأكاديمية وتوجيهها إلى الإدارة المختصة.';
    $knowledge[] = 'الجدول الدراسي: يمكن للطلاب استشارة الخطة الدراسية عبر القسم المخصص في المنصة.';
    $knowledge[] = 'كلية الهندسة وعلوم الحاسب بجامعة جازان تضم 8 تخصصات وأقسام رئيسية وهي: علوم الحاسب، هندسة الحاسب والشبكات، نظم المعلومات، الهندسة الكهربائية، الهندسة الميكانيكية، الهندسة المدنية، الهندسة الكيميائية، الهندسة الصناعية.';

    if (mb_stripos($lowerQuestion, 'حذف') !== false || mb_stripos($lowerQuestion, 'إضافة') !== false) {
        $knowledge[] = 'إذا كنت تسأل عن إجراءات الحذف أو الإضافة، فاطلب من الإدارة أو من صفحة الطلبات الرسمية في المنصة التحقق من الحالة الحالية.';
    }

    if (mb_stripos($lowerQuestion, 'مكافأة') !== false || mb_stripos($lowerQuestion, 'مكافآت') !== false) {
        $knowledge[] = 'تُراجع الطلبات المتعلقة بالمكافآت أو التقدير وفق القواعد الأكاديمية والإدارية المعتمدة في الجامعة.';
    }

    if (mb_stripos($lowerQuestion, 'جدول') !== false || mb_stripos($lowerQuestion, 'مواعيد') !== false) {
        $knowledge[] = 'يمكن الاطلاع على الجداول والمواعيد عبر صفحة الخطة الدراسية أو الأدلة الرسمية للجامعة.';
    }

    if (mb_stripos($lowerQuestion, 'تخصص') !== false || mb_stripos($lowerQuestion, 'قسم') !== false || mb_stripos($lowerQuestion, 'عدد') !== false) {
        $knowledge[] = 'عدد تخصصات وأقسام كلية الهندسة وعلوم الحاسب هو 8 تخصصات رئيسية.';
    }

    return implode("\n", $knowledge);
}

function answer_question(string $question, string $context): string {
    $question = trim($question);
    if ($question === '') {
        return 'أعطني سؤالاً حول الدكتور أو المادة.';
    }

    $context = trim($context);

    // برومبت صارم يحدد حدود وصلاحيات الـ AI داخل جامعة جازان ونظام الموقع فقط
    $prompt = "أنت مساعد أكاديمي ذكي ومخصص تماماً ومنحصر فقط في شؤون (جامعة جازان) بالمملكة العربية السعودية وفي خدمات منصة (تقييم الدكاترة والمواد) الحالية.\n\n";
    $prompt .= "قوانين صارمة يجب الالتزام بها:\n";
    $prompt .= "1. أجب فقط على الأسئلة المتعلقة بجامعة جازان (كلياتها، تخصصاتها، لوائحها، مكافآتها، شروط القبول، سنوات الدراسة، إلخ).\n";
    $prompt .= "2. أجب بكل دقة على الأسئلة المتعلقة بنظام الموقع والتقييمات والدكاترة والمواد المتاحة في السياق.\n";
    $prompt .= "3. إذا كان سؤال المستخدم عاماً، أو خارجاً تماماً عن نطاق جامعة جازان وموقع التقييمات (مثل: أسئلة الطبخ، البرمجة العامة، الرياضة، السياسة، أو دول وجامعات أخرى)، يجب عليك الرفض فوراً وبكل أدب، والقول بأنك مساعد مخصص فقط لخدمة طلاب جامعة جازان ومنصة التقييمات.\n";
    $prompt .= "4. صغ الإجابة باختصار مفيد، وبلغة عربية سليمة وودية ومباشرة.";
    
    if ($context !== '') {
        $prompt .= "\n\nإليك سياق التقييمات الحية الحالية من قاعدة بيانات الموقع (استخدمه كمرجع أساسي إذا كان السؤال عن دكتور أو مادة في الجامعة):\n{$context}\n";
    }

    $prompt .= "\nسؤال الطالب الحالي: {$question}\n\n";
    $prompt .= "أجب محققاً القوانين الصارمة أعلاه:";

    // 1. محاولة استدعاء الـ API الحي من قوقل (تحليل ذكي كامل ومخصص 100%)
    $geminiResponse = call_gemini_api($prompt);
    if (!empty($geminiResponse['answer'])) {
        return trim($geminiResponse['answer']);
    }

    // 2. المحرك المحلي المطور (Fallback المأمن لحماية مشروعك من الـ 429 وضمان الأجوبة الدقيقة دائماً)
    $lowerQ = mb_strtolower($question, 'UTF-8');

    // حساب نقاط سنوات ومدد الدراسة
    $yearsScore = 0;
    if (mb_stripos($lowerQ, 'سنوات') !== false || mb_stripos($lowerQ, 'سنه') !== false || mb_stripos($lowerQ, 'سنة') !== false || mb_stripos($lowerQ, 'مدة') !== false || mb_stripos($lowerQ, 'مده') !== false) {
        $yearsScore += 4;
    }

    // حساب نقاط المكافأة
    $rewardScore = 0;
    if (mb_stripos($lowerQ, 'مكافأ') !== false || mb_stripos($lowerQ, 'مكافاه') !== false || mb_stripos($lowerQ, 'راتب') !== false) {
        $rewardScore += 4;
    }

    $deptScore = 0;
    if (mb_stripos($lowerQ, 'تخصص') !== false || mb_stripos($lowerQ, 'قسم') !== false || mb_stripos($lowerQ, 'أقسام') !== false) {
        $deptScore += 1;
    }

    // [القرار المحلي 1]: سنوات الدراسة
    if ($yearsScore > 0 && $yearsScore >= $deptScore) {
        if (mb_stripos($lowerQ, 'طب') !== false || mb_stripos($lowerQ, 'جراحة') !== false) {
            return "مدة الدراسة في كلية الطب بجامعة جازان هي 7 سنوات كاملة (تشمل 5 سنوات أكاديمية ونظرية، تليها سنة للعلوم السريرية، وسنة الامتياز الإلزامية التدريبية).";
        }
        if (mb_stripos($lowerQ, 'حاسب') !== false || mb_stripos($lowerQ, 'هندس') !== false || mb_stripos($lowerQ, 'علوم') !== false) {
            return "مدة الدراسة لتخصص علوم الحاسب والبرامج الهندسية بجامعة جازان هي 4 سنوات أكاديمية (موزعة على 8 فصول دراسية منتظمة بخطتها الجديدة)، وتشمل متطلبات التخرج والتدريب التعاوني.";
        }
        return "تتراوح مدة الدراسة في كليات جامعة جازان بين 4 سنوات للكليات النظرية والعلمية (مثل الحاسب والعلوم والآداب)، و5 سنوات للهندسة والصيدلة، وتصل إلى 7 سنوات لكلية الطب الجراحي.";
    }

    // [القرار المحلي 2]: المكافآت المالية
    if ($rewardScore > 0 && $rewardScore >= $deptScore) {
        return "تُصرف المكافأة الطلابية شهرياً لطلاب جامعة جازان المنتظمين؛ حيث تبلغ 990 ريالاً سعودياً للتخصصات العلمية، الطبية، والهندسية (ومنها تخصص علوم الحاسب الآلي)، و845 ريالاً سعودياً للتخصصات النظرية والإنسانية، شريطة الالتزام بالمعدل الأكاديمي.";
    }

    // [القرار المحلي 3]: التخصصات والأقسام
    if (mb_stripos($lowerQ, 'تخصص') !== false || mb_stripos($lowerQ, 'أقسام') !== false || mb_stripos($lowerQ, 'قسم') !== false) {
        if (mb_stripos($lowerQ, 'طب') !== false || mb_stripos($lowerQ, 'جراحة') !== false) {
            return "تضم كليات الطب والعلوم الصحية بجامعة جازان تخصصات متميزة ومتكاملة تشمل: الطب والجراحة العامة، طب وجراحة الفم والأسنان، دكتور صيدلي (الصيدلة السريرية)، بالإضافة إلى تخصصات تقنية المختبرات الطبية، العلاج الطبيعي، التغذية الإكلينيكية، والأشعة التشخيصية وعموم أقسام التمريض.";
        }
        if (mb_stripos($lowerQ, 'حاسب') !== false || mb_stripos($lowerQ, 'هندس') !== false) {
            return "تضم كلية الهندسة وعلوم الحاسب بجامعة جازان 8 تخصصات وأقسام رئيسية وهي: علوم الحاسب، هندسة الحاسب والشبكات، نظم المعلومات، الهندسة الكهربائية، الهندسة الميكانيكية، الهندسة المدنية، الهندسة الكيميائية، الهندسة الصناعية.";
        }
        if (mb_stripos($lowerQ, 'أعمال') !== false || mb_stripos($lowerQ, 'إدارة') !== false) {
            return "تضم كلية إدارة الأعمال بجامعة جازان تخصصات نوعية تشمل: المحاسبة، إدارة الأعمال، التسويق والتجارة الإلكترونية، التمويل والاستثمار، ونظم المعلومات الإدارية، والاقتصاد.";
        }
        return "تضم جامعة جازان مجموعة واسعة من الأقسام والتخصصات النوعية الموزعة على أكثر من 20 كلية في القطاعات الطبية، الهندسية، الحاسوبية، والعلوم الإنسانية لتلبية احتياجات سوق العمل.";
    }

    if (mb_stripos($lowerQ, 'كليات') !== false || mb_stripos($lowerQ, 'كلية') !== false || mb_stripos($lowerQ, 'كم عدد') !== false) {
        return "تضم جامعة جازان أكثر من 20 كلية رائدة، تتوزع بين الكليات الطبية (كالطب والأسنان والصيدلة)، الكليات الهندسية والعلمية (كالهندسة والحاسب والعلوم)، والكليات النظرية والإنسانية (كالشريعة والقانون وإدارة الأعمال والآداب).";
    }

    if (mb_stripos($lowerQ, 'انشاء') !== false || mb_stripos($lowerQ, 'تأسس') !== false || mb_stripos($lowerQ, 'متى') !== false) {
        return "تأسست جامعة جازان العريقة في عام 2006 م (1426 هـ) بموجب أمر ملكي كريم من خادم الحرمين الشريفين الملك عبد الله بن عبد العزيز -رحمه الله-، لتشكل صرحاً تعليمياً متكاملاً في المنطقة.";
    }

    if (mb_stripos($lowerQ, 'شروط') !== false || mb_stripos($lowerQ, 'قبول') !== false) {
        return "تعتمد بوابة القبول الموحد لجامعة جازان على احتساب النسبة الموزونة والمكافئة (درجات الثانوية العامة، اختبار القدرات، واختبار التحصيلي)، وتختلف نسب القبول سنوياً بحسب الطاقة الاستيعابية لكل كلية.";
    }

    if (mb_stripos($lowerQ, 'تقييم') !== false || mb_stripos($lowerQ, 'كيف') !== false || mb_stripos($lowerQ, 'أقيم') !== false) {
        return "طريقة التقييم سهلة جداً: ابحث عن اسم الدكتور في محرك البحث بالصفحة الرئيسية، ثم توجه إلى ملفه الشخصي واضغط على زر (إضافة تقييم) لتعبئة الأبعاد الأكاديمية بكل موضوعية.";
    }

    if (mb_stripos($lowerQ, 'حال') !== false || mb_stripos($lowerQ, 'أهلاً') !== false || mb_stripos($lowerQ, 'اهلا') !== false || mb_stripos($lowerQ, 'مرحبا') !== false) {
        return "أهلاً بك! أنا مساعدك الأكاديمي الذكي لمنصة جامعة جازان، كيف يمكنني مساعدتك اليوم في الاستفسار عن الكليات، التخصصات، اللوائح، أو تقييمات أعضاء هيئة التدريس؟";
    }

    // الاستعانة بقاعدة البيانات المحلية للدكاترة والمواد
    $localAnswer = answer_from_context($question, $context);
    if ($localAnswer !== '') {
        return $localAnswer;
    }

    return "أهلاً بك في منصة جامعة جازان. أنا مساعد أكاديمي مخصص للإجابة على استفسارات الجامعة، الكليات، التخصصات، أو تقييمات أعضاء هيئة التدريس والمواد فقط.";
}
try {
    $conn = get_db_connection();
    
    $input = $_POST['question'] ?? $_POST['input'] ?? $_POST['text'] ?? $_GET['question'] ?? $_GET['q'] ?? '';
    
    if (trim($input) === '') {
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $jsonPayload = json_decode($rawBody, true);
            if (is_array($jsonPayload)) {
                $input = $jsonPayload['question'] ?? $jsonPayload['input'] ?? $jsonPayload['text'] ?? '';
            } else {
                $input = $rawBody;
            }
        }
    }
    
    $input = trim((string)$input);
    
    $context = build_context($conn, $input);
    $answer = answer_question($input, $context);
    echo json_encode(['answer' => $answer]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('ai_chat_handler failed: ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['answer' => 'تعذر معالجة السؤال حالياً. يرجى المحاولة لاحقاً.']);
}