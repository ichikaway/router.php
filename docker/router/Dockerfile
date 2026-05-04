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
    && docker-php-ext-install sockets \
    && pecl install parallel \
    && docker-php-ext-enable parallel
#    wget \
#    libffi-dev \
#    libzip-dev \
#    libcap2-bin \
#    && docker-php-ext-install sockets \
#    && docker-php-ext-install ffi \
#    && docker-php-ext-install pcntl \
#    && docker-php-ext-install zip \
#    && setcap cap_sys_ptrace=eip /usr/local/bin/php

#RUN docker-php-ext-install sockets
