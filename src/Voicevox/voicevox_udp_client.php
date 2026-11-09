<?php
/**
 * テスト用クライアント
 *   php voicevox_udp_client.php "こんにちは" out.wav [host] [port]
 * -play モードのデーモンには out.wav 不要(OKが返るだけ): php ... "こんにちは" -
 */
$text = $argv[1] ?? null;
$out  = $argv[2] ?? 'out.wav';
$host = $argv[3] ?? '127.0.0.1';
$port = (int)($argv[4] ?? 9876);
if ($text === null) { fwrite(STDERR, "usage: client.php TEXT [out.wav|-] [host] [port]\n"); exit(1); }

$s = stream_socket_client("udp://$host:$port", $en, $es, 5);
stream_set_timeout($s, 60);
fwrite($s, $text);

$chunks = [];
$total  = null;
while ($total === null || count($chunks) < $total) {
    $d = stream_socket_recvfrom($s, 65535);
    if ($d === false || $d === '') { fwrite(STDERR, "timeout\n"); exit(2); }
    if (str_starts_with($d, 'ERR:')) { fwrite(STDERR, "$d\n"); exit(3); }
    if ($d === 'OK') { echo "playing\n"; exit(0); }
    $h = unpack('Ntotal/Nidx', $d);
    $total = $h['total'];
    $chunks[$h['idx']] = substr($d, 8);
    stream_set_timeout($s, 5);
}
ksort($chunks);
file_put_contents($out, implode('', $chunks));
echo "saved $out (" . filesize($out) . " bytes)\n";
