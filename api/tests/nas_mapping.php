<?php
declare(strict_types=1);

// Isolated state and a local fake transport: no DB, NAS changes or real credentials.
require __DIR__ . '/../src/bootstrap.php';
$root = sys_get_temp_dir() . '/ithub-nas-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/packages/smbclient', 0700, true);
file_put_contents($root . '/packages/smbclient/__init__.py', '');
$transport = $root . '/transport';
file_put_contents($transport, <<<'PY'
#!/usr/bin/python3
import json,sys
body=json.load(sys.stdin)
if body['folder']=='offline':
 print(json.dumps({'ok':False,'code':'network'}))
elif body['folder']=='bad-auth':
 print(json.dumps({'ok':False,'code':'auth'}))
elif body['folder']=='denied':
 print(json.dumps({'ok':False,'code':'denied'}))
elif body['folder']=='missing':
 print(json.dumps({'ok':False,'code':'missing'}))
elif body['folder']=='malformed':
 print('not json')
else:
 assert body['password']=='  FixtureSecret!  '
 assert body['password'] not in '\0'.join(sys.argv)
 print(json.dumps({'ok':True,'entries':[],'truncated':False}))
PY);
chmod($transport, 0700);
$GLOBALS['ithub_config']['nas'] = ['state_dir' => $root . '/state', 'python' => $transport, 'python_packages' => $root . '/packages'];
$checks = 0;
function verify(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
    echo "OK $checks $label\n";
}
function rejects(callable $action, int $status): HttpError {
    try { $action(); } catch (HttpError $e) { verify($e->status === $status, "Expected HTTP $status"); return $e; }
    throw new RuntimeException('Expected failure');
}
function cleanup(string $dir): void {
    foreach (new FilesystemIterator($dir) as $item) {
        if ($item->isDir()) cleanup($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($dir);
}
try {
    $body = ['unc' => '\\\\192.168.1.10\\Documents\\แผนก IT (ทดสอบ)\\เอกสาร', 'username' => 'fixture', 'domain' => '', 'password' => '  FixtureSecret!  ', 'revision' => null];
    verify(nas_status()['mapping'] === null && nas_runtime_ready(), 'Unconfigured state and runtime readiness');
    $parsed = nas_parse($body, null);
    verify($parsed['folder'] === 'แผนก IT (ทดสอบ)\\เอกสาร' && $parsed['password'] === $body['password'], 'Unicode paths and password whitespace are preserved');
    verify(nas_parse(array_replace($body, ['unc' => '//10.2.3.4/Documents/IT/']), null)['unc'] === '\\\\10.2.3.4\\Documents\\IT', 'Normalize slash paths and trailing slash');
    foreach (['\\\\127.0.0.1\\Documents', '\\\\8.8.8.8\\Documents', '\\\\169.254.169.254\\meta', '\\\\172.32.1.1\\Documents', '\\\\example.com\\Documents', '\\\\192.168.1.10:445\\Documents', '\\\\192.168.1.10', '\\\\192.168.1.10\\Documents\\..\\private', '\\\\192.168.1.10\\Documents\\a*', '\\\\192.168.1.10\\Documents\\a' . "\0" . 'b'] as $unc) {
        rejects(fn() => nas_parse(array_replace($body, ['unc' => $unc]), null), 422);
    }
    rejects(fn() => nas_parse(array_replace($body, ['password' => '']), null), 422);
    rejects(fn() => nas_parse(array_replace($body, ['password' => ['bad']]), null), 422);
    rejects(fn() => nas_parse(array_replace($body, ['username' => "bad\nname"]), null), 422);
    $test = nas_test($body);
    verify($test['listing']['entries'] === [] && nas_read() === null, 'Testing a connection never saves it');
    $saved = nas_save($body, ['id' => 12]);
    verify($saved['listing']['entries'] === [] && $saved['mapping']['revision'] !== '', 'Save verifies connection and returns a revision');
    verify(!array_key_exists('password', $saved['mapping']) && !str_contains(json_encode(nas_status()), $body['password']), 'Password is never returned to the client');
    $raw = file_get_contents(nas_state_dir() . '/mapping.enc');
    verify(!str_contains($raw, 'FixtureSecret') && !str_contains($raw, '192.168.1.10') && !str_contains($raw, 'fixture'), 'All stored connection details are encrypted');
    verify(nas_read()['password'] === $body['password'], 'Saved password decrypts without trimming');
    verify((fileperms(nas_state_dir() . '/mapping.enc') & 0007) === 0 && (fileperms(nas_state_dir() . '/secret.key') & 0007) === 0, 'Connection and key are inaccessible to other users');
    $next = array_replace($body, ['password' => '', 'revision' => $saved['mapping']['revision']]);
    verify(nas_test($next)['listing']['entries'] === [], 'Blank password reuses saved secret for the same host/share/account');
    foreach ([['username' => 'someone-else'], ['domain' => 'OTHER'], ['unc' => '\\\\192.168.1.11\\Documents'], ['unc' => '\\\\192.168.1.10\\OtherShare']] as $change) {
        $error = rejects(fn() => nas_parse(array_replace($next, $change), nas_read()), 422);
        verify(isset($error->errors['password']), 'Require a fresh password when host/share/account changes');
    }
    foreach (['offline', 'bad-auth', 'denied', 'missing', 'malformed'] as $folder) {
        rejects(fn() => nas_save(array_replace($next, ['unc' => '\\\\192.168.1.10\\Documents\\' . $folder]), ['id' => 12]), $folder === 'malformed' ? 502 : 422);
        verify(file_get_contents(nas_state_dir() . '/mapping.enc') === $raw, 'Failed connection preserves previous mapping');
    }
    rejects(fn() => nas_save($body, ['id' => 12]), 409);
    rejects(fn() => nas_disconnect(['revision' => 'stale']), 409);
    rejects(fn() => nas_locked(fn() => nas_save($next, ['id' => 12])), 409);
    $saved2 = nas_save($next, ['id' => 13]);
    verify($saved2['mapping']['revision'] !== $next['revision'] && nas_read()['saved_by'] === 13, 'Saving creates a fresh revision and records the actual admin');
    rejects(fn() => nas_test($next), 409);
    $raw2 = file_get_contents(nas_state_dir() . '/mapping.enc');
    $corrupt = $raw2;
    $corrupt[strlen($corrupt) - 1] = chr(ord($corrupt[strlen($corrupt) - 1]) ^ 1);
    file_put_contents(nas_state_dir() . '/mapping.enc', $corrupt);
    rejects(fn() => nas_status(), 503);
    file_put_contents(nas_state_dir() . '/mapping.enc', $raw2);
    $result = nas_disconnect(['revision' => $saved2['mapping']['revision']]);
    verify($result['mapping'] === null && nas_read() === null, 'Disconnect removes saved mapping and credentials');
    verify(is_file(nas_state_dir() . '/secret.key'), 'Disconnect retains encryption key for future setup');
    $GLOBALS['ithub_config']['nas']['python'] = '/nonexistent-python';
    rejects(fn() => nas_test($body), 503);
    echo "PASS $checks NAS mapping checks; no database or remote writes\n";
} finally {
    cleanup($root);
}
