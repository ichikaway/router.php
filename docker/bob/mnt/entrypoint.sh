#!/bin/bash
ip route add 10.0.0.0/24 via 10.0.1.250
ethtool -K eth0 rx off
ethtool -K eth0 tx off
ethtool -K eth0 tso off
ethtool -K eth0 gro off
bash -c "/bin/bash"
