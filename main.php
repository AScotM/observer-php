#!/usr/bin/env php
<?php

declare(strict_types=1);

final class ConsoleColor
{
    public const RESET = "\033[0m";
    public const GREEN = "\033[32m";
    public const CYAN = "\033[36m";
    public const YELLOW = "\033[33m";
    public const RED = "\033[31m";
    public const MAGENTA = "\033[35m";
    public const BLUE = "\033[34m";
    public const WHITE = "\033[37m";

    public static function wrap(string $text, string $color, bool $enabled): string
    {
        if (!$enabled) {
            return $text;
        }
        return $color . $text . self::RESET;
    }
}

final class JsonFile
{
    public static function load(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("File is not readable: $path");
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: $path");
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException("File does not contain a valid JSON object: $path");
        }

        return $data;
    }

    public static function save(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON');
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create directory: $directory");
            }
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary file: $tmp");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to move temporary file into place: $path");
        }
    }
}

final class FileReader
{
    public static function readText(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }

        $content = @file_get_contents($path);
        return $content === false ? '' : trim($content);
    }

    public static function readInt(string $path): int
    {
        $value = self::readText($path);
        return is_numeric($value) ? (int)$value : 0;
    }

    public static function readNullableInt(string $path): ?int
    {
        $value = self::readText($path);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $int = (int)$value;
        return $int >= 0 ? $int : null;
    }
}

final class HostInfo
{
    public static function hostname(): string
    {
        return gethostname() ?: '';
    }

    public static function fqdn(): string
    {
        $hostname = self::hostname();
        if ($hostname === '') {
            return '';
        }

        $ip = gethostbyname($hostname);
        $fqdn = @gethostbyaddr($ip);
        if ($fqdn === false || $fqdn === $ip) {
            return $hostname;
        }

        return $fqdn;
    }

    public static function osRelease(): string
    {
        $osRelease = '/etc/os-release';
        $fedoraRelease = '/etc/fedora-release';

        if (is_readable($osRelease)) {
            $lines = @file($osRelease, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $map = [];
                foreach ($lines as $line) {
                    if (!str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    $map[$key] = trim($value, "\"' \t\r\n");
                }
                return $map['PRETTY_NAME'] ?? '';
            }
        }

        return FileReader::readText($fedoraRelease);
    }

    public static function kernel(): string
    {
        return php_uname('r');
    }

    public static function arch(): string
    {
        return php_uname('m');
    }

    public static function cpuCount(): int
    {
        $path = '/proc/cpuinfo';
        if (!is_readable($path)) {
            return 0;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
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

    public static function uptimeSeconds(): float
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

    public static function loadAvg(): array
    {
        $avg = sys_getloadavg();
        if (!is_array($avg)) {
            return [];
        }

        return [
            isset($avg[0]) ? (float)$avg[0] : 0.0,
            isset($avg[1]) ? (float)$avg[1] : 0.0,
            isset($avg[2]) ? (float)$avg[2] : 0.0,
        ];
    }

    public static function meminfo(): array
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
}

final class NetworkInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $macAddress,
        public readonly string $operstate,
        public readonly int $mtu,
        public readonly ?int $speedMbps,
        public readonly array $ipv4Addresses,
        public readonly int $rxBytes,
        public readonly int $txBytes
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'mac_address' => $this->macAddress,
            'operstate' => $this->operstate,
            'mtu' => $this->mtu,
            'speed_mbps' => $this->speedMbps,
            'ipv4_addresses' => $this->ipv4Addresses,
            'rx_bytes' => $this->rxBytes,
            'tx_bytes' => $this->txBytes,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['name'] ?? ''),
            (string)($data['mac_address'] ?? ''),
            (string)($data['operstate'] ?? ''),
            (int)($data['mtu'] ?? 0),
            isset($data['speed_mbps']) ? (is_numeric($data['speed_mbps']) ? (int)$data['speed_mbps'] : null) : null,
            self::normalizeStringList($data['ipv4_addresses'] ?? []),
            (int)($data['rx_bytes'] ?? 0),
            (int)($data['tx_bytes'] ?? 0)
        );
    }

    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[$item] = true;
            }
        }

        $items = array_keys($out);
        sort($items);
        return $items;
    }
}

final class Manifest
{
    public function __construct(
        public readonly string $hostname,
        public readonly string $fqdn,
        public readonly string $timestamp,
        public readonly int $timestampEpoch,
        public readonly string $osRelease,
        public readonly string $kernel,
        public readonly string $arch,
        public readonly int $cpuCount,
        public readonly float $uptimeSeconds,
        public readonly array $loadavg,
        public readonly int $memTotalBytes,
        public readonly int $memAvailableBytes,
        public readonly array $ipAddresses,
        public readonly array $interfaces
    ) {
    }

    public function toArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'fqdn' => $this->fqdn,
            'timestamp' => $this->timestamp,
            'timestamp_epoch' => $this->timestampEpoch,
            'os_release' => $this->osRelease,
            'kernel' => $this->kernel,
            'arch' => $this->arch,
            'cpu_count' => $this->cpuCount,
            'uptime_seconds' => $this->uptimeSeconds,
            'loadavg' => $this->loadavg,
            'mem_total_bytes' => $this->memTotalBytes,
            'mem_available_bytes' => $this->memAvailableBytes,
            'ip_addresses' => $this->ipAddresses,
            'interfaces' => array_map(
                static fn(NetworkInterface $iface): array => $iface->toArray(),
                $this->interfaces
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        $interfaces = [];
        $rawInterfaces = $data['interfaces'] ?? [];
        if (is_array($rawInterfaces)) {
            foreach ($rawInterfaces as $item) {
                if (is_array($item)) {
                    $interfaces[] = NetworkInterface::fromArray($item);
                }
            }
        }

        return new self(
            (string)($data['hostname'] ?? ''),
            (string)($data['fqdn'] ?? ''),
            (string)($data['timestamp'] ?? ''),
            (int)($data['timestamp_epoch'] ?? 0),
            (string)($data['os_release'] ?? ''),
            (string)($data['kernel'] ?? ''),
            (string)($data['arch'] ?? ''),
            (int)($data['cpu_count'] ?? 0),
            (float)($data['uptime_seconds'] ?? 0.0),
            self::normalizeFloatList($data['loadavg'] ?? []),
            (int)($data['mem_total_bytes'] ?? 0),
            (int)($data['mem_available_bytes'] ?? 0),
            self::normalizeStringList($data['ip_addresses'] ?? []),
            $interfaces
        );
    }

    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[$item] = true;
            }
        }

        $items = array_keys($out);
        sort($items);
        return $items;
    }

    private static function normalizeFloatList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $out[] = (float)$item;
            }
        }

        return $out;
    }
}

final class InterfaceCollector
{
    public static function collect(): array
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

            $interfaces[] = new NetworkInterface(
                $name,
                FileReader::readText($base . '/' . $name . '/address'),
                FileReader::readText($base . '/' . $name . '/operstate'),
                FileReader::readInt($base . '/' . $name . '/mtu'),
                FileReader::readNullableInt($base . '/' . $name . '/speed'),
                self::ipv4Addresses($name),
                $rxBytes,
                $txBytes
            );
        }

        usort(
            $interfaces,
            static fn(NetworkInterface $a, NetworkInterface $b): int => strcmp($a->name, $b->name)
        );

        return $interfaces;
    }

    private static function ipv4Addresses(string $name): array
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

        $items = array_keys($ips);
        sort($items);
        return $items;
    }

    private static function readRxTxBytes(string $name): array
    {
        $base = '/sys/class/net/' . $name . '/statistics';
        return [
            FileReader::readInt($base . '/rx_bytes'),
            FileReader::readInt($base . '/tx_bytes'),
        ];
    }
}

final class ManifestBuilder
{
    public static function build(): Manifest
    {
        $meminfo = HostInfo::meminfo();
        $interfaces = InterfaceCollector::collect();
        $allIps = [];

        foreach ($interfaces as $iface) {
            foreach ($iface->ipv4Addresses as $ip) {
                if (!str_starts_with($ip, '127.')) {
                    $allIps[$ip] = true;
                }
            }
        }

        $ips = array_keys($allIps);
        sort($ips);

        return new Manifest(
            HostInfo::hostname(),
            HostInfo::fqdn(),
            date('c'),
            time(),
            HostInfo::osRelease(),
            HostInfo::kernel(),
            HostInfo::arch(),
            HostInfo::cpuCount(),
            HostInfo::uptimeSeconds(),
            HostInfo::loadAvg(),
            $meminfo['MemTotal'] ?? 0,
            $meminfo['MemAvailable'] ?? 0,
            $ips,
            $interfaces
        );
    }
}

final class ManifestDiff
{
    public static function diff(Manifest $old, Manifest $new): array
    {
        $fieldChanges = [];
        foreach ([
            'hostname' => [$old->hostname, $new->hostname],
            'fqdn' => [$old->fqdn, $new->fqdn],
            'os_release' => [$old->osRelease, $new->osRelease],
            'kernel' => [$old->kernel, $new->kernel],
            'arch' => [$old->arch, $new->arch],
            'cpu_count' => [$old->cpuCount, $new->cpuCount],
            'mem_total_bytes' => [$old->memTotalBytes, $new->memTotalBytes],
            'mem_available_bytes' => [$old->memAvailableBytes, $new->memAvailableBytes],
        ] as $field => [$before, $after]) {
            if ($before !== $after) {
                $fieldChanges[] = [
                    'field' => $field,
                    'old' => $before,
                    'new' => $after,
                ];
            }
        }

        $oldIps = $old->ipAddresses;
        $newIps = $new->ipAddresses;

        $oldMap = self::interfaceMap($old->interfaces);
        $newMap = self::interfaceMap($new->interfaces);

        $addedInterfaces = array_values(array_diff(array_keys($newMap), array_keys($oldMap)));
        $removedInterfaces = array_values(array_diff(array_keys($oldMap), array_keys($newMap)));

        sort($addedInterfaces);
        sort($removedInterfaces);

        $changedInterfaces = [];
        $common = array_intersect(array_keys($oldMap), array_keys($newMap));
        sort($common);

        foreach ($common as $name) {
            $before = $oldMap[$name]->toArray();
            $after = $newMap[$name]->toArray();

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
            'old_timestamp' => $old->timestamp,
            'new_timestamp' => $new->timestamp,
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

    private static function interfaceMap(array $interfaces): array
    {
        $map = [];
        foreach ($interfaces as $iface) {
            if ($iface instanceof NetworkInterface) {
                $map[$iface->name] = $iface;
            }
        }
        return $map;
    }
}

final class Exporter
{
    public static function export(string $format, Manifest $manifest): string
    {
        return match ($format) {
            'json' => self::json($manifest->toArray()),
            'table' => self::table($manifest),
            default => throw new InvalidArgumentException("Unsupported format: $format"),
        };
    }

    public static function exportDiff(string $format, array $diff, bool $colors): string
    {
        return match ($format) {
            'json' => self::json($diff),
            'table' => self::diffTable($diff, $colors),
            default => throw new InvalidArgumentException("Unsupported format: $format"),
        };
    }

    private static function json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON');
        }
        return $json . PHP_EOL;
    }

    private static function table(Manifest $manifest): string
    {
        $lines = [];
        $lines[] = 'SYSTEM MANIFEST';
        $lines[] = str_repeat('=', 72);
        $lines[] = sprintf('%-20s %s', 'Hostname', $manifest->hostname);
        $lines[] = sprintf('%-20s %s', 'FQDN', $manifest->fqdn);
        $lines[] = sprintf('%-20s %s', 'Timestamp', $manifest->timestamp);
        $lines[] = sprintf('%-20s %s', 'OS Release', $manifest->osRelease);
        $lines[] = sprintf('%-20s %s', 'Kernel', $manifest->kernel);
        $lines[] = sprintf('%-20s %s', 'Architecture', $manifest->arch);
        $lines[] = sprintf('%-20s %d', 'CPU Count', $manifest->cpuCount);
        $lines[] = sprintf('%-20s %.2f', 'Uptime Seconds', $manifest->uptimeSeconds);
        $lines[] = sprintf('%-20s %s', 'Load Average', implode(', ', array_map(
            static fn(float $v): string => number_format($v, 2, '.', ''),
            $manifest->loadavg
        )));
        $lines[] = sprintf('%-20s %s', 'Memory Total', self::humanBytes($manifest->memTotalBytes));
        $lines[] = sprintf('%-20s %s', 'Memory Available', self::humanBytes($manifest->memAvailableBytes));
        $lines[] = sprintf('%-20s %s', 'IP Addresses', implode(', ', $manifest->ipAddresses));
        $lines[] = '';
        $lines[] = 'INTERFACES';
        $lines[] = str_repeat('-', 72);
        $lines[] = sprintf(
            '%-12s %-10s %-8s %-8s %-17s %-16s',
            'Name',
            'State',
            'MTU',
            'Speed',
            'RX',
            'TX'
        );

        foreach ($manifest->interfaces as $iface) {
            $lines[] = sprintf(
                '%-12s %-10s %-8d %-8s %-17s %-16s',
                $iface->name,
                $iface->operstate,
                $iface->mtu,
                $iface->speedMbps !== null ? $iface->speedMbps . 'M' : '-',
                self::humanBytes($iface->rxBytes),
                self::humanBytes($iface->txBytes)
            );
            $lines[] = sprintf('%-12s %-10s %-8s %-8s %-17s %-16s', '', '', '', '', 'MAC', $iface->macAddress);
            $lines[] = sprintf('%-12s %-10s %-8s %-8s %-17s %-16s', '', '', '', '', 'IPv4', implode(', ', $iface->ipv4Addresses));
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function diffTable(array $diff, bool $colors): string
    {
        $lines = [];
        $lines[] = 'MANIFEST DIFF';
        $lines[] = str_repeat('=', 72);
        $lines[] = sprintf('%-20s %s', 'Old Timestamp', (string)($diff['old_timestamp'] ?? ''));
        $lines[] = sprintf('%-20s %s', 'New Timestamp', (string)($diff['new_timestamp'] ?? ''));
        $lines[] = '';

        $lines[] = 'FIELD CHANGES';
        $lines[] = str_repeat('-', 72);
        $fieldChanges = $diff['field_changes'] ?? [];
        if (is_array($fieldChanges) && $fieldChanges !== []) {
            foreach ($fieldChanges as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $field = (string)($change['field'] ?? '');
                $old = self::stringify($change['old'] ?? null);
                $new = self::stringify($change['new'] ?? null);
                $lines[] = $field;
                $lines[] = '  old: ' . ConsoleColor::wrap($old, ConsoleColor::RED, $colors);
                $lines[] = '  new: ' . ConsoleColor::wrap($new, ConsoleColor::GREEN, $colors);
            }
        } else {
            $lines[] = 'No scalar field changes';
        }

        $lines[] = '';
        $lines[] = 'IP ADDRESS CHANGES';
        $lines[] = str_repeat('-', 72);
        $ipSection = is_array($diff['ip_addresses'] ?? null) ? $diff['ip_addresses'] : [];
        $addedIps = is_array($ipSection['added'] ?? null) ? $ipSection['added'] : [];
        $removedIps = is_array($ipSection['removed'] ?? null) ? $ipSection['removed'] : [];

        if ($addedIps === [] && $removedIps === []) {
            $lines[] = 'No IP changes';
        } else {
            foreach ($addedIps as $ip) {
                $lines[] = ConsoleColor::wrap('+ ' . (string)$ip, ConsoleColor::GREEN, $colors);
            }
            foreach ($removedIps as $ip) {
                $lines[] = ConsoleColor::wrap('- ' . (string)$ip, ConsoleColor::RED, $colors);
            }
        }

        $lines[] = '';
        $lines[] = 'INTERFACE CHANGES';
        $lines[] = str_repeat('-', 72);
        $ifaceSection = is_array($diff['interfaces'] ?? null) ? $diff['interfaces'] : [];
        $addedIfaces = is_array($ifaceSection['added'] ?? null) ? $ifaceSection['added'] : [];
        $removedIfaces = is_array($ifaceSection['removed'] ?? null) ? $ifaceSection['removed'] : [];
        $changedIfaces = is_array($ifaceSection['changed'] ?? null) ? $ifaceSection['changed'] : [];

        if ($addedIfaces === [] && $removedIfaces === [] && $changedIfaces === []) {
            $lines[] = 'No interface changes';
        } else {
            foreach ($addedIfaces as $name) {
                $lines[] = ConsoleColor::wrap('+ interface added: ' . (string)$name, ConsoleColor::GREEN, $colors);
            }
            foreach ($removedIfaces as $name) {
                $lines[] = ConsoleColor::wrap('- interface removed: ' . (string)$name, ConsoleColor::RED, $colors);
            }
            foreach ($changedIfaces as $ifaceChange) {
                if (!is_array($ifaceChange)) {
                    continue;
                }
                $name = (string)($ifaceChange['name'] ?? '');
                $lines[] = ConsoleColor::wrap('* interface changed: ' . $name, ConsoleColor::CYAN, $colors);
                $changes = is_array($ifaceChange['changes'] ?? null) ? $ifaceChange['changes'] : [];
                foreach ($changes as $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    $field = (string)($change['field'] ?? '');
                    $old = self::stringify($change['old'] ?? null);
                    $new = self::stringify($change['new'] ?? null);
                    $lines[] = '    ' . $field;
                    $lines[] = '      old: ' . ConsoleColor::wrap($old, ConsoleColor::RED, $colors);
                    $lines[] = '      new: ' . ConsoleColor::wrap($new, ConsoleColor::GREEN, $colors);
                }
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $size = (float)$bytes;
        $index = 0;

        while ($size >= 1024.0 && $index < count($units) - 1) {
            $size /= 1024.0;
            $index++;
        }

        return number_format($size, 2, '.', '') . ' ' . $units[$index];
    }

    private static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            return $json === false ? '[array]' : $json;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }
}

final class OptionParser
{
    public static function parse(array $argv): array
    {
        $options = getopt('', [
            'output:',
            'compare:',
            'format:',
            'help',
            'no-color',
        ]);

        if (isset($options['help'])) {
            self::printHelp($argv[0] ?? 'observer.php');
            exit(0);
        }

        $format = isset($options['format']) ? (string)$options['format'] : 'json';
        if (!in_array($format, ['json', 'table'], true)) {
            throw new InvalidArgumentException('Format must be json or table');
        }

        return [
            'output' => isset($options['output']) ? (string)$options['output'] : null,
            'compare' => isset($options['compare']) ? (string)$options['compare'] : null,
            'format' => $format,
            'colors' => !isset($options['no-color']) && self::stdoutIsTty(),
        ];
    }

    private static function stdoutIsTty(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }

        return false;
    }

    private static function printHelp(string $script): void
    {
        $name = basename($script);
        echo <<<TXT
Usage: php {$name} [options]

Options:
  --output <file>    Write current manifest JSON to file
  --compare <file>   Compare current manifest with saved manifest JSON
  --format <type>    Output format: json or table
  --no-color         Disable colored output
  --help             Show this help message

Examples:
  php {$name}
  php {$name} --format table
  php {$name} --output snapshots/node-a.json
  php {$name} --compare snapshots/node-a.json --format table

TXT;
    }
}

final class Application
{
    public static function run(array $argv): int
    {
        try {
            self::assertEnvironment();
            $options = OptionParser::parse($argv);
            $manifest = ManifestBuilder::build();

            if (is_string($options['output'])) {
                JsonFile::save($options['output'], $manifest->toArray());
            }

            if (is_string($options['compare'])) {
                $previous = Manifest::fromArray(JsonFile::load($options['compare']));
                $diff = ManifestDiff::diff($previous, $manifest);
                echo Exporter::exportDiff($options['format'], $diff, $options['colors']);
                return 0;
            }

            echo Exporter::export($options['format'], $manifest);
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
}

exit(Application::run($_SERVER['argv']));
