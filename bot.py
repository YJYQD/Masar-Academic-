import hashlib
import hmac
import html
import os
import re
import logging
import datetime
import json
import urllib.parse
import urllib.request
from pathlib import Path

try:
    from telegram import Update, ReplyKeyboardRemove, InlineKeyboardButton, InlineKeyboardMarkup
    from telegram.constants import ParseMode
    from telegram.ext import (
        ApplicationBuilder,
        ContextTypes,
        CommandHandler,
        MessageHandler,
        CallbackQueryHandler,
        ConversationHandler,
        filters,
    )
except ImportError:  # pragma: no cover - optional dependency in local test env
    Update = object
    ReplyKeyboardRemove = None

    class ParseMode:
        HTML = 'HTML'

    class _DummyFilters:
        TEXT = object()
        COMMAND = object()

        def __and__(self, other):
            return self

        def __rand__(self, other):
            return self

        def __invert__(self):
            return self

    class _DummyHandler:
        def __init__(self, *args, **kwargs):
            pass

    class _DummyApplicationBuilder:
        def token(self, token):
            return self

        def build(self):
            raise RuntimeError('python-telegram-bot is required to run the bot')

    class _DummyContextTypes:
        DEFAULT_TYPE = object

    class _DummyApplication:
        def add_handler(self, *args, **kwargs):
            pass

        def run_polling(self):
            raise RuntimeError('python-telegram-bot is required to run the bot')

    class _DummyCommandHandler(_DummyHandler):
        pass

    class _DummyMessageHandler(_DummyHandler):
        pass

    class _DummyCallbackQueryHandler(_DummyHandler):
        pass

    class _DummyConversationHandler(_DummyHandler):
        pass

    class _DummyInlineKeyboardButton:
        def __init__(self, text, callback_data=None):
            self.text = text
            self.callback_data = callback_data

    class _DummyInlineKeyboardMarkup:
        def __init__(self, inline_keyboard):
            self.inline_keyboard = inline_keyboard

    filters = _DummyFilters()
    ApplicationBuilder = _DummyApplicationBuilder
    ContextTypes = _DummyContextTypes
    CommandHandler = _DummyCommandHandler
    MessageHandler = _DummyMessageHandler
    CallbackQueryHandler = _DummyCallbackQueryHandler
    ConversationHandler = _DummyConversationHandler
    InlineKeyboardButton = _DummyInlineKeyboardButton
    InlineKeyboardMarkup = _DummyInlineKeyboardMarkup
    Application = _DummyApplication

try:
    import mysql.connector
except ImportError:  # pragma: no cover - optional dependency for tests
    mysql = None
else:
    mysql = mysql.connector

try:
    import bcrypt
except ImportError:  # pragma: no cover - optional dependency for tests
    bcrypt = None

MYSQL_ERROR = getattr(mysql, 'Error', Exception)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

BASE_DIR = Path(__file__).resolve().parent
ENV_PATH = BASE_DIR / '.env'
if ENV_PATH.exists():
    for line in ENV_PATH.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        value = value.strip().strip('"').strip("'")
        os.environ[key] = value

def build_database_config() -> dict:
    database_name = (os.getenv('DB_NAME') or 'doctor_rating').strip() or 'doctor_rating'
    password = os.getenv('DB_PASS') or os.getenv('DB_PASSWORD') or ''
    return {
        'host': os.getenv('DB_HOST', '127.0.0.1'),
        'port': int(os.getenv('DB_PORT', '3306')),
        'user': os.getenv('DB_USER', 'root'),
        'password': password,
        'database': database_name,
        'charset': 'utf8mb4',
    }


DATABASE_CONFIG = build_database_config()

if not DATABASE_CONFIG['password']:
    logger.warning('DB password not found in environment; bot will continue with empty password if your local MariaDB uses none.')

UNIVERSITY_EMAIL_PATTERN = re.compile(r'^[A-Za-z0-9._%+-]+@(?:stu\.)?jazanu\.edu\.sa$', re.IGNORECASE)
BOT_TOKEN = os.getenv('TELEGRAM_BOT_TOKEN')

if not BOT_TOKEN:
    raise RuntimeError('Missing TELEGRAM_BOT_TOKEN environment variable')

WEEKDAY_LABELS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
WEEKDAY_VALUES = {
    'الأحد': 0,
    'الاثنين': 1,
    'الثلاثاء': 2,
    'الأربعاء': 3,
    'الخميس': 4,
    'الجمعة': 5,
    'السبت': 6,
}

REGISTER_USERNAME, REGISTER_PASSWORD = range(2)


def classify_message(message: str) -> str:
    text = (message or '').strip()
    if not text:
        return 'general'

    normalized = text.lower()
    if any(keyword in normalized for keyword in ['تواصل مع المطور', 'مطور', 'contact', 'developer']):
        return 'developer_contact'
    if any(keyword in normalized for keyword in ['اقتراح', 'ميزة', 'أقترح', 'أريد اقتراح', 'لدي اقتراح']):
        return 'suggestion'
    if any(keyword in normalized for keyword in ['مشكلة', 'شكوى', 'خطأ', 'تعذر', 'فشل', 'لا أستطيع', 'لدي شكوى']):
        return 'problem'
    return 'general'


def build_support_reply(category: str, message: str = '') -> str:
    if category == 'problem':
        return 'تم تسجيل مشكلتك وسنرسلها إلى المطور فورًا. شكراً لك.'
    if category == 'suggestion':
        return 'تم تسجيل اقتراحك وسنرسلها إلى المطور. شكراً لك.'
    if category == 'developer_contact':
        return 'تم توجيه رسالتك إلى المطور وسنرد عليك قريبًا.'
    return 'تم استلام رسالتك وسنراجعها.'


def send_message_to_admin(category: str, message: str, username: str = '') -> bool:
    token = os.getenv('TELEGRAM_BOT_TOKEN', '').strip()
    chat_id = os.getenv('TELEGRAM_ADMIN_CHAT_ID', '').strip()
    if not token or not chat_id:
        logger.warning('Telegram admin message skipped: missing token or chat id')
        return False

    label = {
        'general': 'استفسار عام',
        'problem': 'مشكلة',
        'suggestion': 'اقتراح',
        'developer_contact': 'تواصل مع المطور',
    }.get(category, category)

    text = f"[منصة مسار الأكاديمية]\nنوع الرسالة: {label}\nالمستخدم: {username or 'غير معروف'}\n\n{message}".strip()
    payload = urllib.parse.urlencode({'chat_id': chat_id, 'text': text, 'parse_mode': 'HTML'}).encode('utf-8')
    request = urllib.request.Request(
        f'https://api.telegram.org/bot{token}/sendMessage',
        data=payload,
        headers={'Content-Type': 'application/x-www-form-urlencoded'}
    )

    try:
        with urllib.request.urlopen(request, timeout=6) as response:
            data = json.loads(response.read().decode('utf-8'))
            if not data.get('ok'):
                logger.warning('Telegram send failed: %s', data)
            return bool(data.get('ok'))
    except Exception as exc:  # pragma: no cover - network dependent
        logger.exception('Failed to send support message to Telegram admin: %s', exc)
        return False


def get_db_connection():
    if mysql is None:
        raise RuntimeError('mysql-connector-python is required for database access')

    config = build_database_config()
    database_name = config['database']

    try:
        return mysql.connect(**config)
    except MYSQL_ERROR as exc:
        if getattr(exc, 'errno', None) == 1049:
            logger.warning('Database %s not found; attempting to create it.', database_name)
            root_config = dict(config)
            root_config.pop('database', None)
            try:
                root_conn = mysql.connect(**root_config)
            except MYSQL_ERROR:
                raise

            try:
                cursor = root_conn.cursor()
                cursor.execute(f'CREATE DATABASE IF NOT EXISTS `{database_name}`')
                root_conn.commit()
            finally:
                root_conn.close()

            return mysql.connect(**config)
        raise


def create_password_hash(password: str) -> str:
    if bcrypt is not None:
        return bcrypt.hashpw(password.encode('utf-8'), bcrypt.gensalt()).decode('utf-8')

    try:
        from werkzeug.security import generate_password_hash
    except ImportError as exc:  # pragma: no cover - dependency fallback
        raise RuntimeError('bcrypt or werkzeug is required to hash passwords') from exc

    return generate_password_hash(password, method='pbkdf2:sha256')


def get_site_webhook_url() -> str:
    for env_name in ('SITE_WEBHOOK_URL', 'SITE_URL', 'APP_URL', 'APP_BASE_URL'):
        value = (os.getenv(env_name) or '').strip()
        if not value:
            continue

        if value.startswith('http://') or value.startswith('https://'):
            if value.endswith('/telegram_webhook.php'):
                return value
            if value.endswith('/'):
                return value + 'telegram_webhook.php'
            return value.rstrip('/') + '/telegram_webhook.php'

        if value.startswith('/'):
            return value.rstrip('/') + '/telegram_webhook.php'

    return ''


def send_site_webhook_event(event: str, payload: dict | None = None) -> bool:
    url = get_site_webhook_url()
    if not url:
        return False

    body = {
        'event': event,
        'source': 'telegram_bot',
        'timestamp': datetime.datetime.utcnow().isoformat() + 'Z',
    }
    if payload:
        body.update(payload)

    body_bytes = json.dumps(body, ensure_ascii=False).encode('utf-8')
    headers = {'Content-Type': 'application/json'}
    secret = (os.getenv('SITE_WEBHOOK_SECRET') or '').strip()
    if secret:
        signature = hmac.new(secret.encode('utf-8'), body_bytes, hashlib.sha256).hexdigest()
        headers['X-Site-Webhook-Signature'] = signature

    request = urllib.request.Request(
        url,
        data=body_bytes,
        headers=headers,
        method='POST',
    )

    try:
        with urllib.request.urlopen(request, timeout=6) as response:
            return response.status < 400
    except Exception as exc:  # pragma: no cover - network dependent
        logger.exception('Failed to send webhook event %s to site: %s', event, exc)
        return False


def build_schedule_keyboard(selected_day: str | None = None):
    buttons = [
        [
            InlineKeyboardButton('📅 اليوم', callback_data='schedule:today'),
            InlineKeyboardButton('🗂️ الجدول الكامل', callback_data='schedule:full'),
        ],
    ]

    day_row = []
    for label in WEEKDAY_LABELS:
        prefix = '✅ ' if selected_day == label else ''
        day_row.append(InlineKeyboardButton(f'{prefix}{label}', callback_data=f'schedule:{label}'))
    buttons.append(day_row)
    return InlineKeyboardMarkup(buttons)


def build_schedule_message(day_value: int | None = None, rows: list | None = None) -> str:
    if day_value is None:
        title = '📚 الجدول الدراسي الكامل'
        label = 'الجدول الكامل'
    else:
        title = f'📚 جدول {WEEKDAY_LABELS[day_value]}'
        label = WEEKDAY_LABELS[day_value]

    message_lines = [f'<b>{html.escape(title)}</b>', '']
    if not rows:
        message_lines.append('🌿 لا توجد محاضرات مسجلة لهذا القسم بعد.')
        message_lines.append('')
        message_lines.append('إذا كنت تتوقع وجود جدول، فربما لم تُضف المحاضرات إلى قاعدة البيانات بعد أو أن القسم غير مرتبط بالجدول الحالي.')
        return '\n'.join(message_lines)

    for row in rows:
        start_time = html.escape(str(row.get('start_time') or '').strip())
        end_time = html.escape(str(row.get('end_time') or '').strip())
        location = html.escape(str(row.get('location') or 'غير محدد').strip())
        course_code = html.escape(str(row.get('course_code') or '').strip())
        title_text = html.escape(str(row.get('title') or 'مقرر غير محدد').strip())
        notes = html.escape(str(row.get('notes') or '').strip())

        time_text = f'⏰ {start_time} - {end_time}' if start_time or end_time else '⏰ غير محدد'
        course_text = f'📚 {title_text}'
        location_text = f'📍 {location}'
        extra_text = f'📝 {notes}' if notes else ''
        if course_code:
            course_text += f' ({course_code})'

        message_lines.append(course_text)
        message_lines.append(time_text)
        message_lines.append(location_text)
        if extra_text:
            message_lines.append(extra_text)
        message_lines.append('')

    if day_value is None:
        message_lines.append('✨ هذا العرض يحتوي على جميع المحاضرات المسجلة.')
    else:
        message_lines.append(f'✨ هذه هي المحاضرات الخاصة بـ {html.escape(label)}.')

    return '\n'.join(message_lines).strip()


def build_attendance_summary(rows: list | None = None) -> str:
    lines = ['<b>📊 ملخص الحضور</b>', '']
    if not rows:
        lines.append('لا توجد سجلات حضور مرتبطة بحسابك بعد.')
        return '\n'.join(lines).strip()

    presents = sum(1 for row in rows if (row.get('status') or '').lower() == 'present')
    absents = sum(1 for row in rows if (row.get('status') or '').lower() == 'absent')
    lates = sum(1 for row in rows if (row.get('status') or '').lower() == 'late')
    total = len(rows)
    attendance_rate = round(((presents + (lates * 0.5)) / total) * 100, 1) if total else 100.0

    lines.append(f'✅ حضور: {presents}')
    lines.append(f'🔴 غياب: {absents}')
    lines.append(f'🟡 تأخير: {lates}')
    lines.append(f'📈 نسبة الالتزام: {attendance_rate}%')
    lines.append('')
    lines.append('<b>آخر السجلات:</b>')

    for row in rows[:5]:
        status_text = 'حاضر' if (row.get('status') or '').lower() == 'present' else 'غائب' if (row.get('status') or '').lower() == 'absent' else 'متأخر'
        course_code = html.escape(str(row.get('course_code') or 'غير محدد').strip())
        created_at = html.escape(str(row.get('created_at') or '').strip())
        lines.append(f'• {created_at} — {course_code} — {status_text}')

    return '\n'.join(lines).strip()


async def schedule_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    chat_id = str(update.effective_chat.id)
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute('SELECT id FROM users WHERE telegram_chat_id = %s LIMIT 1', (chat_id,))
        user = cursor.fetchone()
        if not user:
            await update.message.reply_text(
                '🔗 عليك أولًا ربط حسابك مع البوت من الموقع حتى أتمكن من عرض جدولك الدراسي.',
                reply_markup=ReplyKeyboardRemove()
            )
            return

        cursor.execute('SELECT id, title, course_code, day_of_week, start_time, end_time, location, notes FROM schedules WHERE user_id = %s ORDER BY day_of_week, start_time', (user['id'],))
        rows = cursor.fetchall()
        await update.message.reply_text(
            build_schedule_message(day_value=None, rows=rows),
            parse_mode=ParseMode.HTML,
            reply_markup=build_schedule_keyboard()
        )
    except MYSQL_ERROR as exc:
        logger.exception('Database error while loading schedule menu: %s', exc)
        await update.message.reply_text('حدث خطأ أثناء تحميل الجدول. حاول لاحقاً.', reply_markup=ReplyKeyboardRemove())
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'conn' in locals() and conn.is_connected():
            conn.close()


async def handle_schedule_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None:
        return

    await query.answer()
    chat_id = str(update.effective_chat.id)
    callback_data = query.data

    if not callback_data.startswith('schedule:'):
        return

    _, action = callback_data.split(':', 1)
    day_value = None
    day_label = None
    if action == 'today':
        today_value = datetime.date.today().weekday()
        day_value = today_value
        day_label = WEEKDAY_LABELS[day_value]
    elif action == 'full':
        day_value = None
        day_label = None
    else:
        day_label = action
        if day_label not in WEEKDAY_VALUES:
            return
        day_value = WEEKDAY_VALUES[day_label]

    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute('SELECT id FROM users WHERE telegram_chat_id = %s LIMIT 1', (chat_id,))
        user = cursor.fetchone()
        if not user:
            await query.edit_message_text(
                '🔗 عليك أولًا ربط حسابك مع البوت من الموقع حتى أتمكن من عرض جدولك الدراسي.',
                reply_markup=build_schedule_keyboard(selected_day=day_label)
            )
            return

        if day_value is None:
            cursor.execute('SELECT id, title, course_code, day_of_week, start_time, end_time, location, notes FROM schedules WHERE user_id = %s ORDER BY day_of_week, start_time', (user['id'],))
            rows = cursor.fetchall()
        else:
            cursor.execute('SELECT id, title, course_code, day_of_week, start_time, end_time, location, notes FROM schedules WHERE user_id = %s AND day_of_week = %s ORDER BY start_time, title', (user['id'], day_value))
            rows = cursor.fetchall()

        message = build_schedule_message(day_value=day_value, rows=rows)
        await query.edit_message_text(
            message,
            parse_mode=ParseMode.HTML,
            reply_markup=build_schedule_keyboard(selected_day=day_label)
        )
    except MYSQL_ERROR as exc:
        logger.exception('Database error while rendering schedule callback: %s', exc)
        await query.edit_message_text('حدث خطأ أثناء تحديث الجدول. حاول لاحقاً.', reply_markup=build_schedule_keyboard(selected_day=day_label))
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'conn' in locals() and conn.is_connected():
            conn.close()


async def attendance_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    chat_id = str(update.effective_chat.id)
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute('SELECT id FROM users WHERE telegram_chat_id = %s LIMIT 1', (chat_id,))
        user = cursor.fetchone()
        if not user:
            await update.message.reply_text(
                '🔗 عليك أولًا ربط حسابك مع البوت من الموقع حتى أتمكن من عرض ملخص حضورك.',
                reply_markup=ReplyKeyboardRemove()
            )
            return

        cursor.execute('SELECT course_code, status, created_at FROM attendance_log WHERE user_id = %s ORDER BY created_at DESC, id DESC LIMIT 20', (user['id'],))
        rows = cursor.fetchall()
        send_site_webhook_event('attendance_requested', {'chat_id': chat_id, 'user_id': user['id']})
        await update.message.reply_text(
            build_attendance_summary(rows),
            parse_mode=ParseMode.HTML,
            reply_markup=ReplyKeyboardRemove()
        )
    except MYSQL_ERROR as exc:
        logger.exception('Database error while loading attendance summary: %s', exc)
        await update.message.reply_text('حدث خطأ أثناء تحميل ملخص الحضور. حاول لاحقاً.', reply_markup=ReplyKeyboardRemove())
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'conn' in locals() and conn.is_connected():
            conn.close()


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    payload = context.args[0] if context.args else ''
    if payload:
        try:
            conn = get_db_connection()
            cursor = conn.cursor(dictionary=True)
            account_type = 'user'
            account_id = None

            if payload.startswith('admin:'):
                account_type = 'admin'
                account_id = int(payload.split(':', 1)[1])
            elif payload.startswith('user:'):
                account_type = 'user'
                account_id = int(payload.split(':', 1)[1])
            else:
                account_id = int(payload)

            if payload.startswith('attendance:'):
                account_id = int(payload.split(':', 1)[1])
                cursor.execute('SELECT id FROM users WHERE id = %s LIMIT 1', (account_id,))
                user = cursor.fetchone()
                if user:
                    cursor.execute('UPDATE users SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s', (str(update.effective_chat.id), update.effective_user.username or '', account_id))
                    conn.commit()
                    send_site_webhook_event('attendance_requested', {'chat_id': str(update.effective_chat.id), 'user_id': account_id, 'telegram_username': update.effective_user.username or ''})
                    await update.message.reply_text('تم فتح رابط الحضور بنجاح. يمكنك الآن استخدام /attendance لعرض ملخص حضورك.', reply_markup=ReplyKeyboardRemove())
                else:
                    await update.message.reply_text('لم نتمكن من ربط الحساب لأن المعرف غير معروف. يرجى استخدام الرابط من الموقع.', reply_markup=ReplyKeyboardRemove())
                return

            if account_type == 'admin':
                cursor.execute('SELECT id, user_id FROM admins WHERE id = %s OR user_id = %s LIMIT 1', (account_id, account_id))
                account = cursor.fetchone()
                if account:
                    cursor.execute('ALTER TABLE admins ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(255) DEFAULT NULL')
                    cursor.execute('ALTER TABLE admins ADD COLUMN IF NOT EXISTS telegram_username VARCHAR(100) DEFAULT NULL')
                    admin_id = account['id']
                    linked_user_id = account.get('user_id') or account_id
                    cursor.execute('UPDATE admins SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s', (str(update.effective_chat.id), update.effective_user.username or '', admin_id))
                    if linked_user_id:
                        cursor.execute('UPDATE users SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s', (str(update.effective_chat.id), update.effective_user.username or '', linked_user_id))
                    conn.commit()
                    send_site_webhook_event('admin_linked', {'chat_id': str(update.effective_chat.id), 'user_id': linked_user_id, 'telegram_username': update.effective_user.username or ''})
                    await update.message.reply_text('تم ربط حسابك الإداري بنجاح مع البوت. يمكنك العودة إلى الموقع الآن.', reply_markup=ReplyKeyboardRemove())
                else:
                    await update.message.reply_text('لم نتمكن من ربط الحساب لأن المعرف غير معروف. يرجى استخدام الرابط من الموقع.', reply_markup=ReplyKeyboardRemove())
            else:
                cursor.execute('SELECT id FROM users WHERE id = %s LIMIT 1', (account_id,))
                user = cursor.fetchone()
                if user:
                    cursor.execute('UPDATE users SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s', (str(update.effective_chat.id), update.effective_user.username or '', account_id))
                    conn.commit()
                    send_site_webhook_event('account_linked', {'chat_id': str(update.effective_chat.id), 'user_id': account_id, 'telegram_username': update.effective_user.username or ''})
                    await update.message.reply_text('تم ربط حسابك بنجاح مع المنصة باستخدام معرف مجهول آمن. يمكنك العودة إلى الموقع الآن.', reply_markup=ReplyKeyboardRemove())
                else:
                    await update.message.reply_text('لم نتمكن من ربط الحساب لأن المعرف غير معروف. يرجى استخدام الرابط من الموقع.', reply_markup=ReplyKeyboardRemove())
        except (MYSQL_ERROR, ValueError):
            logger.exception('Database error while linking telegram payload')
            await update.message.reply_text('حدث خطأ أثناء الربط. حاول لاحقاً.', reply_markup=ReplyKeyboardRemove())
        finally:
            if 'cursor' in locals():
                cursor.close()
            if 'conn' in locals() and conn.is_connected():
                conn.close()
        return

    await update.message.reply_text(
        'مرحباً! 👋\nأهلاً بك في منصة مسار الأكاديمية.\n' +
        'يمكنك استخدام هذا البوت للتواصل مع الدعم أو ربط حسابك مع المنصة.\n' +
        'استخدم /schedule لعرض جدولك الدراسي التفاعلي.\n' +
        'استخدم /attendance لعرض ملخص حضورك السريع.\n' +
        'وإذا أردت إنشاء حساب جديد مباشرة، اكتب /register',
        reply_markup=ReplyKeyboardRemove()
    )


async def start_register(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    await update.message.reply_text(
        '🧾 سننشئ حسابك الجديد بخطوتين فقط، وبأقل قدر من المعلومات.\n\n1) اكتب اسم المستخدم الذي تريده.\n2) ثم اكتب كلمة المرور.',
        reply_markup=ReplyKeyboardRemove()
    )
    return REGISTER_USERNAME


async def register_username(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or update.message.text is None:
        return REGISTER_USERNAME

    username = update.message.text.strip()
    if not username:
        await update.message.reply_text('اسم المستخدم لا يمكن أن يكون فارغاً. أعد كتابة اسم المستخدم من جديد.')
        return REGISTER_USERNAME

    if len(username) < 3:
        await update.message.reply_text('اسم المستخدم قصير جداً. يرجى استخدام 3 أحرف أو أكثر.')
        return REGISTER_USERNAME

    context.user_data['pending_username'] = username
    await update.message.reply_text('🔐 الآن اكتب كلمة المرور الخاصة بك.\nلا نطلب أي معلومات إضافية مثل البريد أو البيانات الشخصية.')
    return REGISTER_PASSWORD


async def register_password(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or update.message.text is None:
        return REGISTER_PASSWORD

    password = update.message.text.strip()
    if not password:
        await update.message.reply_text('كلمة المرور لا يمكن أن تكون فارغة. أعد كتابة كلمة المرور.')
        return REGISTER_PASSWORD

    username = context.user_data.get('pending_username', '').strip()
    if not username:
        await update.message.reply_text('حدث خطأ في الجلسة. يرجى البدء من جديد باستخدام /register')
        return ConversationHandler.END

    chat_id = str(update.effective_chat.id)
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute('SELECT id FROM users WHERE username = %s LIMIT 1', (username,))
        existing = cursor.fetchone()
        if existing:
            await update.message.reply_text('⚠️ اسم المستخدم هذا محجوز مسبقاً. يرجى اختيار اسم آخر.')
            context.user_data.clear()
            return ConversationHandler.END

        password_hash = create_password_hash(password)
        cursor.execute(
            'INSERT INTO users (username, email, password_hash, telegram_chat_id, telegram_username) VALUES (%s, NULL, %s, %s, %s)',
            (username, password_hash, chat_id, update.effective_user.username or '')
        )
        conn.commit()

        cursor.execute('SELECT id FROM users WHERE username = %s LIMIT 1', (username,))
        created_user = cursor.fetchone()
        if created_user:
            cursor.execute(
                'UPDATE users SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s',
                (chat_id, update.effective_user.username or '', created_user['id'])
            )
            conn.commit()
        send_site_webhook_event('registration_completed', {
            'chat_id': chat_id,
            'user_id': created_user['id'],
            'username': username,
            'telegram_username': update.effective_user.username or '',
        })
        await update.message.reply_text(
            '✅ تم إنشاء الحساب بنجاح!\n' +
            'يمكنك الآن تسجيل الدخول إلى الموقع باستخدام اسم المستخدم وكلمة المرور اللذين أدخلتهما.',
            reply_markup=ReplyKeyboardRemove()
        )
    except MYSQL_ERROR as exc:
        logger.exception('Database error during Telegram registration: %s', exc)
        await update.message.reply_text('حدث خطأ أثناء إنشاء الحساب. حاول لاحقاً.', reply_markup=ReplyKeyboardRemove())
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'conn' in locals() and conn.is_connected():
            conn.close()

    context.user_data.clear()
    return ConversationHandler.END


async def cancel_registration(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data.clear()
    await update.message.reply_text('تم إلغاء تسجيل الحساب.', reply_markup=ReplyKeyboardRemove())
    return ConversationHandler.END


async def handle_email(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None or update.message.text is None:
        return

    message_text = update.message.text.strip()
    category = classify_message(message_text)
    if category != 'general':
        send_message_to_admin(category, message_text, update.effective_user.username or '')
        await update.message.reply_text(
            build_support_reply(category, message_text),
            reply_markup=ReplyKeyboardRemove()
        )
        return

    chat_id = str(update.effective_chat.id)
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        lookup_value = message_text.strip()

        cursor.execute(
            'SELECT id, username, email FROM users WHERE username = %s OR email = %s LIMIT 1',
            (lookup_value, lookup_value)
        )
        user = cursor.fetchone()

        if not user:
            cursor.execute(
                'SELECT id, username, user_id FROM admins WHERE username = %s LIMIT 1',
                (lookup_value,)
            )
            admin = cursor.fetchone()

            if not admin:
                cursor.execute(
                    'SELECT id, username, email FROM users WHERE telegram_username = %s LIMIT 1',
                    (lookup_value,)
                )
                user_by_telegram = cursor.fetchone()
                if user_by_telegram:
                    user = user_by_telegram
                else:
                    await update.message.reply_text(
                        'لم نجد هذا المستخدم في نظام المنصة. يمكنك إنشاء حساب جديد عبر /register أو التواصل مع الدعم.',
                        reply_markup=ReplyKeyboardRemove()
                    )
                    return

            if admin:
                linked_user_id = admin.get('user_id') or admin['id']
                cursor.execute(
                    'UPDATE admins SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s',
                    (chat_id, update.effective_user.username or '', admin['id'])
                )
                if linked_user_id:
                    cursor.execute(
                        'UPDATE users SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s',
                        (chat_id, update.effective_user.username or '', linked_user_id)
                    )
                conn.commit()
                send_site_webhook_event('account_linked', {'chat_id': chat_id, 'user_id': linked_user_id, 'telegram_username': update.effective_user.username or ''})

                await update.message.reply_text(
                    'تم ربط حساب المشرف مع البوت بنجاح.',
                    reply_markup=ReplyKeyboardRemove()
                )
                return

        cursor.execute('UPDATE users SET telegram_chat_id = %s, telegram_username = %s WHERE id = %s', (chat_id, update.effective_user.username or '', user['id']))
        conn.commit()
        send_site_webhook_event('account_linked', {'chat_id': chat_id, 'user_id': user['id'], 'telegram_username': update.effective_user.username or ''})

        await update.message.reply_text(
            'تم ربط حسابك مع البوت بنجاح. يمكنك الآن التواصل مع الدعم أو استخدام الأوامر المتاحة.',
            reply_markup=ReplyKeyboardRemove()
        )
    except MYSQL_ERROR as exc:
        logger.exception('Database error while handling user link request')
        await update.message.reply_text('حدث خطأ داخلي أثناء معالجة طلبك. حاول لاحقاً.')
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'conn' in locals() and conn.is_connected():
            conn.close()


async def unknown_message(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(
        'أهلاً بك. استخدم /start للبدء أو أرسل رسالتك مباشرة وسنرسلها إلى الدعم إذا لزم الأمر.'
    )


def main() -> None:
    application = ApplicationBuilder().token(BOT_TOKEN).build()
    registration_handler = ConversationHandler(
        entry_points=[CommandHandler('register', start_register)],
        states={
            REGISTER_USERNAME: [MessageHandler(filters.TEXT & ~filters.COMMAND, register_username)],
            REGISTER_PASSWORD: [MessageHandler(filters.TEXT & ~filters.COMMAND, register_password)],
        },
        fallbacks=[CommandHandler('cancel', cancel_registration)],
    )

    application.add_handler(CommandHandler('start', start))
    application.add_handler(CommandHandler('schedule', schedule_menu))
    application.add_handler(CommandHandler('attendance', attendance_menu))
    application.add_handler(registration_handler)
    application.add_handler(CallbackQueryHandler(handle_schedule_callback, pattern='^schedule:'))
    application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_email))
    application.add_handler(MessageHandler(filters.COMMAND, unknown_message))
    application.run_polling()


if __name__ == '__main__':
    main()
