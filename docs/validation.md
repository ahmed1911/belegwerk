# Validating e-invoices

## What belegwerk checks

On every `invoice()` and `xrechnung()` call belegwerk checks:

- **Input**: required fields, types, code lists (ISO 3166-1, EAS, UN/ECE Rec. 20, UNTDID 5189/7161), IBANs and the VAT
  rules that need no Schematron engine ([Invoice data](invoice-data.md#fields)); throws a `ValidationException`.
- **[XRechnung requirements](invoice-data.md#xrechnung-requirements)**, for `xrechnung()` only.
- **XSD schema** of the generated XML; throws a `RuntimeException` ([Errors](api.md#errors)).

## What needs the KoSIT validator

The complete business rules (EN 16931, BR-DE) are Schematron rules. They need Java and are not part of belegwerk.
To check your invoices, or incoming invoices from [`read()`](reading-e-invoices.md), run the official
[KoSIT validator](https://github.com/itplr-kosit/validator) with the
[XRechnung configuration](https://github.com/itplr-kosit/validator-configuration-xrechnung).

horstoeko/zugferd, which belegwerk already depends on, includes `ZugferdKositValidator`. It runs the validator itself
or talks to a validator that runs as a daemon. A daemon avoids a JVM start per invoice:

```sh
java -jar validationtool-*-standalone.jar -s scenarios.xml -r "$PWD" -D -G -H 127.0.0.1 -P 8081
```

```php
use horstoeko\zugferd\ZugferdKositValidator;

$validator = (new ZugferdKositValidator($belegwerk->xrechnung($invoice)))
    ->enableRemoteMode()
    ->setRemoteModeHost('127.0.0.1')
    ->setRemoteModePort(8081)
    ->validate();

if ($validator->hasProcessErrors()) {
    // The validator did not run (daemon unreachable, Java missing): getValidationErrors() is empty, nothing was checked.
}
foreach ($validator->getValidationErrors() as $error) {
    // The invoice breaks a rule.
}
```

Without `enableRemoteMode()`, the class downloads the validator and starts Java for each call. The daemon has no
authentication, so keep it reachable only from your application, like Gotenberg
([Security](security.md#gotenberg-settings)).

## Not supported (yet)

- Currencies other than EUR (`read()` does read them).
- VAT categories other than `S`, `Z`, `E`, `AE`, `K` and `G`: `O` (outside the scope of VAT), `L` and `M` are rejected.
- Payment means other than a SEPA credit transfer (`bank`) and a SEPA direct debit (`direct_debit`), so no card or cash
  payment.
- Writing UBL syntax (`read()` reads it). `xrechnung()` creates CII, `invoice()` a ZUGFeRD/Factur-X PDF in the EN 16931
  profile.
