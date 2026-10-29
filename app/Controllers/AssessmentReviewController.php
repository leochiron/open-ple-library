<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\AssessmentReviewService;
use App\Services\I18nService;
use RuntimeException;

final class AssessmentReviewController
{
    private AssessmentReviewService $service;
    public function __construct(AssessmentReviewService $service) { $this->service = $service; }
    public function handle(string $path, array $admin, string $csrf, I18nService $i18n, array $config): void
    {
        if (($admin['role'] ?? '') !== 'super_admin') { http_response_code(404); echo 'Not found'; return; }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($path, ['/remises', '/remises/export', '/remises/save', '/remises/check', '/remises/deadline'], true)) { http_response_code(404); echo 'Not found'; return; }
        if (($method === 'GET' && !in_array($path, ['/remises', '/remises/export'], true)) || ($method === 'POST' && !in_array($path, ['/remises/save', '/remises/check', '/remises/deadline'], true)) || !in_array($method, ['GET','POST'], true)) { http_response_code(405); echo 'Method not allowed'; return; }
        if ($method === 'POST' && (!is_string($_POST['_csrf'] ?? null) || !hash_equals($csrf, $_POST['_csrf']))) { http_response_code(403); echo 'Invalid request token.'; return; }
        $courses = $this->service->courses();
        if ($courses === [] && $path === '/remises' && $method === 'GET') { render('quiz/admin/assessments-empty', [], $i18n, $config); return; }
        $defaultCourse = array_key_first($courses) ?? '';
        $course = (string)($method === 'POST' ? ($_POST['course'] ?? $defaultCourse) : ($_GET['course'] ?? $defaultCourse));
        if (!isset($courses[$course])) { http_response_code(404); echo 'Épreuve inconnue.'; return; }
        $id = (string)($method === 'POST' ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''));
        $flash = ''; $form = null;
        if ($method === 'POST') {
            try {
                if ($path === '/remises/save') {
                    $this->service->saveReview($course, $id, (string)($_POST['status'] ?? ''), (string)($_POST['comment'] ?? ''), (int)($_POST['version'] ?? -1), (string)$admin['display_name']);
                    $flash = 'Suivi enregistré.';
                } elseif ($path === '/remises/check') {
                    $check = $this->service->runCheck($course, $id, (string)$admin['display_name']);
                    $flash = $check['message'];
                } else {
                    $this->service->saveDeadline($course, (string)($_POST['deadline'] ?? ''));
                    $flash = 'Échéance enregistrée, heure de Paris.'; $id = '';
                }
                $_SESSION['assessment_review_flash'] = $flash;
                header('Location: /quiz-admin/remises?course=' . rawurlencode($course) . ($id !== '' ? '&id=' . rawurlencode($id) : ''), true, 303); return;
            } catch (RuntimeException $e) {
                http_response_code(422); $flash = $e->getMessage();
                if ($path === '/remises/save') { $form = $_POST; }
            }
        } elseif (isset($_SESSION['assessment_review_flash'])) {
            $flash = (string)$_SESSION['assessment_review_flash']; unset($_SESSION['assessment_review_flash']);
        }
        try {
            $result = $this->service->all($course);
            $search = trim((string)($_GET['q'] ?? ''));
            $status = (string)($_GET['status'] ?? '');
            $timing = (string)($_GET['timing'] ?? '');
            if ($status !== '' && !isset(AssessmentReviewService::STATUSES[$status])) { $status = ''; }
            if (!in_array($timing, ['', 'late', 'ontime'], true)) { $timing = ''; }
            $rows = $this->service->filtered($result['records'], $search, $status, $timing);
            if ($path === '/remises/export') { $this->export($rows, $course); return; }
            $detail = $id !== '' ? $this->service->get($course, $id) : null;
            $history = [];
            if ($detail !== null) {
                foreach ($result['records'] as $r) {
                    if ($r['id'] !== $detail['id'] && strcasecmp(trim($r['nom']), trim($detail['nom'])) === 0 && strcasecmp(trim($r['prenom']), trim($detail['prenom'])) === 0) { $history[] = $r; }
                }
            }
            render('quiz/admin/submissions', ['courses' => $courses, 'course' => $course, 'deadline' => $this->service->deadline($course), 'rows' => $rows, 'total' => count($result['records']), 'warnings' => $result['warnings'], 'detail' => $detail, 'history' => $history, 'flash' => $flash, 'search' => $search, 'statusFilter' => $status, 'timingFilter' => $timing, 'csrfToken' => $csrf, 'form' => $form], $i18n, $config);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p><a href="/quiz-admin/remises">Retour aux remises</a></p>';
        }
    }
    private function export(array $rows, string $course): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="remises-' . $course . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Épreuve','Nom','Prénom','Réception (Paris)','Réception ISO','Référence','Dépôt GitHub','Délai','Statut','Commentaire privé','Dernier contrôle','Commit main contrôlé','Résultat du contrôle'], ';', '"', '');
        foreach ($rows as $r) {
            $check = $r['review']['check'] ?? [];
            $line = [$course,$r['nom'],$r['prenom'],$r['received_label'],$r['received_at'],$r['reference'],$r['github_url'],$r['late'] ? 'En retard' : 'À l’heure',AssessmentReviewService::STATUSES[$r['review']['status']],$r['review']['comment'],isset($check['checked_at']) ? AssessmentReviewService::paris($check['checked_at']) : '',$check['main_sha'] ?? '',$check['message'] ?? ''];
            fputcsv($out, array_map([AssessmentReviewService::class, 'csvSafe'], $line), ';', '"', '');
        }
        fclose($out);
    }
}
