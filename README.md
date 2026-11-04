# ITP ТОиР — backend

Самостоятельный API системы технического обслуживания и ремонта оборудования (CMMS).
**Laravel 12 / PHP 8.4 на сервере**, Eloquent, Sanctum (сессионный CSRF), SQLite, Reverb.
Frontend расположен отдельно: [itp-toir-frontend](https://github.com/sh-ansar/itp-toir-frontend).
Рабочий URL: https://platform.itpmining.kz/mro/

## Начало работы

1. Установите PHP 8.4 с pdo_sqlite, Composer и расширениями Laravel. Запускайте проект **на отдельной локальной БД**, не копируйте production .env.
2. В каталоге backend выполните: **composer install**.
3. Скопируйте **.env.example** в **.env**. Укажите APP_URL, TOIR_FRONTEND_URL и DB_DATABASE. Для локального режима допустим MAIL_MAILER=array.
4. Создайте пустую SQLite БД в database/database.sqlite (или укажите абсолютный путь DB_DATABASE).
5. Выполните по порядку:

~~~powershell
php artisan key:generate
php artisan migrate
php artisan toir:bootstrap --email=admin@example.test --name="Администратор" --company="QA Company" --code=qa-company
php artisan serve --host=127.0.0.1 --port=8000
~~~

Команда bootstrap предназначена **только для новой БД**: пароль создаётся без публикации в консоли. Не запускайте её повторно в рабочей системе.

Отдельный терминал с frontend (Vue/Vite) запускается из другого репозитория. Не требуется npm в каталоге Laravel.

## Проверки перед коммитом

~~~powershell
php artisan test --compact
php vendor/bin/pint --test --preset laravel
~~~

Форматирование: **php vendor/bin/pint --preset laravel**. Команда **composer dev** запускает только PHP-сервер; для чат-воркера, Reverb и планировщика предусмотрены отдельные процессы.

## Документация для передачи

- [Архитектура, границы модулей и схема данных](docs/ARCHITECTURE.md)
- [Рабочее место разработчика и правила изменений](docs/DEVELOPER_GUIDE.md)
- [Развёртывание, резервирование и восстановление](docs/RUNBOOK.md)
- [Технический аудит и остаточные риски](docs/QUALITY_AUDIT.md)

## Принципы безопасности

Вход и компании **самостоятельные**, без SSO Portal/HRM. Активная компания хранится в сессии; её идентификатор и роль проверяются backend для каждой операции. Нельзя использовать company_id от клиента для выбора области данных. Пароли, APP_KEY, .env, пользовательские cookies и SQLite не помещать в Git.

Риск-скоринг оборудования — объяснимый эвристический алгоритм, **не ML-модель и не расчёт вероятности отказа**. Production SMTP пока не подтверждён реальной доставкой; не выдавайте это за готовую функциональность.

**Ветки:** разработка в feature/toir-foundation или новых feature-ветках от согласованной основы; main не обновлять без отдельной приёмки.
