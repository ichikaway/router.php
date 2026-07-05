FROM php:8.5.5-zts

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
    strace \
    linux-perf \
    libxdp-dev \
    libbpf-dev \
    libelf-dev \
    zlib1g-dev \
    clang \
    llvm \
    pkg-config \
    make \
    bpftool \
    && docker-php-ext-install sockets \
    && docker-php-ext-install ffi \
    && pecl install parallel \
    && docker-php-ext-enable parallel \
    && echo "ffi.enable=true" > /usr/local/etc/php/conf.d/xx-ffi.ini
#    wget \
#    libzip-dev \
#    libcap2-bin \
#    && docker-php-ext-install pcntl \
#    && docker-php-ext-install zip \
#    && setcap cap_sys_ptrace=eip /usr/local/bin/php
