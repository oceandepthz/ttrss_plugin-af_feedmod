#!/bin/bash

if [ $# -ne 1 ]; then
  echo "指定された引数は$#個です。" 1>&2
  echo "実行するには1個の引数が必要です。" 1>&2
  exit 1
fi

/usr/bin/grep -v $1 /pub/ttrss/plugins/af_feedmod/af_feed_no_entry.txt > /tmp/af_feed_no_entry.tmp.txt
/usr/bin/chown apache.apache /tmp/af_feed_no_entry.tmp.txt
/usr/bin/mv /pub/ttrss/plugins/af_feedmod/af_feed_no_entry.txt /pub/ttrss/plugins/af_feedmod/af_feed_no_entry.txt.`/usr/bin/date +%Y%m%d%H%M%S`
/usr/bin/mv /tmp/af_feed_no_entry.tmp.txt /pub/ttrss/plugins/af_feedmod/af_feed_no_entry.txt

