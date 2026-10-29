"""Exercise real HTTP flows against an isolated copy and synthetic data only."""
from pathlib import Path
import tempfile, shutil, subprocess, socket, os, json, time, re, urllib.request, urllib.error, urllib.parse, http.cookiejar

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ple-assessment-routes-') as temporary:
    root = Path(temporary)
    for directory in ['app', 'public']:
        shutil.copytree(repo / directory, root / directory, ignore=shutil.ignore_patterns('branding.php', 'a1-ordre-passage.php'))
    shutil.copy2(repo / 'index.php', root / 'index.php')
    shutil.copy2(repo / 'tests/HttpTestRouter.php', root / 'router.php')
    storage = root / 'storage'; storage.mkdir()
    content = root / 'content'; (content / 'training/private').mkdir(parents=True)
    (content / 'training/public').mkdir()
    (content / 'training/private/secret.md').write_text('PRIVATE_SUBJECT_MARKER', encoding='utf8')
    (content / 'training/public/_intro.html').write_text('<h1>Example public assessment</h1>', encoding='utf8')
    definitions = {
        'DEMO-HTTP': {'scope': 'training/public', 'title': 'Public example', 'return': '/training', 'access': 'public', 'submission': True, 'deadline': '2026-10-29T18:00:00+01:00'},
        'PRIVATE-HTTP': {'scope': 'training/private', 'title': 'Protected example', 'return': '/training', 'access': 'protected', 'submission': True},
    }
    (storage / 'assessments.json').write_text(json.dumps({'version': 1, 'assessments': definitions}), encoding='utf8')
    branding = "<?php $b=require __DIR__.'/branding.example.php'; $b['public_base_url']='https://courses.example.test'; $b['site_name']='Example Courses'; $b['google_drive_enabled']=false; $b['student_contact_email']='teacher@example.test'; $b['display_site_address']=true; return $b;"
    (root / 'app/Config/branding.php').write_text(branding, encoding='utf8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    environment = dict(os.environ, APP_ENV='testing', PLE_TEST_FRONT_CONTROLLER=str(root / 'index.php'))
    log = open(root / 'server.log', 'w', encoding='utf8')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(root / 'router.php')], cwd=root, env=environment, stdout=log, stderr=log)
    try:
        for _ in range(80):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.05)
        jar = http.cookiejar.CookieJar(); client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        def request(path, data=None):
            try:
                res = client.open(f'http://127.0.0.1:{port}' + path, urllib.parse.urlencode(data).encode() if data is not None else None, timeout=8)
            except urllib.error.HTTPError as error: res = error
            return res.status, res.read().decode('utf8', 'replace'), res.headers
        for path in ['/training/private', '/training/private/secret.md', '/training/private/secret.md+download', '/training+download']:
            status, body, headers = request(path)
            assert status == 403 and 'PRIVATE_SUBJECT_MARKER' not in body, (path, status, body[:150])
            assert 'no-store' in headers.get('Cache-Control', ''), path
        status, body, _ = request('/training/public'); assert status == 200 and 'submission_csrf' in body
        fields = dict(re.findall(r'name="(submission_csrf|submission_nonce)" value="([^"]+)"', body))
        payload = dict(fields, cc_submission='1', nom='Example', prenom='Student', github_url='https://github.com/example/project')
        status, body, _ = request('/training/public', dict(payload, submission_csrf='invalid')); assert status == 403
        assert not (storage / 'remises-cc').exists(), 'Invalid CSRF must not persist a submission'
        status, body, _ = request('/training/public', payload); assert status == 200, body[:300]
        receipts = list((storage / 'remises-cc/DEMO-HTTP').glob('*.json')); assert len(receipts) == 1
        request('/training/public', payload)
        assert len(list((storage / 'remises-cc/DEMO-HTTP').glob('*.json'))) == 1, 'POST retry must be idempotent'
        status, body, _ = request('/'); assert status == 200 and 'student-contact-dialog' in body and 'Example Courses' in body
        assert '<title>Example Courses</title>' in body and '#081b37' not in body and '#00eaff' not in body
        assert request('/presentation-draw')[0] == 404, 'No configured draw means no public roster'
        print('AssessmentRoutesTest: OK (protected raw/download/archive, CSRF, receipt retry, neutral header, unconfigured draw)')
    finally:
        server.terminate(); server.wait(timeout=8); log.close()
