<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * لایهٔ انتزاعیِ «ری‌استارت سرویس» برای تست‌های Chaos.
 *
 * ---------------------------------------------------------------------------
 * چرا این کلاس وجود دارد
 * ---------------------------------------------------------------------------
 * تست‌های Chaos باید رفتار *محصول* را هنگام از دست رفتن یک سرویس زیرساختی
 * بسنجند. اما پیش از این، تست به‌صورت مستقیم به
 *
 *     exec('sudo service redis-server restart')
 *
 * وابسته بود؛ یعنی به یک جزئیاتِ کاملاً محیطی: اینکه Redis حتماً به‌شکل یک
 * unit در systemd نصب شده باشد و کاربر تست هم sudo بدون رمز داشته باشد.
 * روی هر محیطی که Redis دستی یا داخل کانتینر اجرا شود — که حالت رایج CI است —
 * این تست شکست می‌خورد، در حالی که **هیچ باگی در محصول وجود ندارد**. چنین
 * شکستی نوفه است: اعتماد به سوئیت را از بین می‌برد بی‌آنکه اطلاعاتی بدهد.
 *
 * راه‌حل، جدا کردن «چگونه سرویس ری‌استارت می‌شود» از «محصول پس از ری‌استارت
 * چه رفتاری دارد» است. استراتژی‌ها به ترتیب امتحان می‌شوند و نخستین موردِ
 * در دسترس انتخاب می‌شود:
 *
 *   ۱. override صریح  — متغیر محیطی CHAOS_REDIS_RESTART_CMD
 *   ۲. systemctl       — اگر unit واقعاً موجود باشد
 *   ۳. service         — اگر اسکریپت SysV موجود باشد
 *   ۴. process         — کشتن پروسهٔ redis-server و اجرای دوبارهٔ همان argv
 *   ۵. هیچ‌کدام        — skip با پیام صریح و مستند
 *
 * استراتژی «process» همان تضمینی را فراهم می‌کند که تست واقعاً به آن نیاز
 * دارد: قطع اتصال‌های زنده و از دست رفتن وضعیتِ فرّار. رفتار محصول (شمارندهٔ
 * fence پایدار، رد کردن fence کهنه) دقیقاً مثل حالت systemd آزموده می‌شود.
 *
 * ---------------------------------------------------------------------------
 * آنچه این کلاس عمداً انجام نمی‌دهد
 * ---------------------------------------------------------------------------
 * ری‌استارت را شبیه‌سازی *نمی‌کند*. اگر هیچ استراتژی کارآمدی موجود نباشد، تست
 * skip می‌شود — نه اینکه با «موفقیت» جعلی سبز شود. یک تست chaos که در واقع
 * چیزی را از کار نینداخته، بدتر از تستِ نبود است.
 */
final class ServiceRestarter
{
    /** @var callable(string):array{0:int,1:string} */
    private $runner;

    /**
     * @param callable(string):array{0:int,1:string}|null $runner
     *        اجراکنندهٔ فرمان؛ برای تست خودِ این کلاس قابل تزریق است.
     *        باید [exitCode, output] برگرداند.
     */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? static function (string $command): array {
            $output = [];
            $code = 0;
            exec($command . ' 2>&1', $output, $code);
            return [$code, implode("\n", $output)];
        };
    }

    /**
     * نام استراتژی‌ای که برای این سرویس قابل استفاده است، یا null.
     *
     * @return 'override'|'systemctl'|'service'|'process'|null
     */
    public function availableStrategy(string $service): ?string
    {
        if ($this->overrideCommand($service) !== null) {
            return 'override';
        }
        if ($this->hasSystemdUnit($service)) {
            return 'systemctl';
        }
        if ($this->hasSysVScript($service)) {
            return 'service';
        }
        if ($this->findProcess($service) !== null) {
            return 'process';
        }
        return null;
    }

    /**
     * سرویس را ری‌استارت می‌کند.
     *
     * @return array{ok:bool,strategy:string,output:string}
     */
    public function restart(string $service): array
    {
        $strategy = $this->availableStrategy($service);
        if ($strategy === null) {
            return [
                'ok' => false,
                'strategy' => 'none',
                'output' => 'هیچ سازوکار ری‌استارتی برای «' . $service . '» در دسترس نیست.',
            ];
        }

        switch ($strategy) {
            case 'override':
                $command = (string) $this->overrideCommand($service);
                [$code, $out] = ($this->runner)($command);
                return ['ok' => $code === 0, 'strategy' => 'override', 'output' => $out];

            case 'systemctl':
                [$code, $out] = ($this->runner)($this->sudoPrefix() . 'systemctl restart ' . escapeshellarg($this->unitName($service)));
                return ['ok' => $code === 0, 'strategy' => 'systemctl', 'output' => $out];

            case 'service':
                [$code, $out] = ($this->runner)($this->sudoPrefix() . 'service ' . escapeshellarg($service) . ' restart');
                return ['ok' => $code === 0, 'strategy' => 'service', 'output' => $out];

            default:
                return $this->restartProcess($service);
        }
    }

    /**
     * پیامی که هنگام نبود هر استراتژی به markTestSkipped داده می‌شود.
     * عمداً پرجزئیات است تا skip در CI «مبهم» به نظر نرسد.
     */
    public function skipReason(string $service): string
    {
        return sprintf(
            'سرویس «%s» با هیچ‌یک از سازوکارهای شناخته‌شده قابل ری‌استارت نیست '
            . '(systemctl / service / مدیریت مستقیم پروسه). این محدودیتِ محیط است، نه نقص محصول. '
            . 'برای اجرای این تست، یکی از موارد زیر را فراهم کنید: '
            . '(الف) unit فعال systemd به نام %s.service؛ '
            . '(ب) اسکریپت SysV در /etc/init.d/%s؛ '
            . '(ج) پروسهٔ در حال اجرای %s که این تست بتواند آن را ری‌استارت کند؛ '
            . 'یا (د) متغیر محیطی CHAOS_REDIS_RESTART_CMD با فرمان دلخواه ری‌استارت.',
            $service,
            $service,
            $service,
            $service
        );
    }

    // ---------------------------------------------------------------- داخلی

    private function overrideCommand(string $service): ?string
    {
        $key = 'CHAOS_' . strtoupper(str_replace(['-', '.'], '_', $service)) . '_RESTART_CMD';
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            // نام کوتاه هم پذیرفته می‌شود: redis-server → CHAOS_REDIS_RESTART_CMD
            $short = explode('-', $service)[0];
            $value = getenv('CHAOS_' . strtoupper($short) . '_RESTART_CMD');
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function unitName(string $service): string
    {
        return str_ends_with($service, '.service') ? $service : $service . '.service';
    }

    private function hasSystemdUnit(string $service): bool
    {
        [$code, $out] = ($this->runner)('systemctl list-unit-files ' . escapeshellarg($this->unitName($service)));
        return $code === 0 && str_contains($out, $service);
    }

    private function hasSysVScript(string $service): bool
    {
        return is_file('/etc/init.d/' . $service) && is_executable('/etc/init.d/' . $service);
    }

    private function sudoPrefix(): string
    {
        [$code] = ($this->runner)('sudo -n true');
        return $code === 0 ? 'sudo -n ' : '';
    }

    /**
     * پروسهٔ در حال اجرای سرویس را پیدا می‌کند.
     *
     * @return array{pid:int,cmd:string}|null
     */
    private function findProcess(string $service): ?array
    {
        $binary = $this->binaryName($service);
        [$code, $out] = ($this->runner)('ps -o pid=,args= -C ' . escapeshellarg($binary));
        if ($code !== 0 || trim($out) === '') {
            // ps -C روی برخی سیستم‌ها موجود نیست؛ به pgrep برمی‌گردیم.
            [$code, $out] = ($this->runner)('pgrep -a ' . escapeshellarg($binary));
            if ($code !== 0 || trim($out) === '') {
                return null;
            }
        }
        foreach (explode("\n", trim($out)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (!is_array($parts) || count($parts) < 2 || !ctype_digit($parts[0])) {
                continue;
            }
            // پروسهٔ خودِ ps/pgrep را نادیده بگیر.
            if (str_contains($parts[1], 'pgrep') || str_contains($parts[1], ' -C ')) {
                continue;
            }
            return ['pid' => (int) $parts[0], 'cmd' => $parts[1]];
        }
        return null;
    }

    private function binaryName(string $service): string
    {
        return match ($service) {
            'redis-server', 'redis' => 'redis-server',
            default => $service,
        };
    }

    /**
     * خط فرمانی که سرویس را دوباره بالا می‌آورد.
     *
     * چرا نمی‌توان صرفاً به خروجی «ps» اکتفا کرد: دیمن‌هایی مثل Redis عنوان
     * پروسهٔ خود را بازنویسی می‌کنند (setproctitle). در نتیجه ps چیزی مثل
     *
     *     redis-server 127.0.0.1:6379
     *
     * نشان می‌دهد که آدرس گوش‌دادن است، نه مسیر فایل پیکربندی؛ اجرای دوبارهٔ
     * آن رشته، سرویس را با پیکربندیِ کاملاً متفاوت — یا اصلاً بالا نیامده —
     * می‌سازد. برای Redis از خودِ سرویس می‌پرسیم: INFO server هم مسیر باینری
     * و هم مسیر فایل پیکربندی را دقیق برمی‌گرداند.
     */
    private function relaunchCommand(string $service, int $pid, string $psCommand): string
    {
        if ($this->binaryName($service) !== 'redis-server') {
            return $psCommand;
        }

        $executable = '';
        $configFile = '';
        foreach (['redis-cli', '/home/user/tools/redis/bin/redis-cli'] as $cli) {
            [$code, $out] = ($this->runner)($cli . ' INFO server');
            if ($code !== 0 || trim($out) === '') {
                continue;
            }
            foreach (explode("\n", $out) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'executable:')) {
                    $executable = trim(substr($line, 11));
                } elseif (str_starts_with($line, 'config_file:')) {
                    $configFile = trim(substr($line, 12));
                }
            }
            if ($executable !== '') {
                break;
            }
        }

        // جایگزین: مسیر باینری را از /proc بخوان (ps آن را بازنویسی کرده).
        if ($executable === '') {
            $link = @readlink('/proc/' . $pid . '/exe');
            if (is_string($link) && $link !== '') {
                $executable = $link;
            }
        }
        if ($executable === '') {
            return $psCommand;
        }

        return $configFile !== '' && is_file($configFile)
            ? escapeshellarg($executable) . ' ' . escapeshellarg($configFile)
            : escapeshellarg($executable);
    }

    /**
     * ری‌استارت با مدیریت مستقیم پروسه: کشتن و اجرای دوبارهٔ همان خط فرمان.
     *
     * @return array{ok:bool,strategy:string,output:string}
     */
    private function restartProcess(string $service): array
    {
        $process = $this->findProcess($service);
        if ($process === null) {
            return ['ok' => false, 'strategy' => 'process', 'output' => 'پروسه پیدا نشد.'];
        }
        $pid = $process['pid'];
        $cmd = $this->relaunchCommand($service, $pid, $process['cmd']);

        ($this->runner)('kill -TERM ' . $pid);
        // منتظر خروج واقعی می‌مانیم؛ ری‌استارتِ نیمه‌کاره تست را بی‌معنا می‌کند.
        $gone = false;
        for ($i = 0; $i < 50; $i++) {
            [$c] = ($this->runner)('kill -0 ' . $pid);
            if ($c !== 0) {
                $gone = true;
                break;
            }
            usleep(100_000);
        }
        if (!$gone) {
            ($this->runner)('kill -KILL ' . $pid);
            usleep(300_000);
        }

        ($this->runner)('nohup ' . $cmd . ' >/dev/null 2>&1 &');

        // منتظر می‌مانیم تا سرویس واقعاً آمادهٔ پذیرش اتصال شود — نه صرفاً
        // اینکه پروسه‌ای در جدول پروسه‌ها دیده شود.
        for ($i = 0; $i < 100; $i++) {
            usleep(100_000);
            if ($this->findProcess($service) !== null && $this->acceptsConnections($service)) {
                return [
                    'ok' => true,
                    'strategy' => 'process',
                    'output' => 'ری‌استارت با مدیریت مستقیم پروسه انجام شد: ' . $cmd,
                ];
            }
        }

        return [
            'ok' => false,
            'strategy' => 'process',
            'output' => 'سرویس پس از ری‌استارت دوباره بالا نیامد: ' . $cmd,
        ];
    }

    /**
     * آیا سرویس دوباره اتصال می‌پذیرد؟ برای سرویس‌های ناشناخته، صرفِ وجود
     * پروسه کافی در نظر گرفته می‌شود.
     */
    private function acceptsConnections(string $service): bool
    {
        if ($this->binaryName($service) !== 'redis-server') {
            return true;
        }
        $host = '127.0.0.1';
        $port = 6379;
        $configured = function_exists('config') ? config('redis', []) : [];
        if (is_array($configured)) {
            if (isset($configured['host']) && is_string($configured['host']) && $configured['host'] !== '') {
                $host = $configured['host'];
            }
            if (isset($configured['port']) && (is_int($configured['port']) || ctype_digit((string) $configured['port']))) {
                $port = (int) $configured['port'];
            }
        }
        $errNo = 0;
        $errStr = '';
        $socket = @fsockopen($host, $port, $errNo, $errStr, 0.3);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }
}
