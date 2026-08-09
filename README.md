# LoxBerry-Plugin: Zendure SolarFlow

Bindet **Zendure SolarFlow** an Loxone an — **ohne Cloud, ohne Zendure-Konto**.
Unterstützt beide lokalen Wege: die HTTP-Schnittstelle der neueren Geräte und
lokales MQTT für die ältere Reihe.

> **Fassung 0.9.1 — ungeprüft.** Das Plugin wurde ohne Zendure-Gerät gebaut.
> Aufbau, Sprachdateien, Endpunkt und Oberfläche sind geprüft; ob die
> Eigenschaftsnamen der eigenen Firmware passen und ob die Schreibbefehle am
> Gerät wirken, ist es **nicht**. Deshalb 0.9.1 und nicht 1.0.0.
>
> Die Selbstaktualisierung zeigt auf dieses Repository und ist eingeschaltet.
> Bei gleicher Fassung wird niemandem ein Update angeboten; sobald 1.0.0
> erscheint, greift sie von selbst.

## Version 0.9.1 — nachgemessen und korrigiert

### Ein echtes Aktionstoken lag in der mitgelieferten Konfiguration

`config/zendure.json` und `config/zendure.backup.json` sind Vorlagen, die
LoxBerry bei der Installation nach `config/plugins/<ordner>/` legt. In beiden
stand ein **fertiges Aktionstoken** aus einer echten Installation.

Das ist genau der Schlüssel, der den unangemeldeten Endpunkt schützt — den,
über den sich der Speicher laden, entladen und begrenzen lässt. Wäre die
Datei so veröffentlicht worden, hätte **jede** Installation dasselbe, öffentlich
nachlesbare Token gehabt.

Beide Vorlagen tragen jetzt ein leeres Token. Das ist kein Verlust:
`zd_token()` erzeugt beim ersten Öffnen der Oberfläche ein zufälliges und
speichert es. Und der Endpunkt ist für den Zwischenzustand richtig gebaut —
er weist bei leerem Soll-Token **vor** dem Vergleich mit einer eigenen
Meldung ab, statt `hash_equals('', '')` zu vertrauen, das `true` liefert.

### `uninstall/uninstall` gab es nicht

Die Sicherung `config/plugins/<ordner>.backup.json` liegt bewusst neben dem
Konfigordner, damit sie ein Update übersteht — beim Deinstallieren bleibt sie
damit liegen, mitsamt Aktionstoken und, falls eingetragen, den Zugangsdaten
des MQTT-Brokers. Das Skript hält jetzt den Dienst an, überschreibt die
Sicherung und entfernt sie.

### Der Plugin-Ordner wird ermittelt, nicht geraten

`zd_paths()` fiel auf den festen Namen `zendure` zurück, sobald
`config/plugins/<ordner>` noch fehlte. Eine Zweitinstallation (`zendure_01`)
hätte damit die Konfiguration der ersten benutzt — und darin steht das
Aktionstoken. Maßgeblich ist jetzt `LBPPLUGINDIR`.

### Eine leere Befehlsdatei konnte in die Warteschlange geraten

`zd_befehl_senden()` schrieb `json_encode($befehl)` direkt weiter. Gibt
`json_encode` bei ungültigem UTF-8 `false` zurück, schreibt
`file_put_contents` null Byte und meldet **Erfolg** — der Rückgabewert ist
`0`, nicht `false`. Bei einem Speicher, der geladen oder entladen werden
soll, ist ein unlesbarer Befehl kein Schönheitsfehler.

Dreizehn Punkte aus einer Durchsicht: elf trafen zu, einer traf halb zu, einer
war falsch. Was beim Nachmessen zusätzlich auffiel, ist mit erledigt. Zu jedem
Punkt steht unten, was tatsächlich gemessen wurde — nicht, was plausibel klang.

### Was nicht zutraf, und was nur halb

**„`zd_mosq_heim()` schreibt eine ungültige mosquitto-Konfiguration."** Der
schwerwiegendste Vorwurf — und falsch. Behauptet wurde, `-h 127.0.0.1` in einer
Zeile werde nicht verstanden; Schlüssel und Wert gehörten getrennt. Nachgemessen
mit `mosquitto_sub 2.0.11` gegen einen toten Port:

```
so wie im Plugin: '-h 127.0.0.1'      -> Error: Connection refused
getrennte Zeilen: '-h' / '127.0.0.1'  -> Error: -h argument given but no host specified.
Langform:         'host 127.0.0.1'    -> Error: Unknown option 'host'.
```

Nur die Form, die im Plugin steht, funktioniert. Dass die Datei überhaupt
gelesen wird, zeigt der Gegenversuch: mit `-h gibtesnicht.invalid` kommt
*„Unable to connect (Lookup error.)"*, ohne die Datei *„Connection refused"*.
Und gegen einen nachgebauten Broker enthielt das CONNECT-Paket Benutzername und
Passwort. Die vorgeschlagene Änderung hätte MQTT für Hub 1200, Hub 2000,
Hyper 2000, Ace 1500 und AIO 2400 abgeschaltet — genau der Ausfall, den sie
verhindern sollte.

**„Log-Ende über `exec("tail -n 400")` lesen."** Der Speicherhinweis war
berechtigt, der Vorschlag ist der langsamste der drei Wege. 12.000 Zeilen
(610 kB), je 20 Durchläufe:

| Weg | Zeit | Speicher zusätzlich |
|---|---|---|
| bisher, ganz einlesen | 0,37 ms | 2048 kB |
| `exec("tail -n 400")` | 2,17 ms | 0 kB |
| **neu, rückwärts mit `fseek`** | **0,05 ms** | **0 kB** |

Ein Prozessstart kostet mehr, als das Einlesen je gespart hat. Umgestellt auf
`fseek`; die Ausgabe ist Zeile für Zeile dieselbe wie bisher.

### Zutreffend und behoben

**Zerteilte MQTT-Nachrichten gingen verloren.** `fgets()` liefert eine Zeile
auch dann, wenn sie noch nicht vollständig angekommen ist. Der Rest wurde als
eigene Nachricht gedeutet und verworfen. Reproduziert:

```
bisher:  thema1 => {"a":1}      neu:  thema1 => {"a":1}
         thema2 => {"b":              thema2 => {"b":2}
         VERWORFEN: '2}'               thema3 => {"c":3}
         thema3 => {"c":3}
```

Angefangene Zeilen werden jetzt zwischengespeichert und beim nächsten Durchlauf
vervollständigt; über 64 kB ohne Zeilenumbruch wird der Puffer verworfen und
gemeldet, damit ein hängender Broker den Speicher nicht füllt.

**`apt-get install` in `postinstall.sh`.** Das konnte nie gelingen —
`postinstall.sh` läuft als Benutzer `loxberry`, apt braucht root. Weil die
Ausgabe nach `/dev/null` ging, sah man nur den Ersatztext dahinter.
`mosquitto-clients` steht jetzt in **`dpkg/apt`**; das ist der vorgesehene Weg,
und LoxBerry installiert es mit den nötigen Rechten.

**MQTT-Horcher überlebte den Stopp.** `proc_terminate()` schickt SIGTERM und
kehrt sofort zurück; `mosquitto_sub` lief bei einem Neustart mitunter weiter und
hielt die Broker-Verbindung. Jetzt wird bis zu zwei Sekunden gewartet, danach
SIGKILL. Der Aufruf beginnt mit `exec`, damit kein Shell-Zwischenprozess bleibt.

**Konfiguration mit dem Broker-Passwort kurzzeitig lesbar.** Geschrieben wurde
erst, `chmod` kam danach:

```
bisher: während des Schreibens 0644, danach 0600
neu   : während des Schreibens 0600, danach 0600
```

**Akkupacks blieben ewig stehen.** Eine einmal gesehene Seriennummer wurde nie
wieder entfernt — ein ausgebauter Akku erschien weiter mit dem Ladestand vom
Ausbautag. Ein Wert, der sich nie mehr ändert und trotzdem wie eine Messung
aussieht, ist schlimmer als gar keiner. Nach sechs Stunden ohne Meldung fällt
ein Pack heraus.

**Prozessprüfung traf Fremdprozesse.** `grep` über die gesamte Befehlszeile
erkannte auch einen Editor mit geöffneter `zendure_dienst.php`, sobald die
Prozessnummer wiederverwendet wurde. Geprüft wird jetzt argumentweise: zweites
Argument gleich unser Skript **und** erstes Argument ein PHP.

**Ohne JavaScript war die Seite leer.** `.sm-seite` steht auf `display:none`,
und `sm-active` setzte erst das Skript — der Kommentar an der Reiterleiste
behauptete das Gegenteil. Der Server entscheidet jetzt mit, welcher Reiter offen
ist; nachgeprüft unter PHP 7.4 und 8.1 für alle Reiter und für einen ungültigen
`form`-Wert.

**Reiterliste stand dreimal da** — als Positivliste, als Leiste und als `id`.
Jetzt entsteht alles aus einem Feld; vergessen kann man nichts mehr.

**Wartezeit im Webfrontend auf 20 s gedeckelt** — länger als so mancher
Webserver wartet, der Benutzer sah einen 504 statt einer Auskunft. Jetzt 10 s;
der Dienst arbeitet den Befehl trotzdem zu Ende, das Ergebnis steht im
Protokoll.

**Antwortdateien blieben liegen** und sammelten sich im Datenordner. Gelesen ist
erledigt, also gelöscht.

**Gerätenummer `"01"`** bestand die Prüfung, wurde vom Dienst aber mit Zahlen
verglichen und fand kein Gerät. Jetzt als Ganzzahl weitergegeben.

**Cron rief `dienst.sh` unmittelbar auf** — ohne Ausführungsrecht schlägt das
lautlos fehl, die Ausgabe geht nach `/dev/null`. Jetzt ausdrücklich über
`/bin/bash`.

## Zwei Wege, beide lokal

| | HTTP | MQTT |
|---|---|---|
| Geräte | SolarFlow 800 / 800 Plus / 800 Pro, AC-Reihe | Hub 1200, Hub 2000, Hyper 2000, Ace 1500, AIO 2400 |
| Einrichtung | IP-Adresse eintragen, fertig | Gerät einmalig auf den LoxBerry-Broker umstellen |
| Abruf | `GET http://<ip>/properties/report` | Gerät meldet von selbst |
| Schreiben | `POST http://<ip>/properties/write` | `iot/<Produktschlüssel>/<Gerätekennung>/properties/write` bzw. `.../function/invoke` |
| Zendure-App | läuft weiter | verliert die Verbindung zu diesem Gerät |

Ein Gerät kann entweder Cloud oder lokal, nicht beides.

## Reines PHP

Kein Python, keine virtuelle Umgebung, kein Umweg um PEP 668. Gebraucht werden
nur `mosquitto_sub` und `mosquitto_pub` — und die auch nur, wenn mindestens ein
Gerät über MQTT läuft. Die Broker-Zugangsdaten stehen dabei **nicht** auf der
Kommandozeile, sondern in der Vorgabedatei von mosquitto mit den Rechten 0600;
auf der Kommandozeile stünden sie in der Prozessliste.

## Aufbau

    bin/zendure_dienst.php    Abrufdienst: HTTP-Abruf, MQTT-Horcher,
                              Befehlswarteschlange, MQTT-Publish, Selbsttest
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    webfrontend/htmlauth/     Bedienoberfläche (fünf Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + gemeinsame Bibliothek

Drei Aufgaben, drei Dateien. Weder Oberfläche noch Endpunkt sprechen je selbst
mit einem Gerät — sie lesen den Zwischenspeicher und legen Befehle in einer
Warteschlange ab, die der Dienst im Sekundentakt abarbeitet.

## Vier Befehlssätze

Die Geräte nehmen Steuerbefehle in **unterschiedlicher Form** entgegen. Ein
falscher Satz führt nicht zu einer Fehlermeldung — es passiert schlicht nichts.
Jede Form ist im Quelltext der offiziellen Home-Assistant-Integration
nachgesehen:

| Satz | Form | Geräte |
|---|---|---|
| `zensdk` | `properties/write` mit `smartMode`, `acMode`, `outputLimit`, `inputLimit` | SolarFlow 800 / 2400 |
| `hyper2000` | `function/invoke` `deviceAutomation`, `autoModelValue` als Objekt, Laden über `autoModelProgram 1` mit Preisliste | Hyper 2000 |
| `ace_aio` | wie oben, aber Laden über `autoModelProgram 2` ohne Preisliste | Ace 1500, AIO 2400 |
| `hub` | `autoModelValue` als **blosse Zahl** statt als Objekt; kein Netzladen | Hub 1200 |

Nicht nachgesehen und deshalb **nicht** in der Modelltabelle: Hub 2000,
SolarFlow 1600 AC+, SolarFlow 4000 AC+. Für diese wird der Satz von Hand
gewählt und im Reiter *Test* durchprobiert.

## Schreibbremse — bitte nicht abschalten

Die offizielle Integration merkt zur Ace 1500 an, dass jeder Schreibvorgang im
Flash des Geräts landet und ein ungebremster Regelkreis dessen
Schreibfestigkeit binnen Monaten aufbrauchen würde. Dieses Plugin lässt deshalb
für **alle** Geräte höchstens einen Befehl je 30 s durch und rastert Sollwerte
auf 50 W. Die Rasterung wird in der Antwort gemeldet, nicht verschwiegen.

**Folge für Loxone:** Der Sendetakt muss länger sein als die Bremse. 60 s gegen
30 s Bremse ist ein sicheres Verhältnis.

## Kein Watchdog

Zendure stoppt nicht von selbst, wenn Loxone schweigt — ein gesetzter Sollwert
bleibt stehen. Wer das nicht will, lässt Loxone vor dem geplanten Herunterfahren
einmal `aktion=aus` senden.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&geraet=N` | `ZENDURE;OK=..;SOC=..;SOCMIN=..;SOCMAX=..;PV=..;HAUS=..;NETZ=..;LADEN=..;ENTLADEN=..;BATP=..;GRENZEAUS=..;GRENZEEIN=..;ACMODUS=..;PACKS=..;DVOLT=..;TEMP=..;ALTER=..` |
| `?token=T&aktion=packs&geraet=N` | Werte je Akkupack |
| `?token=T&aktion=liste` | alle eingerichteten Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=entladen&watt=W` | Abgabe ins Hausnetz vorgeben |
| `?token=T&aktion=laden&watt=W` | aus dem Netz laden |
| `?token=T&aktion=aus` | Regie an das Gerät zurückgeben |
| `?token=T&aktion=socmin&prozent=P` | untere Ladezustandsgrenze |
| `?token=T&aktion=socmax&prozent=P` | obere Ladezustandsgrenze |
| `?token=T&aktion=abruf` | sofort abrufen |

**Ein Strich als Wert** heißt: das Gerät hat dieses Feld nicht geliefert. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone
behält dann den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer
mit ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` erledigt, `0` abgelehnt (mit
Grund), `2` eingereiht, aber ohne Antwort in der Wartezeit.

## Nicht belegte Einheiten

Temperatur und Zellspannung reicht die Home-Assistant-Integration unverändert
durch — in welcher Einheit sie kommen, steht nirgends. Das Plugin zeigt sie
deshalb ab Werk **roh** an und bietet im Reiter *Einstellungen* zwei
Umrechnungen zur Auswahl, die man nach einem Blick auf den Rohwert einschaltet.

## Datenschutz

Keine persönlichen Daten im Plugin, keine Verbindung nach außen. Alles bleibt
im Heimnetz.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Alle Protokollangaben stammen aus
[Zendure/Zendure-HA](https://github.com/Zendure/Zendure-HA) (ebenfalls MIT).

Zendure und SolarFlow sind Marken der Zendure Technology Co., Ltd. Dieses
Plugin steht in keiner Verbindung zu diesem Unternehmen und wird von ihm
weder herausgegeben noch unterstützt.
