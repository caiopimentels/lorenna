#!/bin/sh
set -eu
if [ "${1:-}" = midias ]; then
    exec su -s /bin/sh www-data -c 'php scripts/midias.php'
fi
if [ "${1:-}" = monitor ]; then
    exec php scripts/monitor.php
fi
su -s /bin/sh www-data -c 'php scripts/iniciar.php'
exec apache2-foreground
