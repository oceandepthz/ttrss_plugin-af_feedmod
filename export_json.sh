#!/bin/sh

/usr/bin/su -s /bin/bash apache -c '/usr/bin/php /pub/ttrss/plugins/af_feedmod/export_json.php' > af_feedmod.json
