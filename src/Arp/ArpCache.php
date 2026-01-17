<?php

namespace Arp;


class ArpCache
{
    private array $arpTable;

    private int $expireCount = 0;
    private int $expireLimit = 2000000;

    public function __construct()
    {
    }

    public function add(string $key, string $value): bool
    {
        $this->arpTable[$key] = $value;
        return true;
    }

    public function get(string $key): ?string
    {
        if (isset($this->arpTable[$key])) {
            // 一定回数以上参照できた場合は念のためキャッシュをクリアしてもう一度Arpを検索する
            $this->expireCount++;
            if ($this->isExpired()) {
                $this->resetArpTable();
                return null;
            }
            return $this->arpTable[$key];
        }
        return null;
    }

    private function isExpired(): bool
    {
        return $this->expireCount > $this->expireLimit;
    }

    private function resetArpTable(): void
    {
        $this->arpTable = [];
        $this->expireCount = 0;
    }
}

