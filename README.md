# AuVo Rechnungsimport – Dolibarr Supplier Invoice Import

[![Neueste Version](https://img.shields.io/github/v/release/Fips81/dolibarr-auvo-rechnungsimport?label=version&sort=semver)](https://github.com/Fips81/dolibarr-auvo-rechnungsimport/releases/latest)

**Lieferantenrechnungen in Dolibarr importieren und prüfen:** PDF-Rechnungen,
gescannte Belege sowie E-Rechnungen als XRechnung, ZUGFeRD oder Factur-X
hochladen. Das Modul erkennt Rechnungs- und Lieferantendaten, schlägt passende
Geschäftspartner und Produkte vor und erstellt die Lieferantenrechnung erst
nach der Prüfung.

**AuVo Rechnungsimport** is an open-source Dolibarr supplier invoice import
module. It supports incoming invoice review from PDF and OCR, plus structured
e-invoice formats including XRechnung, ZUGFeRD and Factur-X. Review extracted
supplier details, bank data and invoice lines in one UI before creating a
Dolibarr supplier invoice.

- **Strukturierte E-Rechnungen:** XRechnung (CII und UBL), Peppol BIS, ZUGFeRD /
  Factur-X – als XML-Datei oder in der PDF eingebettet. XML-Daten werden nach
  EN 16931 ausgelesen; das Modul ist kein Validator für die formale Konformität.
- **Normale PDFs und Scans:** Textlayer der PDF, sonst Texterkennung (OCR)
  mit Tesseract – lokal auf dem Server.
- **Lernt mit:** Artikelzuordnung pro Lieferant, Produktvarianten sowie die
  Behandlung von Kosten- und Weglass-Positionen werden gemerkt.
- **Viele Rechnungen auf einmal:** Mehrfach-Upload, Analyse im Hintergrund,
  danach eine Rechnung nach der anderen prüfen.

Gegenstück zu [AuVo E-Rechnung](https://github.com/Fips81/dolibarr-auvo-erechnung)
(Ausgangsrechnungen als ZUGFeRD und XRechnung).

**Suchbegriffe:** Dolibarr supplier invoice import, incoming invoice review,
accounts payable, PDF invoice OCR, XRechnung, ZUGFeRD, Factur-X, Peppol BIS.

## Motivation und UX-Prinzip

Wer regelmäßig Eingangsrechnungen prüft, soll diesen Ablauf möglichst vollständig
auf **einer Review-Seite** erledigen können. Ziel ist, dass die prüfende Person
erkannte Daten kontrolliert, fehlende Angaben ergänzt und die vorgesehenen
Aktionen ausführt, ohne dafür durch mehrere Dolibarr-Karten, Entwurfsansichten
und Stammdatenmasken springen zu müssen.

Das spart viele kleine Arbeitsschritte, zum Beispiel:

- Lieferant, Rechnungsnummer, Datum und erkannte Rechnungsdaten direkt im Review
  prüfen und korrigieren.
- Lieferantenstammdaten und Bankdaten dort ergänzen oder einen neuen Lieferanten
  beziehungsweise ein Bankkonto anlegen. Bei einer deutschen IBAN kann die
  Bank anhand der BLZ vorgeschlagen werden.
- Positionen, Artikelzuordnung, Preise und Summen gemeinsam kontrollieren,
  ergänzen und korrigieren.
- Die Lieferantenrechnung erstellen und – wenn gewünscht und erlaubt – die
  zugehörige Lieferantenbestellung im selben Ablauf anlegen oder freigeben.

Für diese einfache Bedienung nimmt das Modul bewusst in Kauf, Teile der
Dolibarr-Eingabemasken und der dazugehörigen Prüfregeln in der Review-Oberfläche
abzubilden. Das kann ähnliche Felder oder Prüfungen an mehreren Stellen
bedeuten. Die eigentlichen Geschäftsvorgänge sollen, soweit möglich, weiterhin
über Dolibarr-Klassen und deren Methoden laufen; die zusätzliche Oberfläche
führt die dafür nötigen Angaben und Aktionen jedoch an einem Ort zusammen.

**Leitlinie für Wartung und Weiterentwicklung:** Eine Änderung soll nicht
allein deshalb auf einen anderen Dolibarr-Bildschirm verweisen, weil es dort
bereits eine Standardmaske oder ähnliche Geschäftslogik gibt. Wenn ein
zusätzlicher Schritt für den Review nötig ist, darf das Modul die passende
Eingabe und Prüfung bewusst in seiner Review-Seite bereitstellen. Komfort darf
dabei die Sicherheits- und Buchungsprüfungen nicht umgehen: wichtige Regeln
müssen serverseitig gelten; clientseitige Prüfungen dienen zusätzlich der
direkten Rückmeldung.

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

  Ohne diese Pakete funktionieren XRechnung und ZUGFeRD trotzdem.

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

### Optional: E-Mail-Sammler

Wenn das Dolibarr-Modul **E-Mail-Sammler** aktiviert ist, füge bei den
Aktionen eines Sammlers **In Rechnungsimport übernehmen** hinzu. Dadurch werden
nicht leere PDF- und XML-Anhänge als Rechnungsimporte eingereiht. Das Modul
startet seinen vorhandenen Hintergrundprozess, nachdem der Sammler seine
Datenbanktransaktion abgeschlossen hat. Das Cron-Modul ist dafür nicht
erforderlich. Kann der Prozess nicht gestartet werden, bleiben die
Anhänge in der Warteschlange und der Fehler wird im Dolibarr-Log vermerkt.
Nach dem Update auf diese Version AuVo Rechnungsimport einmal deaktivieren und
wieder aktivieren, damit Dolibarr den Hook registriert und die neue Spalte
`source` anlegt.

Der E-Mail-Sammler verbindet sich mit dem vom Administrator eingerichteten
IMAP-Dienst. Die Anhänge werden im Dokumentenverzeichnis von Dolibarr für die
Prüfung gespeichert und durchlaufen die konfigurierten Datei-Checks,
einschließlich eines aktivierten Virenscanners. Für den Umgang mit Daten durch
einen solchen Scanner gelten dessen eigene Einstellungen. In den
Moduleinstellungen kann optional ein Spam-Header mit erwartetem Wert
konfiguriert werden. Passende Anhänge werden vor der Analyse zur Prüfung
zurückgehalten und können freigegeben oder als Spam bestätigt werden.
Vorhandene Hinweise auf gleiche Rechnungsnummer und gleichen Betrag können auf
der Importkarte als Duplikat bestätigt werden. Bestätigte Spam-Eingänge und
Duplikate erscheinen unter **Spam & Duplikate**; fachlich zurückgewiesene
Importe haben einen eigenen Menüpunkt **Zurückgewiesen**.

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
- **Kopfdaten** der E-Rechnung kommen mit in die Lieferantenrechnung: Fälligkeitsdatum,
  Zahlungsart (aus dem Zahlungsmittelcode, z. B. 58 → Überweisung) und
  Zahlungsbedingung (Vorschlag aus den Lieferanten-Stammdaten, im Formular
  änderbar). Ist die IBAN beim Lieferanten noch nicht hinterlegt, legt eine
  Checkbox sie als zusätzliches Bankkonto an (nur gültige IBAN, das Standardkonto
  wird nie ersetzt). Bestell-, Vertrags- und Käuferreferenz, Verwendungszweck,
  Leistungszeitraum und Zahlungsbedingungstext (inkl. Skonto) stehen in der
  privaten Notiz.
- **Artikelzuordnung** bei E-Rechnungen, in dieser Reihenfolge: Lieferanten-Artikelnummer
  (BT-155) aus den Einkaufspreisen, gelernte Zuordnung, GTIN/EAN (BT-157, auch als
  GTIN-14 mit führender Null; Barcode des Artikels, dann der Barcode im
  Lieferantenpreis), unsere eigene Artikelnummer (BT-156) als Artikelreferenz.
  Die **Einheit** (BT-130, z. B. HUR, KGM) wird in die Dolibarr-Einheit der Zeile
  übernommen, wenn „Einheiten“ in den Produkteinstellungen aktiv ist.
- **Gutschriften** (Typ 381, UBL CreditNote) werden als Lieferanten-Gutschrift angelegt.
  Im Prüfformular wählt man die Ursprungsrechnung; steht ihre Nummer in der
  Gutschrift (BT-25), ist sie vorausgewählt. Zur Auswahl stehen die validierten
  Rechnungen des Lieferanten. Ohne Ursprungsrechnung geht es nur, wenn Dolibarr
  eigenständige Gutschriften erlaubt (`INVOICE_CREDIT_NOTE_STANDALONE`). Die
  Beträge bleiben positiv, Dolibarr führt sie negativ. Positionen mit negativem
  Betrag (Nachlass innerhalb der Gutschrift) lassen sich nicht abbilden und
  blockieren das Anlegen, bis sie übersprungen werden. Einkaufspreise und
  Wareneingangs-Bestellung gibt es bei Gutschriften nicht.
- **Prüfhinweise** im Prüfformular, keine Formalprüfung: Rechnen die Summen (Positionen,
  Nachlässe/Zuschläge, Steuer, Gesamt- und Zahlbetrag) auf? Passen Steuerkategorie und
  Steuersatz zusammen (z. B. S mit 0 %, oder Z/E/AE/K/G mit Satz)? Fehlen Pflichtangaben
  (Verkäufername, Rechnungsnummer, Datum, Währung, USt-ID/Steuernummer, Fälligkeit)?
  Außerdem der Hinweis, wenn dieselbe Rechnung (Nummer, Betrag, ggf. USt-ID) schon als
  Import vorliegt. Die Hinweise blockieren nichts. Eine Formalprüfung gegen die
  Geschäftsregeln leistet das Modul nicht – dafür den KoSIT-Validator nutzen.

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
- **Unbekannte Lieferanten** können anhand erkannter XML-, PDF-Text- oder
  OCR-Daten vorgeschlagen und nach Prüfung angelegt werden. Die Review-Maske
  zeigt die erkannten Stammdaten; vor dem Anlegen wird erneut nach einem
  vorhandenen Geschäftspartner gesucht. Die globale Einstellung
  `AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER` erlaubt oder sperrt die Neuanlage
  von Lieferanten und Bankkonten. Zusätzlich ist die Dolibarr-Berechtigung zum
  Anlegen von Geschäftspartnern erforderlich.
- **Mehrfach-Upload mit Hintergrundanalyse**: Beliebig viele PDFs und XML-Dateien
  auf einmal hochladen (Server-Grenzen `max_file_uploads`, `upload_max_filesize`,
  `post_max_size` werden im Formular angezeigt). Die Dateien werden nur
  gespeichert und als „In Warteschlange“ markiert; ein losgelöster PHP-CLI-
  Prozess (`scripts/process_queue.php`, gestartet per `setsid`) analysiert sie
  nacheinander – ohne das 30-s-Zeitlimit des Webservers, wichtig für OCR
  mehrseitiger Scans. Eine Sperrdatei (`documents/auvorechnungsimport/temp/
  queue.lock`) verhindert parallele Läufe; ein vom abgestürzten Prozess auf
  „Wird verarbeitet“ hinterlassener Import wird beim nächsten Lauf als Fehler
  markiert, statt die Warteschlange zu blockieren. Die Liste aktualisiert sich
  selbst, solange analysiert wird, und bietet „Analyse starten“, falls kein
  Prozess läuft. PHP-CLI-Pfad optional über `AUVORECHNUNGSIMPORT_PHP_CLI_PATH`
  (Standard: `PHP_BINDIR/php`). Manuell/per Cron: `php scripts/process_queue.php [entity]`
  (als Webserver-Benutzer ausführen, damit Dateien und Sperre ihm gehören).
- **Durchgehen**: Filter „Zu prüfen“, Button „Prüfen starten“, auf der Karte
  „Nächste prüfen“ und nach dem Anlegen ein Link zur nächsten Rechnung.
  „Erneut erkennen“ läuft ebenfalls im Hintergrund.
- Regex-Heuristiken in Schritt 2/3 sind best effort und decken nicht jedes
  Rechnungslayout ab – deshalb immer ein Prüfschritt vor dem Anlegen der
  Lieferantenrechnung.

## Datenschutz und Grenzen

PDF-/XML-Auswertung, OCR und Lieferantenzuordnung laufen lokal auf dem
Dolibarr-Server; Rechnungsdateien werden dafür nicht an einen Cloud-OCR- oder
KI-Dienst übertragen. Wenn eine deutsche IBAN erkannt wird, kann das Modul
optional die Bank anhand der BLZ über einen externen Bankdaten-Webservice
ermitteln (`fintechtoolbox.com`). Dabei wird die BLZ abgefragt, nicht der
Rechnungsinhalt. Diese Anreicherung ist unverbindlich und kann fehlschlagen.

Erkennungsergebnisse sind Vorschläge und müssen geprüft werden. Das Modul ist
weder ein Steuerberater noch ein Validator für die formale E-Rechnungskonformität
und zertifiziert keine GoBD- oder DSGVO-Konformität. Die Verantwortung für
Prüfung, Buchung und Aufbewahrung bleibt beim betreibenden Unternehmen.

## FAQ – Häufige Fragen

### Welche Rechnungsformate werden unterstützt?

PDF-Dateien mit digitalem Text oder Scan sowie XML-E-Rechnungen. Unterstützt
werden unter anderem XRechnung (CII und UBL), Peppol BIS Billing und
ZUGFeRD/Factur-X, einschließlich eingebettetem XML in PDF-Dateien.

### Funktioniert die Erkennung bei gescannten Rechnungen?

Ja. Für PDFs ohne Textschicht kann das Modul OCR mit Tesseract und Poppler
verwenden. Diese Programme müssen auf dem Server installiert sein; die
Texterkennung läuft lokal. OCR-Ergebnisse können unvollständig sein und müssen
in der Review-Ansicht geprüft werden.

### Wird die Lieferantenrechnung automatisch gebucht?

Nein. Erkannte Lieferanten-, Rechnungs- und Positionsdaten werden zunächst zur
Prüfung angezeigt. Erst eine Person löst die Anlage aus. Je nach gewählter
Aktion entsteht eine Lieferantenrechnung als Entwurf; eine optionale Aktion kann
zusätzlich eine Lieferantenbestellung anlegen und freigeben. Eine Zahlung wird
dabei nicht gebucht.

### Kann das Modul einen neuen Lieferanten aus einer Rechnung anlegen?

Ja. Erkannte Daten aus E-Rechnungs-XML, PDF-Text und OCR können in der
Review-Ansicht geprüft und für eine Neuanlage verwendet werden. Die globale
Moduleinstellung und die Dolibarr-Berechtigung zum Anlegen von Geschäftspartnern
müssen dies erlauben. Für deutsche Lieferanten muss eine USt-ID oder
Steuernummer vorliegen.

### Lernt das Modul aus meinen Korrekturen?

Ja. Die Zuordnung von Rechnungspositionen zu Produkten wird pro Lieferant
gespeichert und bei passenden späteren Rechnungen wieder vorgeschlagen. Auch
Produktvarianten sowie die Behandlung als Kosten- oder Weglass-Position können
gelernt werden. Vorschläge bleiben überprüfbar.

### Wie werden Duplikate erkannt?

Das Modul weist auf bereits importierte Rechnungen mit gleicher
Lieferanten-Rechnungsnummer und gleichem Betrag hin; bekannte, abweichende
USt-IDs werden dabei berücksichtigt. Ein identischer PDF-Datei-Hash-Abgleich
und die Suche in Dolibarrs ECM sind derzeit noch nicht implementiert.

### Werden Rechnungsdaten an externe KI- oder OCR-Dienste gesendet?

Nein. PDF-/XML-Auswertung, OCR und Lieferantenzuordnung erfolgen lokal auf dem
Dolibarr-Server. Für eine optionale Bankdaten-Anreicherung kann die BLZ einer
deutschen IBAN an `fintechtoolbox.com` übermittelt werden; der Rechnungsinhalt
wird dafür nicht übertragen.

### Ist das Modul ein GoBD-Archiv oder ein Steuer-/E-Rechnungsvalidator?

Nein. Das Modul unterstützt den Import und die Prüfung, ersetzt aber weder ein
Archivierungs- und Aufbewahrungskonzept noch eine steuerliche Prüfung oder den
formalen Validator für E-Rechnungen.

## Tests

`php tests/einvoice-tests.php` prüft den E-Rechnungs-Leser mit eigenen
Beispieldateien. `bash tests/kosit-testsuite.sh` lädt zusätzlich die
offizielle Testsuite der KoSIT und liest alle 86 Beispielrechnungen: jede wird
gelesen, die Positionen ergeben die Nettosumme, CII- und UBL-Fassung desselben
Falls liefern dieselben Daten. Beides läuft bei jedem Push und vor jedem
Release in der GitHub-CI.

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

### 0.4.0 (in Arbeit)

- Neu: Lieferantenrechnungsprüfung mit Stammdaten-, Bankdaten- und
  Positionsbearbeitung in einer Review-Ansicht; neue Lieferanten können nach
  Prüfung auch aus PDF-Text und OCR angelegt werden.
- Neu: Rechnung speichern, als Entwurf anlegen oder – wenn erlaubt – zusammen
  mit einer Lieferantenbestellung anlegen und diese freigeben.
- Neu: Zurückweisungen mit eigenem Status und Menüpunkt; Spam und Duplikate
  bleiben in einer separaten Liste.
- Neu: Administrierbare Regeln für Lieferanten-/Bankkontoanlage,
  Bestellaktionen und Löschsperren nach Status.
- Neu: Kopfdaten aus der E-Rechnung werden in die Lieferantenrechnung übernommen
  (Fälligkeit, Zahlungsart, Zahlungsbedingung, Referenzen und Leistungszeitraum
  als Notiz); IBAN optional als Bankkonto des Lieferanten.
- Neu: Unbekannte Lieferanten aus den Verkäuferdaten der E-Rechnung anlegen
  (Checkbox im Prüfformular, vorher erneute Dublettensuche).
- Neu: Gutschriften werden als Lieferanten-Gutschrift zur gewählten Ursprungsrechnung
  angelegt (BT-25 wird vorausgewählt).
- Neu: Artikelzuordnung über GTIN (auch mit führender Null, auch Lieferanten-Barcode) und
  die eigene Artikelnummer des Käufers (BT-156); Einheiten (BT-130) werden in die
  Zeilen übernommen.
- Neu: Prüfhinweise im Prüfformular (Summen, Steuerkategorie gegen Satz, Pflichtangaben,
  doppelt hochgeladene Rechnung).

### 0.3.0 (Oktober 2026)

Erste öffentliche Version.

- Umbenannt von InvoiceImport in **AuVo Rechnungsimport** (Ordner
  `auvorechnungsimport`, Modulnummer 194203) mit automatischer Übernahme der
  bisherigen Daten.
- **XRechnung-Import:** XRechnung, Peppol BIS und ZUGFeRD/Factur-X als XML in
  CII und UBL, Rechnung und Gutschrift, mit allen Positionen.
- Lesbare Ansicht für XML-Rechnungen, aufklappbar auch unter ZUGFeRD-PDFs.
- Anlagen der E-Rechnung (BG-24) herunterladbar und an die
  Lieferantenrechnung angehängt.
- Lieferantensuche zusätzlich über die Steuernummer.
- Tests mit der offiziellen KoSIT-Testsuite, GitHub-CI und Release-Workflow.

### 0.2.x und früher (intern, als InvoiceImport)

PDF-Import mit Factur-X/ZUGFeRD, Textlayer und OCR; Positionserkennung mit
lernender Artikel- und Variantenzuordnung; Varianten aus Rechnungszeilen
anlegen; Behandlung pro Position; Mehrfach-Upload mit Hintergrundanalyse.

## Lizenz

[GPLv3](LICENSE) (oder später) – Copyright (C) 2026
[auvo-design](https://auvo-design.de).

## Über dieses Projekt

AuVo Rechnungsimport wird von auvo-design gepflegt und steht allen
Dolibarr-Nutzern frei zur Verfügung. Fehler und Wünsche bitte als
[GitHub-Issue](https://github.com/Fips81/dolibarr-auvo-rechnungsimport/issues)
melden. Sicherheitslücken bitte nicht als Issue, sondern an
r.vogel@auvo-design.de.
