<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use App\Services\PresentationDrawService;
$tmp = sys_get_temp_dir() . '/ple-draw-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    $teams = [['id' => 'one', 'name' => 'First group'], ['id' => 'two', 'name' => 'Second group'], ['id' => 'three', 'name' => 'Third group']];
    file_put_contents($tmp . '/config.json', json_encode(['event' => 'Example presentations', 'launch_code_sha256' => hash('sha256', 'fixture-code'), 'teams' => $teams]));
    $service = new PresentationDrawService($tmp);
    assertSameValue(null, $service->result(), 'No draw before teacher action');
    try { $service->draw('wrong'); throw new LogicException('Wrong code accepted'); } catch (RuntimeException $expected) { assertSameValue(403, $expected->getCode(), 'Wrong launch code denied'); }
    $result = $service->draw('fixture-code');
    assertSameValue(3, count($result['order']), 'Roster size is configurable');
    assertSameValue($result, $service->draw('fixture-code'), 'Retry does not redraw or reorder teams');
    assertSameValue($result, (new PresentationDrawService($tmp))->result(), 'Stored result survives a new request');
    $result['order'][0]['name'] = 'Tampered'; file_put_contents($tmp . '/result.json', json_encode($result));
    try { $service->result(); throw new LogicException('Tampered result accepted'); } catch (RuntimeException $expected) {}
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) { unlink($file); }
    rmdir($tmp);
}
echo "PresentationDrawTest: OK\n";
