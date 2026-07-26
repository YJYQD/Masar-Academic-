<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/db.php';

// Protect page: only allow admin users
if (empty($_SESSION['is_admin']) || empty($_SESSION['admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>محظور</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:24px;direction:rtl;text-align:right;"><h2>غير مصرح</h2><p>هذه الصفحة مخصصة لمسؤولي النظام فقط.</p></body></html>';
    exit();
}

$pageTitle = 'التقرير الذكي للـ AI';
include __DIR__ . '/inc/header.php';
?>

<style>
    .ai-report-shell {
        padding: 3rem 0 5rem;
        text-align: center;
    }
    .ai-report-card {
        background: #fff;
        border-radius: 20px;
        padding: 3rem 2rem;
        box-shadow: 0 10px 35px rgba(0,0,0,.08);
        border: 1px solid rgba(15, 76, 129, .12);
        max-width: 600px;
        margin: 0 auto;
    }
    .ai-report-note {
        background: linear-gradient(135deg, #f6fbff 0%, #eef7ff 100%);
        border: 1px solid #d5ebff;
        border-radius: 14px;
        padding: 1.2rem;
        margin-bottom: 2rem;
        color: #20567a;
        font-size: 1.1rem;
    }
    .btn-launch-ai {
        background: linear-gradient(135deg, #0f4c81 0%, #1d7bb6 100%);
        color: #fff;
        border: none;
        padding: 12px 35px;
        font-size: 1.2rem;
        font-weight: bold;
        border-radius: 50px;
        box-shadow: 0 5px 20px rgba(15, 76, 129, 0.4);
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
        margin-top: 1rem;
    }
    .btn-launch-ai:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(15, 76, 129, 0.5);
        color: #fff;
    }
    .ai-icon {
        font-size: 3.5rem;
        color: #0f4c81;
        margin-bottom: 1rem;
    }
</style>

<div class="container ai-report-shell">
    <div class="ai-report-card">
        <div class="ai-icon">📊🤖</div>
        <div class="ai-report-note">
            <strong>مرحباً بك في نظام التحليل الذكي المعزز بالـ AI</strong>
            <div style="margin-top: 0.5rem; font-size: 0.95rem;">تم تجهيز الرسوم البيانية وإحصاءات رضا الطلاب وتوزيع فئات التميز لأعضاء هيئة التدريس بنجاح.</div>
        </div>
        
        <p style="color: #666; margin-bottom: 1.5rem;">انقر فوق الزر أدناه لفتح لوحة التحليلات التفاعلية المباشرة بكامل الشاشة وبشكل آمن ومستقر 100%:</p>
        
        <!-- التوجيه المباشر لبورت بايثون الخارجي ببروتوكول HTTP العادي لكسر حظر الكوكيز والتوجيه تماماً -->
        <a href="http://46.101.123.84:8501" target="_blank" class="btn-launch-ai">
            إطلاق لوحة التحليلات الحية 🚀
        </a>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>