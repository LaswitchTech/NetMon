<?php

declare(strict_types=1);

spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../app/' . str_replace(['App\\', '\\'], ['', '/'], $c) . '.php';
    if (file_exists($f)) {
        require $f;
    }
});

use App\Core\Gate;
use App\Core\SQLiteDriver;
use App\Models\TokenRepository;
use App\Models\UserRepository;
use App\Auth\TokenService;

$cfg  = require __DIR__ . '/../config/database.php';
$db   = new SQLiteDriver($cfg['sqlite']['path']);
$gate = new Gate($db);
$svc  = new TokenService(new TokenRepository($db), new UserRepository($db), $gate);

// ---- Setup ----
$row = $db->fetchOne('SELECT id FROM users WHERE username = ?', ['testadmin']);
$uid = (int) $row['id'];
echo "Testing with user_id={$uid}\n\n";

// Clean slate
$db->execute('DELETE FROM api_tokens WHERE user_id = ?', [$uid]);

// ---- Generate ----
$result = $svc->generate($uid, 'smoke-test-key');
$raw    = $result['raw'];
$rec    = $result['record'];
echo "Generated  id={$rec['id']}, name={$rec['name']}\n";
echo "Raw length=" . strlen($raw) . " (expect 64)\n";
echo "hash absent: " . (array_key_exists('token_hash', $rec) ? 'FAIL' : 'ok') . "\n";

// ---- Verify valid ----
$principal = $svc->verify($raw);
echo "\nVerify valid token:\n";
echo "  user=" . $principal['user']['username'] . "\n";
echo "  auth_method=" . $principal['auth_method'] . "\n";
echo "  permissions: " . implode(', ', $principal['permissions']) . "\n";
echo "  password_hash absent: " . (array_key_exists('password_hash', $principal['user']) ? 'FAIL' : 'ok') . "\n";

// ---- Verify wrong token ----
$bad = $svc->verify(str_repeat('a', 64));
echo "\nVerify wrong token: " . ($bad === null ? 'null (correct)' : 'FAIL') . "\n";

// ---- last_used_at ----
$after = $db->fetchOne('SELECT last_used_at FROM api_tokens WHERE id = ?', [$rec['id']]);
echo "last_used_at updated: " . ($after['last_used_at'] !== null ? 'ok (' . $after['last_used_at'] . ')' : 'FAIL') . "\n";

// ---- Gate permissions ----
$perms = $gate->permissionsForUser($uid);
echo "\nGate::permissionsForUser: [" . implode(', ', $perms) . "]\n";

// ---- Revoke ----
echo "\nRevoke token id={$rec['id']}: ";
$ok = $svc->revoke($rec['id'], $uid);
echo ($ok ? 'ok' : 'FAIL') . "\n";

// ---- Verify after revoke ----
$gone = $svc->verify($raw);
echo "Verify after revoke: " . ($gone === null ? 'null (correct)' : 'FAIL') . "\n";

// ---- Wrong owner revoke ----
$r2   = $svc->generate($uid, 'ownership-test');
$bad2 = $svc->revoke($r2['record']['id'], 99999);
echo "\nRevoke wrong owner: " . (!$bad2 ? 'blocked (correct)' : 'FAIL') . "\n";

// ---- Expiry check ----
$expired = $svc->generate($uid, 'expired-key', date('Y-m-d H:i:s', time() - 3600));
$expiredResult = $svc->verify($expired['raw']);
echo "Verify expired token: " . ($expiredResult === null ? 'null (correct)' : 'FAIL') . "\n";

// ---- List ----
$list = $svc->listForUser($uid);
echo "\nList tokens count=" . count($list) . " (expect >=1)\n";
$hasHash = array_filter($list, fn($t) => isset($t['token_hash']));
echo "No hashes in list: " . (empty($hasHash) ? 'ok' : 'FAIL') . "\n";

// ---- Gate::can on principal ----
$principal2 = $svc->verify($r2['raw']);
$canAdmin   = $gate->can($principal2, 'admin');
$canFake    = $gate->can($principal2, 'fake.permission');
echo "\nGate::can(admin): " . ($canAdmin ? 'true' : 'false') . " (expect true if admin perms seeded)\n";
echo "Gate::can(fake):  " . (!$canFake ? 'false (correct)' : 'FAIL — should be false') . "\n";

// Cleanup
$db->execute('DELETE FROM api_tokens WHERE user_id = ?', [$uid]);
echo "\nAll tests complete. Cleanup done.\n";
