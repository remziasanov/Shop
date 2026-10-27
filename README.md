# Shop: интернет-магазин на Laravel 13 и Filament 5

Интернет-магазин для виртуального хостинга Beget. Витрина написана на Blade, Tailwind CSS 4, Alpine.js и Livewire, админ-панель — на Filament 5. Фоновые задачи (планировщик и очередь) работают без демонов: через CronTab Beget и очередь в базе данных. Требования к проекту описаны в `docs/TZ.html`.

## Возможности

По разделам ТЗ:

- **Витрина (раздел 5):** главная страница с настраиваемыми блоками, каталог с категориями, фильтрами и сортировкой, поиск с подсказками, карточка товара, режимы продаж, корзина и оформление заказа, резервирование товара, заявка-запрос, страницы политики и согласия (152-ФЗ), SEO (человекопонятные адреса, редиректы со старых адресов, `sitemap.xml`, `robots.txt`).
- **Админ-панель (раздел 6):** дашборд, настройки магазина, категории, товары и фотографии (сжатие в WebP), ценообразование (наценки, скидки, правила по периодам), заказы и заявки, импорт товаров из Excel с фотографиями из ZIP, экспорт каталога в Excel, страницы и меню, пользователи с ролями «администратор» и «менеджер», страница «Система» с проверкой окружения.
- **Уведомления (раздел 9):** о новых заказах, резервах и заявках — по email и в Telegram.
- **Фоновые задачи (раздел 8):** резервы, пересчёт цен, карта сайта, очистка и уведомления выполняются по расписанию.

## Требования

- PHP 8.3 или новее (расширения: `pdo_mysql`, `pdo_sqlite`, `mbstring`, `intl`, `gd` с поддержкой WebP, `zip`, `fileinfo`, `bcmath`, `openssl`, `curl`, `xml`, `ctype`, `tokenizer`).
- Composer 2.
- Node.js 22 и npm — только для сборки фронтенда. На сервере Node.js не нужен: сборка выполняется в GitHub Actions.
- MySQL 8.0 в продакшене (на Beget); для локального запуска и тестов достаточно SQLite.

## Локальный запуск

Инструкция для быстрого старта на SQLite.

```bash
git clone <адрес репозитория> shop
cd shop
composer install
npm ci && npm run build
cp .env.example .env
```

Откройте `.env` и измените значения для локальной работы:

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false
DB_CONNECTION=sqlite
```

Строку `DB_DATABASE=...` из `.env.example` **удалите** (или закомментируйте): без неё Laravel использует файл `database/database.sqlite`. Если оставить `DB_DATABASE=login_shop`, команда `migrate` предложит создать файл `login_shop` в корне проекта. Можно вместо удаления указать абсолютный путь, например `DB_DATABASE=/полный/путь/к/shop/database/database.sqlite`. Правка одной командой:

```bash
sed -i '/^DB_DATABASE=/d' .env
```

`SESSION_SECURE_COOKIE=false` обязателен на `http://127.0.0.1`: с `true` браузер не сохранит cookie, и вход в админ-панель завершится ошибкой 419.

Затем выполните команды:

```bash
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan shop:demo
php artisan storage:link
php artisan shop:create-admin
php artisan serve
```

- `shop:demo` создаёт демонстрационный каталог с вымышленными товарами и сгенерированными фотографиями.
- `shop:create-admin` спрашивает имя, адрес электронной почты и пароль (не менее 10 символов). Для локальной демонстрации укажите, например, `admin@example.com` и пароль, который вы придумали. Учётные данные не коммитятся в репозиторий и не предназначены для продакшена.

После `php artisan serve` витрина открывается по адресу `http://127.0.0.1:8000`, админ-панель — по адресу `http://127.0.0.1:8000/admin` (путь задаётся переменной `ADMIN_PATH`).

Фоновые задачи локально. Очередь можно обработать разово, планировщик можно запускать в отдельном окне терминала:

```bash
php artisan queue:work --stop-when-empty
```

```bash
php artisan schedule:work
```

## Проверки

Перед коммитом и в CI запускаются:

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm run build
```

Проверка миграций на MySQL 8.0 (нужен локальный сервер MySQL, настройки песочницы — в `docs/DEV_ENV.md`):

```bash
DB_CONNECTION=mysql php artisan migrate:fresh --seed
```

Исправить стиль кода автоматически можно командой `vendor/bin/pint` (без `--test`).

## Структура проекта

- `app/Enums` — перечисления (статусы заказов, режимы продаж и т. д.), хранятся в базе как строки.
- `app/Models` — модели Eloquent.
- `app/Services` — бизнес-логика по направлениям: `Pricing`, `Sales`, `Cart`, `Orders`, `Stock`, `Import`, `Export`, `Media`, `Notifications`. Блокировка и изменение остатков — только через `StockService`.
- `app/Jobs` — задачи очереди.
- `app/Settings` — настройки магазина (spatie/laravel-settings), значения по умолчанию — в миграциях настроек.
- `app/Filament` — ресурсы и страницы админ-панели.
- `app/Livewire` и `app/Http/Controllers/Storefront` — интерактивные компоненты и контроллеры витрины.
- `app/Console/Commands` — команды `shop:*`.
- `resources/views/storefront` — шаблоны витрины, `resources/views/mail` — письма.
- `lang/ru` — все тексты интерфейса (`__('shop.…')`).
- `database/migrations`, `database/seeders`, `database/factories` — схема базы, начальные данные и фабрики для тестов.
- `examples/` — шаблон импорта и демонстрационные файлы (`import-demo.xlsx`, `import-demo-photos.zip`).
- `tests/` — тесты на Pest.
- `.github/workflows/` — `ci.yml` (проверки и тесты) и `deploy.yml` (ручной деплой на Beget).

## Документация

- `docs/TZ.html` — техническое задание: единственный источник требований.
- `docs/PROGRESS.md` — ход работ по этапам.
- `docs/DECISIONS.md` — журнал решений: контекст, выбор и отвергнутые варианты.
- `docs/DEV_ENV.md` — окружение разработки и песочница.
- `docs/DEPLOY_BEGET.md` — пошаговое развёртывание на Beget для владельца магазина.
- `CLAUDE.md` — правила работы с кодом и конвенции проекта.

## Деплой

Развёртывание на Beget описано в [docs/DEPLOY_BEGET.md](docs/DEPLOY_BEGET.md): регистрация и тариф, сайт и PHP 8.3, база MySQL, SSH-ключ, секреты GitHub, первоначальная настройка `.env`, две задачи CronTab, SSL и почта, проверка, обновление, откат, резервные копии и частые проблемы. Деплой запускается вручную: в GitHub Actions выберите workflow **Deploy**.

## Команды artisan `shop:*`

Список команд — `php artisan list shop`.

| Команда | Описание | Опции |
|---|---|---|
| `shop:cleanup` | Удаление старых корзин, файлов импорта и экспорта и брошенных загрузок (раздел 8 ТЗ) | — |
| `shop:create-admin` | Создание администратора (AD-02) | `--name`, `--email`, `--password` (не менее 10 символов) |
| `shop:demo` | Создание демонстрационного каталога: категории, товары и сгенерированные фотографии | `--force` (разрешить в production), `--fresh` (удалить старые демо-данные), `--no-images`, `--limit=N` |
| `shop:doctor` | Проверка окружения сервера (TZ 12.4) | `--json` (машиночитаемый вывод) |
| `shop:expire-reservations` | Снятие просроченных резервов и возврат товара на склад (SF-53) | — |
| `shop:heartbeat` | Запись отметки времени работы планировщика (AD-11) | — |
| `shop:make-demo-import` | Создание демонстрационного файла импорта (около 50 вымышленных товаров) и ZIP-архива с фото | `--dir=examples` |
| `shop:make-template` | Создание шаблона импорта товаров (.xlsx) | `--path=examples/import-template.xlsx` |
| `shop:notify-expiring-reservations` | Telegram-предупреждение о резервах, истекающих в течение 6 часов, один раз на резерв (AD-10) | — |
| `shop:recalc-prices` | Пересчёт цен товаров в фоне (PR-02) | `--all` (все товары), `--boundaries` (правила, начавшиеся или закончившиеся), `--sync` (сразу, без очереди) |
| `shop:sitemap` | Создание `public/sitemap.xml` (SF-82) | — |
