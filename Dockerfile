FROM dafire/baseimage-docker:latest

ENV HOME /root

RUN /etc/my_init.d/00_regen_ssh_host_keys.sh

CMD ["/sbin/my_init"]

# Nginx-PHP Installation
RUN apt-get update
RUN apt-get install -y vim curl wget build-essential python-software-properties
RUN add-apt-repository -y ppa:ondrej/php5
RUN add-apt-repository -y ppa:nginx/stable
RUN add-apt-repository -y ppa:mapnik/boost
RUN add-apt-repository -y ppa:chris-lea/redis-server 
RUN wget -O - http://dl.hhvm.com/conf/hhvm.gpg.key | sudo apt-key add -
RUN echo deb http://dl.hhvm.com/ubuntu precise main | sudo tee /etc/apt/sources.list.d/hhvm.list

RUN apt-get update
RUN apt-get install -y php5-cli php5-fpm php5-mysql php5-pgsql php5-sqlite php5-curl\
		       php5-gd php5-mcrypt php5-intl php5-imap php5-tidy

RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php5/fpm/php.ini
RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php5/cli/php.ini

RUN apt-get install -y nginx
RUN apt-get install -y hhvm
RUN apt-get install -y redis-server memcached

RUN echo "daemon off;" >> /etc/nginx/nginx.conf
RUN sed -i -e "s/;daemonize\s*=\s*yes/daemonize = no/g" /etc/php5/fpm/php-fpm.conf
RUN sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php5/fpm/php.ini

RUN sed -i.bak "s/daemonize yes/daemonize no/g" /etc/redis/redis.conf

RUN mkdir           /var/www
ADD .               /var/www
RUN chown -R www-data /var/www
ADD docker/default   /etc/nginx/sites-available/default
RUN mkdir           /etc/service/redis
ADD docker/redis.sh  /etc/service/redis/run
RUN chmod +x        /etc/service/redis/run
RUN mkdir           /etc/service/memcached
ADD docker/memcached.sh    /etc/service/memcached/run
RUN chmod +x        /etc/service/memcached/run
RUN mkdir           /etc/service/nginx
ADD docker/nginx.sh  /etc/service/nginx/run
RUN chmod +x        /etc/service/nginx/run
RUN mkdir           /etc/service/phpfpm
ADD docker/phpfpm.sh /etc/service/phpfpm/run
RUN chmod +x        /etc/service/phpfpm/run

EXPOSE 80
# End Nginx-PHP

RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*
