CC := clang
PKGS := libxdp libbpf

CFLAGS := -O2 -Wall -Wextra -fPIC $(shell pkg-config --cflags $(PKGS))
LDLIBS := $(shell pkg-config --libs $(PKGS))
ARCH_INCLUDE := /usr/include/$(shell uname -m)-linux-gnu
BPF_CFLAGS := -O2 -g -target bpf $(shell pkg-config --cflags libbpf) -I$(ARCH_INCLUDE)

all: libxdpphp.so xdp_filter.bpf.o

libxdpphp.so: xdpphp.c
	$(CC) $(CFLAGS) -shared -o $@ xdpphp.c $(LDLIBS)

xdp_filter.bpf.o: xdp_filter.bpf.c
	$(CC) $(BPF_CFLAGS) -c $< -o $@

clean:
	rm -f libxdpphp.so xdp_filter.bpf.o

.PHONY: all clean
