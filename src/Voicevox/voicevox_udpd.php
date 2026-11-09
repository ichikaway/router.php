<?php
/**
 * VOICEVOX UDP daemon
 *
 * 使い方:
 *   php voicevox_udpd.php [-play] [-host 127.0.0.1] [-port 9876]
 *                         [-engine http://127.0.0.1:50021] [-speaker 1]
 *                         [-cache ./cache] [-chunk 60000]
 *
 * UDPで受け取った文字列(UTF-8)をVOICEVOXでwav化する。
 *  - 通常モード : wavを送信元へ返す(下記チャンクプロトコル)
 *  - -play      : wavは返さず aplay で再生する (返信は "OK" のみ)
 *  - 同一文字列(+speaker)はキャッシュ(ファイル)したwavを再利用する
 *
 * 返信プロトコル(UDPは1データグラム最大約64KBなのでwavを分割):
 *   [4byte: 総チャンク数 BE][4byte: チャンク番号 BE(0始まり)][wav断片]
 *   エラー時は "ERR:<message>" の1データグラム。
 *   受信側は全チャンクを番号順に連結すればwavになる。(voicevox_udp_client.php 参照)
 */

$opts = [
    'host'    => '127.0.0.1',
    'port'    => 9876,
    'engine'  => 'http://127.0.0.1:50021',
    'speaker' => 1,
    'cache'   => __DIR__ . '/cache',
    'chunk'   => 60000,
    'play'    => false,
];

function usage(): void
{
    fwrite(STDERR, <<<TXT
Usage: php voicevox_udpd.php [options]
  -play            wavを返さずaplayで再生する
  -host HOST       待受アドレス      (default 127.0.0.1)
  -port PORT       待受UDPポート     (default 9876)
  -engine URL      VOICEVOXエンジン  (default http://127.0.0.1:50021)
  -speaker ID      話者ID            (default 1)
  -cache DIR       キャッシュ先      (default ./cache)
  -chunk BYTES     返信1パケットのwavバイト数 (default 60000)
  -h, --help       このヘルプ

TXT);
}

for ($i = 1; $i < $argc; $i++) {
    $a = ltrim($argv[$i], '-');
    if ($a === 'h' || $a === 'help') {
        usage();
        exit(0);
    }
    if ($a === 'play') {
        $opts['play'] = true;
    } elseif (isset($opts[$a]) && isset($argv[$i + 1])) {
        $opts[$a] = $argv[++$i];
    } else {
        fwrite(STDERR, "unknown option: {$argv[$i]}\n");
        usage();
        exit(1);
    }
}
$opts['port']    = (int)$opts['port'];
$opts['speaker'] = (int)$opts['speaker'];
$opts['chunk']   = max(512, min(65000, (int)$opts['chunk']));
$opts['engine']  = rtrim($opts['engine'], '/');

if (!is_dir($opts['cache']) && !mkdir($opts['cache'], 0775, true)) {
    fwrite(STDERR, "cannot create cache dir: {$opts['cache']}\n");
    exit(1);
}

function logmsg(string $m): void
{
    fwrite(STDERR, '[' . date('H:i:s') . "] $m\n");
}

/** VOICEVOX engine へPOSTする。成功時は本文、失敗時は例外 */
function http_post(string $url, ?string $body = null, string $ctype = 'application/json'): string
{
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: $ctype\r\nContent-Length: " . strlen($body ?? '') . "\r\n",
        'content'       => $body ?? '',
        'timeout'       => 60,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        throw new RuntimeException("engine unreachable: $url");
    }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    if ($code !== 200) {
        throw new RuntimeException("engine returned HTTP $code: " . substr($res, 0, 200));
    }
    return $res;
}

/** テキスト → wavバイナリ(キャッシュ利用)。返り値: [パス, キャッシュヒットか] */
function get_wav_path(string $text, array $o): array
{
    $path = $o['cache'] . '/' . sha1($o['speaker'] . "\0" . $text) . '.wav';
    if (is_file($path) && filesize($path) > 44) {
        return [$path, true];
    }
    $sp    = $o['speaker'];
    $query = http_post($o['engine'] . '/audio_query?speaker=' . $sp . '&text=' . rawurlencode($text));
    $wav   = http_post($o['engine'] . '/synthesis?speaker=' . $sp, $query);
    // 途中書き込みを避けるため一時ファイル経由でrename
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $wav);
    rename($tmp, $path);
    return [$path, false];
}

function reply($sock, string $peer, string $data): void
{
    @stream_socket_sendto($sock, $data, 0, $peer);
}

function send_wav($sock, string $peer, string $wav, int $chunk): void
{
    $parts = str_split($wav, $chunk);
    $total = count($parts);
    foreach ($parts as $idx => $part) {
        reply($sock, $peer, pack('NN', $total, $idx) . $part);
        if ($idx % 16 === 15) {
            usleep(1000); // 受信バッファ溢れ防止
        }
    }
}

// ---- 起動 ----
$addr = "udp://{$opts['host']}:{$opts['port']}";
$sock = stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND);
if (!$sock) {
    fwrite(STDERR, "bind failed ($addr): $errstr\n");
    exit(1);
}
logmsg("listening on $addr mode=" . ($opts['play'] ? 'play' : 'return-wav')
    . " speaker={$opts['speaker']} engine={$opts['engine']}");

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () use (&$running) { $running = false; });
    }
}
// aplay の子プロセスをゾンビ化させない
$players = [];

while ($running) {
    $r = [$sock]; $w = $e = null;
    if (@stream_select($r, $w, $e, 1) < 1) {
        foreach ($players as $k => $p) {
            if (!proc_get_status($p)['running']) { proc_close($p); unset($players[$k]); }
        }
        continue;
    }
    $data = stream_socket_recvfrom($sock, 65535, 0, $peer);
    if ($data === false || $data === '') {
        continue;
    }
    $text = trim($data);
    if ($text === '' || !preg_match('//u', $text)) {
        reply($sock, $peer, 'ERR:empty or invalid UTF-8 text');
        continue;
    }

    try {
        [$path, $hit] = get_wav_path($text, $opts);
        logmsg(($hit ? 'cache hit ' : 'synthesized ') . (preg_match('/^.{0,40}/su', $text, $mm) ? $mm[0] : '') . " from $peer");

        if ($opts['play']) {
            $players[] = proc_open(['aplay', '-q', $path], [], $pipes);
            reply($sock, $peer, 'OK');
        } else {
            send_wav($sock, $peer, file_get_contents($path), $opts['chunk']);
        }
    } catch (Throwable $ex) {
        logmsg('error: ' . $ex->getMessage());
        reply($sock, $peer, 'ERR:' . $ex->getMessage());
    }
}
logmsg('bye');
