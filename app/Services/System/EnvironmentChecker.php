<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Enums\CheckStatus;
use App\Services\Content\LegalPageStatus;
use App\Support\TrustedHosts;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Environment report for `shop:doctor` and the «Система» page (TZ 12.4).
 * Every check is isolated: an exception becomes an «error» result.
 */
class EnvironmentChecker
{
    private const MIN_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const MIN_MEMORY_BYTES = 128 * 1024 * 1024;

    private const MIN_EXECUTION_SECONDS = 30;

    private const PHOTOS_SIZE_CACHE_KEY = 'system.photos_size';

    private const PHOTOS_SIZE_TTL_SECONDS = 600;

    private const EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'intl', 'gd', 'zip', 'fileinfo',
        'bcmath', 'openssl', 'curl', 'xml', 'ctype', 'tokenizer',
    ];

    public function __construct(private readonly Heartbeat $heartbeat) {}

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        $checks = [
            'php_version' => fn () => $this->phpVersion(),
        ];

        foreach (self::EXTENSIONS as $extension) {
            $checks['ext_'.$extension] = fn () => $this->extension($extension);
        }

        $checks += [
            'gd_webp' => fn () => $this->webp(),
            'storage_writable' => fn () => $this->writable('storage_writable', storage_path()),
            'cache_writable' => fn () => $this->writable('cache_writable', base_path('bootstrap/cache')),
            'storage_link' => fn () => $this->storageLink(),
            'app_key' => fn () => $this->appKey(),
            'app_debug' => fn () => $this->appDebug(),
            'app_url' => fn () => $this->appUrl(),
            'trusted_hosts' => fn () => $this->trustedHosts(),
            'session_secure' => fn () => $this->sessionSecure(),
            'cache_store' => fn () => $this->cacheStore(),
            'queue_connection' => fn () => $this->queueConnection(),
            'log_level' => fn () => $this->logLevel(),
            'mail_mailer' => fn () => $this->mailMailer(),
            'mail_from' => fn () => $this->mailFrom(),
            'mail_host' => fn () => $this->mailHost(),
            'database' => fn () => $this->database(),
            'queue_pending' => fn () => $this->queuePending(),
            'queue_failed' => fn () => $this->queueFailed(),
            'scheduler' => fn () => $this->scheduler(),
            'upload_max_filesize' => fn () => $this->uploadLimit('upload_max_filesize'),
            'post_max_size' => fn () => $this->uploadLimit('post_max_size'),
            'memory_limit' => fn () => $this->memoryLimit(),
            'max_execution_time' => fn () => $this->maxExecutionTime(),
            'photos_size' => fn () => $this->photosSize(),
            'legal_pages' => fn () => $this->legalPages(),
            'staff_roles' => fn () => $this->staffRoles(),
        ];

        $results = [];

        foreach ($checks as $key => $check) {
            $results[] = $this->safely($key, $check);
        }

        return $results;
    }

    /**
     * @param  Closure(): CheckResult  $check
     */
    private function safely(string $key, Closure $check): CheckResult
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return $this->result($key, CheckStatus::Error, $e->getMessage());
        }
    }

    private function result(string $key, CheckStatus $status, string $value, ?string $hint = null): CheckResult
    {
        return new CheckResult($key, __('system.checks.'.$key), $status, $value, $hint);
    }

    private function phpVersion(): CheckResult
    {
        $ok = version_compare(PHP_VERSION, '8.3.0', '>=');

        return $this->result('php_version', $ok ? CheckStatus::Ok : CheckStatus::Error, PHP_VERSION,
            $ok ? null : __('system.hints.php_version'));
    }

    private function extension(string $name): CheckResult
    {
        $loaded = extension_loaded($name);

        return new CheckResult('ext_'.$name, __('system.checks.extension', ['name' => $name]),
            $loaded ? CheckStatus::Ok : CheckStatus::Error,
            $loaded ? __('system.values.enabled') : __('system.values.missing'),
            $loaded ? null : __('system.hints.extension', ['name' => $name]));
    }

    private function webp(): CheckResult
    {
        $supported = function_exists('gd_info') && (gd_info()['WebP Support'] ?? false);

        return $this->result('gd_webp', $supported ? CheckStatus::Ok : CheckStatus::Warning,
            $supported ? __('system.values.enabled') : __('system.values.missing'),
            $supported ? null : __('system.hints.webp'));
    }

    private function writable(string $key, string $path): CheckResult
    {
        $ok = is_dir($path) && is_writable($path);

        return $this->result($key, $ok ? CheckStatus::Ok : CheckStatus::Error,
            $ok ? __('system.values.writable') : __('system.values.not_writable'),
            $ok ? null : __('system.hints.writable', ['path' => $path]));
    }

    private function storageLink(): CheckResult
    {
        $path = public_path('storage');
        $ok = is_link($path) || is_dir($path);

        return $this->result('storage_link', $ok ? CheckStatus::Ok : CheckStatus::Error,
            $ok ? __('system.values.exists') : __('system.values.missing'),
            $ok ? null : __('system.hints.storage_link'));
    }

    private function appKey(): CheckResult
    {
        $ok = (string) config('app.key') !== '';

        return $this->result('app_key', $ok ? CheckStatus::Ok : CheckStatus::Error,
            $ok ? __('system.values.set') : __('system.values.not_set'),
            $ok ? null : __('system.hints.app_key'));
    }

    private function appDebug(): CheckResult
    {
        if (! config('app.debug')) {
            return $this->result('app_debug', CheckStatus::Ok, __('system.values.off'));
        }

        $production = app()->environment('production');

        return $this->result('app_debug', $production ? CheckStatus::Error : CheckStatus::Warning,
            __('system.values.on'), __('system.hints.app_debug'));
    }

    private function appUrl(): CheckResult
    {
        $url = (string) config('app.url');
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (TrustedHosts::canonical() === null) {
            return $this->result('app_url', CheckStatus::Error, $url === '' ? __('system.values.not_set') : $url,
                __('system.hints.app_url_invalid'));
        }

        if ($this->isProduction() && $scheme !== 'https') {
            return $this->result('app_url', CheckStatus::Warning, $url, __('system.hints.app_url_https'));
        }

        return $this->result('app_url', CheckStatus::Ok, $url);
    }

    private function trustedHosts(): CheckResult
    {
        $canonical = TrustedHosts::canonical();
        $hosts = $canonical === null ? [] : [$canonical, TrustedHosts::twin($canonical)];

        foreach ((array) config('shop.extra_hosts', []) as $extra) {
            if (is_string($extra) && trim($extra) !== '') {
                $hosts[] = trim($extra);
            }
        }

        return $this->result('trusted_hosts', CheckStatus::Ok,
            $hosts === [] ? __('system.values.not_set') : implode(', ', $hosts),
            __('system.hints.trusted_hosts'));
    }

    private function sessionSecure(): CheckResult
    {
        $secure = (bool) config('session.secure');

        if (! $secure && $this->isProduction()) {
            return $this->result('session_secure', CheckStatus::Warning, __('system.values.off'), __('system.hints.session_secure'));
        }

        return $this->result('session_secure', CheckStatus::Ok, $secure ? __('system.values.on') : __('system.values.off'));
    }

    private function cacheStore(): CheckResult
    {
        $store = (string) config('cache.default');
        $driver = (string) config('cache.stores.'.$store.'.driver', $store);

        if (in_array($driver, ['redis', 'memcached'], true)) {
            return $this->result('cache_store', CheckStatus::Error, $store, __('system.hints.cache_store'));
        }

        return $this->result('cache_store', CheckStatus::Ok, $store);
    }

    private function queueConnection(): CheckResult
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'database') {
            return $this->result('queue_connection', CheckStatus::Warning, $connection, __('system.hints.queue_connection'));
        }

        return $this->result('queue_connection', CheckStatus::Ok, $connection);
    }

    private function logLevel(): CheckResult
    {
        $level = $this->logChannelLevel();

        if ($this->isProduction() && in_array(strtolower($level), ['debug', 'info', 'notice'], true)) {
            return $this->result('log_level', CheckStatus::Warning, $level, __('system.hints.log_level'));
        }

        return $this->result('log_level', CheckStatus::Ok, $level);
    }

    private function logChannelLevel(): string
    {
        $default = (string) config('logging.default');
        $channel = $default === 'stack'
            ? (string) (config('logging.channels.stack.channels')[0] ?? 'single')
            : $default;

        return (string) config('logging.channels.'.$channel.'.level', 'debug');
    }

    private function mailMailer(): CheckResult
    {
        $mailer = (string) config('mail.default');

        if ($this->isProduction() && in_array($mailer, ['log', 'array'], true)) {
            return $this->result('mail_mailer', CheckStatus::Warning, $mailer, __('system.hints.mail_mailer'));
        }

        return $this->result('mail_mailer', CheckStatus::Ok, $mailer);
    }

    private function mailFrom(): CheckResult
    {
        $from = trim((string) config('mail.from.address'));

        if ($from === '') {
            return $this->result('mail_from', $this->isProduction() ? CheckStatus::Error : CheckStatus::Warning,
                __('system.values.not_set'), __('system.hints.mail_from'));
        }

        return $this->result('mail_from', CheckStatus::Ok, $from);
    }

    private function mailHost(): CheckResult
    {
        if (config('mail.default') !== 'smtp') {
            return $this->result('mail_host', CheckStatus::Ok, __('system.values.not_required'));
        }

        $host = trim((string) config('mail.mailers.smtp.host'));

        if ($host === '') {
            return $this->result('mail_host', CheckStatus::Error, __('system.values.not_set'), __('system.hints.mail_host'));
        }

        return $this->result('mail_host', CheckStatus::Ok, $host);
    }

    private function isProduction(): bool
    {
        return app()->environment('production');
    }

    private function database(): CheckResult
    {
        $connection = DB::connection();
        $connection->getPdo();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return $this->result('database', CheckStatus::Ok,
                'SQLite '.$connection->selectOne('select sqlite_version() as v')->v,
                __('system.hints.sqlite'));
        }

        $version = (string) $connection->selectOne('select version() as v')->v;
        $isMariaDb = stripos($version, 'mariadb') !== false;
        $label = self::databaseLabel($driver, $version);
        $number = preg_match('/\d+\.\d+(\.\d+)?/', $version, $m) ? $m[0] : '0';
        $ok = $isMariaDb || version_compare($number, '8.0', '>=');

        return $this->result('database', $ok ? CheckStatus::Ok : CheckStatus::Warning,
            $label.' '.$version,
            $ok ? null : __('system.hints.mysql_version'));
    }

    /**
     * Human-readable engine name derived from the driver and `version()` string.
     */
    public static function databaseLabel(string $driver, string $version): string
    {
        return match (true) {
            $driver === 'sqlite' => 'SQLite',
            $driver === 'mariadb', stripos($version, 'mariadb') !== false => 'MariaDB',
            $driver === 'mysql' => 'MySQL',
            default => $driver,
        };
    }

    private function queuePending(): CheckResult
    {
        if (! Schema::hasTable('jobs')) {
            return $this->result('queue_pending', CheckStatus::Error, __('system.values.no_table'), __('system.hints.migrate'));
        }

        return $this->result('queue_pending', CheckStatus::Ok, (string) DB::table('jobs')->count());
    }

    private function queueFailed(): CheckResult
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->result('queue_failed', CheckStatus::Error, __('system.values.no_table'), __('system.hints.migrate'));
        }

        $count = DB::table('failed_jobs')->count();

        return $this->result('queue_failed', $count > 0 ? CheckStatus::Warning : CheckStatus::Ok, (string) $count,
            $count > 0 ? __('system.hints.failed_jobs') : null);
    }

    private function scheduler(): CheckResult
    {
        $last = $this->heartbeat->lastRun();

        if ($last === null) {
            return $this->result('scheduler', CheckStatus::Warning, __('system.values.never'), __('system.hints.cron'));
        }

        $stale = $this->heartbeat->isStale();

        return $this->result('scheduler', $stale ? CheckStatus::Warning : CheckStatus::Ok,
            $last->setTimezone((string) config('app.timezone'))->format('d.m.Y H:i:s'),
            $stale ? __('system.hints.cron') : null);
    }

    private function legalPages(): CheckResult
    {
        $pending = app(LegalPageStatus::class)->pendingTitles();

        if ($pending === []) {
            return $this->result('legal_pages', CheckStatus::Ok, __('system.values.legal_done'));
        }

        return $this->result('legal_pages', CheckStatus::Warning, __('system.values.legal_template'),
            __('system.hints.legal_pages', ['pages' => implode(', ', $pending)]));
    }

    /**
     * RB-14: at least one active administrator and every user has a role.
     */
    private function staffRoles(): CheckResult
    {
        $label = __('roles.doctor.label');
        $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id');
        $admins = $adminRoleId === null ? 0 : DB::table('users')->where('is_active', true)->where('role_id', $adminRoleId)->count();

        if ($admins === 0) {
            return new CheckResult('staff_roles', $label, CheckStatus::Error, __('roles.doctor.no_admin'), __('roles.doctor.hint_no_admin'));
        }

        $withoutRole = DB::table('users')->whereNull('role_id')->count();

        if ($withoutRole > 0) {
            return new CheckResult('staff_roles', $label, CheckStatus::Error, __('roles.doctor.no_role', ['count' => $withoutRole]), __('roles.doctor.hint_no_role'));
        }

        return new CheckResult('staff_roles', $label, CheckStatus::Ok, __('roles.doctor.ok'));
    }

    private function uploadLimit(string $key): CheckResult
    {
        $raw = $this->iniValue($key);
        $bytes = $this->toBytes($raw);
        // -1 and 0 (post_max_size) mean unlimited.
        $low = $bytes > 0 && $bytes < self::MIN_UPLOAD_BYTES;

        return $this->result($key, $low ? CheckStatus::Warning : CheckStatus::Ok, $raw,
            $low ? __('system.hints.upload_limit') : null);
    }

    private function maxExecutionTime(): CheckResult
    {
        $seconds = (int) $this->iniValue('max_execution_time');
        $low = $seconds > 0 && $seconds < self::MIN_EXECUTION_SECONDS;

        return $this->result('max_execution_time', $low ? CheckStatus::Warning : CheckStatus::Ok, (string) $seconds,
            $low ? __('system.hints.max_execution_time') : null);
    }

    private function memoryLimit(): CheckResult
    {
        $raw = $this->iniValue('memory_limit');
        $bytes = $this->toBytes($raw);
        $low = $bytes !== -1 && $bytes < self::MIN_MEMORY_BYTES;

        return $this->result('memory_limit', $low ? CheckStatus::Warning : CheckStatus::Ok, $raw,
            $low ? __('system.hints.memory_limit') : null);
    }

    protected function iniValue(string $key): string
    {
        return (string) ini_get($key);
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function photosSize(): CheckResult
    {
        /** @var array{bytes: int, at: int} $cached */
        $cached = Cache::remember(self::PHOTOS_SIZE_CACHE_KEY, self::PHOTOS_SIZE_TTL_SECONDS, fn (): array => [
            'bytes' => $this->directorySize(storage_path('app/public/products')),
            'at' => now()->timestamp,
        ]);

        $time = Carbon::createFromTimestamp($cached['at'])->setTimezone((string) config('app.timezone'))->format('H:i');

        return $this->result('photos_size', CheckStatus::Ok,
            $this->humanSize($cached['bytes']).' ('.__('system.values.calculated_at', ['time' => $time]).')');
    }

    private function directorySize(string $dir): int
    {
        $bytes = 0;

        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        }

        return $bytes;
    }

    private function humanSize(int $bytes): string
    {
        $units = array_values((array) __('system.units'));
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($size, 1, ',', ' ')).' '.$units[$i];
    }
}
