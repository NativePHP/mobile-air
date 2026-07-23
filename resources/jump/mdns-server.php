<?php

/**
 * Pure-PHP DNS-SD (mDNS / Bonjour) responder for the Jump dev server.
 *
 * WHY THIS EXISTS
 * ---------------
 * On macOS the Jump command advertises `_jump._tcp` by shelling out to
 * `dns-sd -R` (Bonjour is built in); on Linux it uses `avahi-publish-service`.
 * Windows ships neither, so LAN auto-discovery was simply skipped there and the
 * phone could only connect by scanning the QR. This script is the Windows
 * (and generic no-Bonjour) fallback: a self-contained multicast responder that
 * answers `_jump._tcp.local` browse/resolve queries with the SAME host + port
 * the QR encodes, using nothing but PHP's `sockets` extension.
 *
 * It advertises exactly the records `dns-sd -R <name> _jump._tcp local <port>
 * host=<ip> port=<port> name=<label>` would, so the app's discovery flow is
 * identical across platforms.
 *
 * USAGE
 *   php mdns-server.php <basePath> <instanceLabel> <port> <ip> [parentPid]
 *
 * SHUTDOWN — WHY THE STOP-FILE + PARENT WATCH
 * On Windows there is no pcntl and proc_terminate() is a hard TerminateProcess,
 * so we can't rely on a signal handler to multicast the DNS-SD "goodbye" (TTL-0)
 * packet that tells the Jump app to drop the entry immediately. Without it the
 * app shows a phantom "server nearby" for the full record TTL after Jump quits.
 * Two cooperative exits cover that:
 *   1. Stop-file (graceful): JumpCommand::stopAdvertiser() creates
 *      storage/framework/jump-mdns.stop; we see it, send goodbye, and exit.
 *   2. Parent-PID watch (crash/orphan): if the Jump process dies without a
 *      clean stop (Ctrl+C hard-kill, crash, or a leftover from testing), we
 *      notice the parent is gone within ~2s, send goodbye, and exit ourselves.
 *
 * Output (banner + errors) goes to STDERR so the caller can route it to
 * storage/logs/jump-mdns.log, mirroring the other Windows Jump subprocesses.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$basePath = $argv[1] ?? getcwd();
$instance = $argv[2] ?? 'NativePHP';
$port = (int) ($argv[3] ?? 0);
$ip = $argv[4] ?? '127.0.0.1';
$parentPid = (int) ($argv[5] ?? 0);

// Graceful-stop sentinel. JumpCommand drops this file to ask us to deregister
// (send goodbye) and exit. Clear any stale copy from a previous run first.
$stopFile = rtrim($basePath, '\\/').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'jump-mdns.stop';
@unlink($stopFile);

function logline(string $msg): void
{
    $ts = date('H:i:s');
    fwrite(STDERR, "[$ts] [Jump mDNS] $msg\n");
}

if (! extension_loaded('sockets')) {
    logline('FATAL: the PHP `sockets` extension is not loaded — cannot advertise on the LAN. Enable ext-sockets in php.ini. (QR still works.)');
    exit(1);
}

if ($port <= 0) {
    logline('FATAL: no port supplied — nothing to advertise.');
    exit(1);
}

const MDNS_GROUP = '224.0.0.251';
const MDNS_PORT = 5353;

// RFC 6762 defaults are PTR 4500 / others 120, tuned for stable services. This
// is an ephemeral dev server that starts and stops constantly, so we cap the
// PTR at 120 too: if a goodbye ever fails to land (both stop-file and
// parent-watch missed), a phantom "server nearby" ages out in ~2min instead of
// 75. Goodbye (TTL 0) still clears it instantly in the normal case.
const TTL_PTR = 120;
const TTL_SRV = 120;
const TTL_TXT = 120;
const TTL_A = 120;

// ---------------------------------------------------------------------------
// Names. Instance labels in DNS-SD are a SINGLE label that may legally contain
// dots and spaces, so we carry every name as an explicit array of labels and
// never split on '.' — otherwise an app dir like "my.app" would corrupt the
// wire name.
// ---------------------------------------------------------------------------
$serviceType = ['_jump', '_tcp', 'local'];
$instanceName = array_merge([$instance], $serviceType); // <label>._jump._tcp.local

// SRV target host. A real .local hostname keeps the record standards-compliant
// even though the app reads host+port straight out of the TXT metadata.
$rawHost = gethostname() ?: 'jump-host';
$hostLabel = preg_replace('/[^A-Za-z0-9-]/', '-', explode('.', $rawHost)[0]);
if ($hostLabel === '' || $hostLabel === null) {
    $hostLabel = 'jump-host';
}
$hostName = [$hostLabel, 'local']; // <host>.local

$label = $instance;
$txtRecords = [
    'host='.$ip,
    'port='.$port,
    'name='.$label,
];

// ---------------------------------------------------------------------------
// Wire encoders
// ---------------------------------------------------------------------------
function encodeName(array $labels): string
{
    $out = '';
    foreach ($labels as $label) {
        $label = (string) $label;
        // A single label is capped at 63 octets on the wire.
        if (strlen($label) > 63) {
            $label = substr($label, 0, 63);
        }
        $out .= chr(strlen($label)).$label;
    }

    return $out."\x00";
}

function encodeTxt(array $records): string
{
    if (empty($records)) {
        return "\x00"; // one empty string
    }
    $out = '';
    foreach ($records as $r) {
        $r = (string) $r;
        if (strlen($r) > 255) {
            $r = substr($r, 0, 255);
        }
        $out .= chr(strlen($r)).$r;
    }

    return $out;
}

function rr(string $name, int $type, int $class, int $ttl, string $rdata): string
{
    return $name.pack('n', $type).pack('n', $class).pack('N', $ttl).pack('n', strlen($rdata)).$rdata;
}

// DNS record types / classes
const TYPE_A = 1;
const TYPE_PTR = 12;
const TYPE_TXT = 16;
const TYPE_SRV = 33;
const TYPE_ANY = 255;
const CLASS_IN = 1;
const FLUSH = 0x8000; // cache-flush bit OR'd onto CLASS for unique records

/**
 * Build a full DNS-SD announcement/response: PTR in the answer section,
 * SRV+TXT+A in additional — the layout Bonjour and Android NsdManager expect.
 * $ttlScale lets us reuse the same builder for goodbye packets (TTL 0).
 */
function buildResponse(array $ctx, int $ttlScale = 1): string
{
    $ptr = rr(encodeName($ctx['serviceType']), TYPE_PTR, CLASS_IN, TTL_PTR * $ttlScale, encodeName($ctx['instanceName']));

    $srvData = pack('n', 0).pack('n', 0).pack('n', $ctx['port']).encodeName($ctx['hostName']);
    $srv = rr(encodeName($ctx['instanceName']), TYPE_SRV, CLASS_IN | FLUSH, TTL_SRV * $ttlScale, $srvData);

    $txt = rr(encodeName($ctx['instanceName']), TYPE_TXT, CLASS_IN | FLUSH, TTL_TXT * $ttlScale, encodeTxt($ctx['txtRecords']));

    $aData = inet_pton($ctx['ip']);
    $a = ($aData !== false && strlen($aData) === 4)
        ? rr(encodeName($ctx['hostName']), TYPE_A, CLASS_IN | FLUSH, TTL_A * $ttlScale, $aData)
        : '';

    $ancount = 1;                       // PTR
    $arcount = 2 + ($a !== '' ? 1 : 0); // SRV, TXT, [A]

    $header = pack('n', 0)          // ID
        .pack('n', 0x8400)          // flags: QR=1, AA=1
        .pack('n', 0)               // QDCOUNT
        .pack('n', $ancount)        // ANCOUNT
        .pack('n', 0)               // NSCOUNT
        .pack('n', $arcount);       // ARCOUNT

    return $header.$ptr.$srv.$txt.$a;
}

// ---------------------------------------------------------------------------
// Incoming-query parsing — just enough to decide whether a packet is asking
// for something we own. Handles compression pointers defensively (queries
// rarely use them, but a malformed one shouldn't wedge the loop).
// ---------------------------------------------------------------------------
function readName(string $pkt, int &$offset): string
{
    $labels = [];
    $len = strlen($pkt);
    $jumped = false;
    $guard = 0;
    while ($offset < $len && $guard++ < 128) {
        $b = ord($pkt[$offset]);
        if ($b === 0) {
            $offset++;
            break;
        }
        if (($b & 0xC0) === 0xC0) { // compression pointer
            if ($offset + 1 >= $len) {
                break;
            }
            $ptr = (($b & 0x3F) << 8) | ord($pkt[$offset + 1]);
            if (! $jumped) {
                $offset += 2;
            }
            $offset = $ptr;
            $jumped = true;
            // continue from pointer target using a local cursor
            $sub = $offset;
            $subLabels = [];
            $subGuard = 0;
            while ($sub < $len && $subGuard++ < 128) {
                $sb = ord($pkt[$sub]);
                if ($sb === 0) {
                    break;
                }
                if (($sb & 0xC0) === 0xC0) {
                    if ($sub + 1 >= $len) {
                        break 2;
                    }
                    $sub = (($sb & 0x3F) << 8) | ord($pkt[$sub + 1]);

                    continue;
                }
                $subLabels[] = substr($pkt, $sub + 1, $sb);
                $sub += 1 + $sb;
            }

            return strtolower(implode('.', array_merge($labels, $subLabels)));
        }
        $labels[] = substr($pkt, $offset + 1, $b);
        $offset += 1 + $b;
    }

    return strtolower(implode('.', $labels));
}

function queryTargets(string $pkt): array
{
    if (strlen($pkt) < 12) {
        return [];
    }
    $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($pkt, 0, 12));
    if ($header['flags'] & 0x8000) {
        return []; // a response, not a query
    }
    $offset = 12;
    $names = [];
    for ($i = 0; $i < $header['qd']; $i++) {
        $name = readName($pkt, $offset);
        $offset += 4; // skip QTYPE + QCLASS
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

// ---------------------------------------------------------------------------
// Socket setup
// ---------------------------------------------------------------------------
$sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false) {
    logline('FATAL: socket_create failed: '.socket_strerror(socket_last_error()));
    exit(1);
}

@socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
if (defined('SO_REUSEPORT') && PHP_OS_FAMILY !== 'Windows') {
    @socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1);
}

if (! @socket_bind($sock, '0.0.0.0', MDNS_PORT)) {
    logline('FATAL: could not bind UDP '.MDNS_PORT.' ('.socket_strerror(socket_last_error($sock)).'). Another mDNS responder (e.g. Bonjour) may own it.');
    exit(1);
}

// Join the multicast group so we actually receive browse/resolve queries.
$joined = false;
if (defined('MCAST_JOIN_GROUP')) {
    $joined = @socket_set_option($sock, IPPROTO_IP, MCAST_JOIN_GROUP, ['group' => MDNS_GROUP, 'interface' => 0]);
}
if (! $joined && defined('IP_ADD_MEMBERSHIP')) {
    $joined = @socket_set_option($sock, IPPROTO_IP, IP_ADD_MEMBERSHIP, ['group' => MDNS_GROUP, 'interface' => '0.0.0.0']);
}
if (! $joined) {
    logline('WARN: could not join multicast group '.MDNS_GROUP.' — passive announcements will still be sent, but query responses may not reach the app.');
}

// mDNS wants TTL 255 on the wire. Leave multicast loopback at its default
// (on): it lets same-host consumers and the OS resolver cache see our records,
// and our own announcements are harmless here since queryTargets() ignores any
// packet with the response (QR) bit set.
if (defined('IP_MULTICAST_TTL')) {
    @socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 255);
}

$ctx = [
    'serviceType' => $serviceType,
    'instanceName' => $instanceName,
    'hostName' => $hostName,
    'port' => $port,
    'ip' => $ip,
    'txtRecords' => $txtRecords,
];

$responsePkt = buildResponse($ctx);

$targets = [
    '_jump._tcp.local',
    strtolower(implode('.', $instanceName)),
    strtolower(implode('.', $hostName)),
];

function announce($sock, string $pkt): void
{
    @socket_sendto($sock, $pkt, strlen($pkt), 0, MDNS_GROUP, MDNS_PORT);
}

function parentAlive(int $pid): bool
{
    if ($pid <= 0) {
        return true; // not tracking a parent
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = (string) @shell_exec('tasklist /FI "PID eq '.$pid.'" /NH /FO CSV 2>NUL');

        return str_contains($out, '"'.$pid.'"');
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return is_dir('/proc/'.$pid);
}

// Deregister: multicast the goodbye (TTL-0) records twice so browsing devices
// drop the entry immediately instead of caching it for the record TTL.
$shuttingDown = false;
$goodbye = function (string $why) use ($sock, $ctx, $stopFile, &$shuttingDown) {
    if ($shuttingDown) {
        return;
    }
    $shuttingDown = true;
    $bye = buildResponse($ctx, 0);
    announce($sock, $bye);
    announce($sock, $bye);
    @unlink($stopFile);
    logline("sent goodbye packets ($why), exiting.");
    exit(0);
};
// pcntl only exists on unix; on Windows the stop-file + parent watch drive the
// goodbye instead (see the loop below).
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn () => $goodbye('SIGTERM'));
    pcntl_signal(SIGINT, fn () => $goodbye('SIGINT'));
}

logline("advertising \"$label\" as _jump._tcp.local -> $ip:$port (SRV target ".implode('.', $hostName).')');

// Initial burst: RFC 6762 §8.3 recommends 2-3 announcements ~1s apart so a
// device that just started browsing catches us without waiting for a query.
announce($sock, $responsePkt);
$nextBurst = microtime(true) + 1.0;
$burstsLeft = 2;
$nextPeriodic = microtime(true) + 30.0;
$lastResponse = 0.0;
$nextParentCheck = microtime(true) + 2.0;

while (true) {
    $read = [$sock];
    $write = null;
    $except = null;
    // 1s tick drives the startup burst + 30s keep-alive between packets.
    $ready = @socket_select($read, $write, $except, 1);

    $now = microtime(true);

    // Cooperative shutdown (Windows has no pcntl): a stop-file means Jump asked
    // us to deregister; a dead parent means Jump crashed or was hard-killed. In
    // both cases send the goodbye so the app drops the entry now, not in ~TTL.
    if (is_file($stopFile)) {
        $goodbye('stop-file');
    }
    if ($now >= $nextParentCheck) {
        $nextParentCheck = $now + 2.0;
        if (! parentAlive($parentPid)) {
            $goodbye('parent gone');
        }
    }

    if ($ready === false) {
        // Interrupted by a signal (e.g. SIGTERM) — loop; the handler exits.
        continue;
    }

    if ($ready > 0) {
        $buf = '';
        $from = '';
        $fromPort = 0;
        $n = @socket_recvfrom($sock, $buf, 4096, 0, $from, $fromPort);
        if ($n > 0 && $buf !== '') {
            $names = queryTargets($buf);
            $wantsUs = false;
            foreach ($names as $qn) {
                if (in_array($qn, $targets, true)) {
                    $wantsUs = true;
                    break;
                }
            }
            // Throttle: at most one response per 250ms to avoid packet storms
            // when several devices browse at once.
            if ($wantsUs && ($now - $lastResponse) > 0.25) {
                announce($sock, $responsePkt);
                $lastResponse = $now;
            }
        }
    }

    if ($burstsLeft > 0 && $now >= $nextBurst) {
        announce($sock, $responsePkt);
        $burstsLeft--;
        $nextBurst = $now + 1.0;
    }

    if ($now >= $nextPeriodic) {
        announce($sock, $responsePkt);
        $nextPeriodic = $now + 30.0;
    }
}
