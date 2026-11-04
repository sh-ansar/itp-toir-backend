# ТОиР — эксплуатация, обновление и восстановление

**Сфера:** только ITP ТОиР. Хост Windows WIN-487ME2E68DR. Не останавливайте HRM, Inventory, Portal и ETP.

## Контуры и каталоги

| Компонент | Расположение / адрес |
|---|---|
| Пользовательский URL | https://platform.itpmining.kz/mro/ |
| API health | https://platform.itpmining.kz/mro/api/v1/health |
| Рабочий checkout backend | C:\deploy\toir-workspace\backend |
| Рабочий checkout frontend | C:\deploy\toir-workspace\frontend |
| Laravel production | C:\deploy\toir\backend\current |
| SPA production | C:\deploy\toir\site\current |
| Production SQLite | C:\deploy\toir\backend\current\database\database.sqlite |
| Резервные копии | C:\deploy\toir\backups |
| PHP на текущем сервере | C:\deploy\hrm\runtime\php-8.4.26-nts\php.exe |

Использование PHP binary из общего расположения **не означает** совместное приложение, БД или сессии с HRM. Не обновляйте/заменяйте этот PHP runtime без согласования с владельцами других систем.

Служба: ITP-TOIR-FastCGI. Задания Windows: ITP-TOIR-Reverb, ITP-TOIR-ChatWorker, ITP-TOIR-Overdue. Порядок запуска и фактические command line подтверждать в Task Scheduler/Service Control, а не копировать параметры из старых документов.

## Проверки только для чтения

~~~powershell
Get-Service ITP-TOIR-FastCGI
Get-ScheduledTask -TaskName ITP-TOIR-Reverb,ITP-TOIR-ChatWorker,ITP-TOIR-Overdue
curl.exe -I https://platform.itpmining.kz/mro/
curl.exe https://platform.itpmining.kz/mro/api/v1/health
~~~

401 на /api/v1/auth/me без сессии — штатно. 204 на /sanctum/csrf-cookie — штатно. Laravel логи: production backend/storage/logs; не выгружать в чат логины, cookies, пароли и ссылки сброса.

## Правила релиза

1. Убедиться в чистом Git checkout, ссылке на commit и успешных CI frontend/backend. Разработку вести на feature-ветке. **Не деплоить напрямую из main.**
2. Зафиксировать HTTP-статусы, состояние Фоновых задач, версию PHP и snapshot DB.
3. Подтвердить отдельную, пригодную для восстановления копию SQLite с учётом WAL; копию .env и изменяемых backend файлов; полный бэкап frontend сайта с .htaccess.
4. Для согласованного backup остановить **только** chat worker, Reverb/планировщик и FastCGI ТОиР. Снимок живой SQLite выполнять через SQLite backup API/VACUUM INTO либо корректную копию БД с WAL/SHM; простое копирование одного .sqlite при активных писателях недостаточно.
5. Проверить PRAGMA integrity_check / foreign_key_check на копии и фактическое восстановление в изолированной QA директории.
6. Запустить backend-тесты и Laravel Pint на исходниках, TypeScript и production build с VITE_BASE_PATH=/mro/.
7. Копировать только контролируемые файлы и не перезаписывать .env, SQLite, файловые логи/сессии. Миграции запускать только на БД ТОиР после проверенного бэкапа.
8. Очистить только cache/route/config ТОиР, перезапустить его собственные службы, проверить /mro и защищённые маршруты.
9. Реально авторизованным QA-пользователем проверить критичные workflow, роли, склад и чат; при ошибке — выполнить проверенный откат. Соседние системы должны оставаться доступны.

В репозитории есть ops/Deploy-ToirFeature.ps1 и вспомогательные скрипты. **Перед каждым применением инспектируйте соответствие текущему серверу и параметры, не используйте их как универсальную команду запуска.** Серверный qa-tools содержит исторические сценарии приёмки и не является частью переносимого репозитория.

## Команды backend

~~~powershell
# В каталоге C:\deploy\toir\backend\current
php artisan migrate:status
php artisan route:list --path=api/v1
php artisan toir:sync-overdue --dry-run
php artisan toir:mail-check
~~~

Используйте локальный PHP 8.4 по указанному пути, если php отсутствует в PATH. Команда toir:seed-acceptance поддерживает --company-id и --apply и записывает QA-записи: **не запускать её на production без отдельного запроса и плана очистки**.

## Почта

Production SMTP не считать включённым только по наличию MAIL_* переменных. Настройки хранятся в .env ТОиР, сервер должен пройти toir:mail-check и **реальную отправку** контрольного письма. Не копируйте .env HRM целиком. Отключённый транспорт блокирует приглашения/восстановление пароля. Не публикуйте SMTP-пароль в Git или логах.

## Чат и планировщик

Входящие HTTP-операции чата сохраняются в собственные таблицы ТОиР. Outbox worker: php artisan toir:chat-outbox. Reverb запускается отдельным заданием Windows. Планировщик Laravel выполняет toir:sync-overdue с интервалом 15 минут; для Windows требуется запуск schedule:run независимым заданием. Дубли worker-процессов недопустимы.

## Проверка восстановленного экземпляра

1. Выберите проверенный snapshot, создайте **новую отдельную папку** для QA backend и локальной БД.
2. Создайте отдельный .env QA с APP_KEY, DB_DATABASE, APP_URL, SESSION_COOKIE, MAIL_MAILER=array и bind-address 127.0.0.1. Не используйте production .env в публичном тестовом стенде.
3. Проверить integrity_check, миграции, реальные login/logout, права и отдельный QA-only объект.
4. Подтвердить отсутствие QA-only объекта в исходной БД. Сравнить критичные таблицы и внешний ключ.
5. Удалить/остановить временные тестовые HTTP-слушатели; хранить резервные данные в защищённой директории.

04.11.2026 изолированная QA база успешно восстановлена и протестирована через независимый Laravel login; **это не является** испытанием полного production disaster recovery.

## Откат

При сбое остановить только процессы ТОиР, вернуть согласованный backup backend/frontend/.env/SQLite (и WAL/SHM, если присутствовали), очистить конфигурационный кэш ТОиР, запустить его службы, проверить web/API/сессии и отсутствие ошибок в логах. Возврат миграций нельзя делать наугад: если изменения схемы необратимы, сначала восстанавливать совместимый snapshot базы и код той же версии.
