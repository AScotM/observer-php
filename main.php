#!/usr/bin/env php
<?php

declare(strict_types=1);

final class SystemObserver
{
    public static function run(array $argv): int
    {
        try {
            self::assertEnvironment();

            $options = self::parseOptions($argv);
            $manifest = self::buildManifest();

            if (isset($options['output'])) {
                self::writeJsonFile($options['output'], $manifest);
            }

            if (isset($options['compare'])) {
                $previous = self::loadJsonFile($options['compare']);
                $diff = self::diffManifests($previous, $manifest);
                self::printJson($diff);
                return 0;
            }

            self::printJson($manifest);
            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private static function assertEnvironment(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('This script must be run from the command line');
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('This script is supported on Linux only');
        }
    }

    private static function parseOptions(array $argv): array
    {
        $options = getopt('', ['output:', 'compare:', 'help']);

        if (isset($options['help'])) {
            self::printHelp($argv[0] ?? 'observer.php');
            exit(0);
        }

        return $options;
    }

    private static function printHelp(string $script): void
    {
        $name = basename($script);
        $text = <<<TXT
Usage: php {$name} [--output file] [--compare file]

Options:
  --output <file>    Write current manifest JSON to file
  --compare <file>   Compare current manifest with saved manifest JSON
  --help             Show this help message

TXT;
        echo $text;
    }

    private static function buildManifest(): array
    {
        $meminfo = self::getMeminfo();
        $interfaces = self::collectInterfaces();

        $allIps = [];
        foreach ($interfaces as $iface) {
            foreach ($iface['ipv4_addresses'] as $ip) {
                if (!str_starts_with($ip, '127.')) {
                    $allIps[$ip] = true;
                }
            }
        }

        $hostname = gethostname() ?: '';
        $fqdn = gethostbyaddr(gethostbyname($hostname));
        if ($fqdn === false || $fqdn === gethostbyname($hostname)) {
            $fqdn = $hostname;
        }

        return [
            'hostname' => $hostname,
            'fqdn' => $fqdn,
            'timestamp' => date('c'),
            'timestamp_epoch' => time(),
            'os_release' => self::parseOsRelease(),
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
            'cpu_count' => self::getCpuCount(),
            'uptime_seconds' => self::getUptime(),
            'loadavg' => self::getLoadAvg(),
            'mem_total_bytes' => $meminfo['MemTotal'] ?? 0,
            'mem_available_bytes' => $meminfo['MemAvailable'] ?? 0,
            'ip_addresses' => array_values(array_keys($allIps)),
            'interfaces' => $interfaces,
        ];
    }

    private static function parseOsRelease(): string
    {
        $osRelease = '/etc/os-release';
        $fedoraRelease = '/etc/fedora-release';

        if (is_readable($osRelease)) {
            $data = [];
            $lines = @file($osRelease, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    if (!str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    $data[$key] = trim($value, "\"' \t\r\n");
                }
                return $data['PRETTY_NAME'] ?? '';
            }
        }

        if (is_readable($fedoraRelease)) {
            $text = @file_get_contents($fedoraRelease);
            return $text === false ? '' : trim($text);
        }

        return '';
    }

    private static function getCpuCount(): int
    {
        $count = 0;
        $cpuinfo = '/proc/cpuinfo';

        if (!is_readable($cpuinfo)) {
            return 0;
        }

        $handle = @fopen($cpuinfo, 'r');
        if ($handle === false) {
            return 0;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (str_starts_with($line, 'processor')) {
                    $count++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private static function getUptime(): float
    {
        $path = '/proc/uptime';
        if (!is_readable($path)) {
            return 0.0;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return 0.0;
        }

        $parts = preg_split('/\s+/', trim($content));
        if (!isset($parts[0]) || !is_numeric($parts[0])) {
            return 0.0;
        }

        return (float)$parts[0];
    }

    private static function getMeminfo(): array
    {
        $path = '/proc/meminfo';
        $result = [];

        if (!is_readable($path)) {
            return $result;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return $result;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (!str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $parts = preg_split('/\s+/', trim($value));
                if (!isset($parts[0]) || !is_numeric($parts[0])) {
                    continue;
                }

                $base = (int)$parts[0];
                $unit = strtolower($parts[1] ?? '');
                $multiplier = $unit === 'kb' ? 1024 : 1;
                $result[$key] = $base * $multiplier;
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    private static function getLoadAvg(): array
    {
        $loads = sys_getloadavg();
        if (!is_array($loads)) {
            return [];
        }

        return [
            isset($loads[0]) ? (float)$loads[0] : 0.0,
            isset($loads[1]) ? (float)$loads[1] : 0.0,
            isset($loads[2]) ? (float)$loads[2] : 0.0,
        ];
    }

    private static function collectInterfaces(): array
    {
        $base = '/sys/class/net';
        if (!is_dir($base)) {
            return [];
        }

        $entries = @scandir($base);
        if ($entries === false) {
            return [];
        }

        $interfaces = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === 'lo') {
                continue;
            }

            $path = $base . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }

            [$rxBytes, $txBytes] = self::readRxTxBytes($name);

            $interfaces[] = [
                'name' => $name,
                'mac_address' => self::readText($base . '/' . $name . '/address'),
                'operstate' => self::readText($base . '/' . $name . '/operstate'),
                'mtu' => self::readInt($base . '/' . $name . '/mtu'),
                'speed_mbps' => self::readNullableInt($base . '/' . $name . '/speed'),
                'ipv4_addresses' => self::getIpv4ForInterface($name),
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
            ];
        }

        usort(
            $interfaces,
            static fn(array $a, array $b): int => strcmp($a['name'], $b['name'])
        );

        return $interfaces;
    }

    private static function getIpv4ForInterface(string $name): array
    {
        $ips = [];

        $output = [];
        $code = 0;
        @exec(
            'ip -4 -o addr show dev ' . escapeshellarg($name) . ' 2>/dev/null',
            $output,
            $code
        );

        if ($code === 0) {
            foreach ($output as $line) {
                if (preg_match('/\binet\s+(\d+\.\d+\.\d+\.\d+)\/\d+/', $line, $matches)) {
                    $ip = $matches[1];
                    if (!str_starts_with($ip, '127.')) {
                        $ips[$ip] = true;
                    }
                }
            }
        }

        return array_values(array_keys($ips));
    }

    private static function readRxTxBytes(string $name): array
    {
        $base = '/sys/class/net/' . $name . '/statistics';
        $rx = self::readInt($base . '/rx_bytes');
        $tx = self::readInt($base . '/tx_bytes');
        return [$rx, $tx];
    }

    private static function readText(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }

        $text = @file_get_contents($path);
        return $text === false ? '' : trim($text);
    }

    private static function readInt(string $path): int
    {
        $value = self::readText($path);
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function readNullableInt(string $path): ?int
    {
        $value = self::readText($path);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $int = (int)$value;
        return $int >= 0 ? $int : null;
    }

    private static function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode manifest JSON');
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Failed to create output directory: ' . $directory);
            }
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary output file: ' . $tmp);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to move temporary output file into place');
        }
    }

    private static function loadJsonFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Compare file is not readable: ' . $path);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read compare file: ' . $path);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException('Compare file must contain a JSON object');
        }

        return $data;
    }

    private static function diffManifests(array $old, array $new): array
    {
        $scalarFields = [
            'hostname',
            'fqdn',
            'os_release',
            'kernel',
            'arch',
            'cpu_count',
            'mem_total_bytes',
            'mem_available_bytes',
        ];

        $fieldChanges = [];
        foreach ($scalarFields as $field) {
            $oldValue = $old[$field] ?? null;
            $newValue = $new[$field] ?? null;
            if ($oldValue !== $newValue) {
                $fieldChanges[] = [
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        $oldIps = self::normalizeStringList($old['ip_addresses'] ?? []);
        $newIps = self::normalizeStringList($new['ip_addresses'] ?? []);

        $oldIfaces = self::interfaceMap($old);
        $newIfaces = self::interfaceMap($new);

        $addedInterfaces = array_values(array_diff(array_keys($newIfaces), array_keys($oldIfaces)));
        $removedInterfaces = array_values(array_diff(array_keys($oldIfaces), array_keys($newIfaces)));

        sort($addedInterfaces);
        sort($removedInterfaces);

        $changedInterfaces = [];
        $common = array_intersect(array_keys($oldIfaces), array_keys($newIfaces));
        sort($common);

        foreach ($common as $name) {
            $before = $oldIfaces[$name];
            $after = $newIfaces[$name];

            if ($before === $after) {
                continue;
            }

            $changes = [];
            $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
            sort($keys);

            foreach ($keys as $key) {
                $beforeValue = $before[$key] ?? null;
                $afterValue = $after[$key] ?? null;
                if ($beforeValue !== $afterValue) {
                    $changes[] = [
                        'field' => $key,
                        'old' => $beforeValue,
                        'new' => $afterValue,
                    ];
                }
            }

            $changedInterfaces[] = [
                'name' => $name,
                'changes' => $changes,
            ];
        }

        return [
            'old_timestamp' => $old['timestamp'] ?? null,
            'new_timestamp' => $new['timestamp'] ?? null,
            'field_changes' => $fieldChanges,
            'ip_addresses' => [
                'old' => $oldIps,
                'new' => $newIps,
                'added' => array_values(array_diff($newIps, $oldIps)),
                'removed' => array_values(array_diff($oldIps, $newIps)),
            ],
            'interfaces' => [
                'added' => $addedInterfaces,
                'removed' => $removedInterfaces,
                'changed' => $changedInterfaces,
            ],
        ];
    }

    private static function interfaceMap(array $data): array
    {
        $result = [];
        $interfaces = $data['interfaces'] ?? [];

        if (!is_array($interfaces)) {
            return $result;
        }

        foreach ($interfaces as $iface) {
            if (!is_array($iface) || !isset($iface['name'])) {
                continue;
            }
            $result[(string)$iface['name']] = $iface;
        }

        return $result;
    }

    private static function normalizeStringList(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $result[$item] = true;
            }
        }
        $values = array_keys($result);
        sort($values);
        return $values;
    }

    private static function printJson(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON output');
        }
        echo $json . PHP_EOL;
    }
}

exit(SystemObserver::run($_SERVER['argv']));
