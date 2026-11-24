# AuVo Rechnungsimport

[![Neueste Version](https://img.shields.io/github/v/release/Fips81/dolibarr-auvo-rechnungsimport?label=version&sort=semver)](https://github.com/Fips81/dolibarr-auvo-rechnungsimport/releases/latest)

**Eingangsrechnungen für Dolibarr**: Rechnung als PDF oder E-Rechnung als XML
hochladen. Das Modul liest Lieferant, Rechnungsnummer, Datum, Beträge und
Positionen, schlägt Lieferant und Artikel vor und legt nach deiner Prüfung
daraus eine Lieferantenrechnung an.

- **E-Rechnungen exakt:** XRechnung (CII und UBL), Peppol BIS, ZUGFeRD /
  Factur-X – als XML-Datei oder in der PDF eingebettet.
- **Normale PDFs und Scans:** Textlayer der PDF, sonst Texterkennung (OCR)
  mit Tesseract – lokal auf dem Server.
- **Lernt mit:** Artikelzuordnung pro Lieferant, Produktvarianten, Kosten- und
  Weglass-Positionen werden gemerkt.
- **Viele Rechnungen auf einmal:** Mehrfach-Upload, Analyse im Hintergrund,
  danach eine Rechnung nach der anderen prüfen.

Gegenstück zu [AuVo E-Rechnung](https://github.com/Fips81/dolibarr-auvo-erechnung)
(Ausgangsrechnungen als ZUGFeRD und XRechnung).

> AuVo Rechnungsimport ist ein unabhängiges Modul für Dolibarr und kein
> offizielles Produkt der Dolibarr Foundation, der KoSIT oder des FeRD.
> ZUGFeRD, Factur-X, XRechnung, Peppol und Dolibarr sind Namen bzw. Marken
> ihrer jeweiligen Inhaber.

## Voraussetzungen

- **Dolibarr** ab 19.0 (bei Aktivierung geprüft), entwickelt und im Einsatz
  mit 24.0
- **PHP** 8.1+ mit der Erweiterung `dom`
- Dolibarr-Module **Lieferanten** und **Geschäftspartner** (werden bei
  Aktivierung mit eingeschaltet); für Produktvarianten das Modul **Varianten**
- Für PDFs ohne E-Rechnung und für Scans die Systempakete:

  ```
  apt install poppler-utils tesseract-ocr tesseract-ocr-deu tesseract-ocr-fra tesseract-ocr-eng
  ```
  On Windows, install Poppler and Tesseract using a package manager or their
  available Windows distributions. Make sure `pdftotext`, `pdftoppm`, and
  `tesseract` are available to the PHP/CLI process through `PATH`.

  Ohne diese Pakete funktionieren XRechnung und ZUGFeRD trotzdem.
  PDFs mit Textebene können ebenfalls oft gut geparst werden.

## Installation

1. `module_auvorechnungsimport-X.Y.Z.zip` aus den
   [Releases](https://github.com/Fips81/dolibarr-auvo-rechnungsimport/releases)
   herunterladen.
2. Entpacken und den Ordner `auvorechnungsimport/` nach `htdocs/custom/`
   kopieren (Besitzer: Webserver-Benutzer, z. B. `www-data`). Alternativ in
   Dolibarr unter *Einstellungen → Module → Externes Modul hochladen*.
3. Unter *Einstellungen → Module* **AuVo Rechnungsimport** aktivieren und den
   Benutzern die Rechte „Eingangsrechnungs-Importe einsehen“ bzw. „Rechnungen
   hochladen und daraus Lieferantenrechnungen anlegen“ geben.
4. Optional in den Moduleinstellungen: OCR-Sprachen und -Seitenzahl,
   Stichwörter für Kosten- und Weglass-Positionen.

Danach gibt es im Hauptmenü den Eintrag **Eingangsrechnungen**.

**Update:** Ordner `custom/auvorechnungsimport` durch den neuen ersetzen
(alten Ordner vorher **außerhalb** von `custom/` sichern, sonst lädt Dolibarr
beide). Neue Versionen können neue Einstellungen oder Tabellen mitbringen –
dann das Modul einmal deaktivieren und wieder aktivieren; die Daten bleiben
dabei erhalten.

### Umstieg von InvoiceImport (0.2.x)

Bis 0.2.x hieß das Modul InvoiceImport (Ordner `invoiceimport`, Modulnummer
500410). Beim ersten Aktivieren übernimmt AuVo Rechnungsimport (Modulnummer
194203) Einstellungen (`INVOICEIMPORT_*` → `AUVORECHNUNGSIMPORT_*`), Importe
und gelernte Zuordnungen (`llx_invoiceimport_*` → `llx_auvorechnungsimport_*`),
die hochgeladenen Dateien (`documents/invoiceimport` →
`documents/auvorechnungsimport`) und die Benutzerrechte. Es wird nur kopiert;
die alten Daten bleiben für einen Rückweg erhalten.

1. InvoiceImport deaktivieren und den Ordner `custom/invoiceimport`
   **außerhalb** von `custom/` sichern.
2. `auvorechnungsimport` nach `custom/` kopieren und aktivieren.

## Erkennung (in dieser Reihenfolge, erster Treffer gewinnt)

0. **XRechnung / E-Rechnung als XML** – XRechnung, Peppol BIS oder
   ZUGFeRD/Factur-X-XML in beiden Syntaxen (UN/CEFACT **CII** und OASIS
   **UBL 2.1**, Rechnung und Gutschrift). Exakt; siehe Abschnitt XRechnung.
1. **Factur-X / ZUGFeRD** – eingebettetes CII-XML im PDF (`atgp/factur-x`
   Reader). Exakt, kein Rätselraten, kein OCR nötig.
2. **Digitaler Textlayer** – `smalot/pdfparser`, für normale (nicht gescannte)
   PDF-Rechnungen. Felder per Regex-Heuristik (Label-Erkennung für
   USt-IdNr./Rechnungsnummer/Datum/Gesamtbetrag in DE/FR/EN).
3. **OCR** – `tesseract` über `pdftoppm`-gerenderte Seiten (erste 2 Seiten),
   nur wenn Schritt 2 weder USt-IdNr. noch Betrag liefert. Sprachen über
   `AUVORECHNUNGSIMPORT_OCR_LANGS` (Default `deu+fra+eng`).

Der Lieferant wird zuerst exakt über die USt-IdNr. (`llx_societe.tva_intra`)
gesucht, bei E-Rechnungen ohne USt-IdNr. über die Steuernummer (Prof-ID 1),
sonst per Namens-Fuzzy-Match (nur bei eindeutigem Treffer).

## XRechnung und andere E-Rechnungen (XML)

Das Modul nimmt E-Rechnungen direkt als XML-Datei an (Upload wie PDFs, auch mehrere auf
einmal):

- **Formate:** XRechnung (CII und UBL), Peppol BIS Billing 3.0, ZUGFeRD /
  Factur-X als XML; ZUGFeRD-/Factur-X-PDFs wie bisher. Gelesen wird nach dem
  semantischen Modell der EN 16931 (`core/lib/auvorechnungsimport_einvoice.php`),
  unabhängig von der Syntax.
- **Übernommen:** Lieferant (USt-IdNr., Steuernummer, Name), Rechnungsnummer,
  Datum, Beträge, alle Positionen mit Artikelnummer, GTIN, Einheit, Netto-
  Einzelpreis (Preis je Basismenge umgerechnet) und Steuersatz, Nachlässe und
  Zuschläge auf Belegebene als eigene Zeilen – danach wie gewohnt Prüfen,
  Artikel zuordnen, Lieferantenrechnung anlegen.
- **Lesbare Ansicht:** Eine XML-Rechnung hat kein Bild. Die Seite des Imports
  zeigt stattdessen den Inhalt als Dokument (`core/lib/auvorechnungsimport_view.php`):
  Kopfdaten, Verkäufer und Käufer, Positionen, Steueraufteilung, Summen,
  Zahlung (Skonto im KoSIT-Format als Text), Lieferung, Hinweise, Anlagen.
  Bei ZUGFeRD-PDFs steht dieselbe Ansicht aufklappbar unter der PDF.
- **Anlagen** der E-Rechnung (BG-24, z. B. die Rechnung als PDF oder
  Stundennachweise) lassen sich herunterladen und werden beim Anlegen der
  Lieferantenrechnung zusammen mit der XML-Datei an sie angehängt.
- **Gutschriften** werden erkannt und angezeigt, aber noch nicht automatisch
  als Lieferanten-Gutschrift angelegt (Dolibarr braucht dafür die
  Ursprungsrechnung).
- **Keine Prüfung** gegen die Geschäftsregeln: das Modul liest, was die
  Rechnung enthält. Für eine Formalprüfung den KoSIT-Validator nutzen.

## Positionen, Artikelzuordnung und Ablauf

- **Rechnungspositionen**
  - aus **Factur-X/ZUGFeRD** exakt (Profile ab BASIC; MINIMUM/BASIC WL
    enthalten keine Positionen). Kopfzuschläge und -nachlässe (Versand,
    Rabatt...) werden als eigene Zeilen übernommen.
  - aus **normalen PDFs** über `pdftotext -layout`, aus **Scans** über ein aus
    den tesseract-Wortkoordinaten (TSV) rekonstruiertes Spaltenlayout. Eine
    Zeile wird an ihrer Rechnung erkannt, nicht am Layout: Menge × Einzelpreis
    = Gesamt (auf den Cent, optional mit Rabatt-%), Preis und Gesamt mit
    Nachkommastellen. Fehlt die Menge (OCR verschluckt einzelne Ziffern) und
    Gesamt/Einzel ist ganzzahlig, gilt das als Menge. Eingerückte Folgezeilen
    (Farbe, Variante) werden an die Bezeichnung angehängt, Summen-/Steuer-/
    Zahlungszeilen per Stichwort übersprungen.
  - Abgleich mit dem Rechnungsbetrag: stimmt die Summe mit netto überein →
    Nettopreise, mit brutto → Bruttopreise (Zeilen werden dann als TTC
    angelegt). Nennt die Rechnung genau einen USt-Satz, gilt er für Zeilen ohne
    eigene USt-Spalte; sonst Dolibarr-Standardsatz des Lieferanten.
  - OCR liest bis `AUVORECHNUNGSIMPORT_OCR_MAXPAGES` Seiten (Default 5, Setup-Seite).
  - Abweichungen von ein paar Cent bei vielen Zeilen sind Dolibarrs USt-Rundung
    pro Zeile; `MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER` hilft nur bei
    Netto-Rechnungen und verschlechtert Brutto-Rechnungen, daher nicht gesetzt.
- **Artikelzuordnung** pro Position, erster eindeutiger Treffer gewinnt:
  1. Lieferanten-Artikelnummer in den Lieferantenpreisen (`product_fournisseur_price.ref_fourn`)
  2. Gelernte Zuordnung (`llx_auvorechnungsimport_linemap`) per normalisierter
     Bezeichnung (Kleinschreibung, ohne Datum/Satzzeichen). Eine
     Lieferanten-Artikelnummer, die beim Lernen auf
     verschiedene Artikel zeigt (eine Nummer für alle Größen/Farben), wird als
     mehrdeutig gesperrt.
  3. **Produktvarianten** (Modul „Varianten“): Elternprodukt über gelernte
     Zuordnung (Lieferanten-Art.-Nr. → Elternprodukt, entsteht automatisch,
     sobald einmal eine Variante von Hand gewählt wird) oder über die Nummer
     („4233K“/„4233“ → `J4233`, eindeutig, sonst nur unter den mit dem
     Lieferanten verknüpften). Die Variante wird über die Attributwerte in der
     Bezeichnung gewählt – Ref und/oder Text („825/grau“, „M“); es muss genau
     eine Kombination übrig bleiben. Ein Treffer auf ein Elternprodukt (z.B.
     Lieferantenpreis am Elternprodukt) wird nie direkt gebucht, sondern immer
     über die Variante aufgelöst; bleibt sie unklar, zeigt das Formular das
     Elternprodukt als Hinweis. Ausnahme: von Hand gelernte Zuordnungen.
  4. Gelernte Zuordnung per Lieferanten-Artikelnummer (nicht bei unklarer
     Variante – das wäre geraten)
  5. EAN/GTIN gegen den Produkt-Barcode
  6. Produkt-Ref = Lieferanten-Artikelnummer bzw. gleiche Bezeichnung (nur
     „Vorschlag“)
- **Variante aus Rechnungszeile anlegen**: Ist das Elternprodukt bekannt, die
  Variante aber noch nicht angelegt, bietet das Prüfformular eine Checkbox
  „Variante anlegen: J6100-XL-999 – Jako T-Shirt One (XL, pink)“ an:
  - alle Attributwerte stehen in der Bezeichnung, nur die Kombination fehlt
    (z.B. 5XL in grau), oder
  - ein Attribut hat einen neuen Wert in der Lieferanten-Schreibweise
    „Code/Name“ („999/pink“) – der Wert wird dann im Attribut angelegt. Der
    Code muss wie die vorhandenen Refs des Attributs aussehen (Ziffern), ein
    vorhandener Code mit anderem Namen gilt als Konflikt, nicht als neu.
  Angelegt wird über das Modul *powervariantes*, falls aktiv (dessen Ref-/
  Namensschema), sonst über Dolibarrs eigene Variantenerzeugung; Preis,
  Gewicht usw. werden danach wie bei Dolibarr aus Elternprodukt + Aufschlag
  synchronisiert. Geplant wird beim Absenden aus der (ggf. korrigierten)
  Bezeichnung neu. Neue Elternprodukte/Modelle werden bewusst nicht angelegt.
- **Behandlung pro Position**: *Übernehmen* (mit Artikel, sonst Freitext),
  *Ohne Artikel buchen* (Kostenzeile, z.B. Versand – der Rechnungsbetrag
  stimmt) oder *Weglassen* (z.B. Werbematerial; bei Positionen mit Betrag wird
  die Lieferantenrechnung niedriger als das PDF – das Formular zeigt live, was
  gebucht wird, und warnt). Vorbelegung: gelernte Wahl pro Lieferant und
  Bezeichnung → zugeordneter Artikel → Stichwörter aus dem Setup
  (`AUVORECHNUNGSIMPORT_KEYWORDS_COST`, `AUVORECHNUNGSIMPORT_KEYWORDS_SKIP`, kommagetrennt,
  Wortanfang, „weglassen“ vor „ohne Artikel“). Gelernt wird in
  `llx_auvorechnungsimport_linemap` mit `key_type` `mode_cost`/`mode_skip` und
  `fk_product = 0`; wird eine Position wieder normal übernommen, wird die Wahl
  vergessen.
- **Mitlernen**: Beim Anlegen wird der gewählte Artikel pro Lieferant gemerkt;
  wird ein Vorschlag geleert, wird die Zuordnung wieder vergessen. Optional
  (Checkbox) werden Einkaufspreis und Lieferanten-Artikelnummer der Menge-1-
  Preiszeile aktualisiert bzw. angelegt; Staffelpreise bleiben unberührt.
- Positionen werden **nur nach Prüfung** im Formular angelegt. Weicht die Summe
  vom Rechnungsbetrag ab, gibt es eine Warnung.
- Unbekannte Lieferanten können optional automatisch angelegt werden
  (`AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER`). Das erfolgt bei sicher erkanntem
  Firmennamen; eine USt-IdNr. wird zur Dublettenprüfung genutzt, ist aber nicht
  erforderlich. Ein schwacher PDF/OCR-Namensvorschlag genügt nicht. Strukturierte E-Rechnungen
  liefern zusätzlich Adresse und Land. Die Rechnung bleibt bis zur manuellen
  Prüfung in der Importwarteschlange.
- Die Freigabe in der Review-Ansicht validiert die Lieferantenrechnung direkt.
  Erkennt die Analyse keine Positionen, muss vor der Freigabe mindestens eine
  manuelle Position mit Menge, Nettopreis und Umsatzsteuersatz ergänzt werden;
  Dolibarr validiert keine Rechnungen ohne Positionen.
- Optional können Eingangsrechnungen mit dem Dolibarr-Modul BlockedLog
  protokolliert werden. Bei Eingang wird die Originaldatei einmalig Base64-
  kodiert mit SHA-256 gespeichert; bei der Freigabe folgt ein Eintrag zur
  validierten Lieferantenrechnung einschließlich der finalen Positionen. Beide
  Logeinträge werden über die Importreferenz verknüpft. Wegen des MEDIUMTEXT-Speichers sind dabei
  Dateien bis 10 MB unterstützt. Die Vorschau des Originals liest ausschließlich
  die Base64-Kopie aus BlockedLog. Löschen des Importdatensatzes entfernt den
  Logeintrag und dessen Originaldatei nicht; die Vorschau bleibt über den
  gespeicherten Logeintrag erreichbar.
- **Mehrfach-Upload mit Cron-Analyse**: Beliebig viele PDFs und XML-Dateien
  auf einmal hochladen (Server-Grenzen `max_file_uploads`, `upload_max_filesize`,
  `post_max_size` werden im Formular angezeigt). Die Dateien werden nur
  gespeichert und als „In Warteschlange“ markiert. Der Dolibarr-Cronjob
  `AuVo Rechnungsimport queue` verarbeitet sie in kleinen Batches (ein Import
  je Lauf). Aktiviere das Dolibarr-Cron-Modul, registriere die Importjobs in den
  Moduleinstellungen und richte den Dolibarr-Cronrunner nach der Dokumentation
  deiner Installation regelmäßig ein, zum Beispiel jede Minute. Bei OCR muss
  der Cronrunner im CLI-Modus laufen, damit lange Scans nicht am Web-Zeitlimit
  scheitern. Eine Sperrdatei
  (`documents/auvorechnungsimport/temp/queue.lock`) verhindert parallele Läufe;
  ein vom abgebrochenen Lauf hinterlassener „Wird verarbeitet“-Import wird beim
  nächsten Cronlauf als Fehler markiert. Manueller Upload und Email Collector
  stellen Dateien weiterhin in dieselbe Queue. Der Cronrunner braucht Zugriff
  auf Poppler und Tesseract. Der tägliche Erinnerungsjob benötigt zusätzlich
  eine gültige Empfängeradresse in den Moduleinstellungen.
- Bei PDF-Eingängen wird zusätzlich SHA-256 gespeichert. Ein byte-identisches
  PDF wird automatisch als Duplikat des früheren Imports markiert; existiert
  bereits eine gebuchte Lieferantenrechnung, wird auch diese verknüpft. Beim
  ersten Treffer werden vorhandene PDF-Importe ohne Hash nach Möglichkeit
  anhand ihrer gespeicherten Originaldatei nachgehasht. Wenn ECM aktiviert ist,
  wird zusätzlich dessen MD5-Index durchsucht; ein Treffer wird durch erneuten
  SHA-256-Vergleich der gespeicherten Datei bestätigt. ECM-Dateien müssen dafür
  im ECM-Index registriert sein.
- **E-Mail-Spam-Prüfung:** Ein optional konfigurierter Mail-Header und Wert
  halten markierte Email-Collector-Anhänge zunächst in einer eigenen
  Spam-Prüfung. Nach Sichtprüfung kann ein Beleg freigegeben oder als Spam
  bestätigt werden. Bestätigte Spam-Belege und Duplikate erscheinen unter
  „Spam & Duplikate“; die Bestätigung wird im BlockedLog protokolliert.
- **Erinnerungen:** Ein täglicher Dolibarr-Cronjob sendet eine Sammelmail für
  überfällige Importe. Empfänger, erste Frist und Wiederholungen mit wachsendem
  Abstand lassen sich in den Moduleinstellungen konfigurieren.
- **Durchgehen**: Filter „Zu prüfen“, Button „Prüfen starten“, auf der Karte
  „Nächste prüfen“ und nach dem Anlegen ein Link zur nächsten Rechnung.
  „Erneut erkennen“ stellt den Import ebenfalls wieder in die Cron-Queue.
- Alternative Schreibweisen des eigenen Unternehmens können in den
  Moduleinstellungen mit Semikolons getrennt hinterlegt werden. Wird ein solcher Name als Lieferant
  erkannt, blockiert das Modul die Neuanlage und verlangt die Auswahl eines
  externen Lieferanten.
- In der Review-Ansicht kann zwischen vorhandenem und neuem Lieferanten gewählt
  werden. Die Neuanlage braucht die aktivierte Option und das Geschäftspartner-
  Anlegerecht; die bewusste Auswahl „Neuer Lieferant“ erlaubt auch, einen OCR-
  Namen vor dem Anlegen manuell zu prüfen.
- Regex-Heuristiken in Schritt 2/3 sind best effort und decken nicht jedes
  Rechnungslayout ab – deshalb immer ein Prüfschritt vor dem Anlegen der
  Lieferantenrechnung.

## Datenschutz

Das Lesen von Rechnungen, OCR, Lieferanten-/Artikelzuordnung und
Duplikatprüfung laufen lokal auf dem Dolibarr-Server. Wenn eine gültige deutsche
IBAN erkannt wird, fragt das Modul zur Anreicherung von Bankname und BIC die
aus ihr abgeleitete 8-stellige Bankleitzahl bei Fintech Toolbox ab. Dabei werden
weder die Rechnungsdatei noch die IBAN übermittelt. Die Bankabfrage ist die
einzige externe API-Anfrage des Moduls.

## Tests

`php tests/einvoice-tests.php` prüft den E-Rechnungs-Leser mit den mitgelieferten
Beispieldateien. `bash tests/kosit-testsuite.sh` lädt zusätzlich das offizielle
KoSIT-Testbundle herunter und prüft dessen XML-Beispiele. Dafür werden Bash, PHP CLI,
`curl`, `sha256sum` und Python 3 benötigt; den Python-Interpreter kann man über
`PYTHON` vorgeben. Beide Prüfungen und ein PHP-Syntaxcheck laufen in der
GitHub-CI bei Pushes und Pull Requests.

`tests/fixtures/text-no-vat-gross-integer-amount.pdf` ist eine anonymisierte
PDF-Regressionsdatei für die Text-/OCR-Erkennung: Sie enthält einen ganzzahligen
Betrag in deutscher Schreibweise (`625,-`) und einen Hinweis auf die
Kleinunternehmerregelung. Erwartet wird eine erkannte Position zum Bruttopreis;
der Steuersatz bleibt leer und muss in der Prüfansicht ausdrücklich bestätigt
werden (hier mit `0`). Die PDF-Erkennung benötigt die lokale Dolibarr-Umgebung
und deren PDF-/OCR-Werkzeuge; sie ist deshalb nicht Teil des eigenständigen
XML-Testlaufs.

`php tests/pdf-text-tests.php` prüft mit der mitgelieferten anonymisierten
PDF-Datei `tests/fixtures/text-sender-name-linebreak.pdf`, dass ein über zwei
Zeilen umbrochener Absendername („Sportgemeinschaft“ / „Zusammenspiel e V“)
vollständig und als sicherer Firmenname erkannt wird. Der Test benötigt die
Dolibarr-Umgebung und die mitgelieferte Bibliothek `smalot/pdfparser`.

Das Release-ZIP entsteht automatisch, sobald ein Tag `vX.Y.Z` gepusht wird
(`.github/workflows/release-zip.yml`: Tag muss zur Modulversion passen, Tests
laufen, das ZIP ohne `tests/` und `.github/` hängt am Release).

## Eingebettete Bibliotheken

`vendor/` ist mitgeliefert, das Modul braucht kein `composer install`. Bewusst
keine Abhängigkeit zu AuVo E-Rechnung, obwohl beide `atgp/factur-x` nutzen:
jedes Modul bleibt einzeln installier- und entfernbar.

| Bibliothek | Zweck | Lizenz |
|---|---|---|
| `atgp/factur-x` (mit `setasign/fpdi`, `setasign/fpdf`) | eingebettetes XML aus ZUGFeRD-/Factur-X-PDFs | MIT |
| `smalot/pdfparser` | Textlayer digitaler PDFs | LGPL-3.0 |
| `symfony/polyfill-mbstring` | Kompatibilität | MIT |

## Changelog

Versionshinweise und Änderungen stehen in [CHANGELOG.md](CHANGELOG.md).

## Lizenz

[GPLv3](LICENSE) (oder später) – Copyright (C) 2026
[auvo-design](https://auvo-design.de).

## Über dieses Projekt

AuVo Rechnungsimport wird von auvo-design gepflegt und steht allen
Dolibarr-Nutzern frei zur Verfügung. Fehler und Wünsche bitte als
[GitHub-Issue](https://github.com/Fips81/dolibarr-auvo-rechnungsimport/issues)
melden. Sicherheitslücken bitte nicht als Issue, sondern an
r.vogel@auvo-design.de.
