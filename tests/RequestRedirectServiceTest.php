<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use App\Services\RequestRedirectService as Redirect;
$aliases = [['from' => '/old', 'to' => 'https://archive.example.test/new', 'prefix' => true, 'status' => 301]];
assertSameValue(['location' => 'https://archive.example.test/new/a%20b+download?x=1', 'status' => 301], Redirect::resolve('/index.php/old/a%20b+download?x=1', 'GET', $aliases), 'Encoded paths, actions and queries survive redirect');
assertSameValue(null, Redirect::resolve('/old-copy', 'GET', $aliases), 'No prefix collision');
assertSameValue(null, Redirect::resolve('/old', 'POST', $aliases), 'No posted form redirected');
assertSameValue(null, Redirect::resolve('/old', 'GET', [['from' => '/old', 'to' => '//evil.test']]), 'Protocol-relative destination rejected');
echo "RequestRedirectServiceTest: OK\n";
