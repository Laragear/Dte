---
name: laragear-dte-certification
description: "Use this skill to prepare the application to certification subsequent production (real) environment. Don't use this if the business does not have a real Digital Certificate or is still on active development."
license: MIT
metadata:
  author: laragear
---

# Laragear Dte Certification

The application requires to be _certified_ by SII before real operation on production environments. The library requires a real Digital Certificate (not the fake created by the application) and to follow the certification process. If this has not been set up, use the apropiate skill to do it.  

Once the business complies with _"Pre-Certificación"_, it will receive a `.txt` file with data to create the different sets the SII will require to handle. The set will have an "Attention Number", like `5145080`.

## Prerequisites

1. Truncate the tables: Delete all data from the tables to start with a fresh certification process for the very first time.

```php
use Laragear\Dte\Certification\CertificationManager;

public function start(CertificationManager $manager)
{
    $manager->purgeDatabase();
}
```

2. Visit the [Certification Portal](https://maullin.sii.cl/cvc/dte/certificacion_dte.html) at "Mantención de Usuarios" and allow the user to authorize CAF ("Solicitar Folios").
3. On the same Certification Portal, download a CAF to create the required DTE ("Solicitud de Timbraje Electrónico").(https://maullin.sii.cl/cvc/dte/certificacion_dte.html)).
4. Upload/Load the CAF to the library.

## Certification

Follow the certification documentation `CERTIFICATION.md` at the root if this package. It's usually found in `vendor/laragear/dte/CERTIFICATION.md`.
