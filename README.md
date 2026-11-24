# Lagerverwaltungssystem

Internes Lagerverwaltungs-, Vertriebs- und Dokumentensystem auf Basis von Laravel.

Das System verbindet Produkt- und Chargenverwaltung, FIFO-Lagerlogik, Kunden und Lieferanten, Filialausgänge, Angebote/Rechnungen, PDF-Dokumente, Preislogik, Reservierungen, Warnungen, Statistik sowie rollenbasierte Arbeitsabläufe.

> **Verbindlicher Dokumentationshinweis:** Nach jeder erfolgreich getesteten und übernommenen Änderung muss diese `README.md` im selben Arbeitsablauf geprüft und – sobald der dokumentierte Funktions-, Security-, Betriebs- oder Produktionsstand betroffen ist – aktualisiert werden. Ein Arbeitspaket gilt erst als abgeschlossen, wenn Implementierung/Produktion und README auf demselben Stand sind.

**Repository:** `IT-Kayali/Lager-System`

## Aktueller Funktionsumfang

### Produkte

- Produktverwaltung mit Produktbezeichnung, Code-Nummer, Fake Name, Einheit und Lieferant
- Kategorien mit Prioritäten
- Produktübersicht mit eigener Kategorie-Spalte: alle zugeordneten Kategorien pro Produkt; bei fehlender Zuordnung `Ohne Kategorie`
- Kategorien können Preisstaffeln aktivieren/deaktivieren
- Mindestbestand und Warnschwellen
- Verfügbarer Bestand unter Berücksichtigung aktiver Reservierungen
- Sichere Produktlöschung: verwendete Produkte werden nicht aus historischen Vorgängen entfernt
- Produkt-Excel-Export mit ausschließlich einem sichtbaren Blatt `Produkte` (ohne Produktcode und Preisstaffeln). Lieferanten werden aus dem verknüpften Stammdatensatz mit Text-Fallback exportiert. Eine ausgeblendete interne Produkt-ID erlaubt die zuverlässige Umbenennung.
- Produkt-Excel-Import mit Vorschau, Einzelzeilenauswahl und den Modi **Aktualisieren** (keine Archivierung) und **Synchronisieren** (bewusst entfernte Exportzeilen nach ausdrücklicher Bestätigung archivieren).
- Für das Synchronisieren ist ein vollständiger neuer Systemexport mit unveränderten ausgeblendeten Exportmetadaten nötig. Produkte, die erst nach dem Export hinzugefügt wurden, werden nicht archiviert. Bei inzwischen geänderten Produkten wird der Import abgelehnt; eine Vorschau allein führt keine Datenbankänderung aus.
- Ohne interne ID wird der eindeutige Produktname verwendet; ältere Importdateien mit `product_code` bleiben im Aktualisierungsmodus unterstützt. Leere Excel-Felder lassen bestehende Werte bestehen; der Mindestbestand `0` wird übernommen. Alte Blätter `Preisstaffeln` werden ignoriert; der Import verändert keine bestehenden Preise oder Lagerbewegungen.
- Erweiterte Suche mit auswählbarem Suchfeld und „Exakter Wert“
- Sortierung unter anderem nach Produktbezeichnung, Fake Name, Code und Lieferant
- Natürliche Produktsortierung für numerische Namen, z. B. `2, 3, 10`

### Vorgemerkt: eigener Produktarchiv-Bereich

- **Offene Folgeaufgabe:** Einen eigenen Archivbereich für archivierte Produkte mit Suche, Anzeige des Archivierungsgrundes und Möglichkeit zur Wiederherstellung ergänzen. Diese Oberfläche gehört **nicht** zum aktuellen Excel-Arbeitspaket und darf bei späteren Änderungen nicht vergessen werden.
- Für den geplanten Produkt-Excel-Abgleich gilt: Entfernte Zeilen werden ausschließlich in einem ausdrücklich bestätigten Synchronisierungsmodus archiviert, niemals endgültig gelöscht. Historische Belege und Lagerbewegungen bleiben erhalten.
- Der Produktimport/-export und der zukünftige Preisstaffelimport/-export auf der Seite **Preise** sind eigenständige Arbeitsabläufe. Der Produktimport darf Preise nicht überschreiben.
- Archivierte Produkte bleiben als Datenbankdatensätze für historische Belege und Lagerbewegungen erhalten. Im normalen Produkt- und Preisbereich sowie in neuen Auswahllisten werden aktive Produkte angezeigt. Ein separates Archiv-Menü mit Such- und Wiederherstellungsfunktion muss noch entwickelt werden.

### Preise – Excel nach Kundengruppen (Änderung 11)

- Im Preisbereich stehen **Importvorlage**, **Preise exportieren** und **Preise importieren** zur Verfügung; jeweils für **eine ausgewählte Kundengruppe** oder **alle Kundengruppen**.
- Die Kundengruppen und die vorhandenen Mengenstaffeln werden bei jedem Download dynamisch aus der Datenbank geladen. Neue Kundengruppen oder Staffeldefinitionen benötigen keine feste Excel-Spalte und keinen Programmumbau.
- Die Excel-Tabelle **Preise** enthält Produktname, Kundengruppe, Preisart (`staffel` oder `manuell`), Preisstufe, Von-/Bis-Menge, Einheit, Preis und Beschreibung. Ausgeblendete IDs schützen die Zuordnung zu einer vorhandenen Preisregel. Ein zweites Blatt **Anleitung** erklärt die Bedienung.
- **Vorlage:** Spalte `price` ist leer; **Export:** aktuelle Preise sind eingetragen. Ausschließlich `price` bearbeiten und anschließend die Datei zur Vorschau hochladen. Deutsche Dezimalzahlen (z. B. `12,50`) werden angenommen. Leere Zellen werden ignoriert, ausdrücklich eingetragene `0` wird übernommen.
- Die Vorschau prüft alle gefüllten Preiszeilen und zeigt die Differenz zwischen Alt und Neu. Nach Auswahl und Bestätigung werden die Preise in Paketen zu jeweils 100 Zeilen aktualisiert und im Aktivitätsprotokoll dokumentiert. Jedes Paket ist für sich transaktional; bei einem Fehler bleiben **bereits abgeschlossene Pakete** gespeichert, und der Import lässt sich im offenen Browser fortsetzen. Parallel geänderte Preise oder Mengenregeln werden zurückgewiesen.
- Bestehende Preisstaffeln **und vorhandene manuelle Mengenregeln** können aktualisiert werden. Fehlt für ein manuell bepreistes Produkt (z. B. eine **Stück**-Kategorie ohne Preisstaffel) die Preisregel, erscheint eine leere Standardzeile **ab 1 Einheit** mit interner Preis-ID `0`. Erst wenn `price` in dieser Zeile ausgefüllt und in der Vorschau bestätigt wurde, entsteht eine einzelne offene manuelle Regel; vorhandene manuelle Mengenregeln werden niemals überschrieben oder gelöscht. Für zusätzliche bzw. komplexe Mengenbereiche weiterhin die Preisverwaltung verwenden. Excel entfernt oder archiviert keine Produkte, Gruppen oder Preisregeln.
- Die Datei enthält höchstens 50.000 Importzeilen (10 MB Upload), Formeln und doppelte Preiszuordnungen werden abgewiesen. Bei der Bestätigung überträgt der Browser nur abgewählte Zeilennummern in einem kompakten Feld, damit das PHP-POST-Limit `max_input_vars` große Importe nicht still abschneidet. Die Verarbeitung zeigt einen Fortschrittszähler und läuft über separat bestätigte 100er-Pakete; JavaScript und eine gültige Sitzung werden benötigt. Während des Imports die Seite geöffnet lassen; nach einem Fehler mit „Import fortsetzen“ weiterarbeiten. Nur Manager, Administratoren und Verkauf haben gemäß bestehenden Preisbereichs-Rollen Zugriff.
- Der **Produkt-Excel-Import** bleibt davon unabhängig; er überschreibt weiterhin keine Preise.
- **Korrektur für große Exporte:** Der XLSX-Export schreibt die Tabellen zeilenweise in temporäre XML-Dateien und verarbeitet Produkte in kleinen Datenbankpaketen. Dadurch werden nicht mehr sämtliche Excel-Zellen im PHP-Arbeitsspeicher aufgebaut. Importiert wird weiterhin über PhpSpreadsheet.
- **Korrektur für umfangreiche Preisimporte:** Beim Hochladen wird nur das Tabellenblatt `Preise` ohne Formatierungsdaten eingelesen. Die bestehenden Staffel- und Mengenpreisregeln werden in Paketen zu jeweils 250 Excel-Zeilen vorab geladen statt pro ausgefüllter Preiszeile einzeln aus MySQL abgefragt. Die Prüfung sämtlicher Zuordnungen, die Vorschau und die ausdrückliche Bestätigung bleiben erhalten. Die Einlesung der XLSX-Datei selbst erfolgt weiterhin über PhpSpreadsheet; große Dateien benötigen deshalb ausreichend PHP-FPM-Arbeitsspeicher.
- **Fünf Nachkommastellen:** Produkt-Preisstaffeln und manuelle Mengenpreise speichern `DECIMAL(15,5)`. Export und Vorlagen zeigen Preise mit fünf Dezimalstellen; der Import akzeptiert maximal fünf. Im Preisformular werden fünf Nachkommastellen jetzt **sichtbar und unverändert als deutsche Kommazahl** dargestellt (z. B. `12,34567`), unabhängig von der Darstellung nativer Zahlenfelder im Browser. Der Server normalisiert Komma- und Punktzahlen und validiert maximal fünf Stellen. Die zentrale JavaScript-Zahlenformatierung bewahrt die fünf Nachkommastellen auch nach dem Laden, bei Livewire-Navigation und nach Verlassen der Preisfelder; Mengen und andere Geldbeträge behalten ihre bisherige Genauigkeit. Rechnungssummen in Euro bleiben auf zwei Cent-Stellen gerundet.
- **Deployment:** Die Fünf-Dezimalstellen-Migration stammt aus einer früheren ERP11-Korrektur und muss bereits angewendet sein. Die aktuelle JavaScript-Korrektur benötigt **keine neue Migration**, aber einen Vite-Asset-Build (`npm ci` bei fehlenden Abhängigkeiten, danach `npm run build`), damit die neue `resources/js/german-numbers.js` über `public/build/` ausgeliefert wird. Vor einem Produktiv-Update eine Datenbanksicherung erstellen und vorhandene lokale Änderungen auf Dateikonflikte prüfen. **Wichtig:** SSH-Deployments mit `umask 027` ausführen (nicht mit `umask 077`); generierte `bootstrap/cache`- und `storage/framework/views`-Dateien sowie durch Git aktualisierte PHP-/Blade-Dateien müssen für die PHP-FPM-Gruppe lesbar sein. Nach dem Update Berechtigungen gezielt prüfen und erst dann Laravel-Caches neu erstellen. Ein falscher Dateimodus (`700`) führte am 19.11.2026 auf dem Produktionsserver zu HTTP 500. Neue Composer-Pakete sind nicht nötig.

### Preise – kompaktes Format (ERP11.1, in Entwicklung)

- Zusätzlich zum unveränderten Standardformat steht ein kompaktes Excel-Format für Export und Importvorlage zur Verfügung.
- Für jede verwendete Produkteinheit mit exportierbaren Preisregeln entsteht ein eigenes Blatt; Produkte werden natürlich sortiert, Kundengruppen in ihrer Systemreihenfolge und Mengenbereiche numerisch aufsteigend. Individuelle Preisregeln und die leere Standardregel ab 1 Einheit werden berücksichtigt.
- Farbige Gruppenüberschriften, fixierte Kopfzeilen, fünf Nachkommastellen; graue Preisfelder ohne passende Regel dürfen nicht ausgefüllt werden. Verborgene, per Anwendungsschlüssel signierte Zuordnungen schützen die Zuordnung; Produktnamen, Einheit und Spaltenüberschriften müssen unverändert bleiben.
- Der Import erkennt beide Formate automatisch und nutzt dieselbe Validierung, Vorschau und 100er-Paketverarbeitung. Die Vorschau nennt bei kompakten Dateien die Excel-Zelle. Keine neue Migration.
- Kompaktformat: maximal 50.000 Preisfelder einschließlich Lücken und 500 Preisspalten pro Blatt. Bei sehr vielen individuellen Mengenbereichen oder doppelt vorhandenen gleichartigen Regeln bitte Standardformat oder eine einzelne Kundengruppe verwenden. Der Standardexport bleibt speicherschonend; das Kompaktformat verwendet PhpSpreadsheet und benötigt einen gesonderten Lasttest vor Produktion.

### Chargen & FIFO

- Wareneingänge und Chargenverwaltung
- Chargenliste als Excel-Datei (`chargen.xlsx`) exportieren und importieren; im Chargenbereich stehen beide Aktionen direkt zur Verfügung.
- Chargen-Excel-Import mit Vorschau und Einzelzeilenauswahl: neue Chargen anlegen, bestehende per interner ID (alternativ eindeutiger Batchnummer) aktualisieren und Chargennummern bei unveränderter Zuordnung umbenennen.
- Importierte Mengenänderungen erzeugen protokollierte Lagerbewegungen (`in`/`adjustment`); für die Übernahme von Bestandsänderungen ist das Bestätigungswort `BESTAND` erforderlich. Die Datenbank wird erst bei Bestätigung geändert.
- Bestandsreduzierungen unter den bereits für Angebote oder Filialausgänge reservierten Gesamtbestand werden verhindert.
- Aus dem Export entfernte Zeilen werden **nicht** gelöscht oder archiviert. Stale Exportdaten und Änderungen seit der Vorschau werden zurückgewiesen; bestehende Reservierungen, Preise und historische Vorgänge werden nicht importiert.
- Excel-Spalten: `batch_number`, `product`, `quantity`, `expires_at`, `received_at`. Verborgene IDs und Exportmetadaten dienen der sicheren Zuordnung. Neue Chargen setzen ein vorhandenes aktives Produkt voraus, leeres Ablaufdatum wird für neue Chargen aus der Standardfrist ermittelt.
- Automatische Batchnummern
- Konfigurierbares Standard-Ablaufdatum für neue Chargen
- FIFO-Warenausgang nach ältester Charge
- Mengenanpassungen mit Bestandsbewegungen
- Ablaufdatum und FIFO-Status
- Erweiterte Suche nach:
  - Batchnummer
  - Produkt
  - Code-Nummer
  - Fake Name
- Suche mit „Exakter Wert“
- Sortierung nach Batchnummer, Produkt, Menge, Ablaufdatum und Wareneingang

### Lagerbestand & Reservierungen

- Bestandsführung über Chargen
- Reservierungen durch aktive Angebote
- Reservierungen durch aktive Filialausgänge
- Mindestbestand-Schutz
- Warnungen für niedrigen und kritischen Bestand
- Konfigurierbarer prozentualer Vorwarnbereich
- Warnungsübersicht mit Excel-Export

### Filialausgänge

- Der Filial-Lieferschein zeigt die vorhandene interne Lagernotiz unter den Produktpositionen an. Leere Notizen werden ausgelassen; längere Texte mit Zeilenumbrüchen werden über weitere PDF-Seiten verteilt, ohne die Fußzeile zu überlagern. Zugriffsrechte für die PDF bleiben Manager und Lager vorbehalten.
- Arabische Lagernotizen werden beim PDF-Export zeilenweise für Dompdf aufbereitet (verbundene Zeichen, rechtsbündige Darstellung, lateinische Zeilen bleiben unverändert). Die Datenbanknotiz bleibt im Original erhalten. Dafür wird `khaled.alshamaa/ar-php` v7 via Composer benötigt; beim Serverupdate vor der Freigabe `composer install --no-dev --prefer-dist --optimize-autoloader` ausführen.
- Mehrere Produkte pro Filialausgang
- Filialauswahl
- Statusbasierter Workflow
- Bestandsreservierung bereits während des offenen Vorgangs
- Bearbeiten und Löschen mit automatischer Bestands-/Reservierungsanpassung
- Eigener Lieferschein
- Erweiterte Suche nach Filialausgang, Produkt, Mitarbeiter und Notiz
- Filter nach Filiale und Status
- Suche mit „Exakter Wert“
- **Filialausgang-Historie:** Übergebene, ausgegebene und stornierte Filialausgänge bleiben für berechtigte Rollen in der Liste auffindbar und über eine schreibgeschützte Detailansicht mit Status, Mitarbeiter, Positionen und Notiz einsehbar. Verkauf kann auch nach Übergabe/Abschluss lesen, aber weiterhin nicht bearbeiten; Manager/Admin behalten ihre bisherigen Rechte. Im Lager bleiben noch nicht übergebene offene Verkaufsentwürfe unsichtbar, und stornierte Vorgänge sind ausschließlich lesbar.
- Rollenbasierte Übergabe zwischen Verkauf und Lager:
  - Verkauf erstellt und bearbeitet den Vorgang im Status **Offen**
  - Verkauf übergibt mit **In Bearbeitung** an das Lager
  - danach ist der Vorgang für Verkauf nicht mehr bearbeitbar
  - Lager sieht Vorgänge erst ab **In Bearbeitung**
  - Lager kann Status vorwärts und rückwärts ändern
  - Rückgabe auf **Offen** gibt den Vorgang wieder an Verkauf zurück

### Angebote & Rechnungen

- Angebotsverwaltung mit mehreren Produktpositionen
- Der Angebotseditor berechnet verfügbare Mengen per Sammelabfragen für Chargen, Angebots- und Filialreservierungen; archivierte Produkte werden in der Neuauswahl ausgeblendet.
- Automatische Lagerreservierung
- Kundenauswahl mit Kundengruppe
- Suchbare Dropdowns mit echten Platzhaltern
- Erweiterte Suche nach:
  - Angebotsnummer
  - Kunde
  - Kundennummer
  - Produkt
- Suche mit „Exakter Wert“
- Statusfilter
- Konfigurierbare alphanumerische Angebotsnummern, z. B.:
  - `00111`
  - `AL00001`
  - `ANG-00050`
- Preisvorschau je Produkt und Menge
- Automatische Preisberechnung aus Preisregeln
- Manuell überschreibbarer Gesamtpreis je Angebotsposition
- Manuelle Überschreibung verändert die zentrale Preisregel nicht
- Versandart und Versandpreis
- Versandpreis wird im Formular netto erfasst und intern als Brutto-Wert weitergeführt
- Kartonanzahl bei Lieferung
- Pflichtfeld-Markierungen direkt im Formular
- Technische 422-Seiten bei ungültigen Mengen werden vermieden; Validierungsfehler erscheinen im Formular
- Interne Angebotsnotizen für ERP-Benutzer

### Übergabe Verkauf → Lager bei Angeboten

- Verkauf kann ein Angebot im Status **Angebot** bearbeiten
- Verkauf übergibt es mit **In Bearbeitung** an das Lager
- ab diesem Zeitpunkt kann Verkauf den Vorgang nicht mehr bearbeiten oder stornieren
- Lager sieht nur übergebene Angebote
- Lager kann zwischen zulässigen Lagerstatus vor- und zurückgehen
- Rückgabe von **In Bearbeitung** auf **Angebot** übergibt den Vorgang wieder an Verkauf
- **Erledigt** bleibt final, da die FIFO-Buchung bereits erfolgt ist

### Lager-Benachrichtigungen

Für die Rolle **Lager** gibt es persistente Benachrichtigungen für neu übergebene Aufträge:

- neue Benachrichtigung bei **Angebot → In Bearbeitung**
- neue Benachrichtigung bei **Filialausgang → In Bearbeitung**
- **Neue interne Bestellnotizen:** Wird in einer bereits für das Lager sichtbaren Bestellung (In Bearbeitung, Abholbereit oder Erledigt) eine neue interne Notiz geschrieben, erhalten alle anderen aktiven Lager-Mitarbeiter je eine eigene ungelesene Benachrichtigung. Verkäufer- und Manager-Notizen werden an alle aktiven Lager-Mitarbeiter gemeldet, Lagermitarbeiter erhalten keine Eigenbenachrichtigung.
- Die Meldung nennt die Bestellung, den Verfasser und einen kurzen Notiz-Auszug. Ein Klick führt direkt zur internen Notizsektion der Lager-Bestellung. Jede Notiz hat einen unabhängigen Gelesen-Status pro Empfänger.
- Vor der Übergabe angelegte Notizen werden aus Rollen- und Datenschutzgründen nicht direkt ans Lager gemeldet; sie sind nach der Übergabe im Auftrag lesbar. Bei Rückgabe oder Stornierung werden nicht mehr gültige Lager- und Notizmeldungen ausgeblendet.
- Glocke mit rotem Ungelesen-Zähler für Aufträge und Notizen im Lager-Benutzerbereich der Sidebar
- Benachrichtigungen bleiben nach Neuladen, Abmelden und erneutem Anmelden erhalten
- Öffnen der Benachrichtigungsliste markiert nichts als gelesen
- erst der Klick auf eine konkrete Benachrichtigung markiert genau diese Meldung als gelesen
- Angebotsmeldungen öffnen direkt den Lagerauftrag
- Filialausgangsmeldungen öffnen die gefilterte Filialausgangsansicht
- automatische Aktualisierung des Zählers im Browser (alle 20 Sekunden sowie beim Öffnen der Glocke und nach erneutem Aktivieren des Tabs)
- eigener Gelesen/Ungelesen-Stand pro Lager-Mitarbeiter
- Rückgabe eines Auftrags an Verkauf entfernt nicht mehr gültige Lagerbenachrichtigungen
- Benachrichtigungsrouten sind serverseitig ausschließlich für die Rolle **Lager** zugelassen

### Preislogik

Das System unterstützt zwei Preisarten je Produkt/Kategorie:

1. **Preisstaffeln**
   - Preis nach Kundengruppe und Mengen-/Gewichtsstufe

2. **Manuelle Preisregeln**
   - mehrere Mengenbereiche pro Produkt und Kundengruppe
   - optional offene Obergrenze
   - wird verwendet, wenn die Produktkategorie keine Preisstaffeln nutzt

Zusätzlich kann der Gesamtpreis einer einzelnen Angebotsposition manuell angepasst werden.

### Kunden

- Kundennummer
- Firmen-/Kundenname
- Kundengruppen
- E-Mail
- Telefon / WhatsApp mit Ländervorwahl
- USt-Nummer
- Rechnungsadresse
- optionale abweichende Lieferadresse
- interne Kundennotiz
- Kunden-Wallet / Buchungen
- Umsatz- und Produktübersicht abgeschlossener Vorgänge
- dauerhafter **Lieferschein-Hinweis** je Kunde
- Erweiterte Suche nach Kundennummer, Kunde, E-Mail, Telefon, Stadt und USt-Nummer
- Kundengruppenfilter und „Exakter Wert“

Beispiel für einen dauerhaften Lieferschein-Hinweis:

```text
Kunde braucht Karton ohne Logo
```

Dieser Hinweis wird automatisch auf Lieferscheinen des ausgewählten Kunden angezeigt, aber nicht auf Angebot oder Rechnung.

### Lieferanten

- Lieferantenverwaltung
- Kontaktdaten
- Verknüpfung mit Produkten
- WhatsApp-/Telefon-Unterstützung
- Erweiterte Suche nach Lieferantennummer, Lieferant, Ansprechpartner, E-Mail und Stadt
- Suche mit „Exakter Wert“
- Zugriff für **Manager** und **Lager**
- kein Lieferantenzugriff für **Verkauf**

### PDF-Dokumente

Unterstützte Dokumente:

- Angebot
- Rechnung
- Lieferschein
- Filialausgang-Lieferschein

Es gibt unterschiedliche Dokumentvorlagen, unter anderem mit und ohne Firmenlogo/Firmendaten.

Weitere PDF-Funktionen:

- hochladbare Logos und Hintergründe
- konfigurierbare Dokumenttexte
- sortierte Produktpositionen
- Einheitsspalte
- Kartonanzahl auf Lieferschein bei Versandart Lieferung
- dauerhafter Kundenhinweis im Kopfbereich des Lieferscheins
- Die obere Kundennotiz (dauerhafter Lieferschein-Hinweis) wird in beiden Lieferschein-Vorlagen vollständig **fett** dargestellt. Die untere, bestellbezogene Notiz und die bestehende Seitenaufteilung werden dadurch nicht verändert.
- Angebotsfeld **Notizen optional** wird bei Bedarf zusätzlich am Ende des Lieferscheins ausgegeben
- Hinweisbeschriftung passend zur Vorlage:
  - `Hinweis:`
  - `Note:`

Die bestehende Dokumentstruktur wird möglichst unverändert gelassen; zusätzliche Lieferschein-Informationen werden gezielt in das gerenderte Dokument eingefügt.

### Dashboard, Statistik & Warnungen

- Dashboard mit Lager- und Geschäftskennzahlen
- Statistikbereich für Manager
- Diagramme mit Chart.js aus dem lokalen Vite-Bundle (kein externer Chart.js-CDN-Aufruf)
- Bestandswarnungen
- Warnungssuche nach Produkt, Fake Name, Code-Nummer und Lieferant
- Filter nach Warnstatus und „Exakter Wert“
- Excel-Export der aktuell gefilterten Warnungen
- Statusanzeigen für Lagerbestand

### Einstellungen

Administrativ konfigurierbar sind unter anderem:

- Reservierungsdauer
- Angebotsnummern-Schema
- Kundengruppen
- Preisstaffel-Definitionen
- Warnschwelle für niedrigen Bestand
- Standard-Ablaufdatum neuer Chargen
- Login-Darstellung
- Button-/UI-Darstellung
- Dokumentvorlagen
- Datenbank-Backup-Konfiguration für automatische Intervalle und Aufbewahrung

### Datenbank-Backups & Wiederherstellung

Seit **04.10.2026** ist die Admin-Backupverwaltung aus Pull Request **#133** produktiv. Der Funktions-Deploy wurde über Merge-Commit `bcb9f1642768012ea598415135a0ce446aa01a50` ausgerollt und anschließend auf Produktion geprüft.

Umgesetzt:

- ausschließlich **Admins** dürfen Backup- und Restore-Aktionen ausführen
- manuelle vollständige MySQL-Datenbank-Backups über die Einstellungen
- automatische Backups über den Laravel-Scheduler
- unterstützte Intervalle:
  - alle **6 Stunden**
  - alle **12 Stunden**
  - **täglich**
  - **wöchentlich**
- konfigurierbare Aufbewahrung automatischer Backups von **1 bis 90**
- manuelle Backups und Vor-Restore-Sicherheitskopien werden von der automatischen Retention nicht entfernt
- private Ablage unter `storage/app/private/system-backups/`
- vorhandene Backups können im Admin-Bereich aufgelistet, heruntergeladen und gelöscht werden
- Backup-Löschen und Retention sind mit Backup-/Restore-Operationen serialisiert
- Wiederherstellung im Browser nur nach:
  - aktuellem Admin-Passwort
  - exakter Bestätigungsphrase
  - ausdrücklicher Sicherheitsbestätigung
- vor jedem Restore wird automatisch ein **Pre-Restore-Sicherheitsbackup** des aktuellen Datenbankzustands erzeugt
- Restore läuft im Wartungsmodus; ein bereits vorher aktiver Wartungsmodus wird erhalten und nicht versehentlich beendet
- bei Restore-Fehlern wird automatisch versucht, das unmittelbar zuvor erzeugte Sicherheitsbackup zurückzuspielen
- bei fehlgeschlagenem Restore **und** fehlgeschlagenem Rollback bleibt das System aus Sicherheitsgründen im Wartungsmodus
- Backup-Dateien werden vor Veröffentlichung bzw. Restore auf Integrität geprüft:
  - vollständige gzip-Schreibvorgänge
  - erfolgreicher gzip-Abschluss
  - gzip-Integrität
  - Dump-Endemarkierung
  - SHA256
  - vollständiges Manifest
- jedes neue Backup enthält einen Fingerprint des Laravel-Migrationsstands
- Backups ohne passenden Schema-Fingerprint werden vor Wartungsmodus und Datenbankänderung abgelehnt
- schemaändernde Artisan-Kommandos wie `migrate`, `migrate:rollback`, `migrate:reset`, `migrate:refresh`, `migrate:fresh`, `migrate:install` und `db:wipe` verwenden denselben Operations-Lock wie Backup/Restore
- HTTP-Requests und Scheduler-Schreibvorgänge werden über einen Shared/Exclusive-System-Write-Lock koordiniert
- Restore-Requests bleiben außerhalb des exklusiven Restore-Fensters durch den Shared-Lock geschützt
- MySQL-Client-Konfiguration berücksichtigt die effektive Laravel-Verbindung einschließlich `DB_URL`
- konfigurierte MySQL-SSL-CA wird an `mysqldump` und `mysql` weitergegeben
- Dumps werden mit `--set-gtid-purged=OFF` erzeugt
- Datenbank-Sessions werden nach erfolgreichem Restore verworfen; der ausführende Admin wird ausgeloggt
- Audit-Fehler können einen bereits erfolgreich erstellten Backup-, Restore- oder Löschvorgang nicht nachträglich als fehlgeschlagen darstellen
- der Scheduler enthält produktiv:
  - `*/5 * * * * php artisan reservations:release-expired`
  - `* * * * * php artisan system:backup --automatic`

Produktiv bestätigt am **04.10.2026**:

- Pre-Deployment-Datenbanksicherung erfolgreich erstellt und per gzip-Endetest geprüft
- Deployment auf PR #133 erfolgreich
- `php artisan migrate --force`: **Nothing to migrate**
- Route-, Config- und Blade-Cache erfolgreich aufgebaut
- erstes verwaltetes Produktionsbackup erfolgreich erstellt und verifiziert:
  - `lager-manual-20261004-155731-7ce5fe76.sql.gz`
  - Größe: **30,3 KB**
  - SHA256: `aa6b5a2b5da8f9994d40173159f896e97aabf391fac5ccea8372d6f0cca2ffb8`
- Anwendung nach Deployment wieder live
- HTTP antwortet erwartungsgemäß mit **308** auf HTTPS
- Login-Seite ist nach Redirect mit **HTTP 200** erreichbar
- finaler Produktionscheck: **PR #133 PRODUKTIV UND FUNKTIONSFÄHIG**

## Rollen & Rechte

Das System verwendet aktuell fünf Rollen.

### Admin

Superuser. Hat Zugriff auf alle Bereiche und zusätzlich auf administrative Systemeinstellungen sowie Benutzer-/Sicherheitsverwaltung.

### Manager

Operativer Vollzugriff auf die Geschäfts- und Lagerfunktionen, unter anderem Produkte, Chargen, Kunden, Lieferanten, Preise, Angebote, Dokumente und Statistik.

### Lager

Schwerpunkt Lager und Fulfillment:

- Produkte
- Kategorien
- Chargen & FIFO
- Bestand
- Warnungen
- Lieferanten
- übergebene Angebote
- übergebene Filialausgänge
- Statusbearbeitung im Lagerworkflow

### Verkauf

Schwerpunkt Vertrieb:

- Produktvorschau
- Kunden
- Preise
- Angebote und Rechnungen
- Filialausgänge
- Übergabe von Vorgängen an Lager

Nach der Übergabe eines Angebots oder Filialausgangs auf **In Bearbeitung** ist der Vorgang für Verkauf nicht mehr bearbeitbar, bis Lager ihn gegebenenfalls zurückgibt.

### CRM / Kundenpflege

Schwerpunkt Kundenstammdaten. Die Rolle kann Kunden anlegen und bearbeiten, ohne Zugriff auf sensible Lager- oder Administrationsbereiche. Kunden-Detailansicht und Löschen bleiben für CRM gesperrt.

## Suche, Filter & einheitliche Listenansichten

Die Hauptbereiche verwenden ein gemeinsames Bedien- und Darstellungsprinzip:

- einheitliche Such- und Filterleiste
- Suchfeld, Suchbereich, Status-/Gruppen-/Filialfilter und Aktionen auf Desktop in einer Zeile
- responsive Umstellung auf kleinere Displays
- gleiche Feldhöhen, Abstände, Buttons und Tabellenrahmen
- serverseitige Suche
- auswählbare Suchfelder
- „Exakter Wert“
- auf-/absteigende Sortierung über Tabellenüberschriften
- Erhalt der Filterparameter bei Pagination
- bereichsspezifische Filterinhalte bei gleichem Layout

Der gemeinsame Standard gilt unter anderem für Produkte, Kategorien, Chargen & FIFO, Angebote, Lager-Angebote, Kunden, Lieferanten, Filialausgänge, Warnungen und die Preisauswahl.

Die Produkt- und Chargensortierung unterstützt auch numerisch benannte Produkte zuverlässig.

## Aktivitätsprotokoll & Sicherheit

- rollenbasierte Middleware
- Rechte werden nicht nur über ausgeblendete Menüeinträge/Buttons umgesetzt
- nicht erlaubte direkte URLs und Aktionen werden serverseitig mit 403 gesperrt
- Create-, Edit-, Delete-, Status-, PDF-/Export- und Zusatzrouten werden je nach Rolle geschützt
- Lager kann keinen neuen Filialausgang über die direkte Create-Route anlegen
- interne Angebotsnotizen sind auf Manager, Verkauf und Lager beschränkt
- Topbar-Schnellaktionen und Produktaktionen berücksichtigen die Rolle
- direkte Rollen-Regressionstests für kritische Zugriffswege
- aktive/deaktivierte Benutzer
- Benutzerverwaltung
- Sicherheitsbereich
- Aktivitätsprotokoll für wichtige Vorgänge
- Passwörter werden über Laravel gehasht
- sensible Umgebungsdaten liegen außerhalb des Repositories
- starke Passwortregeln für administrativ vergebene Passwörter in Produktion
- deaktivierte Benutzer können sich nicht anmelden
- bestehende Sitzungen werden bei Deaktivierung, Rollenwechsel und Passwortänderung widerrufen
- Remember-Tokens werden bei sicherheitsrelevanten Benutzeränderungen ungültig gemacht
- eigene Passwortänderung beendet die aktuelle Sitzung und führt zurück zum Login
- eigener Benutzer kann nicht über die Benutzerverwaltung deaktiviert oder gelöscht werden
- HTTPS ist für die Produktions-IP aktiv
- normale HTTP-Anfragen werden dauerhaft auf HTTPS umgeleitet
- Session-Cookies werden in Produktion mit dem Secure-Flag ausgeliefert
- automatische Zertifikatserneuerung ist eingerichtet und getestet; im Normalbetrieb ist keine manuelle Erneuerung notwendig
- Nginx-Produktversionsnummer wird im HTTP-Header nicht mehr offengelegt
- `X-Content-Type-Options: nosniff` ist aktiv
- `Referrer-Policy: strict-origin-when-cross-origin` ist aktiv
- `X-Frame-Options: SAMEORIGIN` ist aktiv
- konservative `Permissions-Policy` für Kamera, Mikrofon, Geolocation, Payment und USB ist aktiv
- CSP erzwingt für JavaScript bereits `script-src 'self'`; `unsafe-inline` ist für Scripts nicht mehr erforderlich
- Style-CSP befindet sich noch in Phase 4G; `style-src 'self' 'unsafe-inline'` bleibt derzeit nur im Report-Only-Header aktiv
- persönliche Seite **Mein Konto & Sicherheit** nutzt das Premium-Layout
- Benutzer-Bearbeitung nutzt das Premium-Layout mit deutlich sichtbarem Aktiv/Deaktiviert-Status

## Security-Hardening-Status

Stand: **04.10.2026**

Die Security-Arbeiten werden bewusst in getrennten Phasen mit Sicherungen, isolierten Tests und Rückfallpunkten durchgeführt. Ziel ist, Sicherheitsverbesserungen ohne unnötige Unterbrechung des Produktionssystems einzuführen.


### Aktueller Produktions- und CSP-Stand

Maßgeblich ist der produktive Stand vom **04.10.2026** im Repository **`IT-Kayali/Lager-System`**. Zusätzlich zum weiterhin gültigen CSP-/Security-Stand ist die vollständige Admin-Backupverwaltung aus Pull Request **#133** produktiv ausgerollt und technisch geprüft. Die weiter unten aufgeführten Unterphasen dokumentieren teilweise bewusst den jeweiligen historischen Zwischenstand zum damaligen Datum.

Aktuell produktiv bestätigt:

- HTTPS ist aktiv; HTTP wird mit **308** auf HTTPS umgeleitet
- Session-Cookies werden in Produktion mit dem Secure-Flag ausgeliefert
- Nginx, PHP-FPM, TLS und automatische Zertifikatserneuerung sind funktionsfähig
- die risikoarmen Security-Header sind aktiv
- CSP-Reporting an `/api/csp-report` ist aktiv
- JavaScript-CSP ist vollständig verschärft: `script-src 'self'` wird im echten Enforcement erzwungen
- in den getrackten produktiven Browser-Views wurden echte Inline-Scripts, Inline-Event-Handler und `javascript:`-URLs auf **0** reduziert
- Style-CSP ist noch in Arbeit; `style-src` wird noch **nicht** scharf erzwungen
- Admin-Backupverwaltung mit manuellen und automatischen MySQL-Backups ist produktiv
- Browser-Restore mit Pre-Restore-Sicherheitsbackup, Integritätsprüfung, Schema-Fingerprint, Locking und automatischem Rollback ist produktiv
- Scheduler für automatische Backups ist produktiv aktiv
- echter Produktions-Backup-/Restore-E2E wurde vor Merge erfolgreich geprüft; nach Deployment wurde zusätzlich ein echtes Produktionsbackup erstellt und verifiziert

Aktueller Enforcement-Header:

```text
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
script-src 'self';
connect-src 'self';
frame-src 'self';
img-src 'self' data:;
font-src 'self' data:;
media-src 'self';
worker-src 'self' blob:;
manifest-src 'self';
```

Der parallele Report-Only-Header bleibt für Styles bewusst toleranter:

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'self';
form-action 'self';
img-src 'self' data:;
font-src 'self' data:;
style-src 'self' 'unsafe-inline';
script-src 'self';
connect-src 'self';
frame-src 'self';
media-src 'self';
worker-src 'self' blob:;
manifest-src 'self';
```

`style-src 'self' 'unsafe-inline'` bleibt ausschließlich so lange bestehen, bis Phase 4G die noch vorhandenen Inline-Styles und dynamischen Style-Mutationen kontrolliert bereinigt und im Browser validiert hat.

### Phase 1 – Security Baseline ✅ abgeschlossen

Umgesetzt und über Pull Request **#86** ausgerollt:

- starke Passwortanforderungen für administrativ vergebene Passwörter in Produktion
- Selbstlöschung des aktuell angemeldeten Benutzers aus UI und Backend entfernt
- technische Exception-Details in ausgewählten Benutzerpfaden durch generische Fehlermeldungen ersetzt
- tatsächliche Exceptions werden weiterhin intern gemeldet
- zusätzliche Tests für die geänderten Sicherheitsregeln
- Linter und Test-Suite erfolgreich

### Phase 2 – Session- und Benutzer-Härtung ✅ abgeschlossen

Umgesetzt und über Pull Request **#87** ausgerollt:

- deaktivierte Benutzer werden bereits beim Login abgewiesen
- bereits angemeldete, später deaktivierte Benutzer verlieren ihre Sitzung
- Rollenänderung widerruft bestehende Sitzungen des betroffenen Benutzers
- Passwortänderung durch Admin widerruft bestehende Sitzungen
- Deaktivierung widerruft bestehende Sitzungen
- Benutzerlöschung widerruft Sitzungen vor der Löschung
- Remember-Token wird bei Session-Widerruf invalidiert
- eigene Passwortänderung meldet den Benutzer anschließend ab
- Benutzerformular zeigt einen eindeutigen Status **Aktiv / Deaktiviert**
- eigener Benutzer kann nicht deaktiviert werden
- Tests für Login-Sperre, Session-Widerruf und Security-UI ergänzt
- Linter und Test-Suite erfolgreich

### Phase 3 – HTTPS / TLS ✅ abgeschlossen

Die HTTPS-Umstellung wurde absichtlich mehrstufig durchgeführt, damit HTTP während der Vorbereitung weiter funktionierte und jederzeit ein Rückfall möglich blieb.

Umgesetzter Produktionsstand:

- vollständige Nginx- und Laravel-Sicherungen vor den einzelnen Umschaltpunkten
- Let’s-Encrypt-Zertifikat direkt für die öffentliche IP **217.154.248.134**
- Certbot **5.8.0**
- ACME-Verfahren über **webroot**
- zuerst erfolgreicher Let’s-Encrypt-Staging-Test
- danach echtes öffentlich vertrauenswürdiges Zertifikat
- HTTPS zunächst parallel zu HTTP auf Port 443 aktiviert und separat getestet
- Laravel anschließend auf `APP_URL=https://217.154.248.134` umgestellt
- `SESSION_SECURE_COOKIE=true`
- HTTP wird dauerhaft mit **308** auf HTTPS umgeleitet
- ACME-Challenges unter `/.well-known/acme-challenge/` bleiben direkt über HTTP erreichbar
- TLS-Zertifikatsprüfung erfolgreich (`ssl_verify_result = 0`)
- Certbot-Snap-Renewal-Timer ist aktiviert
- Deploy-Hook prüft vor dem Reload zuerst `nginx -t` und lädt Nginx anschließend graceful neu
- `certbot renew --dry-run --run-deploy-hooks` erfolgreich getestet
- Nginx und PHP-FPM blieben während der Umstellung aktiv

Wichtig: Das eingesetzte IP-Zertifikat verwendet das Let’s-Encrypt-Profil **shortlived**. Die automatische Erneuerung ist deshalb Bestandteil des Betriebs und darf nicht entfernt werden.

### Phase 4 – Security Header / CSP 🔄 in Arbeit

> Hinweis: Die Unterphasen 4A bis 4E beschreiben den jeweiligen damaligen Zwischenstand. Der aktuelle maßgebliche CSP-Stand steht im Abschnitt **Aktueller Produktions- und CSP-Stand**.

Bestandsaufnahme am 07.09.2026:

- HTTPS funktioniert
- Session-Cookies besitzen das Secure-Flag
- zu Beginn wurden noch keine zusätzlichen Security-Header wie HSTS, CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy oder Permissions-Policy ausgeliefert
- Nginx gab zu Beginn seine konkrete Server-Version über den `Server`-Header preis
- Statistik lädt Chart.js aktuell über `https://cdn.jsdelivr.net/npm/chart.js`
- im Projekt existieren Inline-/Data-SVG-Ressourcen
- deshalb darf eine Content-Security-Policy nicht blind scharf aktiviert werden

#### Phase 4A – risikoarme Basis-Header ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell geprüft:

- vor der Änderung vollständige Nginx-/Laravel-Sicherung als Rückfallpunkt erstellt
- `server_tokens off` aktiviert
- der `Server`-Header enthält keine konkrete Nginx-Version mehr
- `X-Content-Type-Options: nosniff` aktiviert
- `Referrer-Policy: strict-origin-when-cross-origin` aktiviert
- HTTPS, TLS, HTTP→HTTPS-Redirect und ACME-Challenge nach der Änderung technisch geprüft
- Nginx-Konfiguration vor Reload mit `nginx -t` validiert
- nur graceful Nginx-Reload, kein unnötiger Dienst- oder Serverneustart
- Login, Dashboard, Statistik, Angebote, Filialausgänge, Benutzerverwaltung und PDF-Funktionen manuell geprüft

#### Phase 4B – Frame-Schutz & Permissions-Policy ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell geprüft:

- `X-Frame-Options: SAMEORIGIN` aktiviert
- konservative `Permissions-Policy` aktiviert:
  - `camera=()`
  - `microphone=()`
  - `geolocation=()`
  - `payment=()`
  - `usb=()`
- Clipboard wurde bewusst nicht gesperrt
- HTTPS, TLS, HTTP→HTTPS-Redirect und ACME-Challenge nach der Änderung technisch geprüft
- Nginx-Konfiguration vor Reload mit `nginx -t` validiert
- nur graceful Nginx-Reload, kein unnötiger Dienst- oder Serverneustart
- Browser-Funktionstest erfolgreich

Zusätzlich wurde die Sicherheitsoberfläche konsolidiert:

- persönliche Seite **Mein Konto & Sicherheit** in das Alowidat-Premium-Layout integriert
- alte Laravel-Starter-Kit-Navigation auf der Sicherheitsseite entfernt
- direkter Sidebar-Link **Mein Konto** ergänzt
- Benutzer-Bearbeitung komplett in das helle Premium-Layout überführt
- Aktiv/Deaktiviert-Status bleibt ausdrücklich sichtbar und bearbeitbar
- optionaler Passwortwechsel bleibt erhalten
- bestehender Session-Widerruf bei Status-, Rollen- und Passwortänderungen bleibt unverändert
- globales Login-CSS wurde auf die echte Login-Seite begrenzt, damit Sicherheitsformulare nicht mehr fälschlich im dunklen Login-Stil dargestellt werden
- Linter und Test-Suite erfolgreich
- Live-Funktionstest erfolgreich

#### Phase 4C – CSP Report-Only & Browser-Reporting ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und technisch sowie im Browser geprüft:

- `Content-Security-Policy-Report-Only` aktiviert; die Policy blockiert noch keine Anwendungsausführung
- Reporting-Ziel über `Reporting-Endpoints` eingerichtet
- zusätzlich `report-uri /api/csp-report` und `report-to csp-endpoint` für Browser-Kompatibilität gesetzt
- eigener stateless Laravel-Endpunkt `POST /api/csp-report`
- Endpoint ist rate-limitiert und akzeptiert nur begrenzte Report-Größen
- CSP-Reports werden in einer eigenen Logdatei mit kurzer Aufbewahrung gespeichert
- Querystrings und URL-Fragmente werden vor dem Logging entfernt
- Legacy-`report-uri`-Payloads und moderne Reporting-API-Payloads werden unterstützt
- echter Firefox-Report erfolgreich getestet
- bei normaler Nutzung von Dashboard, Produkten, Chargen, Filialausgängen, Angeboten, Kunden, Lieferanten sowie Sicherheitsseiten wurden keine echten CSP-Verstöße der Anwendung beobachtet
- HTTPS, TLS, HTTP→HTTPS, ACME, Nginx und PHP-FPM blieben funktionsfähig
- CSP bleibt bewusst **Report-Only**; noch keine scharfe Enforcement-Policy

HSTS wird vorerst zurückgestellt, solange die Anwendung ausschließlich über die IP-Adresse betrieben wird. Eine spätere Domain-Einführung ist der passende Zeitpunkt für eine erneute HSTS-Bewertung.

#### Phase 4D – externe Frontend-Abhängigkeiten reduzieren ✅ abgeschlossen

Am 08.09.2026 produktiv umgesetzt und geprüft:

- Chart.js wird ausschließlich aus dem lokalen Vite-Bundle geladen
- der zusätzliche externe Aufruf über `https://cdn.jsdelivr.net/npm/chart.js` wurde entfernt
- Statistik und Diagramm **Umsatzentwicklung** funktionieren weiterhin
- Browser-Netzwerkanalyse mit Filter `jsdelivr` blieb leer
- die externe Freigabe `https://cdn.jsdelivr.net` wurde anschließend aus `script-src` der CSP-Report-Only-Policy entfernt
- die CSP bleibt weiterhin **Report-Only** und blockiert noch keine Ressourcen
- CSP-Reporting blieb funktionsfähig
- nach der Änderung wurden keine neuen echten Anwendungs-Verstöße gemeldet
- der einzige neue Eintrag war der absichtlich erzeugte Test-Report `https://blocked.invalid/phase4d1.js`
- Nginx-Konfiguration wurde vor Aktivierung mit `nginx -t` geprüft
- nur graceful Nginx-Reload, kein unnötiger Neustart
- Preview-Worktree und temporärer Read-only-Datenbankbenutzer wurden nach erfolgreicher Live-Prüfung vollständig entfernt
- `unsafe-inline` bleibt vorerst erhalten, weil im Projekt noch zahlreiche Inline-Scripts und Inline-Styles existieren

Noch **nicht vollständig** aktiviert:

- HSTS
- vollständige scharfe CSP für Script-, Style-, Bild-, Font-, Connect- und weitere Ressourcen-Direktiven

#### Phase 4E.1 – konservativer CSP-Enforcement-Pilot ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und anschließend manuell bestätigt:

- zusätzlich zur bestehenden vollständigen `Content-Security-Policy-Report-Only` wird erstmals eine echte `Content-Security-Policy` ausgeliefert
- scharf erzwungen werden ausschließlich die risikoarmen Direktiven:
  - `base-uri 'self'`
  - `object-src 'none'`
  - `frame-ancestors 'self'`
  - `form-action 'self'`
- Script-, Style-, Bild-, Font-, Connect-, Frame-, Media-, Worker- und Manifest-Regeln bleiben weiterhin vollständig im Report-Only-Modus
- `unsafe-inline` wird deshalb weiterhin nicht scharf erzwungen bzw. entfernt
- vor der Aktivierung wurde der Rollback-Punkt `/var/backups/lager-phase4-M-20260908-203006` erstellt
- Nginx-Konfiguration wurde vor und nach der Änderung erfolgreich mit `nginx -t` geprüft
- Aktivierung erfolgte nur per graceful Nginx-Reload
- HTTPS blieb mit gültiger TLS-Verifizierung erreichbar
- HTTP-zu-HTTPS-Weiterleitung blieb bei `308`
- ACME-Challenge und automatischer Zertifikatsbetrieb blieben funktionsfähig
- CSP-Report-Endpunkt blieb mit HTTP `204` funktionsfähig
- nach dem Pilot wurden keine echten neuen CSP-Verstöße der Anwendung protokolliert; der neue `blocked.invalid/phase4e.js`-Eintrag war ein absichtlicher Test
- Login, Formulare und die zentralen Lager-Funktionen wurden anschließend manuell produktiv geprüft und als funktionierend bestätigt

#### Phase 4E.2 – `connect-src 'self'` im Enforcement ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell bestätigt:

- die bestehende scharfe CSP wurde um `connect-src 'self'` erweitert
- damit dürfen Browser-Verbindungen wie `fetch`, Livewire-/AJAX-Aufrufe und vergleichbare Verbindungen nur noch zum eigenen Origin aufgebaut werden
- die vollständige Ressourcen-Policy bleibt parallel weiterhin als `Content-Security-Policy-Report-Only` aktiv
- Script-, Style-, Bild-, Font-, Frame-, Media-, Worker- und Manifest-Regeln bleiben weiterhin Report-Only
- vor der Aktivierung wurde erneut ein eigener Rollback-Punkt der Phase 4 angelegt
- Nginx wurde ausschließlich per graceful Reload neu geladen
- HTTPS/TLS, HTTP-zu-HTTPS-Weiterleitung, ACME und CSP-Report-Endpunkt blieben funktionsfähig
- nach dem manuellen Live-Test wurde kein echter neuer `connect-src`-Verstoß der Anwendung protokolliert
- der Eintrag `https://blocked.invalid/phase4e2-connect` war ein absichtlicher Test des Report-Endpunkts
- zentrale JavaScript-/AJAX-Funktionen und die Lageroberfläche wurden anschließend produktiv als funktionierend bestätigt

#### Phase 4E.3 – `frame-src 'self'` im Enforcement ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell bestätigt:

- die bestehende scharfe CSP wurde um `frame-src 'self'` erweitert
- eingebettete Frames dürfen damit nur noch vom eigenen Origin geladen werden
- im aktuellen Projekt wurden vor der Aktivierung keine eingebetteten `iframe`-Abhängigkeiten gefunden
- die vollständige Ressourcen-Policy bleibt parallel weiterhin als `Content-Security-Policy-Report-Only` aktiv
- Script-, Style-, Bild-, Font-, Media-, Worker- und Manifest-Regeln bleiben weiterhin Report-Only
- vor der Aktivierung wurde erneut ein eigener Rollback-Punkt der Phase 4 angelegt
- Nginx wurde ausschließlich per graceful Reload neu geladen
- HTTPS/TLS, HTTP-zu-HTTPS-Weiterleitung, ACME und CSP-Report-Endpunkt blieben funktionsfähig
- nach dem manuellen Live-Test wurde kein echter neuer `frame-src`-Verstoß der Anwendung protokolliert
- der Eintrag `https://blocked.invalid/phase4e3-frame` war ein absichtlicher Test des Report-Endpunkts
- Dashboard, Angebote, Filialausgang, Einstellungen, Rechte & Sicherheit, Statistik sowie PDF-/Lieferschein-Funktionen wurden anschließend produktiv als funktionierend bestätigt

#### Phase 4E.4 – `img-src 'self' data:` im Enforcement ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell bestätigt:

- die bestehende scharfe CSP wurde um `img-src 'self' data:` erweitert
- Bilder dürfen damit nur noch vom eigenen Origin oder als `data:`-Ressource geladen werden
- Login-Logo, Login-Hintergrund, Sidebar-Logo, Favicon und Dokumentvorlagen-Vorschauen werden weiterhin korrekt aus internen Routen bzw. lokalem Storage geladen
- CSS-SVGs als `data:image` bleiben ausdrücklich erlaubt
- die vollständige Ressourcen-Policy bleibt parallel weiterhin als `Content-Security-Policy-Report-Only` aktiv
- Script-, Style-, Font-, Media-, Worker- und Manifest-Regeln bleiben weiterhin Report-Only
- vor der Aktivierung wurde erneut ein eigener Rollback-Punkt der Phase 4 angelegt
- Nginx wurde ausschließlich per graceful Reload neu geladen
- HTTPS/TLS, HTTP-zu-HTTPS-Weiterleitung, ACME und CSP-Report-Endpunkt blieben funktionsfähig
- nach dem manuellen Live-Test wurde kein echter neuer `img-src`-Verstoß der Anwendung protokolliert
- der Eintrag `https://blocked.invalid/phase4e4-image.png` war ein absichtlicher Test des Report-Endpunkts
- Logos, Hintergründe, Favicons, Dokumentvorlagen-Vorschauen sowie die zentralen Lagerfunktionen wurden anschließend produktiv als funktionierend bestätigt

#### Phase 4E.5 – `font-src 'self' data:` im Enforcement ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell bestätigt:

- die bestehende scharfe CSP wurde um `font-src 'self' data:` erweitert
- Browser-Schriften dürfen damit nur noch vom eigenen Origin oder als `data:`-Ressource geladen werden
- Bootstrap Icons und die per Vite gebündelten Frontend-Schriften funktionieren weiterhin
- vor der Aktivierung wurden keine externen Google-/Bunny-Font-Aufrufe in den Browser-Ressourcen gefunden
- PDF-Schriften werden serverseitig aus lokalen Dateien geladen und sind von der Browser-CSP nicht betroffen
- die vollständige Ressourcen-Policy bleibt parallel weiterhin als `Content-Security-Policy-Report-Only` aktiv
- Script-, Style-, Media-, Worker- und Manifest-Regeln bleiben weiterhin Report-Only
- vor der Aktivierung wurde erneut ein eigener Rollback-Punkt der Phase 4 angelegt
- Nginx wurde ausschließlich per graceful Reload neu geladen
- HTTPS/TLS, HTTP-zu-HTTPS-Weiterleitung, ACME und CSP-Report-Endpunkt blieben funktionsfähig
- nach dem manuellen Live-Test wurde kein echter neuer `font-src`-Verstoß der Anwendung protokolliert
- der Eintrag `https://blocked.invalid/phase4e5-font.woff2` war ein absichtlicher Test des Report-Endpunkts
- Schriften, Icons, Layout und die zentralen Lagerfunktionen wurden anschließend produktiv als funktionierend bestätigt

#### Phase 4E.6 – `media-src 'self'` im Enforcement ✅ abgeschlossen

Am 08.09.2026 produktiv aktiviert und manuell bestätigt:

- die bestehende scharfe CSP wurde um `media-src 'self'` erweitert
- Audio- und Video-Ressourcen dürfen damit nur noch vom eigenen Origin geladen werden
- vor der Aktivierung wurden im aktuellen Projekt keine Browser-Abhängigkeiten über `<audio>`, `<video>`, `MediaSource`, `Audio()` oder externe Media-Ressourcen gefunden
- die vollständige Ressourcen-Policy bleibt parallel weiterhin als `Content-Security-Policy-Report-Only` aktiv
- Script-, Style-, Worker- und Manifest-Regeln bleiben weiterhin Report-Only
- vor der Aktivierung wurde erneut ein eigener Rollback-Punkt der Phase 4 angelegt
- Nginx wurde ausschließlich per graceful Reload neu geladen
- HTTPS/TLS, HTTP-zu-HTTPS-Weiterleitung, ACME und CSP-Report-Endpunkt blieben funktionsfähig
- nach dem manuellen Live-Test wurde kein echter neuer `media-src`-Verstoß der Anwendung protokolliert
- der Eintrag `https://blocked.invalid/phase4e6-media.mp3` war ein absichtlicher Test des Report-Endpunkts
- Dashboard, Angebote, Filialausgang, Einstellungen, Statistik sowie PDF-/Lieferschein-Funktionen wurden anschließend produktiv als funktionierend bestätigt

#### Phase 4F – JavaScript-CSP ✅ abgeschlossen

Bis zum 18.09.2026 wurde der JavaScript-Anteil der CSP vollständig bereinigt und anschließend scharf aktiviert.

Wesentliche Schritte:

- Inline-Scripts aus den produktiven Browser-Views in externe, selbst gehostete Runtime-Dateien verschoben
- Angebotseditor-Runtime über Pull Request **#122** externalisiert
- Produkt- und Filialausgang-Runtimes über Pull Request **#123** externalisiert
- letzte Inline-Runtimes aus Premium-Layout und Sidebar über Pull Request **#124** externalisiert
- getrackte produktive Blade-Views anschließend erneut auf echte Inline-Scripts, Inline-Event-Handler und `javascript:`-URLs geprüft
- Ergebnis der finalen JavaScript-Inventur: **0** echte Inline-Scripts, **0** Inline-Event-Handler, **0** `javascript:`-URLs
- `script-src 'self'` zuerst im Report-Only-Modus unter realer Browser-Nutzung geprüft
- nach sauberer Report-Auswertung anschließend `script-src 'self'` in das echte CSP-Enforcement übernommen
- Style-CSP wurde dabei ausdrücklich nicht gleichzeitig verschärft

Damit ist **JavaScript-CSP abgeschlossen**. Ein späterer Style-CSP-Schritt darf deshalb nicht mit der bereits abgeschlossenen Script-Härtung vermischt werden.

#### Phase 4G – Style-CSP 🔄 in Arbeit

Die Style-Bereinigung wird getrennt und in kleinen, browsergetesteten Paketen durchgeführt.

Ausgangsinventur 4G.1:

- **99** getrackte Blade-Dateien
- davon **94** Browser-Views und **5** PDF-Views
- ursprünglich **53** `<style>`-Blöcke in Browser-Views
- **301** `style=""`-Attribute
- davon **14** dynamische Style-Attribute
- **29** JavaScript-Style-Mutationen
- PDF-Views werden separat bewertet, weil serverseitig erzeugte PDFs nicht automatisch Browser-CSP-Blocker sind

Bereits abgeschlossen:

- **4G.2A / PR #125:** Dashboard-CSS aus `resources/views/dashboard.blade.php` nach `public/css/dashboard.css` ausgelagert
- Dashboard-Stylesheet wird über einen optionalen Premium-Head-Slot vor dem Body geladen
- ein im ersten Preview entdeckter FOUC wurde dadurch behoben und anschließend im Browser bestätigt
- **4G.2B / PR #126:** Login-/Browser-Branding-CSS aus `resources/views/partials/browser-branding.blade.php` nach `public/css/browser-branding.css` ausgelagert
- Login-Branding, Hintergrund, Logo, Favicon und weiße Hilfstexte anschließend im Browser geprüft
- **4G.2C / PR #129:** gemeinsame Statusfarben aus `resources/views/partials/unified-status-colors.blade.php` nach `public/css/unified-status-colors.css` ausgelagert und den alten Inline-Partial entfernt
- die Status-CSS wird jetzt direkt im `<head>` des Premium-Layouts geladen
- ein im ersten Preview sichtbarer Farbwechsel beim Laden der Angebotsseite wurde behoben: die finalen `status-unified-*`-Klassen werden für Angebote, Lager-Angebote und Filialausgänge bereits serverseitig im ersten HTML ausgegeben
- `csp-shared-runtime.js` bleibt nur noch als Absicherung für später dynamisch eingefügte Status-Badges zuständig
- der korrigierte 4G.2C-Preview wurde im Browser mit Hard-Reloads geprüft; der vorher sichtbare kurze Wechsel von gelb auf grau/blau/grün trat danach nicht mehr auf
- **4G.2 Sammelpaket / PR #130:** alle danach noch verbliebenen **50 produktiven `<style>`-Blöcke** in einem gemeinsamen Feature externalisiert
- statische Styles wurden in same-origin CSS-Dateien unter `public/css/csp/` verschoben und aus dem Premium-Layout abhängig vom jeweiligen Bereich im `<head>` geladen
- die dynamischen Button-Farben werden jetzt über den same-origin Endpunkt `/application-theme.css` ausgeliefert
- das dynamische Login-Design einschließlich konfigurierbarem Hintergrund wird jetzt über den same-origin Endpunkt `/login-styles.css` ausgeliefert
- ein eigener Regressionstest fordert für produktive Browser-Views **0** verbleibende Inline-`<style>`-Blöcke
- `git diff --check`, PHP-Syntaxprüfungen, Blade-/Route-Cache, Erreichbarkeit aller neuen CSS-Dateien sowie Nginx/PHP-FPM wurden im Produktions-Preview erfolgreich geprüft
- der gebündelte Browser-Rundgang über die zentralen ERP-Bereiche wurde anschließend manuell als funktionierend bestätigt

Damit verbleiben in den **produktiven Browser-Views 0 `<style>`-Blöcke**. Ein einzelner `<style>`-Block in der nicht produktiv gerouteten Laravel-`welcome.blade.php` bleibt bewusst außerhalb der produktiven CSP-Inventur.

#### Phase 4G.3 / PR #131 – Inline-Style-Attribute und CSSOM-Mutationen ✅ browsergetestet

Nach Abschluss von 4G.2 wurden die verbliebenen Style-Attribute und direkten JavaScript-Style-Mutationen gebündelt bereinigt:

- die unmittelbare 4G.3-Baseline bestätigte **302 getrackte `style=`/`:style=`-Treffer** in produktiven Blade-Views
- zusätzlich vorhandene **14 Treffer in ignorierten lokalen Backup-Kopien** wurden separat aus dem produktiven View-Baum verschoben und gehörten nicht zum Git-Stand
- nach der Bereinigung enthalten produktive Browser-Views **0 Inline-`style=`/`:style=`-Attribute**
- produktive Browser-Views enthalten weiterhin **0 Inline-`<style>`-Blöcke**
- statische Style-Zuordnungen werden über `public/css/csp/inline-attributes.css` bereitgestellt
- dynamische Style-Zustände werden über `public/css/csp/dynamic-attributes.css` und deklarative Datenattribute abgebildet
- die geprüften Browser-Runtimes verwenden keine direkten `.style...`-Mutationen, kein `setAttribute('style', ...)` und erzeugen keine dynamischen `<style>`-Elemente
- die CSP-Regressionstests liefen mit **17 bestandenen Tests und 198 Assertions** erfolgreich
- Vite-Produktionsbuild, Blade-Cache, Route-Cache, Nginx, PHP-FPM und die neuen CSS-Assets wurden erfolgreich geprüft
- beim ersten Live-Preview auf Commit `566b6f049ee20b40134e0e74fa13016f4eb586aa` wurde eine abgeschnittene `public/js/offers-editor-runtime.js` entdeckt; dadurch funktionierten das automatische Anlegen der nächsten Angebotsposition und die Versandblock-Positionierung nicht
- der fehlende Runtime-Dateischluss wurde anschließend aus dem stabilen Base-Stand wiederhergestellt; `node --check` ist danach erfolgreich und die beiden betroffenen Angebotsfunktionen wurden im Browser erneut ausdrücklich als funktionierend bestätigt
- ein zusätzlicher Regressionstest prüft jetzt JavaScript-Syntax sowie die Kernmarker für Auto-Position, Versandblock-Positionierung und Angebotsvalidierung

Wichtig: `style-src 'self'` wird mit 4G.3 noch **nicht** scharf aktiviert. Der nächste Schritt ist ein strenger **Report-Only-Test mit `style-src 'self'` ohne `'unsafe-inline'`** unter realer Browser-Nutzung. Erst bei sauberem Ergebnis wird Style-CSP für Enforcement bewertet.

### Was noch fehlt – Pflichtreihenfolge

Die folgenden Punkte gelten als **Pflichtprogramm** und werden vor optionalen Zusatzhärtungen abgearbeitet:

1. **Phase 4G vollständig abschließen:** produktive `<style>`-Blöcke, Inline-`style=`/`:style=`-Attribute und die geprüften direkten JavaScript-Style-Mutationen sind jetzt bei **0**; als Nächstes `style-src 'self'` ohne `'unsafe-inline'` streng im Report-Only-Modus testen und bei sauberem Ergebnis in das Enforcement übernehmen.
2. **CSP final konsolidieren:** Enforcement und Report-Only auf Konsistenz prüfen, reale CSP-Reports auswerten und die zugehörigen Regressionstests vervollständigen.
3. **Login / Session / CSRF / 2FA / Rollen prüfen:** Fortify-/2FA-Konfiguration, CSRF-Schutz, Session-Verhalten, Least-Privilege und negative Zugriffstests für direkte URLs und sensible Aktionen.
4. **Produktionskonfiguration prüfen:** insbesondere `APP_DEBUG=false`, Secure Cookies, produktive Cache-/Environment-Konfiguration und fehlende Debug-Ausgaben.
5. **Dateisystem und `.env` prüfen:** Owner, Rechte, Webroot, `storage`, `bootstrap/cache` und Schutz sensibler Konfigurationsdateien.
6. **Upload-Sicherheit prüfen:** Logos, Hintergründe und weitere Uploads auf Dateityp, MIME, Größe, Ablageort und öffentliche Erreichbarkeit prüfen.
7. **Dependency-Sicherheit prüfen:** `composer audit` und `npm audit`; Updates nur kontrolliert und mit Regressionstests übernehmen.
8. **Backup-Betrieb überwachen:** automatische Sicherungen, Retention, Speicherverbrauch, Audit-Logs und Wiederherstellbarkeit im laufenden Betrieb regelmäßig kontrollieren.
9. **Logs und Datenschutz prüfen:** Logs auf Secrets, Tokens, personenbezogene oder unnötig sensible Daten untersuchen und Aufbewahrung bewerten.
10. **Security-Header final prüfen:** aktive Header, CSP und TLS-Konfiguration noch einmal als Gesamtpaket validieren.
11. **Vollständigen Regressionstest durchführen:** Produkte, Chargen/FIFO, Kunden, Lieferanten, Angebote, PDFs, Filialausgänge, Rollen, Statistik, Warnungen, Einstellungen und Benachrichtigungen.
12. **Security-Dokumentation finalisieren:** diese README sowie `docs/SECURITY_HARDENING_LOG.md` nach Abschluss der technischen Arbeiten auf den endgültigen Stand bringen.
13. **Finalen Produktions-Closeout durchführen:** Git/`main`, Dienste, HTTPS, CSP, Backups, Health und Wiederherstellbarkeit abschließend bestätigen.

### Optional / später

Diese Punkte sind bewusst **nicht** Teil des aktuellen Pflichtprogramms und werden erst danach bewertet:

- HSTS bei späterer Nutzung einer eigenen Domain
- zusätzliche `default-src 'self'`-Konsolidierung im Enforcement, falls danach noch sinnvoll
- spätere Reduzierung oder Entfernung des Report-Only-Headers
- COOP / COEP / CORP
- Subresource Integrity (SRI)
- automatisierte Security-Monitoring-/Alerting-Lösungen
- externer Pentest bzw. zusätzlicher Security-Scan
- automatisierte Dependency-Updates

### Bekannter technischer Punkt für neue Installationen

Die bestehende Produktionsdatenbank ist davon nicht betroffen. Bei einer komplett neuen Datenbank wurde jedoch eine historische Migrationsreihenfolge erkannt, bei der einzelne Fremdschlüssel auf Tabellen verweisen, deren Migration zeitlich später eingeordnet ist. Bis dies separat bereinigt ist, muss ein frischer Deployment-Test besonders kontrolliert durchgeführt werden.

## Sicherheitsprinzip für Produktionsänderungen

Für sicherheitsrelevante Produktionsänderungen gilt:

```text
Ist-Zustand prüfen
  → Sicherung / Rückfallpunkt
  → Änderung möglichst isoliert vorbereiten
  → Syntax-/Konfigurationsprüfung
  → graceful reload statt unnötigem Neustart
  → technischer Test
  → manueller Funktionstest
  → erst danach dauerhafte Aktivierung
```

Produktionsstabilität und Wiederherstellbarkeit haben während der Security-Härtung die gleiche Priorität wie die eigentliche Sicherheitsmaßnahme.

## Tech Stack

### Backend

- PHP **8.3+**
- Laravel **13.7+**
- Livewire **4.1+**
- Laravel Fortify
- DomPDF
- PhpSpreadsheet
- libphonenumber-for-php
- Pest **4** für Tests

### Frontend

- Blade
- JavaScript
- Vite **8**
- Tailwind CSS **4**
- Tom Select **2.6+**
- Chart.js **4.5+**
- Bootstrap Icons
- Exo Font

### Betrieb

- MySQL / MariaDB
- Nginx
- PHP-FPM
- Linux / Ubuntu

## Projektstruktur

Wichtige Bereiche:

```text
app/Http/Controllers/    Controller und Workflows
app/Models/               Eloquent-Modelle
app/Services/             Geschäftslogik und Services
app/Support/              Hilfslogik, Sortierung, Zahlenformatierung
database/migrations/      Datenbankmigrationen
resources/views/pages/    Backend-Oberflächen
resources/views/pdf/      PDF-Grundvorlagen
resources/js/             Frontend-JavaScript
routes/                   Web- und Zusatzrouten
tests/Feature/            Feature-Tests
docs/                     Projektdokumentation
```

## Installation

Die ausführliche Server-Installation befindet sich in:

```text
docs/INSTALLATION.md
```

Kurzfassung für eine neue Umgebung:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Für Produktion werden Composer-Abhängigkeiten üblicherweise ohne Dev-Pakete installiert:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm install
npm run build
php artisan optimize:clear
```

## Deployment bestehender Installation

PHP-/Blade-Änderungen:

```bash
cd /var/www/lager-system
git switch main
git pull --ff-only origin main
php artisan optimize:clear
```

Wenn Migrationen enthalten sind:

```bash
php artisan migrate --force
```

Wenn JavaScript/Vite geändert wurde:

```bash
npm run build
php artisan optimize:clear
```

## Tests

Lokale Entwicklungsumgebung:

```bash
php artisan test
```

Oder über Composer:

```bash
composer test
```

Hinweis: Eine Produktionsinstallation kann mit `composer install --no-dev` betrieben werden. In diesem Fall stehen die Pest-/Dev-Testpakete auf dem Produktionsserver nicht zur Verfügung.

## Repository-Sicherheit

Folgende Dateien und Verzeichnisse gehören nicht in Git:

- `.env`
- `vendor/`
- `node_modules/`
- `storage/app/public/*`
- `storage/logs/*`
- `public/build/`
- SQL-Dumps
- ZIP-/Backup-Dateien
- Zugangsdaten und Secrets

Die Datei `.env.example` darf und soll als Vorlage im Repository bleiben.

## Entwicklungsworkflow

Änderungen werden über eigene Branches und Pull Requests durchgeführt.

### Verbindliche README-Pflege

Die `README.md` ist Bestandteil jeder erfolgreich abgeschlossenen Änderung und muss den tatsächlichen Stand von `main` widerspiegeln.

> **Hinweis – verpflichtend:** Nach **jeder erfolgreichen Änderung** muss die README im Closeout geprüft werden. Sobald sich Funktionen, Security, Betrieb, Deployment, offene Punkte oder Produktionsstatus geändert haben, muss die README **direkt mit aktualisiert** werden. Erfolgreich umgesetzt, aber nicht dokumentiert, gilt als **nicht vollständig abgeschlossen**.

Ab sofort gilt verbindlich:

- nach **jeder erfolgreich getesteten Änderung** wird geprüft, ob Funktionsumfang, Security-Status, Deployment, offene Punkte oder Betriebsabläufe in der README angepasst werden müssen
- wenn die Änderung dokumentationsrelevant ist, wird die README **noch im selben Arbeitsablauf auf GitHub aktualisiert**
- bevorzugt wird die README im **gleichen Feature-/Fix-Branch und Pull Request** aktualisiert, sobald die Änderung erfolgreich validiert wurde
- falls eine Änderung erst nach Merge bzw. direkt in der Produktionskonfiguration final bestätigt werden kann, folgt unmittelbar ein eigener Dokumentations-Commit bzw. Dokumentations-PR
- ein Arbeitspaket gilt erst als vollständig abgeschlossen, wenn Code/Produktionsänderung **und** der dazugehörige README-Stand auf GitHub aktuell sind
- bei Security-Hardening werden zusätzlich erledigte Phasen, aktuelle Enforcement-/Report-Only-Regeln und die verbleibenden Pflichtpunkte fortgeschrieben
- reine interne Refactorings ohne Auswirkungen auf Verhalten, Betrieb, Security oder Projektstatus benötigen keine künstliche Inhaltsänderung; die README-Prüfung findet trotzdem statt

Für die weitere Arbeit mit ChatGPT an diesem Projekt bedeutet das: Nach jeder erfolgreich bestätigten Änderung wird die README-Pflege automatisch als fester Closeout-Schritt mitgeführt und nicht mehr separat vergessen.

Empfohlener Ablauf:

```text
main
  └── feature/... oder fix/...
        └── Änderung vorbereiten
              └── technische Tests / Browser-Test
                    └── README auf aktuellen Stand bringen
                          └── Pull Request / CI
                                └── Merge nach Freigabe
                                      └── Produktions-Deploy / Health-Check
                                            └── README-Stand auf GitHub final bestätigen
```

Dadurch bleibt `main` möglichst stabil, getestete Änderungen können kontrolliert übernommen werden und die Projektdokumentation bleibt synchron mit dem tatsächlichen Produktionsstand.

## Namensbasierte URLs – Oktober 2026

Die Links für Produkte, Kategorien, Kunden und Lieferanten
wurden vereinheitlicht.

### Umsetzung

- Produkte nutzen den tatsächlichen Produktnamen in der URL,
  beispielsweise `/products/1` und `/products/3A`.
- Kategorien verwenden lesbare Slugs aus dem Kategorienamen.
- Lieferanten verwenden Slugs aus dem Firmennamen.
- Kunden verwenden Slugs aus dem Firmennamen.
- Sonderzeichen, Leerzeichen und Schrägstriche werden
  für die URL umgewandelt.
- Gespeicherte Namen bleiben unverändert.
- Vorschau, Bearbeitung und Aktualisierung verwenden
  die jeweilige Model-Routenauflösung.
- Filialausgang und Bestand buchen nutzen für die
  Vorbelegung `?produkt=PRODUKTNAME`.
- Die Formulare und Lagerbuchungen verwenden weiterhin
  die ursprünglichen numerischen Datenbank-IDs.
- Eindeutig auflösbare ältere Links bleiben unterstützt.
- Namenskonflikte werden in den Verwaltungsbereichen
  und beim Produkt-Excel-Import geprüft.

### Validierung und Betrieb

- PHP-Syntax und Laravel-Ansichten geprüft.
- Funktionsprüfung der neuen Links auf dem Live-Server
  vom Betreiber bestätigt.
- Die automatisierten Laravel-Tests konnten auf dem
  Produktivserver nicht ausgeführt werden, weil dort
  der Artisan-Befehl `test` nicht verfügbar ist.
- Automatisierte Tests müssen vor dem Merge in einer
  Entwicklungs- oder CI-Umgebung ausgeführt werden.
- Keine bestehenden Datenbank-IDs, Lagerbestände,
  Chargen oder Zuordnungen umnummeriert.

### PDF-Positionen und Chargen

- Angebote, Rechnungen und Kunden-Lieferscheine (mit und ohne Logo) sowie Filial-Lieferscheine zeigen eine fortlaufende Positionsnummer über alle Seiten. Versandkosten und Leerzeilen werden nicht nummeriert.
- Nur Lieferscheine zeigen unter der Produktbezeichnung in kleiner grauer Schrift die jüngste tatsächlich zugeordnete Charge (Wareneingangsdatum, bei Gleichstand Batch-ID). Kunden-Lieferscheine nutzen die FIFO-Ausgangsbewegungen des konkreten Angebots, Filial-Lieferscheine die gespeicherten Positionszuordnungen. Explizite Kopfspaltenbreiten sorgen für eine schmale Positionsspalte und mehr Platz für Produktnamen.
- Bei offenen, in Bearbeitung befindlichen und abholbereiten Kundenbestellungen wird ohne Lagerbewegungen eine ausdrücklich vorläufige FIFO-Vorschau aus dem aktuellen physischen Bestand angezeigt. Die Vorschau reserviert oder bucht nichts, berücksichtigt keine Reihenfolge anderer Bestellungen und kann sich bis zur Erledigung ändern. Bei unzureichendem Bestand, Storno, abgelaufener Reservierung oder nicht eindeutig rekonstruierbaren historischen Zuordnungen bleibt die Batchzeile leer. Bestehende Kundenbewegungen referenzieren die aktuelle Batch-Stammdatenzeile; gelöschte oder nicht mehr zuordenbare Chargen werden nicht geraten.
- Die dynamischen Kunden-Lieferscheinlayouts aus der bisherigen Produktion einschließlich Bestellnotizen wurden in Git übernommen. Seitenplanung berücksichtigt den zusätzlichen Platz für Batchzeilen; Filial-Lagernotizen und bestehende arabische Darstellung bleiben erhalten.
- Keine Migration, keine Änderung der Lagerbuchung, Reservierung, Preisberechnung oder FIFO-Logik.

### Produktverwaltung: Sammelaktionen und Seitenkontext

- Manager und Lagerpersonal können Produkte der aktuellen Seite über einzelne Checkboxen oder die Kopf-Checkbox markieren, die Aktion „Ausgewählte löschen“ wählen und nach einer Bestätigung anwenden. Die Auswahl ist auf die aktuelle Seite (15 Produkte) begrenzt; der Vertrieb erhält keine Löschrechte.
- Einzel- und Sammellöschen behalten die aktuelle Suchanfrage, das Suchfeld, die Exaktsuche, Sortierung und Seitenzahl bei. Existiert die bisherige Seite nicht mehr, wird auf die letzte verfügbare Seite des gefilterten Ergebnisses zurückgekehrt. Die aktuelle Produktliste hat eine feste Seitengröße von 15 und keinen separaten Kategoriefilter.
- Beide Löschwege verwenden denselben serverseitigen Schutz. Produkte mit Angeboten, Chargen (auch bei Nullbestand), Lagerbewegungen oder Filialausgängen sowie archivierte Produkte werden nicht gelöscht. Bei einer gemischten Auswahl werden nur unbenutzte Produkte gelöscht; geschützte und fehlende Produkte werden separat gemeldet.
- Die Sammelaktion akzeptiert ausschließlich gültige eindeutige Datenbank-IDs und die zugelassene Aktion. CSRF, bestehende Rollenberechtigungen und historische Belege bleiben geschützt. Keine Datenbankmigration oder Änderung der Lagerbuchungslogik.
