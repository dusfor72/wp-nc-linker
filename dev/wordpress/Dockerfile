FROM wordpress:cli-2.12-php8.4 AS cli

FROM wordpress:7.1.3-php8.4-apache

COPY --from=cli /usr/local/bin/wp /usr/local/bin/wp
COPY php.ini /usr/local/etc/php/conf.d/zz-wpncl.ini
COPY apache-wpncl.conf /etc/apache2/conf-available/wpncl.conf
COPY entrypoint.sh /usr/local/bin/wpncl-entrypoint.sh

RUN a2enconf wpncl

ENTRYPOINT ["wpncl-entrypoint.sh"]
CMD ["apache2-foreground"]
