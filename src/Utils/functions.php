<?php
function macToBinary(string $mac): string {
    $hex = str_replace(':', '', $mac);
    // pack() で16進文字列をバイナリ化
    return pack('H*', $hex);
}

function hexToMac(string $hex): string {
    // unpack() でバイナリを16進文字列に変換
    return implode(':', str_split($hex, 2));
}

function hexDump($data, $width = 16) {
    $len = strlen($data);
    for ($i = 0; $i < $len; $i += $width) {
        $chunk = substr($data, $i, $width);
        $hex = implode(' ', str_split(bin2hex($chunk), 2));
        $ascii = preg_replace('/[^\x20-\x7E]/', '.', $chunk);
        printf("    %04x: %-48s %s\n", $i, $hex, $ascii);
    }
}
