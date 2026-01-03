#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <net/ethernet.h>
#include <netpacket/packet.h>
#include <net/if.h>
#include <sys/socket.h>
#include <sys/ioctl.h>
#include <arpa/inet.h>
#include <linux/if_ether.h>
#include <linux/ip.h>

#define BUFFER_SIZE 65536

int main() {
    int sockfd;
    unsigned char buffer[BUFFER_SIZE];

    // RAWソケット作成（全プロトコル対象）
    sockfd = socket(AF_PACKET, SOCK_RAW, htons(ETH_P_ALL));
    if (sockfd < 0) {
        perror("socket");
        exit(EXIT_FAILURE);
    }

    // インターフェース名
    const char *iface = "eth0"; // 環境に応じて変更（例: "ens33", "en0"など）

    // インターフェースをプロミスキャスモードに設定
    struct ifreq ifr;
    strncpy(ifr.ifr_name, iface, IFNAMSIZ);
    ioctl(sockfd, SIOCGIFFLAGS, &ifr);
    ifr.ifr_flags |= IFF_PROMISC;
    ioctl(sockfd, SIOCSIFFLAGS, &ifr);
    printf("Promiscuous mode enabled on %s\n", iface);

    // 無限ループでパケット読み取り
    while (1) {
        ssize_t data_size = recvfrom(sockfd, buffer, BUFFER_SIZE, 0, NULL, NULL);
        if (data_size < 0) {
            perror("recvfrom");
            break;
        }

        // EthernetヘッダをスキップしてIPヘッダを見る
        struct ethhdr *eth = (struct ethhdr *)buffer;
        if (ntohs(eth->h_proto) == ETH_P_IP) {
            struct iphdr *iph = (struct iphdr *)(buffer + sizeof(struct ethhdr));
            struct in_addr src, dst;
            src.s_addr = iph->saddr;
            dst.s_addr = iph->daddr;

            printf("IP packet: %s -> %s\n",
                   inet_ntoa(src),
                   inet_ntoa(dst));
        }
    }

    close(sockfd);
    return 0;
}

