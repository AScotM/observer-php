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
    public const BOLD = "\033[1m";

    public static function wrap(string $text, string $color, bool $enabled): string
    {
        if (!$enabled) {
            return $text;
        }
        return $color . $text . self::RESET;
    }

    public static function wrapBold(string $text, bool $enabled): string
    {
        if (!$enabled) {
            return $text;
        }
        return self::BOLD . $text . self::RESET;
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
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in file: $path - " . json_last_error_msg());
        }

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

        $tmp = $path . '.tmp.' . getmypid();
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

final class ProcessExecutor
{
    public static function execute(string $command, int $timeoutSeconds = 5): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['output' => [], 'code' => -1, 'errors' => ''];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = [];
        $errors = '';
        $startTime = microtime(true);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $output[] = $chunk;
                } else {
                    $errors .= $chunk;
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startTime) > $timeoutSeconds) {
                proc_terminate($process, 9);
                break;
            }
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $code = proc_close($process);
        $fullOutput = explode("\n", trim(implode('', $output)));
        $fullOutput = array_filter($fullOutput, fn($line) => $line !== '');

        return ['output' => array_values($fullOutput), 'code' => $code, 'errors' => $errors];
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
        $requiredFields = ['hostname', 'timestamp_epoch', 'cpu_count'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {
                throw new RuntimeException("Missing required field in manifest: $field");
            }
        }

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
            $speed = FileReader::readNullableInt($base . '/' . $name . '/speed');
            if ($speed === -1) {
                $speed = null;
            }

            $interfaces[] = new NetworkInterface(
                $name,
                FileReader::readText($base . '/' . $name . '/address'),
                FileReader::readText($base . '/' . $name . '/operstate'),
                FileReader::readInt($base . '/' . $name . '/mtu'),
                $speed,
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
        $command = 'ip -4 -o addr show dev ' . escapeshellarg($name) . ' 2>/dev/null';
        $result = ProcessExecutor::execute($command, 3);

        if ($result['code'] === 0) {
            foreach ($result['output'] as $line) {
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
            'duration_seconds' => $new->timestampEpoch - $old->timestampEpoch,
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
    public static function export(string $format, Manifest $manifest, bool $colors): string
    {
        return match ($format) {
            'json' => self::json($manifest->toArray()),
            'table' => self::table($manifest, $colors),
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

    private static function table(Manifest $manifest, bool $colors): string
    {
        $lines = [];
        $lines[] = ConsoleColor::wrapBold('SYSTEM MANIFEST', $colors);
        $lines[] = str_repeat('=', 72);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Hostname:', ConsoleColor::CYAN, $colors), $manifest->hostname);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('FQDN:', ConsoleColor::CYAN, $colors), $manifest->fqdn);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Timestamp:', ConsoleColor::CYAN, $colors), $manifest->timestamp);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('OS Release:', ConsoleColor::CYAN, $colors), $manifest->osRelease);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Kernel:', ConsoleColor::CYAN, $colors), $manifest->kernel);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Architecture:', ConsoleColor::CYAN, $colors), $manifest->arch);
        $lines[] = sprintf('%-20s %d', ConsoleColor::wrap('CPU Count:', ConsoleColor::CYAN, $colors), $manifest->cpuCount);
        $lines[] = sprintf('%-20s %.2f', ConsoleColor::wrap('Uptime Seconds:', ConsoleColor::CYAN, $colors), $manifest->uptimeSeconds);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Load Average:', ConsoleColor::CYAN, $colors), implode(', ', array_map(
            static fn(float $v): string => number_format($v, 2, '.', ''),
            $manifest->loadavg
        )));
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Memory Total:', ConsoleColor::CYAN, $colors), self::humanBytes($manifest->memTotalBytes));
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Memory Available:', ConsoleColor::CYAN, $colors), self::humanBytes($manifest->memAvailableBytes));
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('IP Addresses:', ConsoleColor::CYAN, $colors), implode(', ', $manifest->ipAddresses));
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('INTERFACES', $colors);
        $lines[] = str_repeat('-', 72);
        $lines[] = sprintf(
            '%-12s %-10s %-8s %-8s %-17s %-16s',
            ConsoleColor::wrap('Name', ConsoleColor::YELLOW, $colors),
            ConsoleColor::wrap('State', ConsoleColor::YELLOW, $colors),
            ConsoleColor::wrap('MTU', ConsoleColor::YELLOW, $colors),
            ConsoleColor::wrap('Speed', ConsoleColor::YELLOW, $colors),
            ConsoleColor::wrap('RX', ConsoleColor::YELLOW, $colors),
            ConsoleColor::wrap('TX', ConsoleColor::YELLOW, $colors)
        );

        foreach ($manifest->interfaces as $iface) {
            $stateColor = match($iface->operstate) {
                'up' => ConsoleColor::GREEN,
                'down' => ConsoleColor::RED,
                default => ConsoleColor::WHITE,
            };
            
            $lines[] = sprintf(
                '%-12s %-10s %-8d %-8s %-17s %-16s',
                ConsoleColor::wrap($iface->name, ConsoleColor::MAGENTA, $colors),
                ConsoleColor::wrap($iface->operstate, $stateColor, $colors),
                $iface->mtu,
                $iface->speedMbps !== null ? $iface->speedMbps . 'M' : '-',
                self::humanBytes($iface->rxBytes),
                self::humanBytes($iface->txBytes)
            );
            $lines[] = sprintf('%-12s %-10s %-8s %-8s %-17s %-16s', '', '', '', '', ConsoleColor::wrap('MAC:', ConsoleColor::CYAN, $colors), $iface->macAddress);
            $lines[] = sprintf('%-12s %-10s %-8s %-8s %-17s %-16s', '', '', '', '', ConsoleColor::wrap('IPv4:', ConsoleColor::CYAN, $colors), implode(', ', $iface->ipv4Addresses));
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function diffTable(array $diff, bool $colors): string
    {
        $lines = [];
        $lines[] = ConsoleColor::wrapBold('MANIFEST DIFF', $colors);
        $lines[] = str_repeat('=', 72);
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('Old Timestamp:', ConsoleColor::CYAN, $colors), (string)($diff['old_timestamp'] ?? ''));
        $lines[] = sprintf('%-20s %s', ConsoleColor::wrap('New Timestamp:', ConsoleColor::CYAN, $colors), (string)($diff['new_timestamp'] ?? ''));
        $lines[] = sprintf('%-20s %d', ConsoleColor::wrap('Duration Seconds:', ConsoleColor::CYAN, $colors), (int)($diff['duration_seconds'] ?? 0));
        $lines[] = '';

        $lines[] = ConsoleColor::wrapBold('FIELD CHANGES', $colors);
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
                $lines[] = ConsoleColor::wrap($field, ConsoleColor::YELLOW, $colors);
                $lines[] = '  old: ' . ConsoleColor::wrap($old, ConsoleColor::RED, $colors);
                $lines[] = '  new: ' . ConsoleColor::wrap($new, ConsoleColor::GREEN, $colors);
            }
        } else {
            $lines[] = 'No scalar field changes';
        }

        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('IP ADDRESS CHANGES', $colors);
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
        $lines[] = ConsoleColor::wrapBold('INTERFACE CHANGES', $colors);
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
                    $lines[] = '    ' . ConsoleColor::wrap($field, ConsoleColor::YELLOW, $colors);
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
            'force-color',
        ]);

        if (isset($options['help'])) {
            self::printHelp($argv[0] ?? 'observer.php');
            exit(0);
        }

        $format = isset($options['format']) ? (string)$options['format'] : 'json';
        if (!in_array($format, ['json', 'table'], true)) {
            throw new InvalidArgumentException('Format must be json or table');
        }

        $output = isset($options['output']) ? (string)$options['output'] : null;
        $compare = isset($options['compare']) ? (string)$options['compare'] : null;

        if ($output !== null && $compare !== null) {
            throw new InvalidArgumentException('Cannot use --output and --compare together');
        }

        $forceColor = isset($options['force-color']);
        $noColor = isset($options['no-color']);

        if ($noColor && $forceColor) {
            throw new InvalidArgumentException('Cannot use --no-color and --force-color together');
        }

        $colors = false;
        if ($forceColor) {
            $colors = true;
        } elseif (!$noColor) {
            $colors = self::stdoutIsTty();
        }

        return [
            'output' => $output,
            'compare' => $compare,
            'format' => $format,
            'colors' => $colors,
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
  --format <type>    Output format: json or table (default: json)
  --no-color         Disable colored output
  --force-color      Force colored output even when not in a TTY
  --help             Show this help message

Examples:
  php {$name}
  php {$name} --format table
  php {$name} --force-color --format table
  php {$name} --force-color --format table | less -R
  php {$name} --output snapshots/node-a.json
  php {$name} --compare snapshots/node-a.json --format table

TXT;
    }
}

final class Application
{
    private static ?string $logger = null;

    public static function setLogger(string $path): void
    {
        self::$logger = $path;
    }

    public static function run(array $argv): int
    {
        try {
            self::assertEnvironment();
            $options = OptionParser::parse($argv);
            $manifest = ManifestBuilder::build();

            if (is_string($options['output'])) {
                JsonFile::save($options['output'], $manifest->toArray());
                self::log("Manifest saved to {$options['output']}");
            }

            if (is_string($options['compare'])) {
                $previous = Manifest::fromArray(JsonFile::load($options['compare']));
                $diff = ManifestDiff::diff($previous, $manifest);
                echo Exporter::exportDiff($options['format'], $diff, $options['colors']);
                self::log("Comparison completed with {$options['compare']}");
                return 0;
            }

            echo Exporter::export($options['format'], $manifest, $options['colors']);
            return 0;
        } catch (Throwable $e) {
            $message = 'error: ' . $e->getMessage() . PHP_EOL;
            fwrite(STDERR, $message);
            self::log($message, true);
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

        if (PHP_VERSION_ID < 80200) {
            throw new RuntimeException('PHP 8.2 or higher is required');
        }
    }

    private static function log(string $message, bool $isError = false): void
    {
        if (self::$logger === null) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $prefix = $isError ? 'ERROR' : 'INFO';
        $logLine = sprintf("[%s] %s: %s\n", $timestamp, $prefix, $message);

        @file_put_contents(self::$logger, $logLine, FILE_APPEND | LOCK_EX);
    }
}

exit(Application::run($_SERVER['argv']));
