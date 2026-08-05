#!/bin/bash
# Zendure SolarFlow - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-zendure}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten."
fi
# Herrenlose Horcher aufraeumen, damit sie sich nicht doppeln
pkill -f "mosquitto_sub .*zendure" 2>/dev/null || true

CF="$BASE/config/plugins/$PFOLDER/zendure.json"
[ -f "$CF" ] && cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json"
echo "<OK> preupgrade abgeschlossen."
exit 0
