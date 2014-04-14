#!/bin/sh

exec /sbin/setuser www-data /usr/bin/memcached > /dev/null 2>&1
