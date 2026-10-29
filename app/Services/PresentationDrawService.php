<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class PresentationDrawService
{
    private string $directory;
    private array $config;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
        $this->config = json_decode((string) file_get_contents($directory . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $teams = $this->config['teams'] ?? [];
        if (!is_array($teams) || count($teams) < 2 || count($teams) > 200) {
            throw new RuntimeException('Invalid team roster');
        }
        $ids = [];
        foreach ($teams as $team) {
            if (!is_array($team) || !is_string($team['id'] ?? null) || $team['id'] === '' || !is_string($team['name'] ?? null) || $team['name'] === '' || isset($ids[$team['id']])) { throw new RuntimeException('Invalid team roster'); }
            $ids[$team['id']] = true;
        }
        if (!is_string($this->config['event'] ?? null) || $this->config['event'] === '' || !is_string($this->config['launch_code_sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $this->config['launch_code_sha256'])) { throw new RuntimeException('Invalid draw configuration'); }
        if (isset($this->config['start_at'])) {
            if (!is_string($this->config['start_at']) || !is_int($this->config['slot_minutes'] ?? null) || $this->config['slot_minutes'] < 1 || $this->config['slot_minutes'] > 480) { throw new RuntimeException('Invalid presentation schedule'); }
            new DateTimeImmutable($this->config['start_at']);
        }
    }

    public function teams(): array
    {
        return $this->config['teams'];
    }

    private function canonical(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function result(): ?array
    {
        $file = $this->directory . '/result.json';
        if (!is_file($file)) {
            return null;
        }
        $result = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $hash = $result['sha256'] ?? '';
        $payload = $result;
        unset($payload['sha256']);
        $expectedIds = array_column($this->teams(), 'id');
        $actualIds = array_column($result['order'] ?? [], 'id');
        sort($expectedIds);
        sort($actualIds);
        if (!is_string($hash) || !hash_equals(hash('sha256', $this->canonical($payload)), $hash)
            || $expectedIds !== $actualIds || ($result['event'] ?? '') !== $this->config['event']) {
            throw new RuntimeException('Stored draw integrity check failed');
        }
        return $result;
    }

    public function draw(string $code): array
    {
        if (!hash_equals((string) $this->config['launch_code_sha256'], hash('sha256', $code))) {
            throw new RuntimeException('Invalid launch code', 403);
        }
        $lock = fopen($this->directory . '/draw.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the draw');
        }
        try {
            $existing = $this->result();
            if ($existing !== null) {
                return $existing;
            }
            $order = $this->teams();
            // Fisher-Yates: each position uses a fresh uniform server-side integer.
            for ($i = count($order) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            }
            $payload = [
                'version' => 1,
                'event' => $this->config['event'],
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
                'algorithm' => 'Fisher-Yates / PHP random_int',
                'source_sha256' => hash_file('sha256', __FILE__),
                'teams_initial' => $this->teams(),
                'order' => $order,
            ];
            if (isset($this->config['start_at'])) { $payload['schedule'] = ['start_at' => $this->config['start_at'], 'slot_minutes' => $this->config['slot_minutes']]; }
            $result = $payload + ['sha256' => hash('sha256', $this->canonical($payload))];
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = tempnam($this->directory, '.draw-');
            if ($temporary === false) {
                throw new RuntimeException('Cannot prepare the draw result');
            }
            try {
                chmod($temporary, 0600);
                if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                    throw new RuntimeException('Cannot persist the draw result');
                }
                // The lock protects the existence check; rename publishes a complete file.
                if (file_exists($this->directory . '/result.json') || !rename($temporary, $this->directory . '/result.json')) {
                    throw new RuntimeException('Cannot publish the draw result');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            return $this->result();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
