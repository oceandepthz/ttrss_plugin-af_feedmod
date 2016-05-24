#!/bin/sh

if [ -e af_feedmod.json ]; then
  /usr/bin/mv /pub/ttrss/plugins.local/af_feedmod/af_feedmod.json /pub/ttrss/plugins.local/af_feedmod/af_feedmod.json.`/usr/bin/date +%Y%m%d%H%M%S`
fi

/usr/bin/su -s /bin/bash apache -c '/usr/bin/php /pub/ttrss/plugins.local/af_feedmod/export_json.php' > af_feedmod.json
