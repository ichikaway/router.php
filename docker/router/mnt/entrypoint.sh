#!/bin/bash

#bash -c "/bin/bash"

#sysctl -w net.bridge.bridge-nf-call-iptables=0
#sysctl -w net.bridge.bridge-nf-call-ip6tables=0
#sysctl -w net.bridge.bridge-nf-call-arptables=0
#
#nft flush ruleset

make -C /router/src/c || true

tail -f /dev/null
