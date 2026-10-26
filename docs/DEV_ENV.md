# Окружение разработки

Проверка выполнена 2026-10-26 в песочнице Claude Code. Требования — по приложению C ТЗ (`docs/TZ.html`).

## Версии инструментов

Команды:

```bash
php -v
php -m
composer -V
node -v
npm -v
mysql --version
```

Результаты:

| Инструмент | Версия | Соответствие |
|---|---|---|
| PHP (cli, NTS) | 8.3.6, Zend Engine v4.3.6 | ≥ 8.3 — OK |
| Composer | 2.8.12 | OK |
| Node.js | v22.22.0 | Node 22 — как в деплое (12.3) — OK |
| npm | 10.9.4 | OK |
| MySQL (клиент) | 8.0.46 (Ubuntu) | тесты на MySQL 8.0 — OK |

При запуске `composer -V` от root выводится предупреждение о плагинах Composer; см. раздел «Composer в песочнице».

## Расширения PHP

Обязательные по приложению C: `pdo_sqlite`, `pdo_mysql`, `intl`, `gd`, `zip`, `bcmath`.

Команда:

```bash
php -m
```

| Расширение | Статус | Примечание |
|---|---|---|
| pdo_sqlite | присутствует | тесты на SQLite in-memory |
| pdo_mysql | присутствует | |
| intl | присутствует | |
| gd | присутствует | WebP — см. ниже |
| zip | присутствует | |
| bcmath | присутствует | установлен setup-скриптом песочницы |

Остальные расширения из раздела 12.4 (mbstring, fileinfo, openssl, curl, xml, ctype, tokenizer) тоже присутствуют в `php -m`.

Поддержка WebP в GD (нужна для обработки фото, AD-51):

```bash
php -r 'var_dump(gd_info()["WebP Support"] ?? false);'
```

Результат: `bool(true)` — WebP поддерживается.

## MySQL в песочнице

Запуск сервера:

```bash
service mysql start
```

Параметры для локальной работы и тестов:

- база данных: `shop`;
- пользователь: `shop`, пароль: `shop` — только для песочницы, в репозиторий и сидеры не попадают;
- тестовая база: `shop_test`.

## Composer в песочнице

- Загрузка dist-архивов с GitHub через прокси песочницы возвращает 403, поэтому Composer переходит на `git clone`. Установка пакетов идёт заметно медленнее.
- Composer отказывается работать от root без разрешения. При запуске от root указываем переменную:

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install
```

## GitHub CLI (`gh`)

- GraphQL-запросы через `gh` в песочнице заблокированы.
- Использовать `gh api` (REST), например: `gh api repos/OWNER/REPO/pulls`.

## Итог

Окружение готово к разработке: версии PHP, Composer и Node соответствуют ТЗ, все обязательные расширения присутствуют, GD поддерживает WebP, MySQL 8.0 доступен через `service mysql start`.

## Обход 403 для dist-only пакетов (phpstan/phpstan)
`phpstan/phpstan` публикуется только zip-архивом с GitHub (без source), прокси песочницы отдаёт 403 → Composer пишет «Could not authenticate against github.com». Обход: клонировать тег и положить zip в кэш Composer под ключом `sha1(dist-url)`:
```bash
git clone --depth 1 --branch <tag> https://github.com/phpstan/phpstan /tmp/x/phpstan-src && rm -rf /tmp/x/phpstan-src/.git
(cd /tmp/x && zip -qr phpstan.zip phpstan-src)
URL=$(composer show -a phpstan/phpstan <tag> | awk '/^dist/{print $3}')
mkdir -p ~/.cache/composer/files/phpstan/phpstan && cp /tmp/x/phpstan.zip ~/.cache/composer/files/phpstan/phpstan/$(php -r "echo sha1('$URL');").zip
```
Также помогает `composer config -g use-github-api false`. В GitHub Actions этих проблем нет.

## Демо-данные
Демо-данные: `php artisan shop:demo` (флаги: `--fresh` — сначала удалить демо-данные, `--no-images` — без фото, `--limit=N` — только первые N товаров, `--force` — разрешить в production). В `DatabaseSeeder` не вызывается.
