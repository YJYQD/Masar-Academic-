import importlib
import os
import sys
import unittest

ROOT = os.path.dirname(os.path.dirname(__file__))
sys.path.insert(0, ROOT)
os.environ.setdefault('TELEGRAM_BOT_TOKEN', 'test-token')

bot = importlib.import_module('bot')


class BotFeatureTests(unittest.TestCase):
    def test_classifies_problem_messages(self):
        self.assertEqual(bot.classify_message('لدي مشكلة في تسجيل الدخول'), 'problem')

    def test_classifies_suggestion_messages(self):
        self.assertEqual(bot.classify_message('أريد اقتراح لتحسين الموقع'), 'suggestion')

    def test_classifies_contact_developer_messages(self):
        self.assertEqual(bot.classify_message('تواصل مع المطور'), 'developer_contact')

    def test_classifies_regular_questions_as_general(self):
        self.assertEqual(bot.classify_message('ما هي المواد المطلوبة لهذا الفصل؟'), 'general')


if __name__ == '__main__':
    unittest.main()
