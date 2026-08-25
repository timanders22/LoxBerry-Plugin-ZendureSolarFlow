# LoxBerry-Plugin: Zendure SolarFlow

Bindet **Zendure SolarFlow** an Loxone an — **ohne Cloud, ohne Zendure-Konto**.
Unterstützt beide lokalen Wege: die HTTP-Schnittstelle der neueren Geräte und
lokales MQTT für die ältere Reihe.

> **Version 0.9.14 — ohne Zendure-Gerät gebaut.** Aufbau, Sprachdateien,
> Endpunkt und Oberfläche sind geprüft; ob die Eigenschaftsnamen der eigenen
> Firmware passen und ob die Schreibbefehle am Gerät wirken, ist es **nicht**.
> Deshalb 0.9.14 und nicht 1.0.0.
>
> Seit 0.9.10 können Sie das **selbst nachmessen**, ohne eine Zeile Quelltext
> anzufassen. 0.9.11 brachte Energiezähler und Schutzfunktionen, 0.9.12 nahm
> dem Einrichten das Raten, 0.9.13 räumte darunter auf. Mit **0.9.14** spricht
> auch der Dienst Englisch — bis dahin waren seine Meldungen fest deutsch und
> landeten so auch in der englischen Oberfläche.
>
> Die Selbstaktualisierung zeigt auf dieses Repository und ist eingeschaltet.
> Bei gleicher Fassung wird niemandem ein Update angeboten; sobald 1.0.0
> erscheint, greift sie von selbst.

## Version 0.9.14 — der Dienst spricht jetzt beide Sprachen

Die Oberfläche war zweisprachig, der Dienst nicht. Seine Meldungen standen
fest deutsch im Quelltext und landeten von dort in der **englischen**
Oberfläche und in `MELDUNG=` am Endpunkt. **79 Textstellen** sind jetzt in den
Sprachdateien.

### Zuerst musste der Dienst die Sprache überhaupt finden

Ohne diesen Schritt wäre der Rest wirkungslos gewesen. `zd_sprache()` kannte
zwei Quellen: die Klasse `LBSystem` und die Umgebungsvariable `LBLANG`. Im
Dienstprozess gibt es beides nicht — er läuft ohne Weboberfläche, und `LBLANG`
setzt niemand. Er wäre also **immer** auf Deutsch gefallen, auch auf einem
englischen LoxBerry.

Dritte Quelle ist jetzt `Base.Lang` aus `config/system/general.json` — der
Wert, den der LoxBerry selbst führt. `LBLANG` behält den Vorrang: wer sie
setzt, meint es so.

### Was übersetzt wurde — und was nicht

| | |
|---|---|
| **Meldungen an den Bediener** | übersetzt. Sie erscheinen im Fehlerkasten der Oberfläche und in `MELDUNG=` am Endpunkt. |
| **Der Selbsttest** `--selbsttest` | übersetzt. Er antwortet einem Menschen im Terminal. Die Marken `[OK]`, `[FEHL]` und `[INFO]` bleiben unverändert — sie sind Struktur, keine Sprache, und wer die Ausgabe mit `grep` durchsucht, sucht danach. |
| **Das Protokoll** (`zendure.log`) | **bleibt einsprachig.** |

Die letzte Zeile ist eine Entscheidung, kein Vergessen. Ein Protokoll ist ein
technisches Nachschlagewerk: wer eine Zeile im Forum zitiert oder in einer
Datei von letzter Woche sucht, will sie wiederfinden. Und eine Datei, deren
Sprache sich mit einer Oberflächen-Einstellung ändert, ist über die Zeit
unbrauchbar — nach einem Sprachwechsel stünden zwei Sprachen untereinander in
derselben Datei. Es gibt eine Prüfzeile, die genau das festhält: sie wird rot,
wenn das Protokoll sprachabhängig wird.

### Ein unbekannter Schalter startet nicht mehr den Dienst

Beim Bau dieser Fassung ist mir das selbst passiert: eine Prüfung rief
`--selftest` statt `--selbsttest`. Das Argument fiel stillschweigend durch,
und statt einer Auskunft startete eine **Dienstschleife**. Wer ein Werkzeug
von Hand aufruft, soll bei einem Tippfehler eine Antwort sehen, keinen
Prozess. Unbekannte `--`-Schalter werden jetzt mit Rückgabewert 2 abgewiesen.

## Version 0.9.13 — eine Quelle statt neun Kopien

Vier Ergänzungen. Drei davon räumen auf, eine behebt einen Fehler — und der
war nur zu sehen, wenn man zwei Stellen nebeneinanderlegte. Zu jeder gibt es
eine Prüfzeile, die an 0.9.12 **rot** und an 0.9.13 **grün** ist.

### Die Befehlserkennung in der Oberfläche war falsch

Seit 0.9.11 setzt die Loxone-Vorlage das führende Semikolon:

```
\i;LADEN=\i\v
```

Ohne dieses Semikolon findet `\iLADEN=\i\v` in der Antwort

```
ZENDURE;OK=1;ENTLADEN=250;LADEN=0
```

die Stelle in **`ENTLADEN=`**, nicht in `LADEN=` — Loxone liest dann 250 statt
0. In der Vorlage war das behoben. In der Tabelle des Reiters *Einbindung in
Loxone* stand aber weiter das alte Muster, und in den Sprachdateien lagen
**acht** fertig ausgeschriebene Befehlserkennungen ohne Semikolon. Wer die
Tabelle abtippte, statt die Vorlage zu importieren, bekam genau den Fehler
zurück, der behoben worden war.

Alle Muster kommen jetzt aus `zd_check()` — derselben Funktion, aus der auch
die Vorlage baut. Was in der Oberfläche steht, ist damit das, was Loxone
bekommt.

### Und die Endpunktadresse stand neunmal da

Dieselbe Zeichenkette wurde an neun Stellen von Hand zusammengesetzt: zweimal
in den Vorlagen, siebenmal in der Oberfläche. Der Wirtsname dazu fünfmal.
Solange alle neun gleich lauten, fällt das nicht auf — sie müssten es aber
auch nach der nächsten Änderung noch, und dafür gibt es keinen Grund außer
Sorgfalt.

Beides kommt jetzt aus `zd_endpunkt_pfad()` und `zd_host()`. Der Filter im
Wirtsnamen ist dabei kein Schmuck: `HTTP_HOST` kommt aus der Anfrage, also
vom Aufrufer, und landet in einer XML-Datei, die jemand nach Loxone
importiert.

### Was in der Konfigurationsdatei fehlt, wird gesagt

`zd_config()` ergänzt Fehlendes bei jedem Lesen — im **Arbeitsspeicher**. Auf
der Platte bleibt die Datei unvollständig, und das fällt erst auf, wenn
jemand sie sichert, vergleicht oder von Hand liest.

Aufgefallen ist es an einem konkreten Fall: `temp_umrechnung` stand gar nicht
in den Vorgaben, wurde aber vom Formular geschrieben und an **fünf** Stellen
mit je eigenem Rückfall auf `'roh'` gelesen. Fünf Rückfälle sind fünf
Gelegenheiten auseinanderzulaufen.

Der Reiter *Test* sagt jetzt, was fehlt — und was **fremd** ist: Schlüssel in
der Datei, die es in den Vorgaben nicht gibt. Die sind wirkungslos, und genau
das überrascht: man hat etwas eingestellt, es steht in der Datei, und es tut
nichts. Ein Knopf ergänzt das Fehlende. Fremdes bleibt stehen — es zu löschen
wäre anmaßend, niemand weiß, ob dort der Rest einer älteren Fassung steht
oder etwas, das der nächsten gehört.

### Trägt die Reiterleiste ohne JavaScript?

Der Fall ist einmal eingetreten: bis 0.9.0 setzte erst das Skript die Klasse
`sm-active`, und weil `.sm-seite` auf `display:none` steht, war die Seite ohne
JavaScript vollständig leer. Der Reiter *Test* beantwortet die Frage jetzt
selbst — fünf Reiter, fünf Bereiche, und jeder bekommt die Auswahl vom Server.

Das ist genau die Prüfung, die das Hausstandard-Werkzeug für dieses Plugin
**nicht** leisten kann: es meldet in der Spalte `tab` einen Strich, weil die
Klasse hier zusammengesetzt entsteht. Ein Strich sammelt sich beim
Überfliegen wie ein Haken ein.

### Der Satz über das MQTT-Gateway stimmte nur zur Hälfte

Zweimal stand in der Oberfläche unbedingt: *„Ohne diesen Eintrag kommt am
Miniserver nichts an."* Das gilt für das MQTT-Gateway **V1**, wo jedes Thema
von Hand auf der Abo-Seite eingetragen werden muss. Unter **V2** schaltet der
LoxBerry-Kern genau diese Seite ab — der Satz schickte dann jeden
V2-Anwender zu einem Feld, das es nicht mehr gibt.

Das Plugin liest die Gateway-Fassung jetzt aus **`Mqtt.Gatewayversion` in
`config/system/general.json`** — dem Wert, den der LoxBerry selbst führt (ab
Werk 1) — und nennt sie. Drei Ausgänge, nicht zwei: fehlt der Schlüssel, wird
nichts behauptet, dann stehen beide Fälle da.

Der erste Anlauf leitete die Nummer aus der **Version des Plugins**
`mqttgateway` ab. Das war eine Ableitung, wo eine Messung danebenliegt: sie
wäre schon dann falsch, wenn jemand eine V2-fähige Fassung installiert und in
`general.json` weiter V1 führt. Das Plugin **MGiSmart** liest seit 1.1.0 an
der richtigen Stelle; die Prüfzeile stellt jetzt ausdrücklich sicher, dass die
Plugin-Datenbank hier nichts mehr entscheidet.

## Version 0.9.12 — Einrichten ohne Raten

Sieben Ergänzungen, und keine davon erfindet eine neue Regelung. Sie
beantworten Fragen, die bisher jeder selbst beantworten musste: *Ist das
überhaupt richtig eingerichtet? Welche IP hat mein Gerät? Was trage ich in
Loxone ein? Überlebt meine Einrichtung einen Fehlgriff?* Zu jeder gibt es
eine Prüfzeile, die an 0.9.11 **rot** und an 0.9.12 **grün** ist.

### Gerätesuche — statt die IP im Router zu suchen

Der Reiter *Geräte* hat einen Knopf **Suchen**. Er klopft das eigene Netz ab
— parallel, mit kurzer Wartezeit — und listet, was auf `/properties/report`
wie ein Zendure-Gerät antwortet. Das eigene Netz wird dabei aus den
Schnittstellen des LoxBerry gelesen, nicht geraten.

Zwei Dinge macht die Suche bewusst:

* **Sie unterscheidet.** Ein beliebiger Webserver auf Port 80 ist kein
  Zendure-Gerät. Geprüft wird die *Antwortform* — ein JSON-Objekt mit
  `properties` —, nicht nur, dass der Port offen ist. Wer das nicht tut,
  bietet dem Bediener seinen Drucker als Batteriespeicher an.
* **Sie sucht auch MQTT.** Steht ein Broker bereit, hört die Suche kurz auf
  `#` mit und meldet die Paare aus Produktschlüssel und Geräte-ID, die sich
  dort melden — genau die zwei Angaben, die man für die ältere Reihe braucht
  und sonst nirgends findet.

Gefundenes wird **angeboten, nicht eingetragen**. Der Bediener bestätigt.

### Selbsttest — der Endpunkt prüft sich selbst

Bis 0.9.11 bot der Reiter *Test* drei Links an, die jemand anklicken sollte.
Ein Link ist keine Messung. Er sagt nichts darüber, ob der Webserver den
unangemeldeten Bereich ausliefert, ob PHP dort durchläuft und ob das Token
stimmt — und das sind die drei Stellen, an denen eine Einrichtung hängt.

Neu ist `?selftest=1&token=T`. Er antwortet mit einer einzigen Zeile
(`SELFTEST;OK=1;TOKEN=OK`) und **löst nichts aus**: kein Abruf, kein
Schaltbefehl, keine Warteschlange. Der Reiter *Test* ruft ihn über
`127.0.0.1` selbst auf und zeigt das Ergebnis.

Drei Ausgänge, nicht zwei. **„Nicht feststellbar"** ist ein eigener Befund:
ein LoxBerry, der sich selbst über `127.0.0.1` nicht erreicht — anderer
Port, eine Anmeldeabfrage vor dem unangemeldeten Bereich — ist deshalb nicht
kaputt; man weiß es nur nicht. Ein Haken wäre dort gelogen, ein Kreuz auch.

### Fertige Loxone-Vorlagen — auch für die Steuerbefehle

0.9.11 lieferte die Statusvorlage. Jetzt liegen alle drei Arten bereit, je
Gerät, dazu die Summe:

| Vorlage | Art | Inhalt |
|---|---|---|
| Status | Virtueller HTTP-Eingang | alle Messwerte samt `ALTER` und `OK` |
| Energie | Virtueller HTTP-Eingang | die Zählerstände für den Energiefluss-Monitor |
| Summe | Virtueller HTTP-Eingang | die gewichtete Summe über alle Geräte |
| **Befehle** | **Virtueller Ausgang** | **acht Befehle: entladen, laden, aus, socmin, socmax, abruf …** |

Der virtuelle Ausgang war die Lücke: die Messwerte kamen fertig, die
Schaltbefehle musste man von Hand nachbauen — mit genau der Adresse, dem
Token und dem Befehlsnamen, bei denen ein Tippfehler stumm bleibt.

Ein Knopf **alle Vorlagen als ZIP** packt den ganzen Satz. Die Dateien tragen
CRLF und `minVersion`, wie Loxone es erwartet.

### Verlauf — drei Tage, zwei Kurven, und CSV

Der Verlauf zeigte den heutigen Tag. Jetzt sind die letzten Tage über
Tagesknopfe erreichbar, die Kurve trägt eine **zweite Linie** (Ladezustand
und Leistung übereinander, nicht nacheinander), und ein Knopf gibt den Tag
als **CSV** heraus — für eine Tabelle, in der man selbst rechnen will.

### Konfiguration sichern und zurückspielen

Wer die Feldzuordnung von Hand nachgezogen hat, hat Arbeit investiert, die
nirgends sonst steht. Der Reiter *Einstellungen* sichert die vollständige
Konfiguration in eine Datei und spielt sie zurück.

Zwei Vorsichten sind eingebaut: die Sicherung trägt das Aktionstoken mit
(sonst müsste man nach dem Zurückspielen in Loxone alles neu eintragen), und
das Einlesen **weist Fremdes ab** — eine Datei, die nicht von diesem Plugin
stammt, wird nicht „so gut es geht" übernommen.

### Healthcheck — ein Befund für drei Verbraucher

LoxBerry ruft `bin/healthcheck` auf und erwartet JSON. Die Oberfläche zeigt
denselben Zustand im Reiter *Test*. Und wenn etwas kippt, soll eine Meldung
kommen.

Drei Verbraucher, **eine** Quelle: `zd_befund()`. Die Meldung geht nur beim
**Wechsel** hinaus, nicht bei jedem Durchgang — eine Benachrichtigung, die
jede Minute dasselbe sagt, wird abgeschaltet und meldet danach auch das
Wichtige nicht mehr.

### Verfall in der Warteschlange — ein alter Befehl ist ein falscher

Ein Befehl liegt im Dateisystem, nicht in der Anfrage. Das ist die Stärke
dieser Bauart: der Dienst arbeitet ihn auch dann noch ab, wenn der Webserver
die Anfrage längst abgebrochen hat. Es ist zugleich ihre Gefahr — steht der
Dienst mehrere Stunden (abgestürzt, angehalten, LoxBerry neu gestartet), wird
beim nächsten Start ausgeführt, was inzwischen falsch ist. Ein
`laden 3000 W` von gestern Abend heute früh auszuführen ist keine verspätete
Regelung, sondern eine falsche.

Ein Stellbefehl verfällt deshalb nach **300 Sekunden** (einstellbar, 0 schaltet
ab). Zwei Dinge daran sind Absicht:

* **Nur Stellbefehle.** `aus`, die Ladezustands- und Leistungsgrenzen und der
  Abruf verfallen nie. Eine Grenze ist eine Einstellung und auch morgen noch
  richtig — und ein zurückgehaltenes `aus` wäre das genaue Gegenteil dessen,
  was der Verfall bezweckt.
* **Der Verwurf wird beantwortet**, nicht verschwiegen: `SET;OK=0` mit dem
  Grund und der tatsächlichen Liegezeit. Sonst könnte der Aufrufer nicht
  unterscheiden, ob sein Befehl abgelehnt wurde oder nie ankam.

Die Unterscheidung „Stellbefehl oder Einstellung" steht seit dieser Fassung an
**einer** Stelle (`zd_ist_stellbefehl()`) und wird von Rückfall,
Schutzschwellen und Verfall gemeinsam benutzt. Vorher gab es sie zweimal.

### Herzschlag — weil `ALTER` einen Zeitsprung nicht übersteht

`ALTER` beantwortet „lebt der Dienst noch?" — solange die Uhr stimmt. Ein
Raspberry Pi hat keine Echtzeituhr: nach dem Booten steht er in der
Vergangenheit, und sobald NTP greift, springt die Zeit. Springt sie nach
vorn, wird `ALTER` negativ und meldet „gerade eben gemessen", obwohl seit
dem letzten Durchgang nichts geschehen ist.

Deshalb trägt die Statuszeile jetzt zusätzlich **`ZAEHLER`**: er zählt
Durchgänge, nicht Sekunden, läuft bei 999 um und ist von der Uhr
unabhängig. `-1` heißt „noch nie gelaufen" — eine 0 wäre davon nicht zu
unterscheiden. In Loxone genügt ein Baustein, der auf *unverändert seit N
Minuten* schaut.

## Version 0.9.11 — Energie, Summe und drei Schutzfunktionen

Auch hier gilt: zu jeder Funktion gibt es eine Prüfzeile, die an 0.9.10
**rot** und an 0.9.11 **grün** ist.

### Energiezähler — weil das Gerät keine liefert

Ein Zendure-Gerät meldet Augenblicksleistungen, keine Zählerstände. Loxone
will für den Energiefluss-Monitor aber Kilowattstunden. Der Dienst
**integriert die Leistung deshalb selbst** — protokollunabhängig, also auch
dann, wenn eine Firmware gar keine Zähler hat, und ohne dass es an
Eigenschaftsnamen hängt, die ohnehin nicht belegt sind.

Daraus entstehen Tages-, Monats- und Jahreswerte, fortlaufende Zählerstände,
Vollzyklen und Wirkungsgrad — abrufbar über `aktion=energie`, sichtbar in der
Oberfläche und über MQTT.

**Das ist keine geeichte Messung, und das Plugin sagt es.** Integriert wird
über den Abfragetakt; was zwischen zwei Abrufen geschieht, sieht niemand. Der
Takt steht deshalb als `TAKT` in jeder Antwort. Drei Schranken sorgen dafür,
dass aus einer Störung keine Energie wird:

| Fall | Verhalten |
|---|---|
| Gerät antwortet nicht (`ok=0`) | es wird nichts angerechnet — durch eine Störung hindurchzuintegrieren erfände Energie |
| Feld nicht gemeldet (`null`) | kein Wert ist keine Null |
| Lücke nach einem Neustart | höchstens drei Takte werden angerechnet; ohne Deckel ergäben 300 W und zwei Tage 14 kWh, die es nie gab |

Ein Tag ohne Messung bekommt **keine** Nullzeile — sonst stünde in der Bilanz
ein Tag mit 0 kWh, an dem in Wahrheit niemand gemessen hat.

### Anschluss an den Energiefluss-Monitor

Loxones eigenes Beispiel `Energy-Flow-Monitor-and-Energy-Manager.loxone`
hängt einen Speicher an einen **Zähler-Baustein mit absolutem
Speicherstand** — `Type="MeterAbsSt"`, mit `MaxLvl="100"` und den Einheiten
`<v.3>kW` und `<v.1>kWh`.

Er nimmt zwei Eingänge: Leistung und Speicherstand in Prozent. Beides liefert
das Plugin (`BATP`, `SOC`) — nur kannte die Baustein-Liste den Zähler bis
0.9.10 nicht. Der Reiter *Einbindung in Loxone* hat jetzt einen eigenen
Abschnitt dafür, samt Hinweis auf die Einheit: das Plugin liefert **Watt**,
der Baustein zeigt Kilowatt.

### Summe über alle Geräte

`aktion=summe` — der Ladezustand **nach Kapazität gewichtet**. Ein
ungewichteter Mittelwert wäre bei einem kleinen Speicher neben einem großen
schlicht falsch: 80 % von 2 kWh und 20 % von 8 kWh sind nicht 50 %, sondern
32 %. Dafür trägt die Gerätetabelle eine neue Spalte *Kapazität*.

**Fail closed:** fehlt eine Kapazität oder antwortet ein Gerät nicht, kommt
für `SOC` und `RESTKWH` ein Strich statt einer Teilsumme. Eine Teilsumme, die
wie eine Gesamtsumme aussieht, ist die gefährlichere Auskunft — nach ihr
würde geregelt. `NOK` sagt, wie viele fehlen. Die Leistungen dagegen werden
weiter addiert; für eine Momentanleistung ist eine Teilsumme brauchbar, für
einen Ladezustand nicht.

### Wiederholungssperre — der wirtschaftlichste Punkt

Bis 0.9.10 wurde **jeder** Sollwert geschrieben, auch der unveränderte. Beim
empfohlenen Sendetakt von 60 s sind das 1440 Flash-Schreibvorgänge am Tag für
einen Wert, der sich nicht geändert hat — bei einem Plugin, dessen
Schreibbremse ausdrücklich mit der Schreibfestigkeit des Flash begründet ist.
Die Bremse begrenzt den *Abstand*, nicht die *Wiederholung*.

Ab Werk wird nur der **gleiche** Wert übergangen (Totband 0); ein größeres
Totband ist eine bewusste Entscheidung. Drei Dinge verhindern, dass daraus
eine Falle wird:

* Nach der Auffrischzeit (Vorgabe 600 s) geht auch der unveränderte Wert
  wieder hinaus — für den Fall, dass das Gerät ihn vergessen hat.
* Sagt die Quittung, dass das Gerät den Wert **nicht** übernommen hat, wird
  erneut gesendet.
* Ein übergangener Befehl wird als **Erfolg mit Begründung** gemeldet, nicht
  verschwiegen: der gewünschte Zustand steht ja am Gerät.

### Rückfall, wenn Loxone schweigt

Das README sagte bisher „Kein Watchdog" und schob die Aufgabe an Loxone: vor
dem geplanten Herunterfahren einmal `aktion=aus` senden. Das deckt einen
geplanten Neustart ab — keinen Ausfall des Miniservers, kein durchtrenntes
Netzwerkkabel, keinen gelöschten Baustein.

Der Dienst kann es selbst: kommt X Minuten kein neuer Lade- oder
Entladewert, gibt er die Regie zurück. **Ab Werk aus** — das greift in eine
laufende Anlage ein. Die Restzeit steht als `RESTZEIT` in der Statuszeile.

Er greift **genau einmal**. Ohne das schickte der Dienst im Takt weiter `aus`
hinterher und füllte den Flash mit genau der Sorte Wiederholung, die die
Sperre daneben verhindert. Und er nimmt nur Stellbefehle zurück — eine
Ladezustandsgrenze ist eine Einstellung und bleibt stehen.

### Schutzschwellen — und warum sie nicht blind sperren

Sollwerte abweisen, die dem Speicher nicht guttun: nicht weiter entladen
unter X %, nicht weiter laden über Y %, Temperatur außerhalb des Bereichs.
Ab Werk aus.

**Sie sperren nicht, wenn ihnen die Messung fehlt.** Ein Schutz, der bei
jedem Aussetzer den Speicher stilllegt, richtet mehr Schaden an als der Fall,
gegen den er schützt — und das Gerät hat sein eigenes Batteriemanagement;
diese Schwellen sind Komfort, nicht Sicherheit. Fehlt die Messung oder ist
sie älter als 15 Minuten, geht der Befehl durch **und das wird gemeldet**, in
der Antwort und im Protokoll. Ein Schutz, der stillschweigend durchlässt,
täuscht Sicherheit vor.

### MQTT sendet nur noch Änderungen

Bis 0.9.10 gingen bei Vorgabetakt 15 s rund 5760 Durchgänge am Tag mit je
etwa zwanzig Feldern **je Gerät** unverändert hinaus. Jetzt nur noch, was
sich geändert hat — und alle 300 s trotzdem alles, damit ein neu gestartetes
Gateway einen vollständigen Stand bekommt.

### Antwortzeit und Firmware

`MS` misst, wie lange das Gerät für die Antwort braucht. Ein Speicher, dessen
Antwortzeit von 40 auf 3000 ms steigt, hat ein Problem, lange bevor er ganz
ausfällt. Nur für HTTP-Geräte — ein MQTT-Gerät meldet von selbst, da gibt es
keine Anfrage, deren Dauer sich messen ließe.

`FW` ist ein Feld **ohne Vorgabe**, und das ist Absicht: unter welchem Namen
ein Zendure-Gerät seine Firmware meldet, ist nirgends belegt. Einen Namen zu
raten hieße, ein leeres Feld gegen ein falsches zu tauschen. Der
Feld-Erkunder zeigt, wie es bei Ihnen heißt; erst dann trägt `FW` einen Wert.

### Die Loxone-Vorlagen sind vollständig geworden

Gegen einen echten Export aus Loxone Config gemessen. Bis 0.9.10 fehlten:

* `HintText=""` am Wurzelelement, und zwar **vorn**
* `<Info templateType="2" minVersion="17010727"/>` als erstes Kindelement —
  gezählt über den Arbeitsordner: 32 von 51 Plugin-Ordnern setzen es, dieser
  war nicht darunter
* `Unit` und `HintText` an jedem Eintrag

Dazu trägt die Befehlserkennung jetzt das **führende Semikolon**. Ohne es
findet `LADEN=` auch die Stelle in `ENTLADEN=`; dass es bisher stimmte, lag
allein an der Reihenfolge der Zeile. Bestehende Eingänge müssen **nicht**
geändert werden.

Und es gibt drei Vorlagen statt einer: Messwerte, Energiezähler (mit eigenem
Zyklus von 300 s) und die Summe. Der Knopf war bis 0.9.10 fest auf Gerät 1
verdrahtet — bei sechs Geräten fehlten fünf Vorlagen.

## Version 0.9.10 — fünf Funktionen gegen die eigene Unsicherheit

Dieses Plugin sagt über sich selbst, was es nicht weiß: die
Eigenschaftsnamen der jeweiligen Firmware, die Wirkung der vier
Befehlssätze, die Einheit von Temperatur und Zellspannung. Bis 0.9.9 stand
das im Hilfetext und blieb dort. Diese Fassung gibt Ihnen die Messung in
die Hand.

Auch hier gilt: zu jeder Funktion gibt es eine Prüfzeile, die an 0.9.9
**rot** und an 0.9.10 **grün** ist.

### Feld-Erkunder und freie Feldzuordnung

Der Reiter *Test* führt jetzt **jede** Eigenschaft auf, die Ihr Gerät
geliefert hat — unter dem Namen, den *Ihre* Firmware benutzt —, mit Wert und
der Auskunft, ob das Plugin sie verwendet. Ein Punkt kennzeichnet die
Stellgrößen.

Und im Reiter *Einstellungen* lässt sich die Zuordnung umbiegen:

    Feld des Plugins   Vorgabe            eigener Name    gelesener Wert
    soc                electricLevel      meinSocFeld     71

Damit passt sich das Plugin an eine abweichende Firmware an, **ohne** dass
jemand Quelltext ändert. Leer lassen heißt „Vorgabe benutzen", nicht „keine
Zuordnung" — sonst verschwände ein Wert, sobald man ein Feld leert.

### Soll/Ist-Quittung: hat der Befehl gewirkt?

Die Statuszeile trägt drei neue Felder: `SOLL`, `SOLLALTER` und `SOLLOK`.
Der Dienst merkt sich nach jedem geglückten Schreibvorgang, **was** er
vorgegeben hat und woran sich das ablesen ließe, und vergleicht beim
nächsten Abruf.

Drei Antworten, nicht zwei:

| `SOLLOK` | Bedeutung |
|---|---|
| `1` | das Gerät meldet den vorgegebenen Wert zurück |
| `0` | es meldet etwas anderes, und die Nachlaufzeit ist um |
| `-` | noch keine Aussage möglich |

Der Strich ist der ehrliche Regelfall bei den drei `invoke`-Befehlssätzen:
dort ist **nirgends** belegt, in welcher Eigenschaft sich ein Befehl
niederschlägt. Wer es an seinem Gerät gesehen hat, trägt das Feld als
*Quittungsfeld* ein — dann urteilt die Quittung auch dort.

### Satz-Assistent

Das README sagte schon immer: „Ein falscher Satz führt nicht zu einer
Fehlermeldung — es passiert schlicht nichts." Und für Hub 2000,
SolarFlow 1600 AC+ und 4000 AC+ stand dort, man möge die Sätze „im Reiter
*Test* durchprobieren". Durchprobieren hieß bis 0.9.9: raten, senden, in der
Zendure-App nachsehen.

Der Assistent macht daraus eine Messung. Je Satz nimmt er alle
Eigenschaften auf, sendet **einen** Entladebefehl, wartet und vergleicht.
Was sich geändert hat, ist die Antwort — und zwar zweifach: welcher Satz
ankommt und welche Eigenschaft ihn quittiert.

Er ist ehrlich über seine Grenzen. `hyper2000` und `ace_aio` bauen für das
Entladen **buchstäblich dieselbe** Nachricht; sie unterscheiden sich nur
beim Laden. Der Assistent nennt deshalb beide und sagt, dass sie hier nicht
auseinanderzuhalten sind, statt einen davon zu benennen.

### Trockenlauf

`&dry=1` an jedem schaltenden Aufruf, dazu ein Haken im Reiter *Test*: der
Befehl wird vollständig fertiggerechnet — Grenzen, Rasterung, Befehlssatz,
Nutzlast — und **nicht** gesendet. Die Antwort nennt die fertige Nutzlast:

    TROCKENLAUF, nichts gesendet. Gesendet wuerde: HTTP
    http://192.168.1.50/properties/write {"properties":{"smartMode":1,
    "acMode":2,"outputLimit":250,"inputLimit":0},"sn":""}
    Auf 250 W gerastert (Schrittweite 50 W, schuetzt den Flash-Speicher).

Dafür muss die Steuerung nicht freigegeben sein — es wird ja nichts
geschrieben. Gemessen wurde beides: dass er rechnet, und dass die
Gegenstelle dabei **keinen** Schreibzugriff zählt.

### Temperatur-Einheit: Vorschlag statt Hilfetext

Der Reiter *Test* stellt alle drei Umrechnungen des aktuellen Rohwerts
nebeneinander, beurteilt jede an der Frage „kann das eine Akkutemperatur
sein?" und übernimmt sie auf einen Klick.

    Rohwert 2985
      roh              2985      unplausibel
      Zehntel-Kelvin    25,4 °C  plausibel      <- Vorschlag
      Zehntel          298,5 °C  unplausibel

Ein Vorschlag, keine Messung — und wo zwei Umrechnungen plausibel sind,
wird **keine** gewählt. Das ist kein Ausweichen: meldet Ihr Gerät die
Temperatur schon in Grad, kann 25 auch 2,5 Grad heißen. Das entscheidet ein
Thermometer, kein Plausibilitätstest.

### Was der Umbau darunter ändert

Befehle werden jetzt erst **gebaut** und dann gesendet. Das klingt nach
Innerei, trägt aber drei der fünf Funktionen zugleich: nur wer die fertige
Nutzlast in der Hand hat, kann sie zeigen ohne zu senden (Trockenlauf),
festhalten was er vorgegeben hat (Quittung) und zwei Sätze gegeneinander
halten (Assistent). `zd_befehl_senden()` ist die einzige Stelle, die ein
Gerät beschreibt — wer eine zweite baut, umgeht alle drei auf einen Schlag.

## Version 0.9.9 — neun Befunde aus einer zeilenweisen Durchsicht

Zu jedem Punkt gibt es eine Prüfzeile, die an 0.9.8 **rot** und an 0.9.9
**grün** ist. Eine Prüfung, die nur an der neuen Fassung grün wird, beweist
nichts über die alte — deshalb sind alle neun in beide Richtungen geeicht.

### Der MQTT-Lesepfad war zur Hälfte tot

Der Horcher abonniert je Gerät **beide** belegten Schreibweisen. Zerlegen
konnte die Auswertung nur die zweite:

```
/PK/DEV/properties/report      -> VERWORFEN
iot/PK/DEV/properties/report   -> 42
```

Ohne das führende `iot/` landete nur `properties` im Vergleich, und die
Meldung fiel lautlos heraus. Ein Gerät, das unter der ersten Form meldet,
blieb dauerhaft `OK=0` — ohne eine Zeile im Protokoll, während das
**Schreiben** weiter funktionierte, weil das immer über `iot/` läuft. Lesen
tot, Schreiben heil: die verwirrendste aller Fehlerlagen.

### Der HTTP-Status wurde nirgends geprüft

`ignore_errors => true` ist richtig — sonst käme bei einem Fehlerstatus gar
kein Körper an. Dann muss der Status aber selbst geprüft werden. Gemessen
gegen eine Gegenstelle:

| Fall | bisher | jetzt |
|---|---|---|
| Schreiben, Gerät antwortet HTTP 500 | `SET;OK=1` | `SET;OK=0` mit dem Wortlaut des Geräts |
| Lesen, Gerät antwortet HTTP 403 | Fehlerkörper wird als Messwert übernommen, `OK=1 ALTER=0` | als Störung erkannt, `OK` fällt, `ALTER` läuft |

Der zweite Fall wiegt schwerer: die Anlage sah gesund aus, obwohl nichts
gemessen wurde, und in `zustand.json` stand kein Fehler.

### Ein Tippfehler warf das ganze Formular weg

Ein einziges Leerzeichen in einer IP-Adresse verwarf Gerätename, Takt,
Schreibbremse, Schrittweite, Aufbewahrungsdauer, Wartezeit und
Temperaturumrechnung gleich mit. Jetzt wird die betroffene Zeile übergangen,
alles Übrige gespeichert und die Beanstandung daneben gemeldet.

Übergangen heißt: die **bisherige** Angabe derselben Zeile bleibt stehen. Sie
einfach wegzulassen wäre schlimmer als das alte Verhalten gewesen — dann
löschte ein Tippfehler das Gerät.

### Fremde Formulare wirkten

`htmlauth/` schützt gegen den unangemeldeten Aufruf — nicht dagegen, dass der
Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
fremden Seite steht. Gemessen: ein POST mit nichts als `token_neu=1` würfelte
das Aktionstoken neu.

```
vorher : pruftokenpruftoken1234
nachher: 5zxg6v3fb7uzx69patkspcc8
```

Danach beantwortet der Endpunkt jeden virtuellen Eingang des Miniservers mit
HTTP 403 — die Überwachung ist tot, ohne jede Rückmeldung. Jedes Formular
trägt jetzt ein aus dem Aktionstoken abgeleitetes Merkmal, geprüft an **einer**
Stelle vor allen Handlern. Eine Zeile im Reiter *Test* zählt nach, ob wirklich
jedes Formular es mitschickt.

### „Rohdaten als JSON ansehen" zeigte keine Rohdaten

Der Knopf lieferte das bereits **umgesetzte** Abbild mit den Feldnamen dieses
Plugins. Gemessen mit einer Geräteantwort, die zwei unbekannte Eigenschaften
mitbrachte: keine davon war zu sehen. Damit führte der einzige Weg, den Hilfe
und README für den Hauptzweifel dieses Plugins nennen, ins Leere.

Neu ist `aktion=rohgeraet`: die Antwort des Geräts, unverändert, mit den
Eigenschaftsnamen Ihrer Firmware. Der Knopf zeigt jetzt dorthin; das
umgesetzte Abbild steht als zweiter Knopf daneben.

### Ein gestorbener MQTT-Horcher kam nie wieder

Neu gestartet wurde er nur, wenn sich die **Geräteliste** änderte. Stirbt
`mosquitto_sub` — Broker-Neustart, Netzhänger, ein fremdes `pkill` —, lieferten
alle MQTT-Geräte bis zum nächsten Dienstneustart nichts mehr, und der Wächter
merkte nichts: der PHP-Prozess lief ja. Der Wiederanlauf ist gebremst (5 s,
verdoppelnd bis 5 min), damit ein Broker, der die Anmeldung ablehnt, nicht
fünfmal je Sekunde einen neuen Prozess bekommt.

### Der Wächter konnte den Dienst vervielfachen

`dienst.sh` wartete nach dem Start genau eine Sekunde und löschte bei
negativem Befund die PID-Datei — **ohne** den eben gestarteten Prozess zu
beenden. Gemessen an echten Prozessen mit einem Start, der drei Sekunden
braucht: nach einem Wächterlauf liefen zwei Dienste auf derselben
Warteschlange, der nächste hätte den dritten nachgelegt. Jetzt wird bis zu
zehn Sekunden gewartet, im Fehlerfall aufgeräumt, und ein `flock` schließt
zwei gleichzeitige Läufe aus.

Dazu blieb nach einem *wirklich* gescheiterten Start der Sollmerker liegen —
der Wächter lief dann im Minutentakt in denselben Fehlschlag. Auslösbar allein
dadurch, dass jemand „Dienst starten" drückt, bevor ein Gerät eingetragen ist.

### Verlauf und Sollmerker überlebten kein Update

`plugininstall.pl` räumt `data/plugins/<ordner>/` bei **jedem** Update
vollständig ab. Dort lagen der SOC-Verlauf — eingestellt sind acht Tage — und
der Sollmerker. Nach jedem Update war die Kurve leer, und der Wächter startete
den Dienst nicht wieder.

Beides liegt jetzt in **`data/plugins/<ordner>.bestand`**, also *neben* dem
Ordner. Der Punkt im Namen ist kein Zufall: er liegt im selben Verzeichnis,
wird von `rm -rf <ordner>/` aber nicht getroffen. Vorhandene Verlaufsdateien
zieht der erste Start einmalig hinüber, und `uninstall` räumt den Ordner
wieder weg.

### Ein Semikolon im Gerätenamen zerlegte die Antwort

Der Eingabefilter entfernte Steuerzeichen und Anführungszeichen — Semikolon
und Gleichheitszeichen nicht. Der Name ging roh in eine semikolongetrennte
Zeile:

```
Name: Keller;OK=1;SOC=99
  1;Keller;OK=1;SOC=99;http;zensdk;Packs=1;OK=1
          ^^^^^^^^^^^^ frei getippter Text, und OK= steht zweimal
```

Eine Befehlserkennung `\iOK=\i\v` greift die erste Fundstelle — und das ist
der Name. Solche Namen werden jetzt beim Speichern abgewiesen, und die Ausgabe
säubert zusätzlich: in einer bestehenden Konfiguration kann so ein Name schon
stehen. Dasselbe galt für die Pack-Seriennummer im MQTT-Themenpfad; sie kommt
vom Gerät und lässt sich nicht abweisen, also wird sie bereinigt.

### Zwei Kleinigkeiten am Rande

Die Kennung des MQTT-Horchers hieß bei **jeder** Installation
`loxberry-zendure-<pid>`. `dienst.sh stop` und `preupgrade.sh` sammeln
herrenlose Horcher mit einem `pkill` auf diese Zeichenkette ein — ein Update
von `zendure_01` erschlug damit den Horcher der Installation `zendure` gleich
mit. Die Kennung trägt jetzt den Ordnernamen.

Und `uninstall` entfernte nur **eine** der beiden Sicherungen mit dem
Aktionstoken und meldete danach trotzdem, Token und Zugangsdaten seien fort.
Die von `preupgrade.sh` angelegte Zweitschrift blieb liegen. Eine unzutreffende
Sicherheitszusage ist schlimmer als gar keine, weil sie weitergegeben wird.

### Was beim Bau dieser Fassung schiefging

Der Sperrmerker gegen zwei gleichzeitige Läufe war zuerst als
`flock <datei> <befehl>` gebaut. In dieser Form **erbt** der gestartete Dienst
den Dateizeiger und hält die Sperre, solange er läuft — jedes spätere `stop`
wartete danach 60 Sekunden vergeblich und tat dann gar nichts. Gefunden hat
das nicht die Durchsicht, sondern der Prüfstand: die Zeile „stop beendet den
Dienst nicht" wurde rot. Behoben mit einer eigenen Dateizeigernummer, die beim
Start des Dienstes ausdrücklich geschlossen wird.

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

Und ein Ordner, der **nicht** im Archiv liegt, sondern zur Laufzeit entsteht:

    data/plugins/<ordner>.bestand/    SOC-Verlauf, Sollmerker, Energiezähler

Er liegt bewusst NEBEN `data/plugins/<ordner>`, weil der Installer diesen bei
jedem Update vollständig abräumt. `uninstall` entfernt ihn wieder.

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

Seit 0.9.11 kommt die **Wiederholungssperre** dazu, und sie wirkt stärker als
die Bremse: ein unveränderter Sollwert wird gar nicht erst geschrieben. Bei
60 s Takt fallen damit statt 1440 Schreibvorgängen am Tag nur noch die
tatsächlichen Änderungen an.

## Watchdog: das Gerät hat keinen, das Plugin kann einen

Zendure stoppt nicht von selbst, wenn Loxone schweigt — ein gesetzter Sollwert
bleibt stehen. Bis 0.9.10 stand hier, man möge Loxone vor dem geplanten
Herunterfahren einmal `aktion=aus` senden lassen. Das deckt einen geplanten
Neustart ab, aber keinen Ausfall.

Seit 0.9.11 kann der Dienst die Regie nach einer einstellbaren Zeit selbst
zurückgeben (*Einstellungen → Rückfall*). **Ab Werk aus.**

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&geraet=N` | `ZENDURE;OK=..;SOC=..;SOCMIN=..;SOCMAX=..;PV=..;HAUS=..;NETZ=..;LADEN=..;ENTLADEN=..;BATP=..;GRENZEAUS=..;GRENZEEIN=..;ACMODUS=..;PACKS=..;DVOLT=..;TEMP=..;ZAEHLER=..;MS=..;FW=..;SOLL=..;SOLLALTER=..;SOLLOK=..;RESTZEIT=..;ALTER=..` |
| `?token=T&aktion=packs&geraet=N` | Werte je Akkupack |
| `?token=T&aktion=liste` | alle eingerichteten Geräte |
| `?token=T&aktion=summe` | `SUMME;OK=..;N=..;NOK=..;SOC=..;KAPAZ=..;RESTKWH=..;PV=..;HAUS=..;NETZ=..;BATP=..;ALTER=..` — Ladezustand nach Kapazität gewichtet, fail closed |
| `?token=T&aktion=energie&geraet=N` | `ENERGIE;OK=..;ZEITRAUM=..;PV=..;HAUS=..;NETZ=..;LADEN=..;ENTLADEN=..;GLADEN=..;GENTLADEN=..;WIRKUNG=..;ZYKLEN=..;TAKT=..;ALTER=..` — dazu `&zeitraum=tag`, `monat` oder `jahr` |
| `?token=T&aktion=roh` | das umgesetzte Abbild als JSON — mit den Feldnamen dieses Plugins |
| `?token=T&aktion=rohgeraet[&geraet=N]` | die Antwort des Geräts, unverändert — mit den Eigenschaftsnamen Ihrer Firmware |
| `?token=T&aktion=entladen&watt=W` | Abgabe ins Hausnetz vorgeben |
| `?token=T&aktion=laden&watt=W` | aus dem Netz laden |
| `?token=T&aktion=aus` | Regie an das Gerät zurückgeben |
| `?token=T&aktion=socmin&prozent=P` | untere Ladezustandsgrenze |
| `?token=T&aktion=socmax&prozent=P` | obere Ladezustandsgrenze |
| `?token=T&aktion=grenzeein&watt=W` | obere Schranke für die Ladeleistung |
| `?token=T&aktion=grenzeaus&watt=W` | obere Schranke für die Abgabeleistung |
| `?token=T&aktion=abruf` | sofort abrufen |
| `?selftest=1&token=T` | `SELFTEST;OK=1;TOKEN=OK` — prüft Erreichbarkeit und Token, **löst nichts aus** |

**Ein Strich als Wert** heißt: das Gerät hat dieses Feld nicht geliefert. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage. Loxone
behält dann den letzten gültigen Wert; deshalb gehören `ALTER` und `OK` immer
mit ausgewertet.

Schaltende Aufrufe antworten mit `SET;OK=…`: `1` erledigt, `0` abgelehnt (mit
Grund), `2` eingereiht, aber ohne Antwort in der Wartezeit.

Jeder schaltende Aufruf nimmt zusätzlich **`&dry=1`**: dann wird der Befehl
vollständig fertiggerechnet und **nicht** gesendet; die Antwort nennt die
fertige Nutzlast. Dafür muss die Steuerung nicht freigegeben sein.

**`SOLL`, `SOLLALTER` und `SOLLOK`** beantworten die Frage, die eine reine
Messwertzeile nicht beantwortet: hat das Gerät den zuletzt gesetzten Wert
übernommen? `SOLLOK=-` heißt „noch keine Aussage" — bei den drei
`invoke`-Befehlssätzen der Regelfall, solange kein Quittungsfeld eingetragen
ist.

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
