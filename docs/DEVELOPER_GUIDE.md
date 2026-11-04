# Руководство следующему разработчику

## 1. Безопасная точка старта

Работайте **с двумя репозиториями**: sh-ansar/itp-toir-backend и sh-ansar/itp-toir-frontend. Ветка разработки: feature/toir-foundation. Не меняйте main без отдельного согласования. Сверьте GitHub Actions и файл docs/QUALITY_AUDIT.md до начала работы.

Нельзя переносить на локальную машину production .env, ключ APP_KEY, SMTP-пароль, SQLite production или чужие пользовательские сессии. Заводите локальную тестовую БД. Данные из другой компании ТОиР в тестах создавайте фабриками.

## 2. Требования

- PHP 8.4 (в composer.json разрешён PHP ^8.2, но проект на сервере и в CI подтверждён именно на 8.4), Composer; pdo_sqlite, mbstring, intl и расширения Laravel.
- Node.js 22, npm с package-lock из frontend репозитория.
- Два отдельных терминала: Laravel backend на 127.0.0.1:8000, Vue/Vite на 127.0.0.1:5173.
- Для производственной сборки: VITE_BASE_PATH=/mro/. Для локальной Vite: корень / и proxy к 127.0.0.1:8000.

## 3. Backend в чистой локальной БД

~~~powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite -ErrorAction SilentlyContinue
php artisan migrate
php artisan toir:bootstrap --email=qa-admin@example.test --name="QA Administrator" --company="QA" --code=qa
php artisan serve --host=127.0.0.1 --port=8000
~~~

Не применяйте bootstrap к существующей production. Пароль первичного администратора должен формироваться и вводиться безопасно, без публикации в чатах.

## 4. Frontend

~~~powershell
npm ci
$env:VITE_BACKEND_ORIGIN = 'http://127.0.0.1:8000'
npm run dev
~~~

Используйте локальную .env с нужными VITE_* или командную переменную; backend cookies и CSRF должны оставаться в same-origin через Vite proxy. Начальный GET /sanctum/csrf-cookie; затем /api/v1/auth/login. Vue не хранит токены в localStorage.

Для сборки под /mro/:
~~~powershell
$env:VITE_BASE_PATH = '/mro/'
npm run typecheck
npm run build
~~~

## 5. Проверки перед PR

~~~powershell
# В backend
php artisan test --compact
php vendor/bin/pint --test --preset laravel

# В frontend
npm ci
npm run typecheck
npm run build
~~~

Если Pint обнаружил отклонения, исправьте через php vendor/bin/pint --preset laravel. Форматирование оформляйте отдельным коммитом от бизнес-изменений.

## 6. Как расширять функциональность

1. Опишите бизнес-инвариант: кто может выполнять операцию, над каким объектом и при каком статусе.
2. Создайте миграцию только для БД ТОиР, учитывайте company_id и внешние ключи той же компании.
3. Вынесите предметный переход в сервис, все связанные записи/аудит — в одну транзакцию. Для конкурентных операций отдельно продумайте уникальные индексы/проверку повторов.
4. Добавьте API в routes/web.php только под auth + toir.account.active. Если объект чужой компании, отвечайте 404; если нет роли — 403.
5. Добавьте Laravel feature-тесты, включая отрицательные сценарии и повторную отправку запроса.
6. В frontend используйте src/api/client.ts, типы src/types, общий реестр компонентов и CSS-переменные src/styles/tokens.css. Не добавляйте прямые fetch в страницы.
7. Отобразите loading/empty/error/success/forbidden состояния. Отдельно проверьте 320, 390, 768, 1440 px и RU/EN/GE там, где локализация поддерживается.
8. Сверьте изменения с реальными UI-компонентами HRM визуально; автоматической синхронизации между продуктами нет.

## 7. Типичные ошибки, которых нужно избегать

- Использование роли пользователя без привязки к компании.
- Указание company_id в body и выборка без where(company_id).
- Прямое редактирование status наряда вместо transition.
- Резерв/списание материала без атомарного обновления остатков и журнала.
- Запуск фонового чат-воркера дважды; самостоятельные сервисы ТОиР изолированы.
- Изменение SMTP и .env без проверки конфигурации, бэкапа и тестового письма.
- Использование устаревшего «демо» из README старых коммитов — полноценный демо-источник удалён; остаточные preview.enabled всегда false.
- Автоматический рефактор крупных контроллеров без достаточного набора новых тестов.

## 8. Контекст и следующие шаги

Главный функционал доступен и тесты зелёные. Масштабирование на много компаний, PostgreSQL, нагрузочные/конкурентные тесты, реальный SMTP, централизованные Policies, frontend lint и отделение CSS/страниц — отдельные задачи, перечисленные в docs/QUALITY_AUDIT.md. Передавать такую неопределённость как «всё готово» нельзя.

## 9. Зона ответственности и история

Не существует SSO с HRM или Portal. Перед переносом UI-компонентов согласуйте лицензирование, изменённые интерфейсы и совместимость API. Для внесения изменений в Reverb, Windows-службы и инфраструктуру сначала прочитайте docs/RUNBOOK.md и текущий статус сервисов.
