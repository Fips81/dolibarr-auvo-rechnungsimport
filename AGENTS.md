# AuVoRechnungsimport – Entwicklungsleitfaden

Diese Datei beschreibt die fachlichen Regeln und die Leitlinien für Änderungen am Modul. Sie ergänzt das README; bei Abweichungen ist der tatsächlich implementierte und serverseitig geprüfte Ablauf maßgeblich. Geplante Funktionen sind ausdrücklich als TODO gekennzeichnet.

## Zweck und UX-Grundsatz

Das Modul bündelt Erkennung, Prüfung und Anlage einer Lieferantenrechnung möglichst auf einer Review-Seite. Nutzer sollen die häufigen Arbeitsschritte ohne Wechsel zwischen Rechnung, Geschäftspartner- und Bankkonto-Masken erledigen können. Dafür darf das Modul Dolibarr-Regeln und Eingabemasken gezielt in einer einfacheren Review-Oberfläche abbilden. Die Oberfläche darf die Dolibarr-Geschäftsregeln aber nicht umgehen: Eingaben müssen beim Absenden serverseitig validiert und Buchungen über die Core-Klassen ausgeführt werden.

Die UI ist auf einen vollständigen Arbeitsablauf ausgerichtet: Dokument ansehen, erkannte Werte prüfen/korrigieren, Lieferant und Positionen zuordnen, speichern oder Rechnung anlegen. Ein Entwurf und eine ausdrücklich freigegebene Aktion sind unterschiedliche Vorgänge. Eine erzeugte Rechnung ist nicht automatisch validiert oder bezahlt.

## Status und Lebenszyklus

Die numerischen Statuswerte in `class/importedinvoice.class.php` sind persistierte Datenbankwerte. Nicht umnummerieren oder für eine andere Bedeutung wiederverwenden, ohne Datenmigration und Prüfung sämtlicher Filter, Aktionen und Übersetzungen.

| Wert | Konstante / Bedeutung |
| ---: | --- |
| 0 | `STATUS_PROCESSING` – Verarbeitung läuft |
| 1 | `STATUS_NEEDS_REVIEW` – Prüfung erforderlich |
| 2 | `STATUS_IMPORTED` – Lieferantenrechnung angelegt |
| 3 | `STATUS_MATCHED` – bereit zur Übernahme |
| 4 | `STATUS_QUEUED` – wartet auf Hintergrundverarbeitung |
| 5 | `STATUS_SPAM_REVIEW` – Spam-Prüfung erforderlich |
| 6 | `STATUS_DUPLICATE` – bestätigtes Duplikat / irrelevanter Eingang |
| 7 | `STATUS_SPAM_CONFIRMED` – bestätigter Spam |
| 8 | `STATUS_REJECTED` – fachlich zurückgewiesen |
| 9 | `STATUS_ERROR` – Erkennungs-/Verarbeitungsfehler |

Vor Statusänderungen die Konstanten und erlaubten Übergänge im aktuellen Quellcode prüfen; die Tabelle ist eine Orientierung, kein Ersatz für den Code. Löschen, Spam-Bestätigung, Duplikat-Bestätigung und Zurückweisung sind getrennte Aktionen. Berechtigungen, CSRF-Schutz und Statusbedingungen gelten auch dann, wenn eine Aktion per URL oder manipuliertem POST aufgerufen wird.

## Fachliche Regeln

### Erkennung und Lieferant

- Strukturierte E-Rechnungsdaten (unter anderem CII/Factur-X und UBL/XRechnung) sowie PDF-Text/OCR sind Erkennungshinweise. Erkannte Werte bleiben überprüfbar und können fehlerhaft oder unvollständig sein.
- Lieferanten werden anhand eindeutiger Kennungen gesucht: Umsatzsteuer-ID, andernfalls deutsche Steuernummer (`idprof1`), danach nur bei hinreichend eindeutiger Namensübereinstimmung. Mehrdeutige Treffer dürfen nicht stillschweigend übernommen werden.
- Vor Zuordnung/Neuanlage muss der erkannte Lieferant gegen den eigenen Geschäftspartner und konfigurierte eigene Namen/Aliase geprüft werden. Ein möglicher Eigenbeleg darf nicht als Lieferantenrechnung angelegt werden.
- Lieferantenanlage und Bankkontoanlage richten sich nach der globalen Administratoreinstellung, der Auswahl in der Review-Maske und den Dolibarr-Berechtigungen. Die konkrete Auswahl ist serverseitig erneut zu prüfen.
- Für deutsche Lieferanten muss mindestens Umsatzsteuer-ID oder Steuernummer vorhanden sein. Eine USt-ID allein darf nicht generell als Pflichtfeld behandelt werden.
- Abweichende Stammdaten dürfen nicht unbemerkt überschrieben werden. Leere Felder können nach der Review-Auswahl ergänzt werden; Konflikte müssen eine ausdrückliche Entscheidung ermöglichen.

### Bankdaten

- IBAN, Kontoinhaber und Bankdaten aus dem Beleg sind Vorschläge und vor dem Schreiben zu prüfen.
- Eine BLZ-Abfrage beim externen Bankdaten-Webservice ist eine optionale Best-Effort-Anreicherung. Fehler oder fehlende Antwort dürfen den Rechnungsimport nicht unbrauchbar machen; keine vertraulichen Rechnungsdaten an den Dienst senden.
- Externe Ergebnisse sind nicht als verifizierte Kontoinhaberschaft oder korrekte Bankanschrift auszugeben.
- Vor Änderungen an Bankkontoanlage/-auswahl die Dolibarr-Bankkonto-API und deren erforderliche Felder prüfen.

### Rechnungsdaten, Positionen und Summen

- Lieferanten-Belegzeichen und Rechnungsdatum sind für die Anlage erforderlich.
- Positionsmodi haben unterschiedliche Bedeutung: übernehmen/buchen, ohne Produkt als Aufwand übernehmen und weglassen. „Weglassen“ muss die Position einschließlich Steuer aus den für den Abgleich verwendeten Summen herausnehmen und darf keine Rechnungszeile erzeugen.
- Beim Weglassen einer Position dürfen deren fehlende Eingabefelder keine Validierung blockieren. Für übernommene Positionen sind Beschreibung, Menge, Preis und ein expliziter Umsatzsteuersatz erforderlich; `0` ist ein gültiger, bewusst einzugebender Satz und nicht mit leer gleichzusetzen.
- Das Buchen mit Produktzuordnung und das Übernehmen als freie Aufwandsposition sind nicht dasselbe. Serverseitig muss geprüft werden, ob der gewählte Modus die erforderliche Produkt-/Positionszuordnung hat.
- Positionssummen werden serverseitig gegen erkannte Rechnungsbeträge geprüft. Steuer und ausgelassene Positionen sind in derselben Preisbasis zu behandeln. Bei einer relevanten Abweichung darf keine Rechnung angelegt werden.
- Bei erkannten Bruttopreisen müssen Eingabebeschriftung, Berechnung und Core-Aufruf dieselbe Preisbasis verwenden. Dolibarr kann Nettowerte aus Bruttopreisen berechnen; keine unpassenden Nettofelder als erkannte Originalwerte darstellen.
- Eine Rechnung ohne Position kann als Entwurf angelegt werden, sofern dies dem aktuellen Modulverhalten und der Core-Unterstützung entspricht. Die kombinierte Aktion zum Anlegen und Freigeben einer Lieferantenbestellung benötigt dagegen mindestens eine dafür buchbare Position.
- Das Merkmal „§ 19 UStG“ wird heuristisch aus Belegtexten erkannt. Es ist ein Prüfhinweis, keine rechtliche Feststellung. Die daraus abgeleitete Behandlung (zum Beispiel Bruttopreis/0 %) darf nicht als automatische rechtliche Klassifizierung kommuniziert werden. Steuerkennzeichen sind konfigurationsabhängig und dürfen nicht ohne ausdrückliche Anforderung fest codiert werden.
- Gutschriften/Korrekturen benötigen besondere Vorzeichen- und Referenzprüfung. Keine positive Rechnungslogik ungeprüft auf negative Beträge übertragen.

### Speichern, Anlegen und Freigeben

- „Speichern“ persistiert die Review-Änderungen, ohne eine Lieferantenrechnung anzulegen.
- „Lieferantenrechnung anlegen“ erstellt die Rechnung über Dolibarr-Core-Klassen. Anlage im Entwurf ist nicht gleich Validierung/Freigabe und nicht gleich Zahlung.
- Eine gesonderte Freigabeaktion muss klar benannt sein und darf keine Zahlung als erledigt verbuchen. Vor Änderungen prüfen, welche Core-Methode den gewünschten Status tatsächlich setzt.
- Optionale Folgeaktionen wie Einkaufspreise aktualisieren oder Lieferantenbestellung anlegen müssen ihre Admin-Vorgabe (vorausgewählt/erlaubt/verboten), Berechtigungen und Auswirkungen serverseitig beachten.

## Duplikate – aktueller Stand und TODO

Aktuell prüft `auvorechnungsimport_find_duplicate_imports()` fachliche Ähnlichkeiten anhand von Lieferanten-Belegzeichen und Betrag; abweichende bekannte Umsatzsteuer-IDs schließen einen Kandidaten aus. Das ist kein Binärhash-Abgleich.

**TODO – geplante zusätzliche Duplikatprüfung:**

1. Beim Eingang den Dateiinhalt hashen und gegen bereits im Modul gespeicherte PDF-Binaries prüfen. Ein identischer Hash soll auf den zuerst eingegangenen Datensatz verweisen; falls ein Import bereits mit einer Lieferantenrechnung verknüpft ist, soll die Review-Ansicht bevorzugt auf diese Rechnung zeigen.
2. Zusätzlich Core-ECM-Dokumente auf identische Datei prüfen, damit manuell abgelegte PDFs erkannt werden. Vor Umsetzung erst die Dolibarr-24-API, unterstützte Hashes, Dokumentpfade, Entity-Grenzen und Zugriffsrechte untersuchen. MD5 kann für Kompatibilität mit einem Core-Index nötig sein, darf aber nicht als kryptografischer Integritätsnachweis dargestellt werden.
3. Manuelle Uploads sollen bereits vor dem Einreihen eine verständliche Duplikatmeldung anzeigen und Duplikate nicht erneut speichern, sofern die Prüfung vollständig und sicher möglich ist.
4. Automatisch erkannte Duplikate sollen nachvollziehbar in den Modulstatus übergehen und in der Oberfläche auf den maßgeblichen ursprünglichen Import bzw. die gebuchte Lieferantenrechnung verweisen.

Diese Punkte sind noch nicht implementiert, solange der Code sie nicht ausdrücklich enthält. Hashgleichheit ist ein starker Hinweis auf identische Bytes, aber fachliche Nummer-/Betragsprüfung bleibt ergänzend sinnvoll. ECM-Zugriff muss Dolibarr-Rechte und Mandant/Entity-Grenzen respektieren.

## Löschen, Protokollierung und Datenschutz – offene TODOs

### Aktueller, im Code sichtbarer Stand

- Die Import-Löschaktion entfernt den Importdatensatz und die zugehörige Importdatei. Eine bereits angelegte Lieferantenrechnung bleibt laut Bestätigungsdialog bestehen. Das ist keine Löschung oder Bereinigung der Lieferantenrechnung und ihrer Core-Dokumente.
- Administratoren können einzelne Importstatus für das Löschen sperren. Die Sperre wird in der Karte und zusätzlich in `ImportedInvoice::delete()` durchgesetzt. Sie ist eine Status-/Bedienregel, aber kein Aufbewahrungs- oder Löschkonzept.
- Im aktuellen Modul ist kein eigener unveränderlicher Lösch-Audit-Eintrag und keine automatische Aufbewahrungsfrist/Löschroutine erkennbar. Dolibarr-Systemlogs sind nicht mit einem revisionssicheren Archiv gleichzusetzen.
- Importdatei, erkannte Daten und Importdatensatz können personenbezogene oder vertrauliche Informationen enthalten. Das Entfernen der Arbeitskopie allein löscht keine unabhängig gespeicherten Kopien, etwa in der erzeugten Lieferantenrechnung, im ECM, in Backups oder in Mail-Systemen.

### TODO: kontrolliertes, nachvollziehbares Löschkonzept

Vor einer Implementierung müssen mindestens folgende Fälle getrennt und fachlich freigegeben werden:

1. **Arbeitskopien und Fehl-/Spam-Eingänge:** festlegen, wann nicht benötigte Uploads, bestätigte Spam-Eingänge, Duplikate und Erkennungsfehler gelöscht werden dürfen und wann sie gelöscht werden sollen. Ein bestätigter Spam-Eingang darf nicht unbegrenzt anwachsen, aber eine pauschale Frist ohne abgestimmten Zweck und Rechtsgrundlage ist ebenfalls nicht ausreichend.
2. **Gebuchte Belege:** Importdatensatz und Originaldatei können mit einer gebuchten Lieferantenrechnung verknüpft sein. Vor dem Löschen muss klar sein, ob das Original in der Lieferantenrechnung/ECM tatsächlich vorhanden und lesbar ist und welche Kopie führend ist. Die Importbereinigung darf den Buchungsbeleg nicht versehentlich entfernen.
3. **Fristen und Fristbeginn:** ein konfigurierbares Fristmodell mit Dokumentkategorie, Fristbeginn, Ablaufzeitpunkt, möglicher Sperre (z. B. laufende Prüfung/Rechtsstreit) und Zuständigkeit entwerfen. Nicht eine einzige pauschale Zehnjahresfrist auf sämtliche Imports anwenden. Eine mögliche Bindung an Abschluss-/Steuerberatungsprozesse ist als fachliche Option zu prüfen, nicht als geltende Rechtsregel festzuschreiben.
4. **Audit bei Löschung:** minimalen Nachweis definieren (z. B. interne Objekt-ID, Zeitpunkt, Akteur oder Prozess, Löschgrund/-kategorie und Ergebnis), ohne Dateiinhalte, Namen, IBAN, E-Mail oder unnötige Rechnungsmetadaten in ein dauerhaftes Log zu kopieren. Ein Audit-Eintrag muss nicht die zu löschenden personenbezogenen Daten duplizieren.
5. **Integrität und Datenschutz gemeinsam lösen:** falls ein unveränderliches Ereignisprotokoll gewünscht wird, zuerst Datenminimierung, Zugriff, Aufbewahrung des Protokolls und Kettenintegrität entwerfen. Hash/Base64 allein verhindert keine Löschung oder Offenlegung; ein Hash kann bei kleinen/erratbaren Inhalten ebenfalls Rückschlüsse ermöglichen. Keine BlockedLog-Abhängigkeit oder personenbezogene Daten in eine Chain einführen, solange das nicht ausdrücklich beschlossen und geprüft ist.
6. **Tatsächliche Löschung:** berücksichtigen, wo Daten liegen (Importverzeichnis, Datenbank, Core-Rechnungsdokument, ECM, temporäre Dateien und Backups) und was „gelöscht“ je Speicher bedeutet. Backup-Rotation, Wiederherstellung und erneute Löschung nach Restore gehören zum Konzept; Dateienystem-`unlink` allein ist keine Zusage physischer Überschreibung.
7. **Berechtigungen und Bedienung:** Löschrechte, gesperrte Status, Bestätigungsdialog, CSRF-Schutz, Verknüpfungen und Fehlerfälle konsistent halten. Für Stapel-/Cron-Löschung zusätzlich Vorschau/Protokollierung, Wiederanlauf und Schutz vor konkurrierender Verarbeitung vorsehen.

Dieses TODO ist eine Architektur- und Fachklärungsaufgabe, kein Auftrag, sofort automatische Löschung oder ein dauerhaftes Personenprotokoll einzubauen. Fristen, Ausnahmen und Nachweispflichten müssen mit Steuerberatung und Datenschutzverantwortlichen für den konkreten Betrieb festgelegt werden.

## Sicherheit und technische Leitlinien

- XML-Parsing muss externe Netzwerkzugriffe und externe Entitätsauflösung verhindern. Vor Änderungen die verwendeten `libxml`-Optionen und deren PHP-Version-Kompatibilität prüfen; keine unsichere DTD-Auflösung aktivieren.
- Uploads müssen Größen-, Typ- und Dateipfadregeln beachten. Ursprüngliche Dateinamen sind nicht vertrauenswürdig und dürfen nicht ungeprüft in Pfaden oder HTML verwendet werden.
- SQL muss Tabellenpräfixe, Entity-Beschränkungen und Dolibarr-DB-Abstraktion berücksichtigen. Direkte SQL-Zugriffe nur dort, wo keine geeignete Core-API existiert; DebugBar-/TraceableDB-Wrapper nicht durch unsichere Annahmen umgehen.
- Mutationen brauchen Dolibarr-Berechtigungsprüfung, Token/CSRF-Schutz und serverseitige Validierung. Clientseitige Sperren sind nur UX, nie Sicherheitsgrenze.
- Keine BlockedLog-Abhängigkeit hinzufügen, solange das nicht ausdrücklich neu entschieden wurde. Keine Aufbewahrungs-, Unveränderlichkeits- oder WORM-Garantie behaupten, die der Code nicht erfüllt.
- Externe Dienste nur mit minimal erforderlichen Daten aufrufen. Rechnungsinhalt und personenbezogene Daten nicht an OCR-/KI-Dienste übertragen, sofern dies nicht ausdrücklich als Funktion beschlossen und datenschutzrechtlich bewertet wurde.
- UI-Texte und HTML müssen korrekt escaped und übersetzt sein. Erkennungsergebnisse können Text mit Sonderzeichen enthalten; doppelte HTML-Escapes vermeiden.

## Rechtliche Einordnung – Orientierung, keine Rechtsberatung

Das Modul unterstützt einen Arbeitsablauf; es zertifiziert weder GoBD-Konformität noch die steuerliche Richtigkeit eines Belegs. Die konkrete Aufbewahrungspflicht hängt von Dokumentart, Geschäftsvorfall, Fristbeginn, Abschluss-/Buchungsbezug und geltender Rechtslage ab. Insbesondere darf nicht pauschal behauptet werden, jede Rechnung müsse immer zehn Jahre aufbewahrt werden.

- UStG § 14b regelt die Aufbewahrung von Rechnungen und nennt aktuell grundsätzlich acht Jahre; Sonderfälle und Fristbeginn sind im Gesetz zu prüfen.
- AO § 147 unterscheidet verschiedene Unterlagen und Fristen (unter anderem zehn, acht und sechs Jahre); die jeweilige Kategorie und der Fristbeginn sind entscheidend.
- AO § 146 und die GoBD betreffen Ordnung, Nachvollziehbarkeit und Unveränderbarkeit der Buchführung. Ein SHA-Hash oder eine Base64-Kopie allein beweist weder ordnungsgemäße Archivierung noch Unveränderbarkeit.
- DSGVO-Grundsätze wie Zweckbindung, Datenminimierung, Speicherbegrenzung und angemessene Sicherheit gelten auch für Rechnungsbelege und Protokolle. Aufbewahrungspflichten können einer sofortigen Löschung entgegenstehen; Löschkonzept und Ausnahmen sind mit Steuerberatung/Datenschutzverantwortlichen festzulegen.
- Automatische Löschung abgelaufener Daten/Schlüssel und eine DSGVO-Retention-Implementierung sind derzeit kein allgemeines Versprechen dieses Moduls. Keine automatische Löschlogik hinzufügen, ohne Fristmodell, Kettenintegrität, Wiederherstellung und Berechtigungen vollständig zu entwerfen.

Primärquellen (jeweils aktuelle Fassung vor einer rechtlichen Änderung prüfen):

- [AO § 146](https://www.gesetze-im-internet.de/ao_1977/__146.html)
- [AO § 147](https://www.gesetze-im-internet.de/ao_1977/__147.html)
- [UStG § 14](https://www.gesetze-im-internet.de/ustg_1980/__14.html)
- [UStG § 14b](https://www.gesetze-im-internet.de/ustg_1980/__14b.html)
- [UStG § 19](https://www.gesetze-im-internet.de/ustg_1980/__19.html)
- [UStDV](https://www.gesetze-im-internet.de/ustdv_1980/)
- [GoBD – BMF, AO-Anhang 33](https://ao.bundesfinanzministerium.de/ao/2026/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-33/inhalt.html)
- [DSGVO – EUR-Lex](https://eur-lex.europa.eu/eli/reg/2016/679/oj?locale=de)

## Wichtige Dateien

- `auvorechnungsimport_card.php` – Review-Aktionen, Formular und Rechnungsanlage
- `auvorechnungsimport_list.php` – Filter, Statuslisten und Aktionen der Übersicht
- `class/importedinvoice.class.php` – Importobjekt, Statuswerte und Persistenz
- `core/lib/auvorechnungsimport.lib.php` – gemeinsame Helfer, Erkennung und Zuordnungen
- `core/lib/auvorechnungsimport_einvoice.php` – strukturierte E-Rechnungsdaten
- `core/lib/auvorechnungsimport_view.php` – Review-Ausgabe
- `admin/setup.php` – Moduloptionen
- `js/` und `css/` – ausgelagerte Review-/Admin-Interaktion und Gestaltung
- `tests/` – Parser-Fixtures und Regressionstests

Vor Änderungen zuerst die aktuelle Implementierung und Dolibarr-Core-Schnittstellen der installierten Zielversion prüfen. Bei Erkennungsänderungen vorhandene Fixtures erweitern, damit ein Spezialfall nicht unbemerkt andere Rechnungen verschlechtert. Zugangsdaten, echte Rechnungen und lokale Umgebungsdetails gehören nicht in dieses Repository.
