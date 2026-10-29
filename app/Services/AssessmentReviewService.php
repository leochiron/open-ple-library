<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AssessmentReviewService
{
    public const STATUSES = ['pending' => 'À vérifier', 'incomplete' => 'À compléter', 'verified' => 'Vérifié'];
    private string $storage;
    private $http;
    public function __construct(string $storage, ?callable $http = null)
    {
        $this->storage = rtrim($storage, '/\\');
        $this->http = $http;
    }
    public function courses(): array
    {
        return array_filter(AssessmentRegistry::load($this->storage), static fn(array $definition): bool => isset($definition['deadline']) && $definition['submission']);
    }
    private function course(string $course): array
    {
        if (!isset($this->courses()[$course])) { throw new RuntimeException('Épreuve inconnue.'); }
        return $this->courses()[$course];
    }
    private function id(string $id): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) { throw new RuntimeException('Remise inconnue.'); }
    }
    public static function paris(string $date): string
    {
        return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i:s');
    }
    public function deadline(string $course): string
    {
        $config = $this->course($course);
        $path = $this->reviewDirectory($course) . '/settings.json';
        if (!is_file($path)) { return $config['deadline']; }
        $data = $this->jsonFile($path);
        if (!is_string($data['deadline'] ?? null) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/', $data['deadline'])) { throw new RuntimeException('Échéance illisible : vérifiez la configuration.'); }
        new DateTimeImmutable($data['deadline']);
        return $data['deadline'];
    }
    public function saveDeadline(string $course, string $value): void
    {
        $this->course($course);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) { throw new RuntimeException('Indiquez une date et une heure valides, heure de Paris.'); }
        $path = $this->reviewDirectory($course) . '/settings.json';
        $this->writeLocked($path, function (array $old) use ($date): array { return ['deadline' => $date->format(DATE_ATOM)]; });
    }
    private function reviewDirectory(string $course): string
    {
        $this->course($course);
        return $this->storage . '/remises-review/' . $course;
    }
    private function jsonFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) { throw new RuntimeException('Lecture impossible.'); }
        $data = json_decode($raw, true);
        if (!is_array($data)) { throw new RuntimeException('Document illisible.'); }
        return $data;
    }
    public function all(string $course): array
    {
        $this->course($course);
        $deadline = new DateTimeImmutable($this->deadline($course));
        $directory = $this->storage . '/remises-cc/' . $course;
        if (is_dir($directory) && !is_readable($directory)) { throw new RuntimeException('Le dossier de remises ne peut pas être lu.'); }
        $records = []; $warnings = [];
        foreach (glob($directory . '/*.json') ?: [] as $file) {
            try {
                $id = pathinfo($file, PATHINFO_FILENAME); $this->id($id);
                $r = $this->jsonFile($file);
                foreach (['nom', 'prenom', 'reference', 'received_at', 'github_url'] as $key) {
                    if (!is_string($r[$key] ?? null) || trim($r[$key]) === '') { throw new RuntimeException('Champ manquant.'); }
                }
                if (($r['assessment'] ?? '') !== $course || $r['reference'] !== $course . '-' . strtoupper(substr($id, 0, 12))) { throw new RuntimeException('Référence incohérente.'); }
                if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/', $r['received_at'])) { throw new RuntimeException('Date invalide.'); }
                self::repository($r['github_url']);
                $date = new DateTimeImmutable($r['received_at']);
                if ($date->format(DATE_ATOM) !== $r['received_at']) { throw new RuntimeException('Date invalide.'); }
                $r['id'] = $id; $r['late'] = $date > $deadline;
                $r['received_label'] = self::paris($r['received_at']);
                $r['review'] = $this->review($course, $id);
                $records[] = $r;
            } catch (\Throwable $e) { $warnings[] = 'Une remise ou son suivi est illisible (' . basename($file) . ').'; }
        }
        usort($records, static function (array $a, array $b): int { return strtotime($b['received_at']) <=> strtotime($a['received_at']); });
        return ['records' => $records, 'warnings' => $warnings];
    }
    public function get(string $course, string $id): array
    {
        $this->id($id);
        foreach ($this->all($course)['records'] as $r) { if ($r['id'] === $id) { return $r; } }
        throw new RuntimeException('Remise inconnue ou illisible.');
    }
    public function review(string $course, string $id): array
    {
        $this->course($course); $this->id($id);
        $path = $this->reviewDirectory($course) . '/' . $id . '.json';
        $r = is_file($path) ? $this->jsonFile($path) : [];
        $r += ['status' => 'pending', 'comment' => '', 'version' => 0, 'check' => null];
        if (!isset(self::STATUSES[$r['status']]) || !is_string($r['comment']) || !is_int($r['version'])) { throw new RuntimeException('Suivi illisible.'); }
        return $r;
    }
    private function writeLocked(string $path, callable $update): array
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new RuntimeException('Enregistrement impossible.'); }
        $lock = fopen($path . '.lock', 'c');
        if (!$lock) { throw new RuntimeException('Verrouillage impossible.'); }
        chmod($path . '.lock', 0600);
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Verrouillage impossible.'); }
            $old = is_file($path) ? $this->jsonFile($path) : [];
            $new = $update($old);
            $temporary = tempnam($directory, '.pending-');
            if ($temporary === false) { throw new RuntimeException('Enregistrement impossible.'); }
            chmod($temporary, 0600);
            $json = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json . "\n") !== strlen($json) + 1 || !rename($temporary, $path)) { throw new RuntimeException('Enregistrement impossible.'); }
            return $new;
        } finally {
            if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    public function saveReview(string $course, string $id, string $status, string $comment, int $version, string $admin): void
    {
        $this->get($course, $id);
        if (!isset(self::STATUSES[$status]) || strlen($comment) > 10000) { throw new RuntimeException('Statut invalide ou commentaire trop long (10 000 octets maximum).'); }
        $this->writeLocked($this->reviewDirectory($course) . '/' . $id . '.json', function (array $r) use ($status, $comment, $version, $admin): array {
            if (($r['version'] ?? 0) !== $version) { throw new RuntimeException('Le suivi a changé dans un autre onglet. Rechargez avant de modifier.'); }
            $r['status'] = $status; $r['comment'] = $comment; $r['version'] = $version + 1;
            $r['updated_by'] = $admin; $r['updated_at'] = date(DATE_ATOM); return $r;
        });
    }
    public static function repository(string $url): array
    {
        if (!preg_match('~\Ahttps://(?:www\.)?github\.com/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)/([a-z0-9_.-]{1,100})/?\z~i', $url, $m) || in_array($m[2], ['.', '..'], true)) { throw new RuntimeException('URL GitHub invalide.'); }
        return [$m[1], preg_replace('/\.git$/', '', $m[2])];
    }
    private function request(string $path): array
    {
        if ($this->http !== null) { return ($this->http)($path); }
        if (!function_exists('curl_init')) { throw new RuntimeException('Contrôle temporairement impossible : connexion GitHub indisponible.'); }
        $curl = curl_init('https://api.github.com' . $path);
        $body = '';
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: PLE-Assessment-Review', 'X-GitHub-Api-Version: 2022-11-28'], CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 2000000) { return 0; } $body .= $chunk; return strlen($chunk); }]);
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($ok === false || $status === 0 || $status === 403 || $status === 429 || $status >= 500 || ($status >= 300 && $status < 400)) { throw new RuntimeException('Contrôle temporairement impossible : GitHub indisponible ou limite de requêtes atteinte. Réessayez plus tard.'); }
        $data = json_decode($body, true);
        if (!is_array($data)) { throw new RuntimeException('Contrôle temporairement impossible : réponse GitHub illisible.'); }
        return ['status' => $status, 'data' => $data];
    }
    public function checkRepository(string $url, string $document = 'EXPLICATIONS.md'): array
    {
        if (!in_array($document, ['EXPLICATIONS.md', 'README.md'], true)) { throw new RuntimeException('Document de contrôle inconnu.'); }
        [$owner, $name] = self::repository($url);
        $base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name);
        $r = $this->request($base);
        if ($r['status'] === 404) { return ['state' => 'inaccessible', 'message' => 'Dépôt inaccessible sans authentification : privé, absent ou renommé.', 'public' => false]; }
        if ($r['status'] !== 200 || !array_key_exists('private', $r['data'])) { throw new RuntimeException('Contrôle temporairement impossible : réponse GitHub inattendue.'); }
        if ($r['data']['private'] !== false) { return ['state' => 'inaccessible', 'message' => 'Dépôt non accessible publiquement.', 'public' => false]; }
        $branches = $this->request($base . '/branches?per_page=100');
        if ($branches['status'] !== 200 || !array_is_list($branches['data'])) { throw new RuntimeException('Contrôle temporairement impossible : branches non consultables.'); }
        $names = [];
        foreach ($branches['data'] as $b) { if (!is_string($b['name'] ?? null)) { throw new RuntimeException('Réponse des branches illisible.'); } $names[] = $b['name']; }
        $result = ['public' => true, 'branches' => $names, 'branches_limited' => count($names) === 100, 'main_sha' => null, 'explanations' => false];
        $commit = $this->request($base . '/commits/main');
        if ($commit['status'] === 404 || $commit['status'] === 409) { return $result + ['state' => 'missing', 'message' => 'Dépôt public, mais main est absente ou sans commit.']; }
        $sha = $commit['data']['sha'] ?? '';
        if ($commit['status'] !== 200 || !preg_match('/\A[a-f0-9]{40}\z/', $sha)) { throw new RuntimeException('Contrôle temporairement impossible : commit main non consultable.'); }
        $result['main_sha'] = $sha;
        $file = $this->request($base . '/contents/' . rawurlencode($document) . '?ref=' . $sha);
        if ($file['status'] !== 200 && $file['status'] !== 404) { throw new RuntimeException('Contrôle temporairement impossible : fichier non consultable.'); }
        $result['explanations'] = $file['status'] === 200 && ($file['data']['type'] ?? '') === 'file' && ($file['data']['size'] ?? 0) > 0;
        return $result + ['state' => $result['explanations'] ? 'observed' : 'missing', 'message' => $result['explanations'] ? 'Dépôt public et ' . $document . ' présent. La qualité et les branches restent à vérifier.' : $document . ' absent, vide ou non reconnu comme fichier sur main.'];
    }
    public function runCheck(string $course, string $id, string $admin): array
    {
        $r = $this->get($course, $id);
        try { $check = $this->checkRepository($r['github_url'], $this->course($course)['document'] ?? 'EXPLICATIONS.md'); }
        catch (RuntimeException $e) { $check = ['state' => 'unavailable', 'message' => $e->getMessage()]; }
        $check['checked_at'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
        $check['checked_by'] = $admin;
        $this->writeLocked($this->reviewDirectory($course) . '/' . $id . '.json', static function (array $old) use ($check): array {
            $old += ['status' => 'pending', 'comment' => '', 'version' => 0];
            $history = $old['check_history'] ?? [];
            $history[] = $check;
            $old['check_history'] = array_slice($history, -20);
            $old['check'] = $check; $old['version']++; return $old;
        });
        return $check;
    }
    public function filtered(array $records, string $search, string $status, string $timing): array
    {
        return array_values(array_filter($records, static function (array $r) use ($search, $status, $timing): bool {
            $name = $r['nom'] . ' ' . $r['prenom'] . ' ' . $r['reference'];
            $found = $search === '' || (function_exists('mb_stripos') ? mb_stripos($name, $search, 0, 'UTF-8') !== false : stripos($name, $search) !== false);
            return $found && ($status === '' || $r['review']['status'] === $status) && ($timing === '' || ($timing === 'late' ? $r['late'] : !$r['late']));
        }));
    }
    public static function csvSafe(string $value): string
    {
        return preg_match('/\A\s*[=+@-]/u', $value) ? "'" . $value : $value;
    }
}
