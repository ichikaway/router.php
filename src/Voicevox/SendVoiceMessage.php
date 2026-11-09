<?php

namespace Voicevox;

/**
 * voicevox_udpd.php (-play モード) に読み上げ文字列をUDPで送る
 *
 * ルータのパケット処理ループを止めないよう、送信は投げっぱなし(ノンブロッキング)。
 * デーモンからの返信("OK" / "ERR:...")は待たず、次回送信時に溜まっている分を読み捨てる。
 */
class SendVoiceMessage
{
    private string $host;
    private int $port;

    private \Socket $socket;

    public function __construct(string $host = '127.0.0.1', int $port = 9876)
    {
        $this->host = $host;
        $this->port = $port;

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new \RuntimeException("voicevox socket create failed: " . socket_strerror(socket_last_error()));
        }
        socket_set_nonblock($socket);
        $this->socket = $socket;
    }

    /**
     * 文字列を送信する。返信は待たない。
     * @return string[] 前回までに届いていたエラー返信("ERR:..."）
     */
    public function send(string $text): array
    {
        $errors = $this->drainReplies();
        @socket_sendto($this->socket, $text, strlen($text), 0, $this->host, $this->port);
        return $errors;
    }

    /**
     * 受信バッファに溜まったデーモンからの返信を読み捨てる。エラー返信のみ返す。
     * @return string[]
     */
    private function drainReplies(): array
    {
        $errors = [];
        while (true) {
            $buf = '';
            $from = '';
            $port = 0;
            $ret = @socket_recvfrom($this->socket, $buf, 65535, 0, $from, $port);
            if ($ret === false || $ret === 0) {
                socket_clear_error($this->socket);
                break;
            }
            if (str_starts_with($buf, 'ERR:')) {
                $errors[] = $buf;
            }
        }
        return $errors;
    }
}
