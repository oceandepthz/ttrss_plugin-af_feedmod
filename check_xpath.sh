#!/bin/sh

/usr/bin/cat /pub/ttrss/plugins.local/af_feedmod/af_feed_no_entry.txt |
	/usr/bin/grep 'xpath'|
	/usr/bin/awk '{print $3,$4}'|
	/usr/bin/sort|
	/usr/bin/uniq|
	/usr/bin/awk '{print $1}'|
	/usr/bin/uniq -c|
	/usr/bin/awk '{if($1>2){print $0}}'
