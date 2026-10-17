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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create directory: $directory");
            }
        }

        $existingMode = null;
        if (file_exists($path)) {
            $perms = @fileperms($path);
            if ($perms !== false) {
                $existingMode = $perms & 0777;
            }
        }

        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

        try {
            if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException("Failed to write temporary file: $tmp");
            }

            if ($existingMode !== null && !@chmod($tmp, $existingMode)) {
                throw new RuntimeException("Failed to preserve file permissions for: $path");
            }

            if (!@rename($tmp, $path)) {
                throw new RuntimeException("Failed to move temporary file into place: $path");
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
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
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Timeout must be positive');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['output' => [], 'code' => -1, 'errors' => ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = hrtime(true);
        $timeoutNanoseconds = $timeoutSeconds * 1_000_000_000;
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                $selected = @stream_select($read, $write, $except, 0, 200000);
                if ($selected === false) {
                    break;
                }

                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = is_int($status['exitcode']) && $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                break;
            }

            if ((hrtime(true) - $startTime) > $timeoutNanoseconds) {
                $timedOut = true;
                @proc_terminate($process, 15);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    @proc_terminate($process, 9);
                }
                break;
            }
        }

        $remainingStdout = stream_get_contents($pipes[1]);
        $remainingStderr = stream_get_contents($pipes[2]);
        if ($remainingStdout !== false) {
            $stdout .= $remainingStdout;
        }
        if ($remainingStderr !== false) {
            $stderr .= $remainingStderr;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $closedCode = proc_close($process);
        $code = $timedOut ? 124 : ($exitCode ?? $closedCode);
        $lines = preg_split('/\R/', trim($stdout));
        if ($lines === false || $stdout === '') {
            $lines = [];
        }

        $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));

        return ['output' => $lines, 'code' => $code, 'errors' => trim($stderr)];
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
        return self::hostname();
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
    private const VALID_STATES = ['unknown', 'notpresent', 'down', 'lowerlayerdown', 'testing', 'dormant', 'up'];

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
        if ($this->name === '') {
            throw new InvalidArgumentException('Interface name must not be empty');
        }
        if ($this->mtu < 0) {
            throw new InvalidArgumentException("Interface {$this->name} has an invalid MTU");
        }
        if ($this->speedMbps !== null && $this->speedMbps < 0) {
            throw new InvalidArgumentException("Interface {$this->name} has an invalid speed");
        }
        if ($this->rxBytes < 0 || $this->txBytes < 0) {
            throw new InvalidArgumentException("Interface {$this->name} has invalid byte counters");
        }
        if ($this->operstate !== '' && !in_array($this->operstate, self::VALID_STATES, true)) {
            throw new InvalidArgumentException("Interface {$this->name} has an invalid operational state");
        }
        if ($this->macAddress !== '' && !filter_var($this->macAddress, FILTER_VALIDATE_MAC)) {
            throw new InvalidArgumentException("Interface {$this->name} has an invalid MAC address");
        }
        foreach ($this->ipv4Addresses as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("Interface {$this->name} has an invalid IPv4 address");
            }
        }
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

    public function structuralArray(): array
    {
        return [
            'name' => $this->name,
            'mac_address' => $this->macAddress,
            'operstate' => $this->operstate,
            'mtu' => $this->mtu,
            'speed_mbps' => $this->speedMbps,
            'ipv4_addresses' => $this->ipv4Addresses,
        ];
    }

    public static function fromArray(array $data): self
    {
        $name = self::requiredString($data, 'name');
        $macAddress = self::optionalString($data, 'mac_address');
        $operstate = self::optionalString($data, 'operstate');
        $mtu = self::requiredNonNegativeInt($data, 'mtu');
        $speedMbps = self::optionalNonNegativeInt($data, 'speed_mbps');
        $rxBytes = self::requiredNonNegativeInt($data, 'rx_bytes');
        $txBytes = self::requiredNonNegativeInt($data, 'tx_bytes');

        return new self(
            $name,
            $macAddress,
            $operstate,
            $mtu,
            $speedMbps,
            self::normalizeStringList($data['ipv4_addresses'] ?? []),
            $rxBytes,
            $txBytes
        );
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new RuntimeException("Invalid or missing interface field: $field");
        }
        return trim($data[$field]);
    }

    private static function optionalString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data)) {
            return '';
        }
        if (!is_string($data[$field])) {
            throw new RuntimeException("Invalid interface field: $field");
        }
        return trim($data[$field]);
    }

    private static function requiredNonNegativeInt(array $data, string $field): int
    {
        if (!array_key_exists($field, $data) || filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("Invalid or missing interface field: $field");
        }
        $value = (int)$data[$field];
        if ($value < 0) {
            throw new RuntimeException("Interface field must be non-negative: $field");
        }
        return $value;
    }

    private static function optionalNonNegativeInt(array $data, string $field): ?int
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }
        if (filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("Invalid interface field: $field");
        }
        $value = (int)$data[$field];
        if ($value < 0) {
            throw new RuntimeException("Interface field must be non-negative: $field");
        }
        return $value;
    }

    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Interface IPv4 addresses must be an array');
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new RuntimeException('Interface IPv4 addresses must contain non-empty strings');
            }
            if (filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException("Invalid IPv4 address in interface manifest: $item");
            }
            $out[$item] = true;
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
        if ($this->hostname === '') {
            throw new InvalidArgumentException('Hostname must not be empty');
        }
        if ($this->timestampEpoch < 0 || $this->cpuCount < 0 || $this->uptimeSeconds < 0.0) {
            throw new InvalidArgumentException('Manifest contains invalid non-negative values');
        }
        if ($this->memTotalBytes < 0 || $this->memAvailableBytes < 0) {
            throw new InvalidArgumentException('Manifest contains invalid memory values');
        }
        if ($this->memAvailableBytes > $this->memTotalBytes && $this->memTotalBytes > 0) {
            throw new InvalidArgumentException('Available memory cannot exceed total memory');
        }
        foreach ($this->loadavg as $value) {
            if (!is_float($value) && !is_int($value)) {
                throw new InvalidArgumentException('Load average values must be numeric');
            }
        }
        foreach ($this->ipAddresses as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('Manifest contains an invalid IPv4 address');
            }
        }
        foreach ($this->interfaces as $interface) {
            if (!$interface instanceof NetworkInterface) {
                throw new InvalidArgumentException('Manifest interfaces must contain NetworkInterface objects');
            }
        }
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
        $hostname = self::requiredString($data, 'hostname');
        $fqdn = self::optionalString($data, 'fqdn');
        $timestamp = self::optionalString($data, 'timestamp');
        $timestampEpoch = self::requiredNonNegativeInt($data, 'timestamp_epoch');
        $osRelease = self::optionalString($data, 'os_release');
        $kernel = self::optionalString($data, 'kernel');
        $arch = self::optionalString($data, 'arch');
        $cpuCount = self::requiredNonNegativeInt($data, 'cpu_count');
        $uptimeSeconds = self::requiredNonNegativeFloat($data, 'uptime_seconds');
        $memTotalBytes = self::requiredNonNegativeInt($data, 'mem_total_bytes');
        $memAvailableBytes = self::requiredNonNegativeInt($data, 'mem_available_bytes');
        $loadavg = self::normalizeFloatList($data['loadavg'] ?? null);
        $ipAddresses = self::normalizeStringList($data['ip_addresses'] ?? null);

        $rawInterfaces = $data['interfaces'] ?? null;
        if (!is_array($rawInterfaces)) {
            throw new RuntimeException('Manifest interfaces must be an array');
        }

        $interfaces = [];
        $seenInterfaces = [];
        foreach ($rawInterfaces as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Manifest interface entry must be an object');
            }
            $interface = NetworkInterface::fromArray($item);
            if (isset($seenInterfaces[$interface->name])) {
                throw new RuntimeException("Duplicate interface in manifest: {$interface->name}");
            }
            $seenInterfaces[$interface->name] = true;
            $interfaces[] = $interface;
        }

        return new self(
            $hostname,
            $fqdn,
            $timestamp,
            $timestampEpoch,
            $osRelease,
            $kernel,
            $arch,
            $cpuCount,
            $uptimeSeconds,
            $loadavg,
            $memTotalBytes,
            $memAvailableBytes,
            $ipAddresses,
            $interfaces
        );
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new RuntimeException("Invalid or missing manifest field: $field");
        }
        return trim($data[$field]);
    }

    private static function optionalString(array $data, string $field): string
    {
        if (!array_key_exists($field, $data)) {
            return '';
        }
        if (!is_string($data[$field])) {
            throw new RuntimeException("Invalid manifest field: $field");
        }
        return trim($data[$field]);
    }

    private static function requiredNonNegativeInt(array $data, string $field): int
    {
        if (!array_key_exists($field, $data) || filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("Invalid or missing manifest field: $field");
        }
        $value = (int)$data[$field];
        if ($value < 0) {
            throw new RuntimeException("Manifest field must be non-negative: $field");
        }
        return $value;
    }

    private static function requiredNonNegativeFloat(array $data, string $field): float
    {
        if (!array_key_exists($field, $data) || !is_numeric($data[$field])) {
            throw new RuntimeException("Invalid or missing manifest field: $field");
        }
        $value = (float)$data[$field];
        if (!is_finite($value) || $value < 0.0) {
            throw new RuntimeException("Manifest field must be a finite non-negative number: $field");
        }
        return $value;
    }

    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Manifest IP addresses must be an array');
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException('Manifest contains an invalid IPv4 address');
            }
            $out[$item] = true;
        }

        $items = array_keys($out);
        sort($items);
        return $items;
    }

    private static function normalizeFloatList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Manifest load average must be an array');
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_numeric($item)) {
                throw new RuntimeException('Manifest load average must contain numeric values');
            }
            $number = (float)$item;
            if (!is_finite($number) || $number < 0.0) {
                throw new RuntimeException('Manifest load average contains an invalid value');
            }
            $out[] = $number;
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
        $common = array_values(array_intersect(array_keys($oldMap), array_keys($newMap)));
        sort($common);

        foreach ($common as $name) {
            $before = $oldMap[$name]->structuralArray();
            $after = $newMap[$name]->structuralArray();

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
            'duration_seconds' => max(0, $new->timestampEpoch - $old->timestampEpoch),
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
            if (!$iface instanceof NetworkInterface) {
                throw new InvalidArgumentException('Interface collection contains an invalid value');
            }
            if (isset($map[$iface->name])) {
                throw new InvalidArgumentException("Duplicate interface name: {$iface->name}");
            }
            $map[$iface->name] = $iface;
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
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    private static function table(Manifest $manifest, bool $colors): string
    {
        $lines = [];
        $lines[] = ConsoleColor::wrapBold('SYSTEM MANIFEST', $colors);
        $lines[] = str_repeat('=', 72);
        $lines[] = self::label('Hostname:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->hostname;
        $lines[] = self::label('FQDN:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->fqdn;
        $lines[] = self::label('Timestamp:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->timestamp;
        $lines[] = self::label('OS Release:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->osRelease;
        $lines[] = self::label('Kernel:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->kernel;
        $lines[] = self::label('Architecture:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->arch;
        $lines[] = self::label('CPU Count:', 20, ConsoleColor::CYAN, $colors) . ' ' . $manifest->cpuCount;
        $lines[] = self::label('Uptime Seconds:', 20, ConsoleColor::CYAN, $colors) . ' ' . number_format($manifest->uptimeSeconds, 2, '.', '');
        $lines[] = self::label('Load Average:', 20, ConsoleColor::CYAN, $colors) . ' ' . implode(', ', array_map(
            static fn(float $v): string => number_format($v, 2, '.', ''),
            $manifest->loadavg
        ));
        $lines[] = self::label('Memory Total:', 20, ConsoleColor::CYAN, $colors) . ' ' . self::humanBytes($manifest->memTotalBytes);
        $lines[] = self::label('Memory Available:', 20, ConsoleColor::CYAN, $colors) . ' ' . self::humanBytes($manifest->memAvailableBytes);
        $lines[] = self::label('IP Addresses:', 20, ConsoleColor::CYAN, $colors) . ' ' . implode(', ', $manifest->ipAddresses);
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('INTERFACES', $colors);
        $lines[] = str_repeat('-', 72);
        $lines[] = self::cell('Name', 12, ConsoleColor::YELLOW, $colors)
            . self::cell('State', 10, ConsoleColor::YELLOW, $colors)
            . self::cell('MTU', 8, ConsoleColor::YELLOW, $colors)
            . self::cell('Speed', 8, ConsoleColor::YELLOW, $colors)
            . self::cell('RX', 17, ConsoleColor::YELLOW, $colors)
            . self::cell('TX', 16, ConsoleColor::YELLOW, $colors);

        foreach ($manifest->interfaces as $iface) {
            $stateColor = match ($iface->operstate) {
                'up' => ConsoleColor::GREEN,
                'down' => ConsoleColor::RED,
                default => ConsoleColor::WHITE,
            };

            $lines[] = self::cell($iface->name, 12, ConsoleColor::MAGENTA, $colors)
                . self::cell($iface->operstate, 10, $stateColor, $colors)
                . self::cell((string)$iface->mtu, 8, null, $colors)
                . self::cell($iface->speedMbps !== null ? $iface->speedMbps . 'M' : '-', 8, null, $colors)
                . self::cell(self::humanBytes($iface->rxBytes), 17, null, $colors)
                . self::cell(self::humanBytes($iface->txBytes), 16, null, $colors);
            $lines[] = str_repeat(' ', 38) . self::cell('MAC:', 17, ConsoleColor::CYAN, $colors) . $iface->macAddress;
            $lines[] = str_repeat(' ', 38) . self::cell('IPv4:', 17, ConsoleColor::CYAN, $colors) . implode(', ', $iface->ipv4Addresses);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function diffTable(array $diff, bool $colors): string
    {
        $lines = [];
        $lines[] = ConsoleColor::wrapBold('MANIFEST DIFF', $colors);
        $lines[] = str_repeat('=', 72);
        $lines[] = self::label('Old Timestamp:', 20, ConsoleColor::CYAN, $colors) . ' ' . (string)($diff['old_timestamp'] ?? '');
        $lines[] = self::label('New Timestamp:', 20, ConsoleColor::CYAN, $colors) . ' ' . (string)($diff['new_timestamp'] ?? '');
        $lines[] = self::label('Duration Seconds:', 20, ConsoleColor::CYAN, $colors) . ' ' . (int)($diff['duration_seconds'] ?? 0);
        $lines[] = '';
        $lines[] = ConsoleColor::wrapBold('STRUCTURAL FIELD CHANGES', $colors);
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
            $lines[] = 'No structural field changes';
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

    private static function label(string $text, int $width, string $color, bool $colors): string
    {
        return ConsoleColor::wrap(str_pad($text, $width), $color, $colors);
    }

    private static function cell(string $text, int $width, ?string $color, bool $colors): string
    {
        $cell = str_pad(self::truncate($text, max(0, $width - 1)), $width);
        return $color === null ? $cell : ConsoleColor::wrap($cell, $color, $colors);
    }

    private static function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength);
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
            try {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return '[array]';
            }
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
                return 0;
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

final class Observatory
{
    private static function text(string $path): ?string
    {
        $value = @file_get_contents($path);
        return $value === false ? null : trim($value);
    }

    private static function number(string $path): ?int
    {
        $value = self::text($path);
        return $value !== null && preg_match('/^-?\d+$/', $value) ? (int)$value : null;
    }

    private static function keyed(string $path): array
    {
        $text = self::text($path);
        $result = [];
        if ($text === null) {
            return $result;
        }
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^([^:\s]+):\s*(\d+)/', $line, $m)) {
                $result[$m[1]] = (int)$m[2];
            }
        }
        return $result;
    }

    private static function cpu(): array
    {
        $stat = self::text('/proc/stat');
        $totals = [];
        if ($stat !== null) {
            foreach (preg_split('/\R/', $stat) ?: [] as $line) {
                if (!preg_match('/^(cpu\d*)\s+(.+)$/', $line, $m)) {
                    continue;
                }
                $fields = preg_split('/\s+/', trim($m[2])) ?: [];
                $values = array_map('intval', $fields);
                $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
                $totals[$m[1]] = ['total' => array_sum($values), 'idle' => $idle];
            }
        }
        $pressure = self::pressure('/proc/pressure/cpu');
        return ['ticks' => $totals, 'pressure' => $pressure, 'load' => HostInfo::loadAvg()];
    }

    private static function pressure(string $path): array
    {
        $text = self::text($path);
        $result = [];
        if ($text === null) {
            return $result;
        }
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!preg_match('/^(some|full)\s+(.+)$/', $line, $m)) {
                continue;
            }
            $values = [];
            foreach (preg_split('/\s+/', $m[2]) ?: [] as $part) {
                if (str_contains($part, '=')) {
                    [$key, $value] = explode('=', $part, 2);
                    $values[$key] = $key === 'total' ? (int)$value : (float)$value;
                }
            }
            $result[$m[1]] = $values;
        }
        return $result;
    }

    private static function memory(): array
    {
        $data = HostInfo::meminfo();
        $total = $data['MemTotal'] ?? null;
        $available = $data['MemAvailable'] ?? null;
        return [
            'total_bytes' => $total,
            'available_bytes' => $available,
            'used_percent' => $total > 0 && $available !== null ? round(100 * ($total - $available) / $total, 3) : null,
            'swap_total_bytes' => $data['SwapTotal'] ?? null,
            'swap_free_bytes' => $data['SwapFree'] ?? null,
            'pressure' => self::pressure('/proc/pressure/memory'),
            'vmstat' => self::vmstat(),
        ];
    }

    private static function vmstat(): array
    {
        $text = self::text('/proc/vmstat');
        $wanted = ['pgfault', 'pgmajfault', 'pswpin', 'pswpout', 'oom_kill'];
        $result = [];
        foreach (preg_split('/\R/', $text ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts ?: []) === 2 && in_array($parts[0], $wanted, true)) {
                $result[$parts[0]] = (int)$parts[1];
            }
        }
        return $result;
    }

    private static function disks(): array
    {
        $mounts = self::text('/proc/self/mountinfo');
        $result = [];
        $ignored = ['proc', 'sysfs', 'devpts', 'cgroup', 'cgroup2', 'securityfs', 'pstore', 'debugfs', 'tracefs', 'configfs', 'mqueue', 'hugetlbfs', 'fusectl', 'binfmt_misc', 'autofs'];
        foreach (preg_split('/\R/', $mounts ?? '') ?: [] as $line) {
            $halves = explode(' - ', $line, 2);
            if (count($halves) !== 2) {
                continue;
            }
            $left = explode(' ', $halves[0]);
            $right = explode(' ', $halves[1]);
            $type = $right[0] ?? '';
            if (in_array($type, $ignored, true)) {
                continue;
            }
            $mount = str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $left[4] ?? '');
            if ($mount === '' || isset($result[$mount])) {
                continue;
            }
            $total = @disk_total_space($mount);
            $free = @disk_free_space($mount);
            $result[$mount] = [
                'filesystem' => $right[1] ?? '',
                'type' => $type,
                'total_bytes' => $total === false ? null : (int)$total,
                'free_bytes' => $free === false ? null : (int)$free,
                'used_percent' => $total > 0 && $free !== false ? round(100 * ($total - $free) / $total, 3) : null,
            ];
        }
        ksort($result);
        return $result;
    }

    private static function diskIo(): array
    {
        $text = self::text('/proc/diskstats');
        $result = [];
        foreach (preg_split('/\R/', $text ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts ?: []) < 14) {
                continue;
            }
            $name = $parts[2];
            $result[$name] = [
                'reads' => (int)$parts[3],
                'read_bytes' => (int)$parts[5] * 512,
                'writes' => (int)$parts[7],
                'write_bytes' => (int)$parts[9] * 512,
                'io_ms' => (int)$parts[12],
            ];
        }
        ksort($result);
        return $result;
    }

    private static function network(): array
    {
        $result = [];
        foreach (@scandir('/sys/class/net') ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $base = '/sys/class/net/' . $name;
            $statistics = [];
            foreach (['rx_bytes', 'tx_bytes', 'rx_packets', 'tx_packets', 'rx_errors', 'tx_errors', 'rx_dropped', 'tx_dropped'] as $key) {
                $statistics[$key] = self::number($base . '/statistics/' . $key);
            }
            $result[$name] = [
                'state' => self::text($base . '/operstate'),
                'mac' => self::text($base . '/address'),
                'mtu' => self::number($base . '/mtu'),
                'speed_mbps' => ($speed = self::number($base . '/speed')) !== null && $speed >= 0 ? $speed : null,
                'statistics' => $statistics,
            ];
        }
        ksort($result);
        return $result;
    }

    private static function security(): array
    {
        $selinux = self::number('/sys/fs/selinux/enforce');
        $lockdown = self::text('/sys/kernel/security/lockdown');
        $lsm = self::text('/sys/kernel/security/lsm');
        return [
            'selinux_enforcing' => $selinux === null ? null : $selinux === 1,
            'selinux_policy' => self::text('/sys/fs/selinux/policyvers'),
            'lockdown' => $lockdown,
            'lsm' => $lsm === null ? null : array_map('trim', explode(',', $lsm)),
            'unprivileged_bpf_disabled' => self::number('/proc/sys/kernel/unprivileged_bpf_disabled'),
            'kptr_restrict' => self::number('/proc/sys/kernel/kptr_restrict'),
            'dmesg_restrict' => self::number('/proc/sys/kernel/dmesg_restrict'),
            'yama_ptrace_scope' => self::number('/proc/sys/kernel/yama/ptrace_scope'),
            'randomize_va_space' => self::number('/proc/sys/kernel/randomize_va_space'),
        ];
    }

    private static function processes(): array
    {
        $counts = ['total' => 0, 'running' => 0, 'sleeping' => 0, 'uninterruptible' => 0, 'zombie' => 0, 'other' => 0];
        foreach (@scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $status = self::text('/proc/' . $entry . '/status');
            if ($status === null || !preg_match('/^State:\s+([A-Z])/m', $status, $m)) {
                continue;
            }
            $counts['total']++;
            $key = match ($m[1]) {
                'R' => 'running',
                'S' => 'sleeping',
                'D' => 'uninterruptible',
                'Z' => 'zombie',
                default => 'other',
            };
            $counts[$key]++;
        }
        return $counts;
    }

    public static function capture(): array
    {
        $manifest = ManifestBuilder::build()->toArray();
        return [
            'schema_version' => 2,
            'captured_at' => gmdate('c'),
            'captured_epoch' => microtime(true),
            'boot_id' => self::text('/proc/sys/kernel/random/boot_id'),
            'manifest' => $manifest,
            'cpu' => self::cpu(),
            'memory' => self::memory(),
            'mounts' => self::disks(),
            'disk_io' => self::diskIo(),
            'network' => self::network(),
            'security' => self::security(),
            'processes' => self::processes(),
            'io_pressure' => self::pressure('/proc/pressure/io'),
        ];
    }

    public static function rates(array $old, array $new): array
    {
        $elapsed = ($new['captured_epoch'] ?? 0) - ($old['captured_epoch'] ?? 0);
        if ($elapsed <= 0 || ($old['boot_id'] ?? null) !== ($new['boot_id'] ?? null)) {
            return ['elapsed_seconds' => max(0, $elapsed), 'available' => false];
        }
        $result = ['elapsed_seconds' => round($elapsed, 6), 'available' => true, 'cpu' => [], 'network' => [], 'disk_io' => []];
        foreach ($new['cpu']['ticks'] ?? [] as $name => $current) {
            $previous = $old['cpu']['ticks'][$name] ?? null;
            if (!is_array($previous)) {
                continue;
            }
            $total = $current['total'] - $previous['total'];
            $idle = $current['idle'] - $previous['idle'];
            if ($total > 0 && $idle >= 0 && $idle <= $total) {
                $result['cpu'][$name] = round(100 * ($total - $idle) / $total, 3);
            }
        }
        foreach (['network' => 'statistics', 'disk_io' => null] as $section => $nested) {
            foreach ($new[$section] ?? [] as $name => $current) {
                $previous = $old[$section][$name] ?? null;
                if (!is_array($previous)) {
                    continue;
                }
                $currentCounters = $nested === null ? $current : ($current[$nested] ?? []);
                $previousCounters = $nested === null ? $previous : ($previous[$nested] ?? []);
                foreach ($currentCounters as $key => $value) {
                    $before = $previousCounters[$key] ?? null;
                    if (is_int($value) && is_int($before) && $value >= $before) {
                        $result[$section][$name][$key . '_per_sec'] = round(($value - $before) / $elapsed, 3);
                    }
                }
            }
        }
        return $result;
    }

    public static function changes(array $old, array $new): array
    {
        $changes = [];
        foreach (['manifest', 'mounts', 'network', 'security'] as $section) {
            $a = $old[$section] ?? [];
            $b = $new[$section] ?? [];
            if ($section === 'manifest') {
                foreach (['timestamp', 'timestamp_epoch', 'uptime_seconds', 'loadavg', 'mem_available_bytes'] as $key) {
                    unset($a[$key], $b[$key]);
                }
                foreach (['interfaces'] as $key) {
                    if (isset($a[$key]) && is_array($a[$key])) {
                        foreach ($a[$key] as &$iface) {
                            unset($iface['rx_bytes'], $iface['tx_bytes']);
                        }
                        unset($iface);
                    }
                    if (isset($b[$key]) && is_array($b[$key])) {
                        foreach ($b[$key] as &$iface) {
                            unset($iface['rx_bytes'], $iface['tx_bytes']);
                        }
                        unset($iface);
                    }
                }
            }
            if ($section === 'network') {
                foreach ($a as &$iface) {
                    unset($iface['statistics']);
                }
                unset($iface);
                foreach ($b as &$iface) {
                    unset($iface['statistics']);
                }
                unset($iface);
            }
            if ($section === 'mounts') {
                foreach ($a as &$mount) {
                    unset($mount['free_bytes'], $mount['used_percent']);
                }
                unset($mount);
                foreach ($b as &$mount) {
                    unset($mount['free_bytes'], $mount['used_percent']);
                }
                unset($mount);
            }
            self::walkDiff($section, $a, $b, $changes);
        }
        return $changes;
    }

    private static function walkDiff(string $path, mixed $a, mixed $b, array &$changes): void
    {
        if (is_array($a) && is_array($b)) {
            $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
            sort($keys);
            foreach ($keys as $key) {
                $existsA = array_key_exists($key, $a);
                $existsB = array_key_exists($key, $b);
                $next = $path . '.' . $key;
                if (!$existsA || !$existsB) {
                    $changes[] = ['path' => $next, 'before' => $existsA ? $a[$key] : null, 'after' => $existsB ? $b[$key] : null, 'kind' => $existsA ? 'removed' : 'added'];
                } else {
                    self::walkDiff($next, $a[$key], $b[$key], $changes);
                }
            }
        } elseif ($a !== $b) {
            $changes[] = ['path' => $path, 'before' => $a, 'after' => $b, 'kind' => 'changed'];
        }
    }
}

final class ObservatoryCli
{
    private static function options(array $argv): array
    {
        $defaults = ['format' => 'table', 'output' => null, 'compare' => null, 'watch' => false, 'interval' => 2.0, 'count' => 0, 'cpu-warn' => 85.0, 'memory-warn' => 90.0, 'disk-warn' => 90.0, 'no-color' => false, 'help' => false];
        $flags = ['watch', 'no-color', 'help'];
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (!str_starts_with($arg, '--')) {
                throw new InvalidArgumentException('Unexpected argument: ' . $arg);
            }
            $pair = explode('=', substr($arg, 2), 2);
            $name = $pair[0];
            if (!array_key_exists($name, $defaults)) {
                throw new InvalidArgumentException('Unknown option: --' . $name);
            }
            if (in_array($name, $flags, true)) {
                if (count($pair) > 1) {
                    throw new InvalidArgumentException('Flag does not accept a value: --' . $name);
                }
                $defaults[$name] = true;
                continue;
            }
            $value = $pair[1] ?? ($argv[++$i] ?? null);
            if ($value === null || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('Missing value for --' . $name);
            }
            $defaults[$name] = $value;
        }
        if (!in_array($defaults['format'], ['table', 'json', 'jsonl'], true)) {
            throw new InvalidArgumentException('Format must be table, json, or jsonl');
        }
        foreach (['interval', 'cpu-warn', 'memory-warn', 'disk-warn'] as $name) {
            if (!is_numeric($defaults[$name])) {
                throw new InvalidArgumentException('Invalid numeric value for --' . $name);
            }
            $defaults[$name] = (float)$defaults[$name];
            if (!is_finite($defaults[$name]) || $defaults[$name] < 0) {
                throw new InvalidArgumentException('Invalid range for --' . $name);
            }
        }
        if ($defaults['interval'] < 0.1) {
            throw new InvalidArgumentException('Interval must be at least 0.1 seconds');
        }
        if (filter_var($defaults['count'], FILTER_VALIDATE_INT) === false || (int)$defaults['count'] < 0) {
            throw new InvalidArgumentException('Count must be a nonnegative integer');
        }
        $defaults['count'] = (int)$defaults['count'];
        if ($defaults['compare'] !== null && $defaults['watch']) {
            throw new InvalidArgumentException('Compare and watch are mutually exclusive');
        }
        return $defaults;
    }

    private static function encode(array $data, bool $pretty): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0)) . PHP_EOL;
    }

    private static function output(string $text, ?string $path, bool $append = false): void
    {
        if ($path === null || $path === '-') {
            echo $text;
            return;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create directory: ' . $directory);
        }
        if ($append) {
            if (@file_put_contents($path, $text, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Cannot append to: ' . $path);
            }
        } else {
            $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
            try {
                if (@file_put_contents($tmp, $text, LOCK_EX) === false || !@rename($tmp, $path)) {
                    throw new RuntimeException('Cannot write: ' . $path);
                }
            } finally {
                @unlink($tmp);
            }
        }
    }

    private static function alerts(array $snapshot, ?array $rates, array $options): array
    {
        $alerts = [];
        $cpu = $rates['cpu']['cpu'] ?? null;
        if ($cpu !== null && $cpu >= $options['cpu-warn']) {
            $alerts[] = ['metric' => 'cpu_percent', 'value' => $cpu, 'threshold' => $options['cpu-warn']];
        }
        $memory = $snapshot['memory']['used_percent'] ?? null;
        if ($memory !== null && $memory >= $options['memory-warn']) {
            $alerts[] = ['metric' => 'memory_used_percent', 'value' => $memory, 'threshold' => $options['memory-warn']];
        }
        foreach ($snapshot['mounts'] as $path => $mount) {
            $used = $mount['used_percent'];
            if ($used !== null && $used >= $options['disk-warn']) {
                $alerts[] = ['metric' => 'disk_used_percent', 'mount' => $path, 'value' => $used, 'threshold' => $options['disk-warn']];
            }
        }
        return $alerts;
    }

    private static function table(array $report): string
    {
        $snapshot = $report['snapshot'];
        $manifest = $snapshot['manifest'];
        $lines = [
            'OBSERVER PHP 2.0.0',
            str_repeat('=', 76),
            sprintf('Host: %-28s OS: %s', $manifest['hostname'], $manifest['os_release']),
            sprintf('Kernel: %-26s Captured: %s', $manifest['kernel'], $snapshot['captured_at']),
            sprintf('CPU: %d cores   Load: %s   Uptime: %.0f s', $manifest['cpu_count'], implode(' / ', $manifest['loadavg']), $manifest['uptime_seconds']),
            sprintf('Memory used: %s%%   Processes: %d   Zombies: %d', $snapshot['memory']['used_percent'] ?? 'n/a', $snapshot['processes']['total'], $snapshot['processes']['zombie']),
            sprintf('SELinux enforcing: %s', ($snapshot['security']['selinux_enforcing'] ?? null) === null ? 'unavailable' : ($snapshot['security']['selinux_enforcing'] ? 'yes' : 'no')),
            '',
            'NETWORK INTERFACES',
            str_repeat('-', 76),
            sprintf('%-17s %-12s %10s %14s %14s', 'Name', 'State', 'MTU', 'RX bytes', 'TX bytes'),
        ];
        foreach ($snapshot['network'] as $name => $iface) {
            $s = $iface['statistics'];
            $lines[] = sprintf('%-17.17s %-12.12s %10s %14s %14s', $name, $iface['state'] ?? '-', $iface['mtu'] ?? '-', $s['rx_bytes'] ?? '-', $s['tx_bytes'] ?? '-');
        }
        $lines[] = '';
        $lines[] = 'MOUNTS';
        $lines[] = str_repeat('-', 76);
        foreach ($snapshot['mounts'] as $path => $mount) {
            $lines[] = sprintf('%-42.42s %-12.12s %8s%%', $path, $mount['type'], $mount['used_percent'] ?? 'n/a');
        }
        if (isset($report['rates'])) {
            $rates = $report['rates'];
            $lines[] = '';
            $lines[] = 'INTERVAL RATES';
            $lines[] = str_repeat('-', 76);
            $lines[] = sprintf('Elapsed: %.3f s   CPU busy: %s%%', $rates['elapsed_seconds'], $rates['cpu']['cpu'] ?? 'n/a');
            foreach ($rates['network'] ?? [] as $name => $values) {
                $lines[] = sprintf('%-17s RX %12s B/s   TX %12s B/s', $name, $values['rx_bytes_per_sec'] ?? '-', $values['tx_bytes_per_sec'] ?? '-');
            }
        }
        if (isset($report['changes'])) {
            $lines[] = '';
            $lines[] = 'STRUCTURAL CHANGES: ' . count($report['changes']);
            foreach ($report['changes'] as $change) {
                $lines[] = sprintf('%s %s', strtoupper($change['kind']), $change['path']);
            }
        }
        if (!empty($report['alerts'])) {
            $lines[] = '';
            $lines[] = 'THRESHOLD ALERTS';
            foreach ($report['alerts'] as $alert) {
                $lines[] = sprintf('%s %s >= %s', $alert['metric'] . (isset($alert['mount']) ? ' ' . $alert['mount'] : ''), $alert['value'], $alert['threshold']);
            }
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public static function run(array $argv): int
    {
        try {
            if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80200) {
                throw new RuntimeException('Linux CLI and PHP 8.2+ are required');
            }
            $options = self::options($argv);
            if ($options['help']) {
                echo "Observer PHP 2.0.0\n\n";
                echo "Usage: php main.php [--format table|json|jsonl] [--output PATH] [--compare PATH]\n";
                echo "       php main.php --watch [--interval SECONDS] [--count N] [--format table|jsonl]\n";
                echo "Options: --cpu-warn PERCENT --memory-warn PERCENT --disk-warn PERCENT --no-color --help\n";
                return 0;
            }
            if ($options['watch'] && $options['format'] === 'json') {
                throw new InvalidArgumentException('Use jsonl or table for continuous monitoring');
            }
            $previous = null;
            if ($options['compare'] !== null) {
                $previous = JsonFile::load($options['compare']);
                if (($previous['schema_version'] ?? null) !== 2) {
                    throw new RuntimeException('Comparison requires a version 2 snapshot');
                }
            }
            $iteration = 0;
            do {
                $snapshot = Observatory::capture();
                $report = ['snapshot' => $snapshot];
                if ($previous !== null) {
                    $report['changes'] = Observatory::changes($previous, $snapshot);
                    $report['rates'] = Observatory::rates($previous, $snapshot);
                }
                $report['alerts'] = self::alerts($snapshot, $report['rates'] ?? null, $options);
                if (!$options['watch'] && $options['compare'] === null && $options['output'] !== null) {
                    self::output(self::encode($snapshot, true), $options['output']);
                } else {
                    $text = $options['format'] === 'table' ? self::table($report) : self::encode($report, $options['format'] === 'json');
                    self::output($text, $options['output'], $options['watch']);
                }
                $previous = $snapshot;
                $iteration++;
                if (!$options['watch'] || ($options['count'] > 0 && $iteration >= $options['count'])) {
                    break;
                }
                usleep((int)round($options['interval'] * 1000000));
            } while (true);
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'error: ' . $error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}

exit(ObservatoryCli::run($_SERVER['argv']));
