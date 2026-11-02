# ITP ТОИР · Backend

Автономный Laravel 12 API для системы технического обслуживания и ремонта. **Portal/HRM/Inventory не нужны для входа.** Учётные данные хранятся только в базе ТОИР. Не копируйте .env или базы данных других проектов.

## Запуск

1. Требования: PHP >= 8.2, Composer, SQLite (для локального режима) либо отдельная PostgreSQL/MySQL БД.
2. \`composer install\`
3. Скопировать \`.env.example\` в \`.env\`. В production: \`APP_ENV=production\`, \`APP_DEBUG=false\`, \`APP_URL\` и \`TOIR_FRONTEND_URL\` с HTTPS, \`SESSION_SECURE_COOKIE=true\`, настроить SMTP и изолированную БД.
4. \`php artisan key:generate\`
5. Для SQLite создать \`database/database.sqlite\` (или указать \`DB_DATABASE\`).
6. \`php artisan migrate\`
7. \`php artisan toir:bootstrap --email=admin@example.test --name="Администратор" --company="ITP Mining" --code=itp-mining\` (пароль вводится скрыто, на экране и в аргументах процесса не появляется).
8. \`php artisan serve --host=127.0.0.1 --port=8000\`
9. \`php artisan test\`

В production отдавайте Vue SPA и API из **одного origin**, либо настройте разрешённый first-party CORS/CSRF в рамках собственного домена. Публичной регистрации, демо-паролей или фейковой авторизации нет.

## Авторизация

Сессионная авторизация Laravel с HttpOnly cookie, CSRF, rate limits, восстановлением пароля, сменой пароля и выбором компании. Клиент сначала выполняет GET \`/sanctum/csrf-cookie\`, затем POST \`/api/v1/auth/login\`. Сведения о профиле: GET \`/api/v1/auth/me\`. Смена компании: POST \`/api/v1/auth/companies/switch\`. Выход: POST \`/api/v1/auth/logout\`.

Роли привязаны к компании (owner/admin/manager/engineer/technician/viewer). Активная компания хранится **в сессии**, не в глобальном поле пользователя. Это исключает переключение активной компании в другом браузере той же учётной записи. Проверка роли и tenant context обязательно применяется к будущим CRUD-эндпоинтам.

Письма сброса пароля в local окружении уходят в LOG, в production настройте MAIL_* до включения восстановления.

## Данные

\`users\`, \`companies\`, \`company_user\`, \`sessions\`, \`password_reset_tokens\`, \`toir_audit_events\`. Предметные таблицы оборудования и ремонтов добавляются отдельным этапом после согласования схемы и tenant policy.

## Статус

Основа самостоятельного backend + сессионный вход; без автоматической связи с Portal. В CI и на сервере проверить запуск приложения, миграции и все feature tests до деплоя. Не запускать миграции на чужих БД.
