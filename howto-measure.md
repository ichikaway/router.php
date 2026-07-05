# iperf3計測の手順書

`plan.md`/`result-*.md`で行ったベンチマークを、今後も同じ条件で再現できるようにするための前提条件・手順のまとめ。結果そのものは`result-*.md`側に記録し、このファイルは「どうやって計測するか」だけを書く。

## 0. 全体構成

docker-compose上の4コンテナ:

```
alice(10.0.0.10) --- net1 --- router(eth0=10.0.0.250 / eth1=10.0.1.250) --- net2 --- bob(10.0.1.10)
                                                                              net2 --- perf(10.0.1.11)
```

- `alice`/`bob`は起動時entrypoint.shで`ethtool -K eth0 rx off tx off tso off gro off`が自動適用される(オフロードを切らないとraw socketで組み立てたパケットが壊れるため必須)。手動で追加設定する必要はない。
- ブランチによって実装が異なる:
  - `performance-up-thread`: AF_PACKETのみ(read/writeとも)、writeだけ`parallel`スレッドへオフロード。XDP/FFI不要。
  - `performance-up-thread-xdp`: read=AF_XDP、write=AF_PACKET+`parallel`スレッド(hybrid構成)。FFI(`ffi.enable=true`)とXDPビルド成果物が必要。

## 1. スタックの起動

```bash
cd /home/ichi/php/router.php
git checkout <対象ブランチ>          # 例: performance-up-thread-xdp
docker compose build router
docker compose up -d --force-recreate router
# alice/bob/perfは通常再ビルド不要。初回のみ `docker compose up -d` で全部起動しておく
```

`performance-up-thread-xdp`ブランチの場合、コンテナ起動時のentrypoint.shが`make -C /router/src/c`を自動実行し`libxdpphp.so`/`xdp_filter.bpf.o`をビルドする。ビルドされているか確認:

```bash
docker compose exec -T router ls -la /router/src/c/*.so /router/src/c/*.o
```

無ければ手動ビルドしてエラーを確認する:

```bash
docker compose exec -T router bash -c "make -C /router/src/c clean && make -C /router/src/c"
```

## 2. ルーターの起動

**単一プロセス(`start.php`)** — 両NICを1プロセスで処理:

```bash
docker compose exec -T router bash -c "
cd /router && nohup php -d ffi.enable=true src/start.php > /tmp/router.log 2>&1 &
disown"
```

**2プロセス(`start_eth0.php` + `start_eth1.php`)** — NICごとにプロセスを分ける(スループットが最も高い構成):

```bash
docker compose exec -T router bash -c "
cd /router
nohup php -d ffi.enable=true src/start_eth0.php > /tmp/router-eth0.log 2>&1 &
disown
nohup php -d ffi.enable=true src/start_eth1.php > /tmp/router-eth1.log 2>&1 &
disown"
```

**JITを有効にする場合**、上記の`php`コマンドに以下を追加する(`memo.txt`記載のオプション):

```
-d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=100M -d opcache.jit=1255
```

**注意:**
- `performance-up-thread`(AF_PACKETのみ)ブランチでは`ffi.enable=true`は不要(付けても害はない)。
- `performance-up-thread-xdp`(hybrid)ブランチでは`ffi.enable=true`必須。無いとFFI初期化で例外になる。
- 起動直後は`/tmp/router*.log`を確認し、`RuntimeException`(FFI/XDPアタッチ失敗)が出ていないことを確認してからベンチマークに進む。
- プロセスをkillするときは`pkill -9 -f start.php`のような自己マッチに注意(下記トラブルシューティング参照)。

## 3. 疎通確認(ベンチマーク前に必ず行う)

```bash
# ルーター自身への到達(ARP/自ノード宛てトラフィックの経路が生きているかの確認)
docker compose exec -T alice ping -c3 -W2 10.0.0.250

# ルーター経由でbobへの到達(転送+ARP解決の確認)
docker compose exec -T alice ping -c3 -W2 10.0.1.10
```

どちらか一方でもロスがあれば、iperf3を実行する前に原因を切り分ける(XDPアタッチ状況は`ip link show eth0`/`bpftool net show`で確認できる)。

## 4. iperf3計測

サーバー(bob)の起動。既存プロセスがあれば必ず一度killしてから起動する:

```bash
docker compose exec -T bob pkill -9 -f iperf3
docker compose exec -T bob bash -c "nohup iperf3 -s > /tmp/iperf3-server.log 2>&1 & disown; sleep 1; echo started"
```

クライアント(alice)からの計測。これまで使用してきた標準コマンド:

```bash
# UDP: 4並列、700Mbit/sずつ、10秒
docker compose exec -T alice iperf3 -c 10.0.1.10 -u -b 700M -P 4 -t 10

# TCP: 2並列、10秒
docker compose exec -T alice iperf3 -c 10.0.1.10 -P 2 -t 10
```

比較する際は必ずこの2本(UDP `-P 4 -b 700M`、TCP `-P 2`)を同じ順序・同じ待機時間で実行し、`result-*.md`にはこのコマンドで出た値だけを載せる。ベンチマーク後、ルーターのPHPプロセスが生きているか必ず確認する(クラッシュしていないか):

```bash
docker compose exec -T router ps aux | grep '[s]tart'
```

## 5. kernel ip_forwardとの比較を行う場合

PHPルーターを介さない「カーネル単体」の参考値を測る場合の手順。

```bash
# 1. PHPルーターを止める
docker compose exec -T router pkill -9 -f start

# 2. (AF_XDPブランチの場合のみ) XDPプログラムを外す。外さないと転送対象トラフィックが
#    XSKMAPへredirectされたままになりip_forwardまで届かない
docker compose exec -T router bash -c "ip link set dev eth0 xdp off; ip link set dev eth1 xdp off"

# 3. カーネルのip_forwardを有効化
docker compose exec -T router sysctl -w net.ipv4.ip_forward=1

# 4. 疎通確認 → iperf3計測(手順3, 4と同じ)

# 5. 計測後は必ず元に戻す
docker compose exec -T router sysctl -w net.ipv4.ip_forward=0
# PHPルーターを再起動(手順2)
```

**RPS(Receive Packet Steering)に注意:** vethはシングルキューデバイスでRPSが未設定だと受信側softirq処理が単一CPUコアに偏り、`ip_forward=1`のスループットが大きく制限される(詳細は`result-20260705-2.md`のRPS調査の節を参照)。フェアな比較をしたい場合は、計測前に以下でRPSの状態を確認・必要なら設定する:

```bash
# 現在の設定を確認
docker compose exec -T router cat /sys/class/net/eth0/queues/rx-0/rps_cpus
docker compose exec -T router cat /sys/class/net/eth1/queues/rx-0/rps_cpus

# 有効化する場合(nprocの桁数に応じてビットマスクを調整。24コアなら6桁のf=ffffff)
docker compose exec -T router bash -c "
echo ffffff > /sys/class/net/eth0/queues/rx-0/rps_cpus
echo ffffff > /sys/class/net/eth1/queues/rx-0/rps_cpus"

# 計測後は元(0)に戻す
docker compose exec -T router bash -c "
echo 0 > /sys/class/net/eth0/queues/rx-0/rps_cpus
echo 0 > /sys/class/net/eth1/queues/rx-0/rps_cpus"
```

この設定はコンテナのネットワークネームスペース内(veth のコンテナ側の端)に閉じたもので、ホストOS側の設定変更は不要。再起動すると失われる一時設定なので、比較のたびに設定し直す必要がある。

## 6. トラブルシューティング

- **`pkill -f`の自己マッチ**: `docker compose exec -T router bash -c "pkill -9 -f start.php; ..."`のように同じコマンド文字列の中に検索対象の文字列(`start.php`など)が含まれると、`bash -c`自身のプロセスにもマッチして意図せず自分自身を巻き込んでkillしてしまうことがある(exit code 137で分かる)。対策は次のいずれか:
  - killだけを独立した`docker compose exec`呼び出しにする(他のコマンドと同じ`bash -c`に混ぜない)
  - パターンを`'[s]tart.php'`のように正規表現の文字クラスで書き、grep/pkill自身の引数文字列とはマッチしないようにする
- **`docker compose exec`(オプション`-T`無し)がハングすることがある**: 必ず`-T`を付けて実行する(PTY割り当てを無効化)。
- **AF_XDPは同じNICに複数プロセスから受信ソケットを開けない**: `start_eth0.php`/`start_eth1.php`を使うときは、`Router.php`が`handleNic`で指定されたNIC以外にはAF_XDP受信ソケットを作らない実装になっている前提(`f31e3d6`で修正済み)。もし将来的にRouter.phpを変更する場合はこの制約を壊さないよう注意する。
- **FFI関連の例外が出る場合**: `-d ffi.enable=true`を付け忘れていないか確認する(デフォルトの`ffi.enable=preload`だとCLIスクリプトから`FFI::cdef()`が使えない)。
- **iperf3サーバー・クライアントとも計測のたびにkillしてから起動し直す**: 前回の計測プロセスが残っていると`Bad file descriptor`等のエラーで正しい結果が取れないことがある。
