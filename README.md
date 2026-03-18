# IP Router Built with PHP

## Features

* Packet forwarding using multiple NICs and networks
* Default routing functionality
* ARP support

## Usage

### PHP Version

* PHP 8.5 or higher
* Socket extension

### Sample Scripts

Since RAW sockets are used, all scripts must be executed with root privileges.

* `src/start.php`

    * Standard execution script that outputs debug information

* `src/start_raspi.php`

    * Execution script for Raspberry Pi
    * Requires the wireless LAN interface `wlan0` to be available

* `src/start_eth0.php` and `src/start_eth1.php`

    * Designed for performance measurement
    * Runs as separate PHP processes for `eth0` and `eth1` networks

### Docker Environment

Creates a network and containers named **alice - Router - bob**.
GRO/TSO is disabled in the containers.

```
docker compose build
docker compose up
```

This assigns the following IP addresses to the containers:

```
alice  10.0.0.10
Router 10.0.0.250, 10.0.1.250
bob    10.0.1.10
```

Log in using the following commands, and run `sudo php start.php` in the router container to start the router:

* `docker compose exec router bash`
* `docker compose exec alice bash`
* `docker compose exec bob bash`


---------------------------------
# PHPで作るIPルーター

## 機能
- 複数NICとネットワークを使ったパケット転送
- デフォルトルート機能
- ARP

## 使い方
### PHPバージョン
- PHP8.5以上
- socket拡張

### サンプルスクリプト
RAW socketを使っているため、すべてroot権限での実行が必要

- src/start.php
  - デバッグ情報を出力する標準的な実行スクリプト
- src/start_raspi.php
  - ラズベリーパイ用の実行スクリプト
  - 無線LANのwlan0が利用できる状態になっていること
- src/start_eth0.php と src/start_eth1.php
  - パフォーマンス計測ようにeth0とeth1のネットワークように独立したPHPプロセスで処理できるようにしたもの

### Docker環境
alice - Roter - Bob というネットワークとコンテナを作成する。   
コンテナのGRO/TSOはオフにしている。  

```
docker compose build
docker compose up
```

これで次のIPアドレスがコンテナに割り振られる
```
alice 10.0.0.10
Router 10.0.0.250, 10.0.1.250
bob 10.0.1.10
```

次のコマンドでログインして routerコンテナで `sudo php start.php` をするとルーターが動作する

- docker compose exec router bash
- docker compose exec alice bash
- docker compose exec bob bash

