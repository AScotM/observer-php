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

final class RuntimeClock
{
    public static function iso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function monotonic(): float
    {
        return hrtime(true) / 1000000000;
    }

    public static function seconds(float $start): float
    {
        return max(0.0, self::monotonic() - $start);
    }
}

final class RuntimeDiagnostics
{
    private array $items = [];

    public function add(string $severity, string $code, string $message, array $context = []): void
    {
        $this->items[] = [
            'severity' => strtoupper($severity),
            'code' => $code,
            'message' => $message,
            'context' => $context,
            'timestamp' => RuntimeClock::iso(),
        ];
    }

    public function all(): array
    {
        return $this->items;
    }

    public function countBySeverity(): array
    {
        $counts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'INFO' => 0];
        foreach ($this->items as $item) {
            $severity = $item['severity'];
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }
        return $counts;
    }
}

final class NumericParser
{
    public static function unsigned(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number === false || $number < 0 ? null : $number;
    }

    public static function floating(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? $number : null;
    }

    public static function percentage(float $used, float $total): ?float
    {
        if ($total <= 0.0) {
            return null;
        }
        return round(100.0 * $used / $total, 3);
    }

    public static function human(int|float $value): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $amount = (float)$value;
        $index = 0;
        while (abs($amount) >= 1024 && $index < count($units) - 1) {
            $amount /= 1024;
            $index++;
        }
        return number_format($amount, 2, '.', '') . ' ' . $units[$index];
    }
}

final class SafeText
{
    public static function read(string $path, int $limit = 1048576): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $content = @stream_get_contents($handle, $limit + 1);
            if ($content === false || strlen($content) > $limit) {
                return null;
            }
            return $content;
        } finally {
            fclose($handle);
        }
    }

    public static function lines(string $path, int $limit = 1048576): array
    {
        $content = self::read($path, $limit);
        if ($content === null) {
            return [];
        }
        return preg_split('/\r?\n/', trim($content)) ?: [];
    }

    public static function scalar(string $path): ?string
    {
        $content = self::read($path, 8192);
        return $content === null ? null : trim($content);
    }
}

final class LinuxCpuCollector
{
    public static function collect(): array
    {
        $result = [
            'logical_cpus' => 0,
            'model_names' => [],
            'physical_packages' => [],
            'cores_per_package' => [],
            'frequency_mhz' => [],
            'usage_counters' => [],
            'pressure' => [],
        ];
        $models = [];
        $packages = [];
        $cores = [];
        foreach (SafeText::lines('/proc/cpuinfo', 2097152) as $line) {
            if (preg_match('/^processor\s*:\s*\d+/', $line)) {
                $result['logical_cpus']++;
            }
            if (preg_match('/^model name\s*:\s*(.+)$/', $line, $match)) {
                $models[trim($match[1])] = true;
            }
            if (preg_match('/^physical id\s*:\s*(\d+)/', $line, $match)) {
                $packages[$match[1]] = true;
            }
            if (preg_match('/^cpu cores\s*:\s*(\d+)/', $line, $match)) {
                $cores[(int)$match[1]] = true;
            }
            if (preg_match('/^cpu MHz\s*:\s*([\d.]+)/', $line, $match)) {
                $result['frequency_mhz'][] = (float)$match[1];
            }
        }
        $result['model_names'] = array_keys($models);
        $result['physical_packages'] = array_keys($packages);
        $result['cores_per_package'] = array_keys($cores);
        foreach (SafeText::lines('/proc/stat') as $line) {
            if (preg_match('/^cpu\s+(.+)$/', $line, $match)) {
                $fields = preg_split('/\s+/', trim($match[1])) ?: [];
                $names = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal', 'guest', 'guest_nice'];
                foreach ($names as $index => $name) {
                    $result['usage_counters'][$name] = isset($fields[$index]) ? (int)$fields[$index] : 0;
                }
                break;
            }
        }
        $result['pressure'] = LinuxPressureCollector::collectOne('cpu');
        return $result;
    }
}

final class LinuxPressureCollector
{
    public static function collectOne(string $resource): array
    {
        if (!in_array($resource, ['cpu', 'memory', 'io'], true)) {
            return [];
        }
        $output = [];
        foreach (SafeText::lines('/proc/pressure/' . $resource) as $line) {
            if (!preg_match('/^(some|full)\s+(.+)$/', $line, $match)) {
                continue;
            }
            $record = [];
            foreach (preg_split('/\s+/', trim($match[2])) ?: [] as $token) {
                if (!str_contains($token, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $token, 2);
                $record[$key] = $key === 'total' ? NumericParser::unsigned($value) : NumericParser::floating($value);
            }
            $output[$match[1]] = $record;
        }
        return $output;
    }

    public static function collect(): array
    {
        return [
            'cpu' => self::collectOne('cpu'),
            'memory' => self::collectOne('memory'),
            'io' => self::collectOne('io'),
        ];
    }
}

final class LinuxMemoryCollector
{
    public static function collect(): array
    {
        $values = HostInfo::meminfo();
        $total = (int)($values['MemTotal'] ?? 0);
        $available = (int)($values['MemAvailable'] ?? 0);
        $swapTotal = (int)($values['SwapTotal'] ?? 0);
        $swapFree = (int)($values['SwapFree'] ?? 0);
        return [
            'fields_bytes' => $values,
            'total_bytes' => $total,
            'available_bytes' => $available,
            'used_estimate_bytes' => max(0, $total - $available),
            'used_estimate_percent' => NumericParser::percentage($total - $available, $total),
            'swap_total_bytes' => $swapTotal,
            'swap_free_bytes' => $swapFree,
            'swap_used_bytes' => max(0, $swapTotal - $swapFree),
            'swap_used_percent' => NumericParser::percentage($swapTotal - $swapFree, $swapTotal),
            'pressure' => LinuxPressureCollector::collectOne('memory'),
        ];
    }
}

final class LinuxDiskCollector
{
    public static function collect(): array
    {
        $records = [];
        foreach (SafeText::lines('/proc/diskstats', 2097152) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) < 14) {
                continue;
            }
            $name = $fields[2];
            if (preg_match('/^(loop|ram|fd)\d+$/', $name)) {
                continue;
            }
            $records[$name] = [
                'name' => $name,
                'reads_completed' => (int)$fields[3],
                'reads_merged' => (int)$fields[4],
                'sectors_read' => (int)$fields[5],
                'read_time_ms' => (int)$fields[6],
                'writes_completed' => (int)$fields[7],
                'writes_merged' => (int)$fields[8],
                'sectors_written' => (int)$fields[9],
                'write_time_ms' => (int)$fields[10],
                'in_flight' => (int)$fields[11],
                'io_time_ms' => (int)$fields[12],
                'weighted_io_time_ms' => (int)$fields[13],
            ];
        }
        ksort($records);
        return ['devices' => array_values($records), 'pressure' => LinuxPressureCollector::collectOne('io')];
    }
}

final class LinuxFilesystemCollector
{
    public static function collect(): array
    {
        $items = [];
        $seen = [];
        foreach (SafeText::lines('/proc/self/mountinfo', 4194304) as $line) {
            $parts = explode(' - ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $left = preg_split('/\s+/', $parts[0]) ?: [];
            $right = preg_split('/\s+/', $parts[1]) ?: [];
            if (count($left) < 5 || count($right) < 2) {
                continue;
            }
            $mount = self::decodeMount($left[4]);
            $filesystem = $right[0];
            $source = self::decodeMount($right[1]);
            if (isset($seen[$mount])) {
                continue;
            }
            $seen[$mount] = true;
            $total = @disk_total_space($mount);
            $free = @disk_free_space($mount);
            $total = $total === false ? null : (int)$total;
            $free = $free === false ? null : (int)$free;
            $used = $total === null || $free === null ? null : max(0, $total - $free);
            $items[] = [
                'mount' => $mount,
                'filesystem' => $filesystem,
                'source' => $source,
                'total_bytes' => $total,
                'free_bytes' => $free,
                'used_bytes' => $used,
                'used_percent' => $total === null || $used === null ? null : NumericParser::percentage($used, $total),
                'readonly' => in_array('ro', explode(',', $left[5] ?? ''), true),
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['mount'], $b['mount']));
        return $items;
    }

    private static function decodeMount(string $value): string
    {
        return preg_replace_callback('/\\\\([0-7]{3})/', static fn(array $m): string => chr(octdec($m[1])), $value) ?? $value;
    }
}

final class LinuxNetworkCollector
{
    public static function collect(): array
    {
        $interfaces = [];
        $base = '/sys/class/net';
        foreach (@scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !preg_match('/^[a-zA-Z0-9_.:@-]+$/', $name)) {
                continue;
            }
            $path = $base . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            $interfaces[$name] = [
                'name' => $name,
                'mac_address' => SafeText::scalar($path . '/address'),
                'operstate' => SafeText::scalar($path . '/operstate'),
                'carrier' => NumericParser::unsigned(SafeText::scalar($path . '/carrier') ?? ''),
                'mtu' => NumericParser::unsigned(SafeText::scalar($path . '/mtu') ?? ''),
                'speed_mbps' => NumericParser::unsigned(SafeText::scalar($path . '/speed') ?? ''),
                'flags' => SafeText::scalar($path . '/flags'),
                'rx_bytes' => NumericParser::unsigned(SafeText::scalar($path . '/statistics/rx_bytes') ?? ''),
                'tx_bytes' => NumericParser::unsigned(SafeText::scalar($path . '/statistics/tx_bytes') ?? ''),
                'rx_packets' => NumericParser::unsigned(SafeText::scalar($path . '/statistics/rx_packets') ?? ''),
                'tx_packets' => NumericParser::unsigned(SafeText::scalar($path . '/statistics/tx_packets') ?? ''),
                'rx_errors' => NumericParser::unsigned(SafeText::scalar($path . '/statistics/rx_errors') ?? ''),
                'tx_errors' => NumericParser::unsigned(SafeText::scalar($path . '/statistics/tx_errors') ?? ''),
                'ipv4' => [],
                'ipv6' => [],
            ];
        }
        $addresses = self::ipAddresses();
        foreach ($addresses as $name => $families) {
            if (!isset($interfaces[$name])) {
                continue;
            }
            $interfaces[$name]['ipv4'] = $families['ipv4'];
            $interfaces[$name]['ipv6'] = $families['ipv6'];
        }
        ksort($interfaces);
        return array_values($interfaces);
    }

    private static function ipAddresses(): array
    {
        $result = [];
        $command = 'ip -o address show';
        $execution = ReliableCommand::run($command, 4);
        if ($execution['exit_code'] !== 0) {
            return $result;
        }
        foreach ($execution['stdout_lines'] as $line) {
            if (!preg_match('/^\d+:\s+(\S+)\s+(inet6?)\s+(\S+)/', $line, $match)) {
                continue;
            }
            $name = preg_replace('/@.*$/', '', $match[1]);
            $family = $match[2] === 'inet6' ? 'ipv6' : 'ipv4';
            $result[$name] ??= ['ipv4' => [], 'ipv6' => []];
            $result[$name][$family][$match[3]] = true;
        }
        foreach ($result as &$families) {
            foreach (['ipv4', 'ipv6'] as $family) {
                $families[$family] = array_keys($families[$family]);
                sort($families[$family]);
            }
        }
        unset($families);
        return $result;
    }
}

final class ReliableCommand
{
    public static function run(string $command, int $timeout = 5, int $maxBytes = 2097152): array
    {
        $start = RuntimeClock::monotonic();
        $pipes = [];
        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return self::result(null, '', 'Unable to launch command', false, false, $start);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $truncated = false;
        $lastExit = null;
        try {
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running'] && $status['exitcode'] >= 0) {
                    $lastExit = $status['exitcode'];
                }
                $read = [];
                foreach ([1, 2] as $index) {
                    if (!feof($pipes[$index])) {
                        $read[] = $pipes[$index];
                    }
                }
                if ($read !== []) {
                    $write = null;
                    $except = null;
                    $selected = @stream_select($read, $write, $except, 0, 100000);
                    if ($selected !== false && $selected > 0) {
                        foreach ($read as $stream) {
                            $chunk = fread($stream, 65536);
                            if (!is_string($chunk) || $chunk === '') {
                                continue;
                            }
                            if ($stream === $pipes[1]) {
                                $stdout .= $chunk;
                            } else {
                                $stderr .= $chunk;
                            }
                        }
                    }
                }
                if (strlen($stdout) + strlen($stderr) > $maxBytes) {
                    $truncated = true;
                    @proc_terminate($process, 9);
                    break;
                }
                if (RuntimeClock::seconds($start) >= $timeout) {
                    $timedOut = true;
                    @proc_terminate($process, 9);
                    break;
                }
                if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }
            }
            foreach ([1, 2] as $index) {
                while (!feof($pipes[$index])) {
                    $chunk = fread($pipes[$index], 65536);
                    if (!is_string($chunk) || $chunk === '') {
                        break;
                    }
                    if ($index === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                    if (strlen($stdout) + strlen($stderr) > $maxBytes) {
                        $truncated = true;
                        break;
                    }
                }
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
        }
        $exit = proc_close($process);
        if ($exit === -1 && $lastExit !== null) {
            $exit = $lastExit;
        }
        return self::result($exit, $stdout, $stderr, $timedOut, $truncated, $start);
    }

    private static function result(?int $exit, string $stdout, string $stderr, bool $timedOut, bool $truncated, float $start): array
    {
        return [
            'exit_code' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_lines' => $stdout === '' ? [] : preg_split('/\r?\n/', trim($stdout)),
            'timed_out' => $timedOut,
            'truncated' => $truncated,
            'duration_seconds' => RuntimeClock::seconds($start),
        ];
    }
}

final class LinuxKernelCollector
{
    public static function collect(): array
    {
        $cmdline = SafeText::scalar('/proc/cmdline');
        $bootId = SafeText::scalar('/proc/sys/kernel/random/boot_id');
        $modules = [];
        foreach (SafeText::lines('/proc/modules', 4194304) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) >= 3) {
                $modules[] = ['name' => $fields[0], 'size_bytes' => (int)$fields[1], 'references' => (int)$fields[2]];
            }
        }
        return [
            'release' => php_uname('r'),
            'version' => php_uname('v'),
            'architecture' => php_uname('m'),
            'boot_id' => $bootId,
            'cmdline' => $cmdline,
            'modules' => $modules,
            'modules_count' => count($modules),
            'pid_max' => NumericParser::unsigned(SafeText::scalar('/proc/sys/kernel/pid_max') ?? ''),
            'threads_max' => NumericParser::unsigned(SafeText::scalar('/proc/sys/kernel/threads-max') ?? ''),
            'file_max' => NumericParser::unsigned(SafeText::scalar('/proc/sys/fs/file-max') ?? ''),
            'uptime_seconds' => HostInfo::uptimeSeconds(),
        ];
    }
}

final class LinuxProcessCollector
{
    public static function collect(): array
    {
        $total = 0;
        $states = [];
        $threads = 0;
        foreach (@scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $status = SafeText::lines('/proc/' . $entry . '/status', 16384);
            if ($status === []) {
                continue;
            }
            $total++;
            foreach ($status as $line) {
                if (preg_match('/^State:\s+(\S+)/', $line, $match)) {
                    $states[$match[1]] = ($states[$match[1]] ?? 0) + 1;
                }
                if (preg_match('/^Threads:\s+(\d+)/', $line, $match)) {
                    $threads += (int)$match[1];
                }
            }
        }
        ksort($states);
        return ['process_count' => $total, 'thread_count' => $threads, 'states' => $states];
    }
}

final class LinuxSecurityCollector
{
    public static function collect(): array
    {
        $selinux = null;
        $enforce = SafeText::scalar('/sys/fs/selinux/enforce');
        if ($enforce !== null) {
            $selinux = $enforce === '1' ? 'enforcing' : ($enforce === '0' ? 'permissive' : 'unknown');
        }
        $lockdown = SafeText::scalar('/sys/kernel/security/lockdown');
        $lsm = SafeText::scalar('/sys/kernel/security/lsm');
        $fips = SafeText::scalar('/proc/sys/crypto/fips_enabled');
        return [
            'selinux' => $selinux,
            'lockdown' => $lockdown,
            'lsm' => $lsm,
            'fips_enabled' => $fips === null ? null : $fips === '1',
            'unprivileged_bpf_disabled' => NumericParser::unsigned(SafeText::scalar('/proc/sys/kernel/unprivileged_bpf_disabled') ?? ''),
            'ptrace_scope' => NumericParser::unsigned(SafeText::scalar('/proc/sys/kernel/yama/ptrace_scope') ?? ''),
        ];
    }
}

final class ExtendedSnapshot
{
    public static function capture(RuntimeDiagnostics $diagnostics): array
    {
        $started = RuntimeClock::monotonic();
        $sections = [];
        $collectors = [
            'cpu' => [LinuxCpuCollector::class, 'collect'],
            'memory' => [LinuxMemoryCollector::class, 'collect'],
            'pressure' => [LinuxPressureCollector::class, 'collect'],
            'disk' => [LinuxDiskCollector::class, 'collect'],
            'filesystems' => [LinuxFilesystemCollector::class, 'collect'],
            'network' => [LinuxNetworkCollector::class, 'collect'],
            'kernel' => [LinuxKernelCollector::class, 'collect'],
            'processes' => [LinuxProcessCollector::class, 'collect'],
            'security' => [LinuxSecurityCollector::class, 'collect'],
        ];
        $timings = [];
        foreach ($collectors as $name => $callable) {
            $sectionStart = RuntimeClock::monotonic();
            try {
                $sections[$name] = $callable();
            } catch (Throwable $error) {
                $sections[$name] = null;
                $diagnostics->add('LOW', 'COLLECT_' . strtoupper($name), $error->getMessage());
            }
            $timings[$name] = round(RuntimeClock::seconds($sectionStart), 6);
        }
        return [
            'schema_version' => 2,
            'collected_at' => RuntimeClock::iso(),
            'timestamp_epoch' => time(),
            'hostname' => HostInfo::hostname(),
            'os_release' => HostInfo::osRelease(),
            'sections' => $sections,
            'collection_timings_seconds' => $timings,
            'collection_duration_seconds' => round(RuntimeClock::seconds($started), 6),
        ];
    }
}

final class DriftClassifier
{
    private const CONFIG_PATHS = [
        'hostname',
        'os_release',
        'sections.kernel.release',
        'sections.kernel.architecture',
        'sections.kernel.cmdline',
        'sections.kernel.pid_max',
        'sections.kernel.threads_max',
        'sections.kernel.file_max',
        'sections.security.selinux',
        'sections.security.fips_enabled',
        'sections.security.unprivileged_bpf_disabled',
        'sections.security.ptrace_scope',
        'sections.security.lsm',
    ];

    public static function compare(array $before, array $after): array
    {
        $changes = [];
        foreach (self::CONFIG_PATHS as $path) {
            $a = self::lookup($before, $path);
            $b = self::lookup($after, $path);
            if ($a !== $b) {
                $changes[] = [
                    'category' => 'configuration',
                    'path' => $path,
                    'old' => $a,
                    'new' => $b,
                    'severity' => self::severity($path),
                ];
            }
        }
        $oldNetwork = self::indexByName($before['sections']['network'] ?? []);
        $newNetwork = self::indexByName($after['sections']['network'] ?? []);
        $names = array_unique(array_merge(array_keys($oldNetwork), array_keys($newNetwork)));
        sort($names);
        foreach ($names as $name) {
            if (!isset($oldNetwork[$name])) {
                $changes[] = ['category' => 'configuration', 'path' => 'network.' . $name, 'old' => null, 'new' => 'added', 'severity' => 'MEDIUM'];
                continue;
            }
            if (!isset($newNetwork[$name])) {
                $changes[] = ['category' => 'configuration', 'path' => 'network.' . $name, 'old' => 'present', 'new' => null, 'severity' => 'MEDIUM'];
                continue;
            }
            foreach (['mac_address', 'mtu', 'speed_mbps', 'flags', 'ipv4', 'ipv6'] as $field) {
                $a = $oldNetwork[$name][$field] ?? null;
                $b = $newNetwork[$name][$field] ?? null;
                if ($a !== $b) {
                    $changes[] = [
                        'category' => 'configuration',
                        'path' => 'network.' . $name . '.' . $field,
                        'old' => $a,
                        'new' => $b,
                        'severity' => 'MEDIUM',
                    ];
                }
            }
        }
        $oldFilesystems = self::indexByKey($before['sections']['filesystems'] ?? [], 'mount');
        $newFilesystems = self::indexByKey($after['sections']['filesystems'] ?? [], 'mount');
        $mounts = array_unique(array_merge(array_keys($oldFilesystems), array_keys($newFilesystems)));
        sort($mounts);
        foreach ($mounts as $mount) {
            if (!isset($oldFilesystems[$mount]) || !isset($newFilesystems[$mount])) {
                $changes[] = [
                    'category' => 'configuration',
                    'path' => 'filesystems.' . $mount,
                    'old' => isset($oldFilesystems[$mount]) ? 'present' : null,
                    'new' => isset($newFilesystems[$mount]) ? 'present' : null,
                    'severity' => 'MEDIUM',
                ];
                continue;
            }
            foreach (['filesystem', 'source', 'readonly'] as $field) {
                $a = $oldFilesystems[$mount][$field] ?? null;
                $b = $newFilesystems[$mount][$field] ?? null;
                if ($a !== $b) {
                    $changes[] = [
                        'category' => 'configuration',
                        'path' => 'filesystems.' . $mount . '.' . $field,
                        'old' => $a,
                        'new' => $b,
                        'severity' => 'MEDIUM',
                    ];
                }
            }
        }
        $runtime = RuntimeDelta::compare($before, $after);
        return [
            'old_timestamp' => $before['collected_at'] ?? null,
            'new_timestamp' => $after['collected_at'] ?? null,
            'duration_seconds' => (int)($after['timestamp_epoch'] ?? 0) - (int)($before['timestamp_epoch'] ?? 0),
            'configuration_changes' => $changes,
            'configuration_change_count' => count($changes),
            'runtime_deltas' => $runtime,
        ];
    }

    private static function lookup(array $array, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return null;
            }
            $array = $array[$segment];
        }
        return $array;
    }

    private static function severity(string $path): string
    {
        if (str_contains($path, 'security.') || str_contains($path, 'kernel.release')) {
            return 'HIGH';
        }
        return 'MEDIUM';
    }

    private static function indexByName(mixed $items): array
    {
        return self::indexByKey($items, 'name');
    }

    private static function indexByKey(mixed $items, string $key): array
    {
        $result = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && isset($item[$key]) && is_string($item[$key])) {
                $result[$item[$key]] = $item;
            }
        }
        return $result;
    }
}

final class RuntimeDelta
{
    public static function compare(array $before, array $after): array
    {
        $duration = max(0, (int)($after['timestamp_epoch'] ?? 0) - (int)($before['timestamp_epoch'] ?? 0));
        $deltas = [
            'duration_seconds' => $duration,
            'network' => [],
            'cpu' => [],
            'memory' => [],
            'processes' => [],
        ];
        $oldNetwork = self::networkIndex($before);
        $newNetwork = self::networkIndex($after);
        foreach ($newNetwork as $name => $interface) {
            if (!isset($oldNetwork[$name])) {
                continue;
            }
            $record = [];
            foreach (['rx_bytes', 'tx_bytes', 'rx_packets', 'tx_packets', 'rx_errors', 'tx_errors'] as $field) {
                $old = $oldNetwork[$name][$field] ?? null;
                $new = $interface[$field] ?? null;
                $record[$field . '_delta'] = self::counterDelta($old, $new);
                $record[$field . '_per_second'] = $duration > 0 && $record[$field . '_delta'] !== null
                    ? round($record[$field . '_delta'] / $duration, 3)
                    : null;
            }
            $deltas['network'][$name] = $record;
        }
        $oldCpu = $before['sections']['cpu']['usage_counters'] ?? [];
        $newCpu = $after['sections']['cpu']['usage_counters'] ?? [];
        foreach ($newCpu as $field => $value) {
            $deltas['cpu'][$field] = self::counterDelta($oldCpu[$field] ?? null, $value);
        }
        $total = array_sum(array_filter($deltas['cpu'], 'is_int'));
        $idle = ($deltas['cpu']['idle'] ?? 0) + ($deltas['cpu']['iowait'] ?? 0);
        $deltas['cpu']['busy_percent'] = $total > 0 ? round(100 * ($total - $idle) / $total, 3) : null;
        foreach (['available_bytes', 'used_estimate_bytes', 'swap_used_bytes'] as $field) {
            $old = $before['sections']['memory'][$field] ?? null;
            $new = $after['sections']['memory'][$field] ?? null;
            $deltas['memory'][$field . '_change'] = is_numeric($old) && is_numeric($new) ? $new - $old : null;
        }
        foreach (['process_count', 'thread_count'] as $field) {
            $old = $before['sections']['processes'][$field] ?? null;
            $new = $after['sections']['processes'][$field] ?? null;
            $deltas['processes'][$field . '_change'] = is_numeric($old) && is_numeric($new) ? $new - $old : null;
        }
        return $deltas;
    }

    private static function networkIndex(array $snapshot): array
    {
        $result = [];
        foreach ($snapshot['sections']['network'] ?? [] as $interface) {
            if (is_array($interface) && isset($interface['name'])) {
                $result[$interface['name']] = $interface;
            }
        }
        return $result;
    }

    private static function counterDelta(mixed $old, mixed $new): ?int
    {
        if (!is_int($old) || !is_int($new) || $new < $old) {
            return null;
        }
        return $new - $old;
    }
}

final class FindingEngine
{
    public static function analyze(array $snapshot, RuntimeDiagnostics $diagnostics, array $thresholds): array
    {
        $findings = [];
        $memory = $snapshot['sections']['memory'] ?? [];
        $memoryUsed = $memory['used_estimate_percent'] ?? null;
        if (is_numeric($memoryUsed) && $memoryUsed >= $thresholds['memory']) {
            $findings[] = self::finding('MEDIUM', 'MEMORY_USAGE', 'Estimated memory use exceeds threshold', ['percent' => $memoryUsed]);
        }
        $swapUsed = $memory['swap_used_percent'] ?? null;
        if (is_numeric($swapUsed) && $swapUsed >= $thresholds['swap']) {
            $findings[] = self::finding('MEDIUM', 'SWAP_USAGE', 'Swap utilization exceeds threshold', ['percent' => $swapUsed]);
        }
        foreach ($snapshot['sections']['filesystems'] ?? [] as $filesystem) {
            $percent = $filesystem['used_percent'] ?? null;
            if (is_numeric($percent) && $percent >= $thresholds['disk']) {
                $findings[] = self::finding('HIGH', 'FILESYSTEM_USAGE', 'Filesystem utilization exceeds threshold', [
                    'mount' => $filesystem['mount'],
                    'percent' => $percent,
                ]);
            }
        }
        $security = $snapshot['sections']['security'] ?? [];
        if (($security['selinux'] ?? null) === 'permissive') {
            $findings[] = self::finding('INFO', 'SELINUX_PERMISSIVE', 'SELinux is in permissive mode', []);
        }
        foreach ($snapshot['sections']['network'] ?? [] as $interface) {
            if (($interface['operstate'] ?? null) === 'down' && ($interface['name'] ?? '') !== 'lo') {
                $findings[] = self::finding('INFO', 'INTERFACE_DOWN', 'Interface reports down state', ['interface' => $interface['name']]);
            }
            foreach (['rx_errors', 'tx_errors'] as $counter) {
                if (($interface[$counter] ?? 0) > 0) {
                    $findings[] = self::finding('LOW', 'INTERFACE_ERRORS', 'Network interface reports cumulative errors', [
                        'interface' => $interface['name'],
                        'counter' => $counter,
                        'count' => $interface[$counter],
                    ]);
                }
            }
        }
        foreach ($diagnostics->all() as $diagnostic) {
            $findings[] = $diagnostic;
        }
        return $findings;
    }

    private static function finding(string $severity, string $code, string $message, array $context): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'context' => $context,
            'timestamp' => RuntimeClock::iso(),
        ];
    }
}

final class SnapshotFingerprint
{
    public static function configHash(array $snapshot): string
    {
        $reduced = [
            'hostname' => $snapshot['hostname'] ?? null,
            'os_release' => $snapshot['os_release'] ?? null,
            'kernel_release' => $snapshot['sections']['kernel']['release'] ?? null,
            'kernel_cmdline' => $snapshot['sections']['kernel']['cmdline'] ?? null,
            'security' => $snapshot['sections']['security'] ?? null,
            'network' => [],
            'filesystems' => [],
        ];
        foreach ($snapshot['sections']['network'] ?? [] as $interface) {
            $reduced['network'][] = array_intersect_key($interface, array_flip([
                'name', 'mac_address', 'mtu', 'speed_mbps', 'flags', 'ipv4', 'ipv6',
            ]));
        }
        foreach ($snapshot['sections']['filesystems'] ?? [] as $filesystem) {
            $reduced['filesystems'][] = array_intersect_key($filesystem, array_flip([
                'mount', 'filesystem', 'source', 'readonly',
            ]));
        }
        return hash('sha256', json_encode($reduced, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

final class HistoryDatabase
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('SQLite history requires the pdo_sqlite PHP extension');
        }
        $directory = dirname($path);
        if ($directory !== '.' && !is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create database directory: ' . $directory);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            hostname TEXT NOT NULL,
            fingerprint TEXT NOT NULL,
            payload TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_snapshots_host_id ON snapshots(hostname, id)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            hostname TEXT NOT NULL,
            severity TEXT NOT NULL,
            code TEXT NOT NULL,
            payload TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_host_id ON events(hostname, id)');
    }

    public function latest(string $hostname): ?array
    {
        $statement = $this->pdo->prepare('SELECT payload FROM snapshots WHERE hostname = :hostname ORDER BY id DESC LIMIT 1');
        $statement->execute(['hostname' => $hostname]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $data = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : null;
    }

    public function store(array $snapshot, array $findings): int
    {
        $hostname = (string)($snapshot['hostname'] ?? 'unknown');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('INSERT INTO snapshots (created_at, hostname, fingerprint, payload)
                VALUES (:created_at, :hostname, :fingerprint, :payload)');
            $statement->execute([
                'created_at' => $snapshot['collected_at'],
                'hostname' => $hostname,
                'fingerprint' => SnapshotFingerprint::configHash($snapshot),
                'payload' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $eventStatement = $this->pdo->prepare('INSERT INTO events (created_at, hostname, severity, code, payload)
                VALUES (:created_at, :hostname, :severity, :code, :payload)');
            foreach ($findings as $finding) {
                $eventStatement->execute([
                    'created_at' => $finding['timestamp'] ?? RuntimeClock::iso(),
                    'hostname' => $hostname,
                    'severity' => $finding['severity'] ?? 'INFO',
                    'code' => $finding['code'] ?? 'UNKNOWN',
                    'payload' => json_encode($finding, JSON_THROW_ON_ERROR),
                ]);
            }
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function recent(string $hostname, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT id, created_at, fingerprint, payload
            FROM snapshots WHERE hostname = :hostname ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':hostname', $hostname, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $payload = json_decode($row['payload'], true);
            $rows[] = [
                'id' => (int)$row['id'],
                'created_at' => $row['created_at'],
                'fingerprint' => $row['fingerprint'],
                'snapshot' => $payload,
            ];
        }
        return $rows;
    }

    public function prune(int $keep): int
    {
        $statement = $this->pdo->prepare('DELETE FROM snapshots WHERE id NOT IN (
            SELECT id FROM snapshots ORDER BY id DESC LIMIT :keep
        )');
        $statement->bindValue(':keep', $keep, PDO::PARAM_INT);
        $statement->execute();
        return $statement->rowCount();
    }
}

final class CsvWriter
{
    public static function encode(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Unable to create temporary CSV buffer');
        }
        try {
            $headers = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $key) {
                    $headers[$key] = true;
                }
            }
            if ($headers === []) {
                return '';
            }
            $columns = array_keys($headers);
            fputcsv($handle, $columns, ',', '"', '');
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $values[] = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;
                }
                fputcsv($handle, $values, ',', '"', '');
            }
            rewind($handle);
            return stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }
    }
}

final class ExtendedReporter
{
    public static function render(array $report, string $format, bool $colors): string
    {
        return match ($format) {
            'json' => json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            'csv' => self::csv($report),
            'table' => self::table($report, $colors),
            default => throw new InvalidArgumentException('Unsupported extended report format: ' . $format),
        };
    }

    private static function csv(array $report): string
    {
        $snapshot = $report['snapshot'];
        $rows = [];
        foreach ($snapshot['sections']['network'] ?? [] as $interface) {
            $rows[] = [
                'timestamp' => $snapshot['collected_at'],
                'hostname' => $snapshot['hostname'],
                'section' => 'network',
                'name' => $interface['name'],
                'state' => $interface['operstate'],
                'value' => $interface['rx_bytes'],
                'value2' => $interface['tx_bytes'],
                'details' => ['ipv4' => $interface['ipv4'], 'ipv6' => $interface['ipv6']],
            ];
        }
        foreach ($snapshot['sections']['filesystems'] ?? [] as $filesystem) {
            $rows[] = [
                'timestamp' => $snapshot['collected_at'],
                'hostname' => $snapshot['hostname'],
                'section' => 'filesystem',
                'name' => $filesystem['mount'],
                'state' => $filesystem['filesystem'],
                'value' => $filesystem['used_percent'],
                'value2' => $filesystem['free_bytes'],
                'details' => ['source' => $filesystem['source']],
            ];
        }
        foreach ($report['findings'] ?? [] as $finding) {
            $rows[] = [
                'timestamp' => $snapshot['collected_at'],
                'hostname' => $snapshot['hostname'],
                'section' => 'finding',
                'name' => $finding['code'],
                'state' => $finding['severity'],
                'value' => null,
                'value2' => null,
                'details' => $finding['context'],
            ];
        }
        return CsvWriter::encode($rows);
    }

    private static function table(array $report, bool $colors): string
    {
        $snapshot = $report['snapshot'];
        $sections = $snapshot['sections'];
        $lines = [];
        $lines[] = ConsoleColor::wrapBold('OBSERVER-PHP EXTENDED SYSTEM REPORT', $colors);
        $lines[] = str_repeat('=', 78);
        $lines[] = 'Host:       ' . $snapshot['hostname'];
        $lines[] = 'OS:         ' . $snapshot['os_release'];
        $lines[] = 'Kernel:     ' . ($sections['kernel']['release'] ?? 'unknown');
        $lines[] = 'Collected:  ' . $snapshot['collected_at'];
        $lines[] = 'Duration:   ' . number_format($snapshot['collection_duration_seconds'], 4) . ' s';
        $lines[] = 'Fingerprint: ' . ($report['fingerprint'] ?? '');
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('CPU AND MEMORY', $colors);
        $lines[] = str_repeat('-', 78);
        $lines[] = 'Logical CPUs: ' . ($sections['cpu']['logical_cpus'] ?? 0);
        $lines[] = 'CPU models:   ' . implode(', ', $sections['cpu']['model_names'] ?? []);
        $lines[] = 'Memory used:  ' . NumericParser::human($sections['memory']['used_estimate_bytes'] ?? 0);
        $lines[] = 'Memory total: ' . NumericParser::human($sections['memory']['total_bytes'] ?? 0);
        $lines[] = 'Memory load:  ' . self::percent($sections['memory']['used_estimate_percent'] ?? null);
        $lines[] = 'Swap load:    ' . self::percent($sections['memory']['swap_used_percent'] ?? null);
        $lines[] = 'Processes:    ' . ($sections['processes']['process_count'] ?? 0);
        $lines[] = 'Threads:      ' . ($sections['processes']['thread_count'] ?? 0);
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('NETWORK INTERFACES', $colors);
        $lines[] = str_repeat('-', 78);
        foreach ($sections['network'] ?? [] as $interface) {
            $lines[] = sprintf('%-16s %-9s RX %-12s TX %-12s',
                $interface['name'],
                $interface['operstate'] ?? '?',
                NumericParser::human($interface['rx_bytes'] ?? 0),
                NumericParser::human($interface['tx_bytes'] ?? 0));
            $lines[] = '  IPv4: ' . implode(', ', $interface['ipv4']);
            $lines[] = '  IPv6: ' . implode(', ', $interface['ipv6']);
        }
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('FILESYSTEMS', $colors);
        $lines[] = str_repeat('-', 78);
        foreach ($sections['filesystems'] ?? [] as $filesystem) {
            $lines[] = sprintf('%-26s %-12s %8s  %s',
                substr($filesystem['mount'], 0, 26),
                $filesystem['filesystem'],
                self::percent($filesystem['used_percent']),
                $filesystem['source']);
        }
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('FINDINGS', $colors);
        $lines[] = str_repeat('-', 78);
        if (($report['findings'] ?? []) === []) {
            $lines[] = 'No threshold findings';
        }
        foreach ($report['findings'] ?? [] as $finding) {
            $lines[] = '[' . $finding['severity'] . '] ' . $finding['code'] . ': ' . $finding['message'];
        }
        if (isset($report['drift'])) {
            $lines[] = '';
            $lines[] = ConsoleColor::wrapBold('CONFIGURATION DRIFT', $colors);
            $lines[] = str_repeat('-', 78);
            $lines[] = 'Changes: ' . $report['drift']['configuration_change_count'];
            foreach ($report['drift']['configuration_changes'] as $change) {
                $lines[] = '[' . $change['severity'] . '] ' . $change['path'];
                $lines[] = '  old: ' . self::value($change['old']);
                $lines[] = '  new: ' . self::value($change['new']);
            }
            $busy = $report['drift']['runtime_deltas']['cpu']['busy_percent'] ?? null;
            $lines[] = 'CPU busy between snapshots: ' . self::percent($busy);
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function percent(mixed $value): string
    {
        return is_numeric($value) ? number_format((float)$value, 2) . '%' : 'n/a';
    }

    private static function value(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
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

final class ExtendedOptions
{
    public static function parse(array $argv): array
    {
        $options = [
            'extended' => false,
            'watch' => false,
            'interval' => 5.0,
            'count' => 1,
            'format' => 'table',
            'database' => null,
            'history' => 0,
            'retention' => 0,
            'output' => null,
            'compare' => null,
            'memory_threshold' => 90.0,
            'swap_threshold' => 75.0,
            'disk_threshold' => 90.0,
            'color' => false,
            'help' => false,
        ];
        $valueKeys = [
            'interval', 'count', 'format', 'database', 'history', 'retention', 'output',
            'compare', 'memory-threshold', 'swap-threshold', 'disk-threshold',
        ];
        for ($index = 1; $index < count($argv); $index++) {
            $argument = $argv[$index];
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Unexpected argument: ' . $argument);
            }
            $raw = substr($argument, 2);
            $position = strpos($raw, '=');
            $name = $position === false ? $raw : substr($raw, 0, $position);
            $value = $position === false ? null : substr($raw, $position + 1);
            if ($name === 'extended') {
                $options['extended'] = true;
                continue;
            }
            if ($name === 'watch') {
                $options['watch'] = true;
                $options['extended'] = true;
                continue;
            }
            if ($name === 'no-color') {
                $options['color'] = false;
                continue;
            }
            if ($name === 'force-color') {
                $options['color'] = true;
                continue;
            }
            if ($name === 'help') {
                $options['help'] = true;
                continue;
            }
            if (!in_array($name, $valueKeys, true)) {
                throw new InvalidArgumentException('Unknown option: --' . $name);
            }
            if ($value === null) {
                if (!isset($argv[$index + 1]) || str_starts_with($argv[$index + 1], '--')) {
                    throw new InvalidArgumentException('Missing value for --' . $name);
                }
                $value = $argv[++$index];
            }
            $key = str_replace('-', '_', $name);
            $options[$key] = $value;
            if (in_array($name, ['database', 'history', 'retention', 'memory-threshold', 'swap-threshold', 'disk-threshold'], true)) {
                $options['extended'] = true;
            }
        }
        if (!in_array($options['format'], ['table', 'json', 'csv'], true)) {
            throw new InvalidArgumentException('Format must be table, json, or csv');
        }
        foreach (['interval', 'memory_threshold', 'swap_threshold', 'disk_threshold'] as $name) {
            if (!is_numeric($options[$name])) {
                throw new InvalidArgumentException('Invalid numeric value: ' . $name);
            }
            $options[$name] = (float)$options[$name];
        }
        foreach (['count', 'history', 'retention'] as $name) {
            $number = filter_var($options[$name], FILTER_VALIDATE_INT);
            if ($number === false || $number < 0) {
                throw new InvalidArgumentException('Invalid integer value: ' . $name);
            }
            $options[$name] = $number;
        }
        if ($options['interval'] < 0.2 || $options['interval'] > 86400) {
            throw new InvalidArgumentException('Interval must be between 0.2 and 86400 seconds');
        }
        foreach (['memory_threshold', 'swap_threshold', 'disk_threshold'] as $name) {
            if ($options[$name] < 0 || $options[$name] > 100) {
                throw new InvalidArgumentException('Threshold must be between 0 and 100: ' . $name);
            }
        }
        if ($options['history'] > 0 && $options['database'] === null) {
            throw new InvalidArgumentException('--history requires --database');
        }
        if ($options['retention'] > 0 && $options['database'] === null) {
            throw new InvalidArgumentException('--retention requires --database');
        }
        if ($options['watch'] && $options['output'] !== null) {
            throw new InvalidArgumentException('--watch cannot be combined with --output');
        }
        if ($options['watch'] && $options['compare'] !== null) {
            throw new InvalidArgumentException('--watch cannot be combined with --compare');
        }
        return $options;
    }

    public static function help(): string
    {
        return implode(PHP_EOL, [
            'observer-php extended Linux system observer',
            '',
            'Usage: php main.php --extended [options]',
            '',
            '  --extended                 Collect expanded system metrics',
            '  --format table|json|csv    Select report format',
            '  --output PATH              Write extended snapshot JSON',
            '  --compare PATH             Compare with previous extended snapshot',
            '  --database PATH            Enable SQLite snapshot history',
            '  --history N                Show N recent snapshots from database',
            '  --retention N              Keep N most recent snapshots globally',
            '  --watch                    Enable continuous monitoring',
            '  --interval SECONDS         Polling interval, default 5',
            '  --count N                  Watch iterations, 0 means unlimited',
            '  --memory-threshold PCT     Memory warning threshold',
            '  --swap-threshold PCT       Swap warning threshold',
            '  --disk-threshold PCT       Filesystem warning threshold',
            '  --force-color              Enable ANSI colors',
            '  --no-color                 Disable ANSI colors',
            '  --help                     Show help',
            '',
            'Legacy mode remains available without --extended.',
            '',
        ]) . PHP_EOL;
    }
}

final class ExtendedApplication
{
    private static bool $stop = false;

    public static function run(array $argv): int
    {
        try {
            if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80200) {
                throw new RuntimeException('Linux CLI with PHP 8.2 or newer is required');
            }
            $options = ExtendedOptions::parse($argv);
            if ($options['help']) {
                echo ExtendedOptions::help();
                return 0;
            }
            $database = $options['database'] === null ? null : new HistoryDatabase($options['database']);
            if ($options['history'] > 0) {
                $rows = $database->recent(HostInfo::hostname(), $options['history']);
                echo $options['format'] === 'csv'
                    ? CsvWriter::encode(array_map(static fn(array $row): array => [
                        'id' => $row['id'],
                        'created_at' => $row['created_at'],
                        'fingerprint' => $row['fingerprint'],
                    ], $rows))
                    : json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
                return 0;
            }
            if ($options['watch']) {
                return self::watch($options, $database);
            }
            self::once($options, $database);
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'error: ' . $error->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private static function once(array $options, ?HistoryDatabase $database): array
    {
        $diagnostics = new RuntimeDiagnostics();
        $snapshot = ExtendedSnapshot::capture($diagnostics);
        $findings = FindingEngine::analyze($snapshot, $diagnostics, [
            'memory' => $options['memory_threshold'],
            'swap' => $options['swap_threshold'],
            'disk' => $options['disk_threshold'],
        ]);
        $report = [
            'snapshot' => $snapshot,
            'fingerprint' => SnapshotFingerprint::configHash($snapshot),
            'findings' => $findings,
        ];
        $previous = null;
        if ($options['compare'] !== null) {
            $previous = JsonFile::load($options['compare']);
            if (($previous['schema_version'] ?? null) !== 2) {
                throw new RuntimeException('Comparison file must contain an extended schema v2 snapshot');
            }
        } elseif ($database !== null) {
            $previous = $database->latest($snapshot['hostname']);
        }
        if ($previous !== null) {
            $report['drift'] = DriftClassifier::compare($previous, $snapshot);
        }
        if ($options['output'] !== null) {
            JsonFile::save($options['output'], $snapshot);
        }
        if ($database !== null) {
            $report['history_id'] = $database->store($snapshot, $findings);
            if ($options['retention'] > 0) {
                $report['history_pruned'] = $database->prune($options['retention']);
            }
        }
        echo ExtendedReporter::render($report, $options['format'], $options['color']);
        return $snapshot;
    }

    private static function watch(array $options, ?HistoryDatabase $database): int
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): void { self::$stop = true; });
            pcntl_signal(SIGTERM, static function (): void { self::$stop = true; });
        }
        $iteration = 0;
        $previous = null;
        while (!self::$stop && ($options['count'] === 0 || $iteration < $options['count'])) {
            $started = RuntimeClock::monotonic();
            $diagnostics = new RuntimeDiagnostics();
            $snapshot = ExtendedSnapshot::capture($diagnostics);
            $findings = FindingEngine::analyze($snapshot, $diagnostics, [
                'memory' => $options['memory_threshold'],
                'swap' => $options['swap_threshold'],
                'disk' => $options['disk_threshold'],
            ]);
            $report = [
                'snapshot' => $snapshot,
                'fingerprint' => SnapshotFingerprint::configHash($snapshot),
                'findings' => $findings,
            ];
            $baseline = $previous ?? $database?->latest($snapshot['hostname']);
            if ($baseline !== null) {
                $report['drift'] = DriftClassifier::compare($baseline, $snapshot);
            }
            if ($database !== null) {
                $report['history_id'] = $database->store($snapshot, $findings);
                if ($options['retention'] > 0) {
                    $database->prune($options['retention']);
                }
            }
            echo ExtendedReporter::render($report, $options['format'], $options['color']);
            $previous = $snapshot;
            $iteration++;
            if (self::$stop || ($options['count'] > 0 && $iteration >= $options['count'])) {
                break;
            }
            $remaining = $options['interval'] - RuntimeClock::seconds($started);
            if ($remaining > 0) {
                usleep((int)min($remaining * 1000000, 2147483647));
            }
        }
        return 0;
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

if (in_array('--extended', $_SERVER['argv'], true) || in_array('--watch', $_SERVER['argv'], true) || array_filter($_SERVER['argv'], static fn(string $arg): bool => preg_match('/^--(database|history|retention|memory-threshold|swap-threshold|disk-threshold)(=|$)/', $arg) === 1)) {
    exit(ExtendedApplication::run($_SERVER['argv']));
}
exit(Application::run($_SERVER['argv']));
