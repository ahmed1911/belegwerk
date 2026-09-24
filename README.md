# belegwerk

Leichtgewichtige HTTP-API zum Erzeugen von PDF-Dokumenten und E-Rechnungen.

| Endpunkt | Ergebnis |
| --- | --- |
| `POST /v1/invoices/pdf` | Rechnung als PDF |
| `POST /v1/invoices/xrechnung` | XRechnung 3.0 (UN/CEFACT CII, XML) |
| `POST /v1/invoices/zugferd` | ZUGFeRD 2.x / Factur-X: PDF/A-3b mit eingebetteter XML (`?profile=en16931` Standard, oder `?profile=xrechnung`) |
| `POST /v1/documents/pdf` | Beliebiges HTML (+ optional CSS) als PDF |
| `GET /health` | Status der API und von Gotenberg |

## Bausteine

- **[Gotenberg](https://gotenberg.dev)** rendert HTML mit Chromium zu PDF.
- **[horstoeko/zugferd](https://github.com/horstoeko/zugferd)** (MIT) erzeugt das CII-XML
  (EN 16931 bzw. XRechnung 3), prüft es gegen das XSD, bettet es in das PDF ein und schreibt
  PDF/A-3 samt Factur-X-XMP-Metadaten.
- Eigener Code: JSON-Validierung, Berechnung (Positionen, Steuergruppen, Summen), HTML-Layout und HTTP-Schicht.

## Starten

```sh
docker compose up -d --build        # API auf http://127.0.0.1:8080, Gotenberg auf :3000
```

Lokal ohne Docker für die API (Gotenberg muss laufen):

```sh
composer install
GOTENBERG_URL=http://127.0.0.1:3000 composer serve
composer test
```

Konfiguration über Umgebungsvariablen:

- `GOTENBERG_URL`: Standard `http://127.0.0.1:3000`
- `BELEGWERK_API_KEY`: wenn gesetzt, ist `Authorization: Bearer <key>` Pflicht (außer `/health`)

## Beispiel

```sh
curl -X POST http://127.0.0.1:8080/v1/invoices/zugferd \
  -H 'Content-Type: application/json' \
  --data-binary @examples/invoice.json -o rechnung.pdf
```

## Rechnungs-JSON

Siehe [`examples/invoice.json`](examples/invoice.json). Geldbeträge sind **ganze Cent**, Datumsangaben `YYYY-MM-DD`.
Die API berechnet Positionsbeträge (`round(quantity × unit_price_cents)`), Steuer je Kategorie und Satz
sowie die Summen selbst.

| Feld | Pflicht | Beschreibung |
| --- | --- | --- |
| `number`, `date`, `due_date` | ja | Rechnungsnummer, Rechnungsdatum, Fälligkeit |
| `type_code` | | `380` Rechnung (Standard), `381` Gutschrift, `384` Korrektur, `326` Teilrechnung, … |
| `currency` | | derzeit nur `EUR` |
| `service_date` / `period.start` + `period.end` | | Leistungsdatum bzw. Leistungszeitraum |
| `buyer_reference` | XRechnung | Käuferreferenz, bei öffentlichen Auftraggebern die Leitweg-ID |
| `order_reference`, `payment_terms`, `note` | | Bestellnummer, Zahlungsbedingungen (Text), Einleitungstext |
| `occasion`, `project`, `orderer`, `section_title` | | nur für das PDF-Layout |
| `tax_exemption_reason` | bei Kategorie `E` | Befreiungsgrund, z. B. Kleinunternehmer § 19 UStG |
| `seller.name`, `seller.address` | ja | `address`: `street`, `zip`, `city`, `country` (ISO, Standard `DE`), optional `additional` |
| `seller.vat_id` / `seller.tax_number` | eins von beiden | USt-IdNr. / Steuernummer |
| `seller.email`, `seller.phone` | XRechnung | Kontakt; `email` dient auch als elektronische Adresse |
| `seller.contact_name`, `seller.register`, `seller.legal`, `seller.logo` | | Ansprechpartner, Registereintrag, Fußzeilen-Zeilen, Logo (Base64 PNG/JPEG/SVG) |
| `buyer.name`, `buyer.address.city` | ja | Adresse des Käufers (`zip` bei XRechnung Pflicht) |
| `buyer.email` / `buyer.electronic_address` | XRechnung | E-Mail oder `{"scheme": "0204", "id": "<Leitweg-ID>"}` |
| `buyer.vat_id` | bei `AE`/`K` | USt-IdNr. des Käufers |
| `bank.holder`, `bank.iban` | ja | SEPA-Überweisung; optional `bic`, `name` |
| `items[]` | ja | `description`, `unit_price_cents`, optional `quantity` (Standard 1, max. 3 Nachkommastellen), `name`, `unit`, `unit_code`, `tax_rate` (Standard 19), `tax_category` |

**Einheiten:** `unit` akzeptiert gängige deutsche Bezeichnungen (`Stk`, `Std`, `Tag`, `Monat`, `pauschal`, `km`, `kg`, …)
oder direkt einen UN/ECE-Rec.-20-Code (`H87`, `HUR`, `DAY`, `LS`, …). Unbekannte Einheiten brauchen `unit_code`.

**Steuerkategorien:** `S` (Standard, Satz > 0), `Z` (Nullsatz), `E` (steuerbefreit), `AE` (Reverse Charge),
`K` (innergemeinschaftliche Lieferung), `G` (Ausfuhr). Außer `S` immer mit `tax_rate: 0`.

**Fehler** kommen als JSON: `400` ungültiges JSON, `401` fehlender API-Key, `422` Validierung
(mit `details` pro Feldpfad, z. B. `"items.0.unit_price_cents"`), `502` Gotenberg-Fehler.

## Noch nicht unterstützt

Rabatte/Zuschläge auf Belegebene, Anzahlungen, andere Währungen als EUR, Steuerkategorie `O`,
UBL-Syntax sowie das Einlesen eingehender E-Rechnungen (mit horstoeko/zugferd gut ergänzbar).

## Geprüft

Die erzeugten XML-Dateien (Standard-, Kleinunternehmer-, Reverse-Charge-, Gutschrift- und Negativpositions-Fall) wurden mit den
offiziellen Schematron-Regeln EN 16931 CII 1.3.16 und XRechnung 3.0.2 ohne Fehler validiert,
das ZUGFeRD-PDF mit veraPDF als PDF/A-3b (PASS).
