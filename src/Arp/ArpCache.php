<?php

namespace Arp;


class ArpCache
{
    private array $arpTable;

    private int $expireCount = 0;
    private int $expireLimit = 2000000;
    //private int $expireLimit = -1;

    public function __construct(?int $expireLimit = null)
    {
        if ($expireLimit !== null) {
            $this->expireLimit = $expireLimit;
        }
    }

    // キーはIPアドレスのint表現。文字列キーのハッシュ計算を避けるためintで持つ
    public function add(int $key, string $value): bool
    {
        $this->arpTable[$key] = $value;
        return true;
    }

    public function get(int $key): ?string
    {
        if (isset($this->arpTable[$key])) {
            // 一定回数以上参照できた場合は念のためキャッシュをクリアしてもう一度Arpを検索する
            // パケット毎に通るためisExpired()のメソッド呼び出しはインライン化
            if (++$this->expireCount > $this->expireLimit) {
                $this->resetArpTable();
                return null;
            }
            return $this->arpTable[$key];
        }
        return null;
    }

    private function resetArpTable(): void
    {
        $this->arpTable = [];
        $this->expireCount = 0;
    }
}

