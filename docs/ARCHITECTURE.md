# Архитектура ITP ТОиР

Дата актуализации: 04.11.2026. Применяется к **feature/toir-foundation**. Это автономный продукт, а не модуль внутри HRM/Portal/Inventory.

## 1. Общая схема

~~~text
Пользователь → Vue 3 SPA (/mro/) → HTTPS /mro/api/v1/*
                               → Laravel 12 (session + CSRF + active user)
                               → company context из session(toir_company_id)
                               → API controllers → domain services → Eloquent
                                                          ↓
                                               отдельная SQLite ТОиР

Чат Vue → HTTP + Laravel Echo → авторизованные каналы компании
                             → собственный Reverb + chat outbox worker
Планировщик Windows → Laravel schedule → toir:sync-overdue
~~~

Vue, API и защищённые cookies в production обслуживаются **под одним origin**. Backend использует routes/web.php (web middleware и CSRF), а не stateless routes/api.php. Защищённые маршруты требуют auth и toir.account.active.

## 2. Стек и репозитории

- Backend: Laravel 12, PHP 8.4 на Windows, Eloquent, SQLite, Reverb, стандартный Laravel session guard, Sanctum CSRF.
- Frontend: Vue 3, TypeScript, Pinia, Vue Router, Vite 7; собственный API-клиент с credentials: include.
- Исходники отдельно: sh-ansar/itp-toir-backend и sh-ansar/itp-toir-frontend.
- Визуальные компоненты адаптированы из HRM, **общего версионируемого UI-пакета пока нет**. Изменения в HRM автоматически сюда не попадают.
- На сервере используются независимые каталоги, база и службы ТОиР. Не изменять службы HRM, Inventory, Portal.

## 3. Главные точки входа backend

| Уровень | Файлы | Назначение |
|---|---|---|
| HTTP | routes/web.php, bootstrap/app.php | API, middleware, CSRF, broadcasting auth |
| Идентичность | AuthController, EnsureActiveAccount, AppServiceProvider | сессии, компании, подтверждение, rate limit, сброс пароля |
| Реестры | ToirResourceController | CRUD: assets, employees, projects, work-orders, plans, supplies, tenders |
| ТО/наряды | ToirWorkflowService, ToirChecklistService, ToirDispatchController | переходы статусов, проверка выполнения, приёмка |
| Планирование | ToirPlanningService, ToirOperationsController | генерация, наработка, смены, риск-скоринг |
| Склад | ToirStockController | приход, резерв, списание, журнал движения |
| Чат | ToirChatController, ToirChatDeliveryService, ToirRealtimeController | сообщение, квитанции, очередь/outbox, Reverb |
| Управление | ToirCompanyMembersController | членство, роли, приглашения |
| Консоль | app/Console/Commands/*, routes/console.php | bootstrap, QA seed, overdue, outbox, SMTP preflight |

### Важный долг по структуре

ToirResourceController (~542 строк) содержит маршрутизацию семи видов ресурсов и их правила; ToirPlanningService (~516 строк) сочетает планирование с риск-скорингом. Это не блокирует работу, но усложняет безопасную доработку. Рекомендуется выделять DTO/FormRequest, отдельные сервисы и Policies **постепенно, под существующие тесты**, а не переписывать сразу.

## 4. Пользователи, роли, tenant isolation

- users — учётные записи; companies — компании; company_user — активность и роль в конкретной компании.
- Роли: owner, admin, manager, engineer, technician, viewer. Роль не является глобальным полем users.
- Выбранная компания: session('toir_company_id'); менять её разрешено только через /api/v1/auth/companies/switch при проверенном членстве.
- Перед каждым чтением/изменением бизнес-сущности необходимо ограничить запрос company_id из активного членства. Клиентский company_id игнорировать. Любые связанные asset_id, crew_id, employee_id, project_id также проверять на ту же компанию.
- Права проверяет сервер. Скрытие кнопки в Vue не является авторизацией.
- owner/admin управляют участниками; manager управляет ремонтом и данными; engineer изменяет профильные операции; technician выполняет назначенные операции; viewer читает и может сообщать о неисправности. Точные разрешения отдельных операций описываются тестами.
- После отключения пользователя middleware проверяет свежий статус из БД и отзывает сессию.

Для новых эндпоинтов в первую очередь пишите негативные тесты: viewer, другая компания, подмена внешнего ключа, неактивный пользователь, закрытый наряд.

## 5. Ключевая предметная модель

### Оборудование
toir_assets представляет иерархию объект → система/агрегат → узел/компонент. Связи parent_id и все движения проверяются на принадлежность компании. Данные состояния находятся в toir_asset_readings. Цифровой паспорт агрегирует историю.

### ТО и ремонт
toir_maintenance_plans хранит периодичность, следующую дату/наработку; toir_maintenance_steps — шаблон регламента. Выпуск наряда идемпотентен для компании, плана и scheduled_for. toir_work_orders содержит текущий статус; toir_work_order_events — аудит; toir_work_order_steps — снимок чек-листа исполнения.

Типовые переходы:
waiting/overdue → in_progress → closed (плановый) либо awaiting_acceptance → closed (аварийный после независимой приёмки). Отклонение приёмки возвращает на доработку. Поля status, actual_start, actual_end, actual_labor_minutes не должны изменяться через обычный PATCH.

### Склад
toir_stock_items — номенклатура и остатки; toir_stock_movements — журнал движения; toir_work_order_materials — резерв и фактические расходы по наряду. Количество имеет три знака после запятой, стоимость — два. Списание и резервирование выполняются транзакционно; незавершённый резерв блокирует закрытие. Наряд с использованными материалами нельзя архивировать.

### Риски
ToirPlanningService.risks — прозрачные правила на основе критичности, состояния, просрочек, наработки и аварийных заявок. Метод в API: transparent_rules_v1. Не трактовать баллы как вероятность отказа или машинное обучение.

### Чат
Собственные toir_chats, toir_chat_messages, toir_chat_receipts и outbox; Reverb обновляет клиентов через каналы с company scope. При потере WebSocket возможен fallback polling. Чат не подключён к БД Portal.

## 6. API и коды ответов

Основные группы /api/v1: auth/*, resources/*, assets/*, work-orders/*, plans/*, operations/*, stock/*, chats/*, company/members*. Проверить актуальный список: php artisan route:list --path=api/v1.

При ожидаемых условиях возвращать HTTP 401/403/404/409/422, а не скрывать проблемы кодом 200. Не смешивать 404 чужой компании и проверку роли. Для новых публичных контрактов API закреплять request/response JSON в feature-тестах.

## 7. Хранение и миграции

Основной production-движок на дату аудита — SQLite. Миграции в database/migrations; они версионированы и должны применяться **только к отдельной БД ТОиР** после подтверждённого бэкапа. Заявленная совместимость с PostgreSQL/MySQL ещё не подтверждена миграционным и нагрузочным стендом. lockForUpdate и многопользовательские сценарии на SQLite требуют отдельного анализа конкурентных записей до масштабирования на большое число компаний.

## 8. Тестирование и критерии новых изменений

php artisan test --compact; php vendor/bin/pint --test --preset laravel. CI проверяет код без production-секретов на SQLite :memory:. При новых изменениях обязательны тесты tenant isolation, матрицы ролей, повторного запроса/idempotency, конфликтов статусов и склада, если затрагивается предметная логика.

Полный QA на отдельной базе выполнен 04.11.2026 (9 QA аккаунтов, 2 компании, 23 API сценария, 53 браузерные проверки и изолированное восстановление SQLite). Артефакты QA лежат только на авторизованном сервере, не являются общими учетными данными.
