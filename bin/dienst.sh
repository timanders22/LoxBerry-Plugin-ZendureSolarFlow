#!/bin/bash
# Zendure SolarFlow - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
# NEBEN dem Datenordner - plugininstall.pl raeumt data/plugins/<ordner>/ bei
# JEDEM Update vollstaendig ab. Bis 0.9.8 lag der Sollmerker darin: nach einem
# Update war er fort, und der Waechter startete den Dienst nie wieder. Der
# Punkt im Namen haelt den Ordner aus dem "rm -rf <ordner>/" heraus.
PBESTAND="$LBHOMEDIR/data/plugins/$PNAME.bestand"
SOLL="$PBESTAND/soll_laufen"
LOGDATEI="$PLOG/zendure.log"
SKRIPT="$SELF/zendure_dienst.php"
# Ein Sperrmerker gegen zwei gleichzeitige Laeufe. Ohne ihn koennen der
# minuetliche Waechter und ein Klick in der Oberflaeche einander ueberholen.
SPERRE="$PBESTAND/dienst.sperre"

mkdir -p "$PDATA" "$PLOG" "$PBESTAND" 2>/dev/null

# Sollmerker aus einer Installation vor 0.9.9 einmalig hinueberziehen.
if [ -f "$PDATA/soll_laufen" ] && [ ! -f "$SOLL" ]; then
    mv "$PDATA/soll_laufen" "$SOLL" 2>/dev/null || touch "$SOLL"
fi

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein
    # Argumentweise pruefen, nicht die ganze Befehlszeile durchsuchen.
    #
    # /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein grep
    # darueber traf auch einen Editor mit geoeffneter zendure_dienst.php,
    # wenn die Prozessnummer wiederverwendet wurde. Geprueft werden jetzt
    # zwei Dinge: das zweite Argument ist genau unser Skript, und das erste
    # ist ein PHP - "nano <pfad>/zendure_dienst.php" fuehrt den Pfad sonst
    # ebenfalls als zweites Argument.
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "FEHLER: PHP nicht gefunden - ohne PHP laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/zendure.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/zendure.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Ausgabe geht in die Logdatei. Das PHP-Skript protokolliert deshalb NICHT
    # zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    # 9>&- schliesst den Sperr-Dateizeiger fuer den Dienst. Ohne das haelt er
    # die Sperre, solange er laeuft, und jedes spaetere stop/restart wartet
    # vergeblich - siehe den Block am Ende dieser Datei.
    nohup php "$SKRIPT" >> "$LOGDATEI" 2>&1 9>&- &
    NEUPID=$!
    echo "$NEUPID" > "$PID"

    # Bis zu zehn Sekunden warten, nicht genau eine.
    #
    # Bis 0.9.8 stand hier ein festes "sleep 1". Braucht der Start laenger -
    # auf einem beschaeftigten Raspberry Pi keine Seltenheit -, war der
    # Befund negativ, die PID-Datei wurde geloescht UND der eben gestartete
    # Prozess lief weiter. Der Waechter sah eine Minute spaeter "laeuft
    # nicht" und legte den zweiten nach, dann den dritten. Gemessen an
    # echten Prozessen (Pruefstand p7_dienst.sh): nach einem Waechterlauf
    # liefen zwei Dienste auf derselben Warteschlange.
    #
    # Frueh abbrechen, sobald es steht: der Regelfall bleibt schnell.
    I=0
    while [ "$I" -lt 20 ]; do
        if laeuft; then
            echo "gestartet (PID $(cat "$PID"))"
            return 0
        fi
        # Ist der Prozess schon wieder fort, hat er sich selbst beendet -
        # dann bringt weiteres Warten nichts.
        kill -0 "$NEUPID" 2>/dev/null || break
        sleep 0.5
        I=$((I+1))
    done

    # Es steht nicht. Erst aufraeumen, DANN melden - ein herrenloser Prozess
    # ohne PID-Datei ist schlimmer als ein sauberer Fehlschlag.
    if kill -0 "$NEUPID" 2>/dev/null; then
        kill "$NEUPID" 2>/dev/null
        sleep 1
        kill -9 "$NEUPID" 2>/dev/null
    fi
    rm -f "$PID"
    # Und der Sollmerker geht mit. Bliebe er liegen, liefe der Waechter im
    # Minutentakt in denselben Fehlschlag - ausloesbar allein dadurch, dass
    # jemand "Dienst starten" drueckt, bevor ein Geraet eingetragen ist.
    rm -f "$SOLL"
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        # Herrenlose Horcher trotzdem einsammeln
        pkill -f "mosquitto_sub .*loxberry-$PNAME-" 2>/dev/null
        echo "laeuft nicht"
        return 0
    fi
    # Die Kennung traegt den ORDNERNAMEN, nicht das feste Wort "zendure".
    # Sonst erschlaegt ein Stopp in der Installation "zendure" den Horcher
    # von "zendure_01" gleich mit - dessen Kennung enthaelt die Zeichenkette
    # ebenfalls. Passend dazu vergibt zd_horcher_starten() sie.
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    pkill -f "mosquitto_sub .*$PNAME" 2>/dev/null
    echo "angehalten"
    return 0
}

# Ab hier nur einer auf einmal.
#
# Der minuetliche Waechter und ein Klick in der Oberflaeche koennen einander
# ueberholen: beide sehen "laeuft nicht", beide starten. flock schliesst das
# aus. Fehlt flock (kein util-linux), laeuft es ohne Sperre weiter - eine
# fehlende Sperre ist ein Nachteil, ein abgebrochener Dienststart ein Ausfall.
#
# ACHTUNG, HIER STECKT EINE FALLE, und sie ist beim Bau von 0.9.9 zugeschnappt:
# In der Form "flock <datei> <befehl>" oeffnet flock die Sperrdatei und der
# gestartete Befehl ERBT den Dateizeiger. Der Abrufdienst haelt die Sperre
# damit, solange er laeuft - und jedes spaetere "stop" wartet 60 Sekunden
# vergeblich und tut dann gar nichts. Der Pruefstand hat es gefunden
# (p7_dienst.sh, Zeile "stop beendet den Dienst nicht").
#
# Deshalb die ausdrueckliche Form mit einer eigenen Nummer: unten wird sie
# beim Start des Dienstes mit "9>&-" geschlossen.
if command -v flock >/dev/null 2>&1; then
    exec 9>"$SPERRE" 2>/dev/null || true
    flock -w 60 9 2>/dev/null || \
        echo "<WARNING> Sperre nicht bekommen - es laeuft offenbar schon ein Vorgang."
fi

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
