#!/bin/bash
# Zendure SolarFlow - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Das Plugin ist reines PHP - es braucht KEINE virtuelle Python-Umgebung und
# damit auch keinen Umweg um PEP 668 herum. Gebraucht werden nur die
# mosquitto-Kommandozeilenwerkzeuge, und zwar ausschliesslich fuer die alten
# Geraete (Hub, Hyper, Ace, AIO), die nur MQTT sprechen. Fuer die neueren
# Geraete mit lokaler HTTP-Schnittstelle sind sie entbehrlich.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-zendure}"
# Wurzel, in dieser Reihenfolge (Regeln/06: ohne brauchbare Wurzel warnen
# statt vollziehen; dieselbe Regel wie bin/dienst.sh):
#   1. das fuenfte Argument - der Installer uebergibt dort die LoxBerry-Wurzel
#      (plugininstall.pl:1158 und :1577, am Geraet nachgesehen 17.09.2026),
#   2. $LBHOMEDIR, wenn darunter config/plugins und data/plugins liegen,
#   3. vom eigenen Ablageort aufwaerts das erste Verzeichnis mit
#      config/plugins, data/plugins UND config/system/general.json.
# LoxBerry::System taugt hier nicht: es leitet den Pluginordner aus dem
# Aufrufort ab und liefert aus postinstall.sh heraus ueberall Leerstring.
# Dieses Skript laeuft im Tempordner <home>/data/system/tmp/uploads/<name>
# (plugininstall.pl:343); Stufe 3 findet von dort <home>. Bis 0.9.24 stand
# hier "zwei Ebenen ueber dem Tempordner" - das ist <home>/data/system/tmp,
# keine Wurzel - und ein beliebiges Verzeichnis in $LBHOMEDIR galt als
# Wurzel. In WSL gemessen (Pruefung-ZendureSolarFlow-0.9.25/messe_haken.sh,
# Faelle I1, I2, I4): Ordner und zendure.json in einem fremden Baum bzw. im
# beliebigen Verzeichnis angelegt, die Konfiguration der Anlage nicht
# zurueckgespielt. Ohne Wurzel wird jetzt nichts angelegt.
zd_wurzel_suchen() {
    zd_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    zd_i=0
    while [ -n "$zd_v" ] && [ "$zd_v" != "/" ] && [ "$zd_i" -lt 8 ]; do
        if [ -d "$zd_v/config/plugins" ] && [ -d "$zd_v/data/plugins" ] \
           && [ -f "$zd_v/config/system/general.json" ]; then
            echo "$zd_v"
            return 0
        fi
        zd_v=$(dirname "$zd_v")
        zd_i=$((zd_i + 1))
    done
    return 1
}
BASE="${ARGV5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(zd_wurzel_suchen) || BASE=""
    fi
fi
if [ -z "$BASE" ]; then
    echo "<FAIL> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde nichts angelegt"
    echo "<FAIL> und keine Konfiguration zurueckgespielt."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
# NEBEN dem Datenordner: plugininstall.pl ruft beim Upgrade purge_installation
# und entfernt data/plugins/<ordner>/ vollstaendig, ohne Bedingung. Hier liegen
# deshalb der SOC-Verlauf und der Sollmerker - alles, was ein Update
# ueberstehen soll. Der Punkt im Namen haelt den Ordner aus dem
# "rm -rf <ordner>/" heraus.
PBESTAND="$BASE/data/plugins/$PFOLDER.bestand"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

# ---- Die Upgrade-Marke wieder wegraeumen ----
#
# preupgrade.sh legt sie an; bin/dienst.sh startet nicht, solange sie liegt.
# Dieses Skript ist das Rueckgabefenster (Regeln/06) und damit die Stelle, an
# der sie fallen muss - der Startweg prueft sie selbst, also kommt es auf die
# Reihenfolge gegenueber einem Dienststart hier nicht an.
#
# Ueber trap, NICHT am Dateiende: dieses Skript steigt an mehreren Stellen mit
# "exit 1" aus (Ordner nicht anlegbar, kein PHP). Ohne trap bliebe der Dienst
# nach einer gescheiterten Installation eine Stunde gesperrt, ohne dass
# irgendwo stuende, warum. Gemessen an Sprachsteuerung 0.11.7 (Regeln/06,
# Nachtrag 17.09.2026): eine Kommandoersetzung und eine Unterschale loesen den
# EXIT-Trap nicht aus, die Marke faellt also nicht zu frueh.
#
# postupgrade.sh leitet hierher weiter, LoxBerry ruft beim Upgrade aber BEIDE
# Haken (Regeln/06). Der zweite Lauf findet die Marke bereits fort; "rm -f"
# ist dann ein Leerlauf und kein Fehler.
ZD_MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
zd_marke_weg() {
    if [ -f "$ZD_MARKE" ]; then
        rm -f "$ZD_MARKE" && echo "<INFO> Upgrade-Marke entfernt: $ZD_MARKE"
    fi
}
trap zd_marke_weg EXIT

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PDATA/mosq" \
         "$PBESTAND/verlauf" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PBESTAND" "$PLOG" "$PCONFIG" 2>/dev/null
# In diesem Ordner liegen die Broker-Zugangsdaten fuer mosquitto_sub/pub.
chmod 700 "$PDATA/mosq" 2>/dev/null

# Aus einer Installation vor 0.9.9 lagen Verlauf und Sollmerker noch IM
# Datenordner. Beim ersten Update nach 0.9.9 ist der Datenordner bereits
# abgeraeumt - dann gibt es hier nichts mehr zu holen, und das ist der
# Normalfall. Wer 0.9.9 dagegen ueber eine noch unversehrte Installation
# legt (Neuinstallation ohne purge), soll seine Kurve behalten.
if [ -d "$PDATA/verlauf" ]; then
    for F in "$PDATA/verlauf/"geraet*_*.csv; do
        [ -f "$F" ] || continue
        [ -f "$PBESTAND/verlauf/$(basename "$F")" ] || cp -p "$F" "$PBESTAND/verlauf/" 2>/dev/null
    done
    rm -rf "$PDATA/verlauf" 2>/dev/null
    echo "<INFO> Bisheriger SOC-Verlauf nach $PFOLDER.bestand uebernommen."
fi
if [ -f "$PDATA/soll_laufen" ] && [ ! -f "$PBESTAND/soll_laufen" ]; then
    mv "$PDATA/soll_laufen" "$PBESTAND/soll_laufen" 2>/dev/null
fi

[ -f "$PCONFIG/zendure.json" ] || echo '{}' > "$PCONFIG/zendure.json"

# Bis 0.9.19 lag im Archiv zusaetzlich config/zendure.backup.json mit "{}".
# Der Installer legte sie IN den Konfigordner; benutzt hat sie nie jemand -
# die Zweitschrift steht neben dem Ordner ($BK unten). Der Installer
# entfernt nichts, was er einmal kopiert hat, deshalb wird hier abgeraeumt:
# aber nur, wenn die Datei leer ist. Steht etwas darin, hat es jemand
# anderes dort abgelegt, und dann bleibt sie stehen.
ALT="$PCONFIG/zendure.backup.json"
if [ -f "$ALT" ]; then
    ALT_INHALT=$(tr -d ' \t\n\r' < "$ALT" 2>/dev/null)
    if [ -z "$ALT_INHALT" ] || [ "$ALT_INHALT" = "{}" ]; then
        rm -f "$ALT" && echo "<INFO> Leere Altdatei zendure.backup.json im Konfigordner entfernt."
    fi
fi

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
#
# WARUM DIE MITGELIEFERTE config/zendure.json NUR "{}" ENTHALTEN DARF:
# Der Installer kopiert config/* aus dem Archiv ueber config/plugins/<ordner>
# (plugininstall.pl, Zeile 899: cp -r, ohne -n, bei Update wie bei
# Erstinstallation). Bis 0.9.3 lag dort eine Datei mit 322 Byte
# VORGABEWERTEN. Nach dem Ueberschreiben war die Konfiguration damit weder
# leer noch "{}" - die Bedingung unten griff nie, und saemtliche
# Einstellungen waren nach jedem Update fort. Ein Netz, das nicht ausloesen
# kann, ist schlimmer als keines: es taeuscht Sicherheit vor.
#
# Wer die mitgelieferte Datei wieder mit Inhalt fuellt, macht genau diesen
# Fehler erneut.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/zendure.json"
if [ -f "$BK" ]; then
    INHALT=$(tr -d ' \t\n\r' < "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ] || [ -z "$INHALT" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. LoxBerry bringt PHP normalerweise mit -"
    echo "<FAIL> ohne PHP laeuft weder die Oberflaeche noch der Dienst."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- mosquitto-Werkzeuge ----------
# Nur fuer die alten Geraete noetig. Fehlen sie, wird das gemeldet und die
# Installation laeuft weiter - ein Plugin, das wegen eines nur teilweise
# gebrauchten Werkzeugs abbricht, waere unverhaeltnismaessig.
if command -v mosquitto_sub >/dev/null 2>&1 && command -v mosquitto_pub >/dev/null 2>&1; then
    echo "<OK> mosquitto-Werkzeuge vorhanden: $(mosquitto_sub --help 2>&1 | head -1)"
else
    # Hier wird NICHT mehr nachinstalliert.
    #
    # Bis 0.9.0 stand an dieser Stelle ein "apt-get install -y
    # mosquitto-clients". Das kann nicht gelingen: postinstall.sh laeuft als
    # Benutzer loxberry, apt braucht root. Der Aufruf scheiterte also immer -
    # und weil seine Ausgabe nach /dev/null ging, sah man nur den
    # Ersatztext dahinter.
    #
    # Das Paket steht jetzt in dpkg/apt; LoxBerry installiert es waehrend
    # der Plugin-Installation mit den noetigen Rechten. Fehlt es hier
    # trotzdem, ist bei der Paketinstallation etwas schiefgegangen - und
    # genau das gehoert gemeldet, statt es zu verdecken.
    echo "<INFO> mosquitto_sub/mosquitto_pub fehlen - obwohl mosquitto-clients in"
    echo "<INFO> dpkg/apt steht. Bei der Paketinstallation ist etwas schiefgegangen."
    echo "<INFO> Das ist NUR fuer die aelteren Geraete ein Problem (Hub 1200, Hub 2000,"
    echo "<INFO> Hyper 2000, Ace 1500, AIO 2400) - sie sprechen ausschliesslich MQTT."
    echo "<INFO> Geraete mit lokaler HTTP-Schnittstelle (SolarFlow 800 und neuer)"
    echo "<INFO> laufen auch ohne. Nachholen mit: sudo apt install mosquitto-clients"
fi

chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chmod 755 "$PBIN/zendure_dienst.php" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PBESTAND" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 700 "$PDATA/mosq" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, die Geraete eintragen und den"
echo "<INFO> Dienst im Reiter Einstellungen starten."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
# Dieselbe Wurzel wie oben (bis 0.9.24 hier eigens "${5:-$LBHOMEDIR}";
# ohne fuenftes Argument fehlte dann die Wurzel, Fall I5 bzw. V4b in
# Pruefung-ZendureSolarFlow-0.9.25/messe_haken.sh).
NETZ_BASE="$BASE"
NETZ_PDIR="${3:-zendure}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "zendure.json" "44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a"

# Nach dem Umbau auf Retain (0.9.19): den Merker des Doppelt-senden-Filters
# abraeumen. Er liegt in data/ und ueberlebt ein Upgrade; was sich seither
# nicht geaendert hat, wuerde also NICHT gesendet und stuende damit auch
# nicht zurueckbehalten im Broker (Regeln/07, ACTiKamera 1.9.19 am
# 10.09.2026). Der Dienst schickt zwar ohnehin alle mqtt_auffrischung
# Sekunden alles - aber die Einstellung reicht bis 86400, und einen Tag lang
# einen halben Zustand im Broker zu haben ist kein Zustand.
#
# Die Datei ist ein reiner Merker: sie zu loeschen kostet einen einzigen
# vollen Versand und sonst nichts.
rm -f "$PDATA/mqtt_letzte.json" 2>/dev/null


exit 0
