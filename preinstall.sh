#!/bin/bash

# Zendure SolarFlow - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# X-1 (B-Nachzug 01.10.2026, Entscheidung 1 vom 29.09.2026; Muster
# Abfahrtsassistent 1.6.19). Der Installer ruft dieses Skript bei JEDEM
# Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh anlegt (kein
# Altersvergleich). Dann tut es nichts: Zweitschrift und Bestandsordner
# braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Was eine fruehere Installation
# liegen liess, geht nach <name>.alt (liegt dort schon eines: .alt.<zeit>),
# gemeldet mit genau einer <WARNING>:
#   config/plugins/<ordner>.backup.json          Zweitschrift (Token, Broker)
#   config/plugins/<ordner>.backup.zendure.json  zweite Zweitschrift
#   data/plugins/<ordner>.bestand                Verlauf, Energie, soll_laufen
#   data/plugins/<ordner>/soll_laufen            Sollmerker alter Bauart
# Bis 0.9.30 tat das erst postinstall.sh, und nur bei leerer Konfiguration.
# Die Cron-Datei liegt am Geraet aber fast eine Minute vor postinstall.sh: der
# Waechter startete ueber den alten soll_laufen einen Dienst, dessen
# Selbstheilung die Konfiguration aus der alten Zweitschrift schrieb - samt
# Aktionstoken und freigegebener Steuerung. Danach trug die Konfiguration
# Inhalt, und postinstall.sh legte nichts mehr beiseite (in WSL gemessen,
# vb_zen2_bau_skripte/proben, Fall N1). Dieselbe Liste und Benennung wie
# postinstall.sh; die Selbstheilung liest .alt nie, uninstall raeumt .alt*
# ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-zendure}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Ohne config/plugins, data/plugins UND config/system/general.json wird
# nichts angefasst (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

ZD_BEISEITE=""
ZD_FEST=""
for ZD_Q in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.zendure.json" \
            "$BASE/data/plugins/$PFOLDER.bestand" \
            "$BASE/data/plugins/$PFOLDER/soll_laufen"; do
    [ -e "$ZD_Q" ] || [ -L "$ZD_Q" ] || continue
    ZD_ZIEL="$ZD_Q.alt"
    if [ -e "$ZD_ZIEL" ] || [ -L "$ZD_ZIEL" ]; then
        ZD_ZIEL="$ZD_Q.alt.$(date +%Y%m%d_%H%M%S)"
    fi
    if mv "$ZD_Q" "$ZD_ZIEL" 2>/dev/null; then
        chmod go-rwx "$ZD_ZIEL" 2>/dev/null
        ZD_BEISEITE="$ZD_BEISEITE $ZD_ZIEL"
    else
        ZD_FEST="$ZD_FEST $ZD_Q"
    fi
done

if [ -n "$ZD_BEISEITE" ] || [ -n "$ZD_FEST" ]; then
    ZD_TEXT="<WARNING> Neuinstallation: Einstellungen und Bestaende einer frueheren Installation werden NICHT eingespielt."
    [ -n "$ZD_BEISEITE" ] && ZD_TEXT="$ZD_TEXT Beiseitegelegt:$ZD_BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$ZD_FEST" ] && ZD_TEXT="$ZD_TEXT Nicht zu verschieben, bitte von Hand entfernen:$ZD_FEST"
    echo "$ZD_TEXT"
fi
exit 0
