#!/usr/bin/env bash
# Alias : front HTTPS (siteSslHtml) — préféré si Chrome traite l’origine comme secure.
exec "$(dirname "$0")/install-httpd-web-ssl.sh"
