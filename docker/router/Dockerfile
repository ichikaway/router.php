FROM php:8.5.1

RUN apt update
RUN apt install -y \
    vim \
    iputils-ping \
    traceroute \
    iptables \
    net-tools \
    iproute2 \
    bash \
    procps \
    tcpdump \
    curl \
    ethtool \
    wget

RUN docker-php-ext-install sockets
