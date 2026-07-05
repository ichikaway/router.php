# AF_XDP化: PHPルーターのパケット読み書きをAF_PACKETからAF_XDPへ

## Context

`/home/ichi/php/router.php` は、alice(10.0.0.10) -> router(eth0=10.0.0.250/eth1=10.0.1.250) -> bob(10.0.1.10) のdocker-compose構成で動くPHP製L3ルーター。現状は `AF_PACKET` raw socketで各NICのフレームを読み書きしており、`socket_recv`/`socket_write` のたびに `sk_buff` 確保とネットワークスタック全体を通過するオーバーヘッドがスループットの制約になっている（`parallel` PECL拡張でwriteをワーカースレッドにオフロードして緩和している状態）。

検証の結果、このdocker環境（veth、DMA非対応のソフトウェアデバイス）では **AF_XDPのzero-copyモードは使えず、copyモードでの動作になる**ことを確認済み。それでも「skb確保＋フルスタック通過」自体を回避できるため、正しく実装すればAF_PACKETより速くなる見込みがある。作業ブランチ `performance-up-thread-xdp` は本作業のために用意されたもの。

本プランは、上記方針（オプション1: AF_PACKET→AF_XDP置換、copyモード）をPHP FFI + 小さなCヘルパーライブラリで実装する具体策。

## 重要な設計判断

**マルチスレッド(parallel\Runtime)は今回廃止し、単一プロセス・単一ループに簡略化する。**
理由: AF_XDPのTXはブロッキングsyscallではなくリングへのpush（+ 必要時のみ非ブロッキング`sendto`kick）なので、現状`parallel\Runtime`でwriteをオフロードしている理由が消える。加えて`parallel\Channel`はFFIのopaqueポインタ（`xsk_socket*`など）を別インタプリタ間で共有できないため、無理に維持すると共有UMEMのフレーム所有権バグ（二重解放や競合）を踏みやすい。将来必要なら`xsk_socket__create_shared`によるスレッド間共有を追加検討する（今回は実装しない）。

**ARPを壊さないよう、デフォルトの「全部redirect」ではなく自前のフィルタ付きXDPプログラムを書く。**
理由: `Arp.php`は独立した`AF_PACKET`(`ETH_P_ARP`)ソケットでARP解決している。XDPのredirectフックはAF_PACKETのタップより手前で動くため、無条件に全パケットをXSKMAPへredirectする実装だと、ARP要求/応答も含めて全部奪われ、`Arp.php`が常にタイムアウトし転送が一切機能しなくなる（エラーは出ず、静かに壊れる）。対策として、自前のXDPプログラムで **EtherType `ETH_P_IP` のみredirect、それ以外(ARP含む)は`XDP_PASS`** する。既存の`Router.php`のAF_PACKETソケットも`ETH_P_IP`のみを見ているので、スコープは現状と同じ。

## ファイル構成

新規:
- `src/c/xdp_filter.bpf.c` — カーネル側XDPプログラム（`clang -target bpf`でビルド、ETH_P_IPのみXSKMAPへredirect、他はXDP_PASS）
- `src/c/xdpphp.c` — libxdp/libbpfを使うユーザー空間FFIシム。UMEM+リング管理、BPFプログラムのロード/アタッチ、XSKMAPへの登録を内包
- `src/c/Makefile` — `libxdpphp.so` と `xdp_filter.bpf.o` をビルド
- `src/Xdp/XskSocket.php` — FFIラッパークラス（PSR-4: `Xdp\` → `src/Xdp/`）。`Device`ごとに1インスタンス、既存の「NICごとに1ソケット」パターンを踏襲

変更:
- `docker/router/Dockerfile` — `libxdp-dev libbpf-dev libelf-dev zlib1g-dev clang llvm pkg-config bpftool` を追加、`docker-php-ext-install ffi`、`ffi.enable=true`のini追加
- `docker/router/mnt/entrypoint.sh` — `tail -f /dev/null` の前に `make -C /router/src/c || true` を追加（リポジトリ全体がbind mountされ`docker build`時の成果物は上書きされるため、コンテナ起動時にビルドする。ホストでソースを編集して再起動すればそのまま反映される、既存の開発フローと一致）
- `src/Router.php` — コンストラクタ/`readData()`/`start()`を`XskSocket`ベースに置換、`parallel\Runtime`/`Channel`関連を削除
- `.gitignore` — `src/c/*.o`, `src/c/*.so` を追加

変更しない: `Arp/Arp.php`, `Arp/ArpCache.php`, `Network/Device.php`, `Network/IpPacket.php`, `Network/Netmask.php`, `Utils/DeviceInfo.php`, `start.php`（ルーティング/ARP/TTLロジックは無変更）

`docker-compose.yml`は変更不要（`privileged: true`が既にBPF/XDPアタッチ・デバイスアクセスに必要な権限を含んでいる。追加の`cap_add`は冗長）

## Cヘルパーの最小API（FFI向け、コールバック無し・純粋ポーリング）

```c
typedef struct xdpphp_socket xdpphp_socket_t;   // opaque

xdpphp_socket_t *xdpphp_open(const char *ifname, unsigned int queue_id, const char *bpf_obj_path);
void xdpphp_close(xdpphp_socket_t *sock);

// 非ブロッキング。>0=受信バイト数, 0=データなし, -1=エラー
long xdpphp_recv(xdpphp_socket_t *sock, unsigned char *buf, unsigned long buf_len);

// 非ブロッキング送信。1=キュー投入, 0=バックプレッシャー(現状の未チェックsocket_writeと同様dropでよい), -1=エラー
int  xdpphp_send(xdpphp_socket_t *sock, const unsigned char *buf, unsigned long len);

const char *xdpphp_last_error(void);
```

フレームの再利用（fill ring/completion ring管理）はC層内部で完結させ、PHP側には一切見せない：
- RX: `xdpphp_recv`はRXリングから1descriptor取り出しmemcpyしてbufに詰めたら、同じフレームを即fill ringへ戻す
- TX: UMEMをRX用/TX用の2プールに分割し、それぞれ内部free-listで管理。`xdpphp_send`はまずcompletion ringをdrainしてTXフレームを回収→空きフレームを1つ取得→memcpy→TXリングへ投入→`xsk_ring_prod__needs_wakeup()`が真の時のみ`sendto(..., MSG_DONTWAIT, ...)`でkick

UMEMサイズ: フレームサイズ2048B、RX(fill+rx)2048枚+TX(tx+comp)2048枚 ×2NIC ≒ 16MiB。`bind_flags = XDP_COPY`を明示（vethはDMA非対応なのでzerocopyのprobingをスキップ）。`xdpphp_open()`内で`setrlimit(RLIMIT_MEMLOCK, RLIM_INFINITY)`を無条件に呼ぶ（`privileged: true`はcapability/デバイスの話でulimitは別物）。

BPFプログラムのロードは`xsk_setup_xdp_prog()`の簡易ヘルパーは使わず、libbpfを直接使って自前プログラムをロード・アタッチし、`XSK_LIBBPF_FLAGS__INHIBIT_PROG_LOAD`を指定して`xsk_socket__create`が独自にプログラムを差し替えないようにする。ネイティブXDPアタッチ(`bpf_xdp_attach`, flags=0)が失敗したら`XDP_FLAGS_SKB_MODE`で再試行するフォールバックを入れる。

## Router.php側の変更点

- コンストラクタ: `socket_create(AF_PACKET,...)` 等を `new XskSocket($Device->getDeviceName())` に置換（`PACKET_IGNORE_OUTGOING`相当は不要 — AF_XDPのTXは構造上自分のRXリングへループしない）
- `readData()`: `socket_select`をやめ、各ソケットを`recvFrame()`で最大200フレーム/回までドレインする形はそのまま維持（`null`を返す条件も同じ契約を維持）
- `start()`: `parallel\Runtime`/`Channel`のセットアップを削除し、`$chan[$cnt]->send(...)`していた箇所を`$this->sockets[$writeDeviceName]->sendFrame($dstPkt);`に置換

**注意点（今回は直さず明記のみ）**: `start_eth0.php`/`start_eth1.php`（プロセスを分けてNICごとに読む方式）は、AF_XDPでは同一ifindex/queueへの`xsk_socket__update_xskmap`を2プロセスが競合させてしまい片方のRXが壊れる。**v1では`start.php`（単一プロセスで両NIC）のみを使う。**

## 実装順序

1. `src/c/xdp_filter.bpf.c` + `src/c/xdpphp.c` + `src/c/Makefile`
2. `docker/router/Dockerfile` + `docker/router/mnt/entrypoint.sh`、`docker compose build router`
3. `src/Xdp/XskSocket.php`
4. `src/Router.php`編集、`.gitignore`追加
5. スタック起動 → BPFアタッチ確認 → 疎通確認 → ベンチマークの順で検証（先に転送が壊れていないことを確認してから性能比較に進む）

## 検証手順

1. `docker compose build router && docker compose up -d`
2. `docker compose exec router bash` で `/router/src/c/libxdpphp.so`, `xdp_filter.bpf.o` の生成を確認（無ければ`make -C /router/src/c`で手動ビルドしエラー確認）
3. `php start.php` をフォアグラウンド実行し、`xdpphp_open`/FFI起因の例外が即座に見えるようにする
4. 別シェルで `ip link show eth0`/`eth1` に `xdp`（または`xdpgeneric`）表示、`bpftool net show`でプログラムのアタッチ、`bpftool map dump name xsks_map`でfd登録を確認
5. alice→`ping 10.0.0.250`（ルーター自身への到達、ARP経路が生きているかの回帰確認）→ alice→`ping 10.0.1.10`（ルーター経由の転送+ARP解決の確認）
6. 必要なら`tcpdump`でTTL減算・チェックサムの健全性を確認
7. `memo.txt`記載のiperf3コマンドをそのまま再実行（事前に`ethtool -K eth0/eth1 rx off tx off tso off gro off`を適用済みであること）してAF_PACKET版とスループット比較。比較は`git checkout`でブランチを行き来しコンテナ再起動して行う既存のA/Bフローを踏襲
8. 新ループはbusy-spin方式（`socket_select`なし）になるため、軽負荷時でもrouterコンテナのCPU使用率が高止まりするのは想定内の trade-off であり回帰ではない、という点を認識した上で比較する

## 今回やらないこと（将来検討）

- `xsk_socket__create_shared`による複数スレッド/UMEM共有（Option A）
- `XskSocket::sendFrame()`でのバッファ使い回し（現状は呼び出しごとに`FFI::new()`、既存コードのコピー量と同程度なのでv1は許容）
- busy-spinの代わりに`xdpphp_fd()`を公開して`poll()`待機に変更（アイドル時CPU削減）
- BPFオブジェクトのファイルパスロードではなく、シム内へのバイト埋め込み
