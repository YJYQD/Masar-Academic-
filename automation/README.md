# Automation notes

- Run the reminder script periodically with a cron entry such as:
  - */15 * * * * /usr/bin/php /path/to/doctor-rating/automation/telegram_reminders.php >/dev/null 2>&1
- Configure the Telegram bot token and admin chat ID in config.php.
- The reminder logic uses the subject type to adjust the critical absence threshold dynamically.
