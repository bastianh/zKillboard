#!/bin/sh

sysctl vm.overcommit_memory=1
exec /sbin/setuser redis /usr/bin/redis-server /etc/redis/redis.conf > /dev/null 2>&1
