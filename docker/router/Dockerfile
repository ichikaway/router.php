FROM php:8.5.0-zts-bookworm

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

#FROM ubuntu:24.04
#
#RUN apt update
#RUN apt install -y \
#    vim \
#    iputils-ping \
#    traceroute \
#    iptables \
#    net-tools \
#    iproute2 \
#    bash \
#    procps \
#    tcpdump \
#    curl \
#    wget \
#    php8.3 \
#    php8.3-sockets \
#    php8.3-curl \
#    php8.3-mbstring \
