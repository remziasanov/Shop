# Хостинг кроме Beget: варианты, настройка деплоя, частые проблемы

Магазин сделан под виртуальный хостинг Beget (ТЗ, раздел 2), но работает на любом хостинге, где есть **PHP 8.3+ с нужными расширениями, MySQL, SSH, cron раз в минуту и HTTPS**. Этот документ помогает выбрать хостинг и перенести на него деплой. Основная инструкция по Beget — [`DEPLOY_BEGET.md`](DEPLOY_BEGET.md): большинство шагов (база, `.env`, администратор, cron, почта) на других хостингах такие же, ниже описаны только отличия.

> Цены — ориентир по открытым источникам на ноябрь 2026 г. (ссылки в конце). Они зависят от срока оплаты и меняются; перед покупкой проверяйте тарифы на сайте провайдера.

## 1. Что выбрать

### 1.1. Два типа хостинга

| | Виртуальный хостинг (как Beget) | VPS/VDS (свой виртуальный сервер) |
|---|---|---|
| Кто обслуживает сервер | провайдер: PHP, MySQL, обновления, безопасность | вы (или нанятый администратор) |
| Цена для этого магазина | ≈ 150–450 ₽/мес | ≈ 430–900 ₽/мес за 1 vCPU / 2 ГБ |
| Сложность настройки | низкая: панель + инструкция | средняя: установить веб-сервер, PHP, MySQL, SSL, обновлять систему |
| Ограничения | общий процессор, лимиты памяти и времени, нельзя демоны | только размер сервера |
| Когда брать | до нескольких тысяч товаров и сотен заказов в день — с запасом хватает | нужен рост, своя настройка, много трафика, тяжёлые импорты |

**Рекомендация:** пока магазин небольшой — виртуальный хостинг (Beget или его аналоги ниже). Переходить на VPS стоит, когда страница «Система» в админке регулярно показывает предупреждения о памяти и времени выполнения или хостинг начинает ограничивать нагрузку.

### 1.2. Виртуальный хостинг — аналоги Beget

| Провайдер | Цена (ориентир) | Тест | Подходит магазину | Что проверить перед покупкой |
|---|---|---|---|---|
| **Beget** (текущий) | от ≈ 200 ₽/мес | 30 дней | да, проверено на практике | — |
| **Timeweb** — ближайший аналог | от ≈ 180 ₽/мес при долгой оплате, ≈ 450 ₽/мес помесячно; домен .ru в подарок при оплате за год | 10 дней | вероятно да: SSH, cron, выбор версии PHP | версию PHP в SSH (как у Beget, консольный PHP может отличаться от PHP сайта), путь к PHP, частоту cron |
| **Sprinthost** — самый дешёвый | от ≈ 90–190 ₽/мес, допуслуги отдельно | 30 дней | вероятно да | SSH и cron на выбранном тарифе, версия PHP 8.3+, расширения `intl` и `zip` |
| **REG.RU** | ≈ 210–270 ₽/мес (Host-A / Host-0), «от 130 ₽» — только при самой долгой предоплате | нет | возможно | SSH на тарифе, можно ли сделать корень сайта отдельной папкой (часто это `~/www/домен`, см. 2.1), MySQL или MariaDB, cron раз в минуту |

**Совет:** у Timeweb и Sprinthost есть бесплатный тестовый период — разверните магазин на тесте по разделу 2 и прогоните `shop:doctor` до оплаты.

### 1.3. VPS — когда нужен свой сервер

Для магазина достаточно **1 vCPU, 2 ГБ RAM, 20–40 ГБ NVMe**, Ubuntu 24.04.

| Провайдер | 1 vCPU / 2 ГБ (ориентир) | Особенности |
|---|---|---|
| **AdminVPS** | ≈ 430–500 ₽/мес | ежедневные бесплатные бэкапы, поддержка 24/7, перенос «под ключ» — лучший баланс для владельца без администратора |
| **FirstVDS** | ≈ 520–600 ₽/мес | давно на рынке, гибкие тарифы, ISPmanager за доплату |
| **Timeweb Cloud** | ≈ 750–900 ₽/мес | удобная панель, снапшоты, всё в одном аккаунте с доменами |
| **Selectel** | дороже, почасовая оплата | крупная инфраструктура, масштабирование; для небольшого магазина избыточен |

**Рекомендация:** если нужен VPS, а администратора нет — **AdminVPS** (бэкапы и помощь с переносом включены); если администратор есть — FirstVDS или Timeweb Cloud.

## 2. Перенос деплоя на другой виртуальный хостинг

Деплой (`.github/workflows/deploy.yml`) работает с любым хостингом по SSH. Секреты по историческим причинам называются `BEGET_*`, но означают просто «SSH-сервер»; менять их имена не нужно.

### 2.1. Подготовка на хостинге

1. Создайте сайт и привяжите домен. Узнайте **папку сайта** — ту, в которой будет лежать магазин (например `~/example.ru`), и **как называется корень сайта** внутри неё — папка, которую веб-сервер отдаёт браузеру (у Beget, Timeweb и Sprinthost обычно `public_html`). Если хостинг отдаёт саму папку сайта (без вложенной), в панели смените корень сайта на вложенную папку `public_html` — так проще всего.
   - **Если корень сайта вида `~/www/example.ru`** (так бывает у REG.RU): **не** указывайте `~/www` как папку магазина. Смените в панели (или через поддержку) корень сайта на отдельную папку, например `~/shop/public_html`, и укажите `BEGET_PATH` = полный путь к `~/shop`.
   - **Важно:** папка `BEGET_PATH` должна принадлежать только магазину. Деплой синхронизирует её с репозиторием и **удаляет всё лишнее** (кроме `.env`, `storage`, корня сайта и резервных копий) — соседние сайты в той же папке будут стёрты.
2. Выберите **PHP 8.3** (или 8.4, 8.5) для сайта.
3. Создайте базу MySQL и пользователя.
4. Включите SSH. Войдите по SSH и найдите консольный PHP нужной версии:
   ```bash
   ls -d /opt/php* /usr/local/php*/ 2>/dev/null
   which -a php php8.3 php83 2>/dev/null
   for p in $(which -a php php8.3 php83 2>/dev/null); do echo "$p => $($p -r 'echo PHP_VERSION;')"; done
   ```
   Нужен путь к PHP 8.3.36+ (или 8.4.27+ / 8.5.12+) — он пойдёт в секрет `BEGET_PHP`.
5. Проверьте расширения и наличие `rsync`:
   ```bash
   /путь/к/php -m | grep -Ei 'intl|gd|zip|bcmath|pdo_mysql|mbstring|fileinfo|openssl|curl'
   command -v rsync || echo "rsync нет — деплой загрузит файлы архивом"
   ```
6. Добавьте SSH-ключ для GitHub в `~/.ssh/authorized_keys` (как в `DEPLOY_BEGET.md`, раздел 4; создавайте ключ на своём компьютере и переносите на сервер одной строкой).

### 2.2. Окружение и секреты в GitHub

1. **Settings → Environments → New environment** — например `timeweb` (или используйте `production`, если переезжаете полностью). Защита: Required reviewers — вы, только ветка `main` (как в `DEPLOY_BEGET.md`, приложение «Безопасность CI»).
2. **Environment secrets:**

| Секрет | Что записать |
|---|---|
| `BEGET_HOST` | адрес SSH-сервера хостинга (из панели, раздел SSH) |
| `BEGET_USER` | логин SSH |
| `BEGET_SSH_KEY` | приватный ключ целиком, со строками `BEGIN`/`END` |
| `BEGET_PATH` | полный путь к папке сайта, например `/home/u/user/example.ru` (без корня сайта на конце) |
| `BEGET_PHP` | путь к консольному PHP из шага 2.1.4 |
| `BEGET_KNOWN_HOSTS` | необязательно: `ssh-keyscan -t ed25519 АДРЕС` |

3. **Environment variables** (не секреты, вкладка рядом):

| Переменная | Значение |
|---|---|
| `DEPLOY_DOCROOT` | имя корня сайта внутри папки сайта: `public_html` (по умолчанию, можно не задавать), `www` или другое; `none` — не создавать ссылку (VPS, раздел 3) |

Деплой создаёт ссылку `<DEPLOY_DOCROOT> → public`; если там уже была обычная папка (заглушка хостинга), она переименовывается в `<имя>.bak-дата`. Имя, совпадающее с папкой самого магазина (`app`, `vendor`, `public`…), деплой отклонит.

4. Добавьте имя окружения в список сайтов в `deploy.yml` (раздел 13 `DEPLOY_BEGET.md`) — через pull request.

### 2.3. Запуск и настройка

Дальше — как на Beget: первый запуск Deploy (только файлы) → `.env` (раздел 6 `DEPLOY_BEGET.md`) → `key:generate` → второй запуск Deploy → `migrate --force --seed` → `shop:create-admin` → две задачи cron (раздел 7) с вашим путём к PHP и папке → SSL и почта (раздел 8; SMTP-сервер берите из почтового раздела панели своего хостинга) → проверка (раздел 9).

## 3. VPS: пошаговая настройка (Ubuntu 24.04)

На VPS всё ставите вы. Ниже минимальный набор; команды выполняются от root (или через `sudo`).

1. **Система и пользователь для деплоя**
   ```bash
   apt update && apt -y upgrade
   adduser --disabled-password --gecos "" deploy
   mkdir -p /var/www/shop && chown deploy:www-data /var/www/shop
   ```
2. **PHP 8.3 с актуальными исправлениями.** Пакеты Ubuntu получают исправления без смены номера версии (показывают, например, 8.3.6), и проверка безопасности деплоя (D-106) их не пропустит. Поставьте PHP из репозитория ondrej/php — там номер версии актуальный:
   ```bash
   add-apt-repository -y ppa:ondrej/php && apt update
   apt -y install nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl \
     php8.3-gd php8.3-zip php8.3-bcmath php8.3-xml php8.3-curl rsync certbot python3-certbot-nginx
   php8.3 -v
   ```
3. **База:** `mysql` → `CREATE DATABASE shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'shop'@'localhost' IDENTIFIED BY 'надёжный_пароль'; GRANT ALL ON shop.* TO 'shop'@'localhost';`
4. **nginx** — `/etc/nginx/sites-available/shop`:
   ```nginx
   server {
       listen 80;
       server_name example.ru www.example.ru;
       root /var/www/shop/public;
       index index.php;
       client_max_body_size 64m;

       location / { try_files $uri $uri/ /index.php?$query_string; }
       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.3-fpm-shop.sock;   # пул из шага 5
       }
       location ~ /\.(?!well-known) { deny all; }
   }
   ```
   ```bash
   ln -s /etc/nginx/sites-available/shop /etc/nginx/sites-enabled/ && rm -f /etc/nginx/sites-enabled/default
   nginx -t && systemctl reload nginx
   certbot --nginx -d example.ru -d www.example.ru
   ```
   Корень сайта сразу указывает на `public`, поэтому в окружении GitHub задайте `DEPLOY_DOCROOT=none`.
5. **Права: отдельный пул PHP-FPM от пользователя `deploy`.** Файлы загружает и cron запускает `deploy`, поэтому и сайт должен работать от него — иначе файлы кэша и логов, созданные сайтом, нельзя будет удалить при деплое, и наоборот. Создайте `/etc/php/8.3/fpm/pool.d/shop.conf`:
   ```ini
   [shop]
   user = deploy
   group = www-data
   listen = /run/php/php8.3-fpm-shop.sock
   listen.owner = www-data
   listen.group = www-data
   pm = ondemand
   pm.max_children = 8
   php_admin_value[upload_max_filesize] = 64M
   php_admin_value[post_max_size] = 64M
   php_admin_value[memory_limit] = 256M
   php_admin_value[max_execution_time] = 60
   ```
   Выполните `systemctl restart php8.3-fpm`. Лимиты загрузки в пуле совпадают с `client_max_body_size 64m` в nginx (по умолчанию PHP принимает только 2–8 МБ).
6. **SSH-ключ** для GitHub — в `/home/deploy/.ssh/authorized_keys`. Секреты: `BEGET_HOST` = IP или домен сервера, `BEGET_USER` = `deploy`, `BEGET_PATH` = `/var/www/shop`, `BEGET_PHP` = `/usr/bin/php8.3`.
7. **Cron** — `crontab -e -u deploy`, те же две строки, что в разделе 7 `DEPLOY_BEGET.md`, с путями `/usr/bin/php8.3` и `/var/www/shop/artisan`.
8. **Защита:** `ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable`; вход по паролю для SSH отключите (`PasswordAuthentication no` в `/etc/ssh/sshd_config`); включите автоматические обновления (`apt -y install unattended-upgrades`).
9. **Резервные копии:** если провайдер не делает их сам (у AdminVPS — делает), добавьте ежедневный `mysqldump` в cron и храните копии вне сервера.

## 4. Частые проблемы и решения

| Симптом | Причина | Решение |
|---|---|---|
| `Load key …: error in libcrypto` | приватный ключ в секрете повреждён при копировании | скопируйте командой (`Get-Content … -Raw \| Set-Clipboard` на Windows), не мышью из терминала |
| `Permission denied (publickey,password)` | публичного ключа нет в `~/.ssh/authorized_keys` на сервере | добавьте одной строкой и проверьте вход `ssh -i ключ user@host "echo ok"` без пароля |
| Ключ «пропадает» после открытия веб-терминала хостинга | веб-терминал панели перезаписывает `authorized_keys` | работайте по SSH с компьютера; при повторе — добавьте ключ снова |
| Деплой: «PHP … с известными уязвимостями» | консольный PHP хостинга старее исправленной версии (D-106) | выберите другую версию или путь в `BEGET_PHP`; если хостинг подтвердил, что PHP сайта исправлен, а обновить консольный не может, — флажок `allow_vulnerable_php` при запуске |
| `rsync … failed to set times/permissions … code 23` | папка сайта принадлежит хостингу | уже учтено в `deploy.yml` (`rsync -rlzt --omit-dir-times`) |
| Сайт показывает заглушку хостинга | неверный `DEPLOY_DOCROOT` или корень сайта в панели указывает не туда | проверьте `ls -l` в папке сайта: должна быть ссылка `<корень> -> public` |
| 500 сразу после деплоя, в логе `open_basedir` | хостинг запрещает PHP выходить за корень сайта, а код магазина лежит уровнем выше | в панели отключите `open_basedir` для сайта или расширьте его на папку сайта; иначе — VPS |
| 403 / ссылки не открываются | веб-сервер не следует символическим ссылкам | включите FollowSymLinks в панели или смените корень сайта на папку `public` напрямую (`DEPLOY_DOCROOT=none`) |
| Вход в админку «сбрасывается» на форму | сайт открыт по `http://`, а `SESSION_SECURE_COOKIE=true` | включите SSL и открывайте по `https://` |
| Письма не уходят / в спаме | отправка с чужого домена (`@gmail.com`) через SMTP хостинга | ящик на своём домене у этого же хостинга (SPF/DKIM) или SMTP самого почтового сервиса с паролем приложения |
| Telegram или провайдер SMS не отвечают | хостинг блокирует исходящие соединения | уточните в поддержке разрешение исходящего HTTPS (443) |
| Ошибки при миграциях на MariaDB | проект проверяется в CI на MySQL 8.0 (и совместим с 5.7); MariaDB похожа, но не тестируется | выбирайте тариф с MySQL; при MariaDB 10.6+ прогоните `migrate` на тестовом периоде |
| `shop:doctor`: мало памяти / время выполнения | лимиты тарифа | увеличьте `memory_limit` (≥ 256M) и `max_execution_time` в настройках PHP сайта; при постоянной нехватке — старший тариф или VPS |
| Превышен лимит файлов (inodes) | `vendor` и фото занимают десятки тысяч файлов | выберите тариф с запасом по числу файлов; старые фото удаляет `shop:cleanup` |

## 5. Источники

- [vc.ru — аналоги Beget в России, лучшие VPS-провайдеры](https://vc.ru/top-raiting/2879985-analogi-beget-v-rossii-luchshie-vps-provajdery)
- [toolfox.ru — альтернативы Beget](https://toolfox.ru/services/s/beget/alternatives)
- [a2is.ru — альтернативы Beget](https://a2is.ru/catalog/khosting-dlya-sajta/Beget/alternatives-Beget)
- [Sostav — ТОП хостингов 2026](https://www.sostav.ru/blogs/292766/100644)
- [WebAsk — 8 российских провайдеров, цены и тесты](https://webask.io/blog/hosting-dlya-sayta)
- [Vakas — Timeweb или Reg.ru, 2026](https://vakas.ru/articles/timeweb-ili-reg-ru/)
- [vpsindex.ru — каталог VPS-тарифов 2026](https://vpsindex.ru/tarify)
- [hostinghub.ru — дешёвые VPS 2026](https://hostinghub.ru/top/vds/desheviy)
- [DTF — лучшие дешёвые VPS 2026](https://dtf.ru/top-raiting/4767952-luchshie-deshevye-vps-vds)
