<?php

namespace Grav\Plugin;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use Grav\Common\Plugin;
use JsonException;

class UptimePlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $config = $this->config->get('plugins.uptime', []);
        $route = $this->env('UPTIME_ROUTE', $config['route'] ?? '/uptime');

        $path = '/' . trim($this->grav['uri']->path(), '/');
        if ($path !== '/' . trim($route, '/')) {
            return;
        }

        // ---- build payload
        $tz = $this->env('TZ', $config['timezone'] ?? 'Europe/Paris');
        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz))->format($config['datetime_format'] ?? \DateTimeInterface::ATOM);

        $payload = [
            'status' => $this->env('UPTIME_STATUS', $config['status'] ?? 'ok'),
            'service' => $this->env('UPTIME_SERVICE', $config['service'] ?? 'grav'),
            'env' => $this->env('APP_ENV', $config['env'] ?? 'prod'),
            'time' => $now,
        ];

        $tzObj = new \DateTimeZone($tz);
        // host uptime (kernel)
        if ($host = $this->getHostUptime($tzObj)) {
            $payload['uptime_host'] = $host; // {seconds, boot_unix, boot_iso}
        }
        // container uptime (PID 1)
        if ($ctr = $this->getContainerUptime($tzObj)) {
            $payload['uptime_container'] = $ctr; // {seconds, started_unix, started_iso, pid:1}
        }

        // optional extra JSON
        $extraRaw = $this->env('UPTIME_EXTRA_JSON', $config['extra_json'] ?? '');
        if ($extraRaw) {
            try {
                $extra = json_decode($extraRaw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($extra)) {
                    $extra = $this->mapExtraEnv($extra);
                    $payload += $extra;
                }
            } catch (\Throwable) {
                $payload['extra_parse_error'] = 'UPTIME_EXTRA_JSON invalid';
            }
        }

        // headers / code
        $code = (int)($config['http_code'] ?? 200);
        $ctype = $config['content_type'] ?? 'application/json';
        $cache = $config['cache_control'] ?? 'no-store, no-cache, must-revalidate, max-age=0';

        header('Content-Type: ' . $ctype);
        if ($cache) {
            header('Cache-Control: ' . $cache);
        }
        http_response_code($code);

        echo ($ctype === 'application/json')
            ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : $this->toText($payload);

        exit;
    }

    private function mapExtraEnv(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->mapExtraEnv($v);
                continue;
            }

            if (is_string($v) && str_starts_with($v, 'env:')) {
                $spec = substr($v, 4);
                [$var, $def] = array_pad(explode('|', trim($spec), 2), 2, null);
                $val = $this->env($var, $def);
                if ($val === null) {
                    $val = $def ?? $v;
                }
                $out[$k] = $val;
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function env(string $name, mixed $default = null)
    {
        $v = getenv($name);
        return ($v !== false && $v !== '') ? $v : $default;
    }

    /**
     * @throws JsonException
     */
    private function toText(array $arr): string
    {
        $lines = [];
        foreach ($arr as $k => $v) {
            $lines[] = $k . '=' . (is_scalar($v) ? $v : json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @throws DateMalformedStringException
     */
    private function getHostUptime(\DateTimeZone $tz): ?array
    {
        if (!is_readable('/proc/uptime')) {
            return null;
        }
        $first = explode(' ', trim((string)@file_get_contents('/proc/uptime')))[0] ?? null;
        if (!is_numeric($first)) {
            return null;
        }

        $secs = (int)round((float)$first);
        $boot = new \DateTimeImmutable('@' . (time() - $secs))->setTimezone($tz);

        return [
            'seconds' => $secs,
            'boot_unix' => (int)$boot->format('U'),
            'boot_iso' => $boot->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @throws DateMalformedStringException
     */
    private function getContainerUptime(\DateTimeZone $tz): ?array
    {
        // need: clock ticks per second
        $clk = (int)trim((string)@shell_exec('getconf CLK_TCK 2>/dev/null')) ?: 100;

        // btime (kernel boot timestamp)
        $btime = null;
        if (is_readable('/proc/stat')) {
            foreach (explode("\n", (string)@file_get_contents('/proc/stat')) as $line) {
                if (str_starts_with($line, 'btime ')) {
                    $btime = (int)substr($line, 6);
                    break;
                }
            }
        }
        if (!$btime || !is_readable('/proc/1/stat')) {
            return null;
        }

        // /proc/1/stat: parse starttime (field #22, in ticks since boot)
        $stat = trim((string)@file_get_contents('/proc/1/stat'));
        $close = strrpos($stat, ')');             // "pid (comm) ..."
        if ($close === false) {
            return null;
        }
        $rest = substr($stat, $close + 2);       // after ") "
        $parts = preg_split('/\s+/', $rest);
        if (!isset($parts[19])) {
            return null;
        }      // 22nd field -> index 19 here
        $start_ticks = (int)$parts[19];

        $start_ts = (int)floor($btime + ($start_ticks / max(1, $clk)));
        $secs = max(0, time() - $start_ts);
        $started = new \DateTimeImmutable('@' . $start_ts)->setTimezone($tz);

        return [
            'seconds' => $secs,
            'started_unix' => $start_ts,
            'started_iso' => $started->format(\DateTimeInterface::ATOM),
        ];
    }
}
