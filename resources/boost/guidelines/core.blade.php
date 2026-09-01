# Laragear Dte

This package provides compliance with SII DTE (Documents Tributarios Electrónicos).

- This project uses `laragear/dte` to create SII legal documents: Invoices, Receipts, Debit Note, Credit Note, Dispatch Guides, etc. Create documents using the `Dte` facade, e.g. `Dte::invoice()->...`, `Dte::receipt()->...`.
- Print PDF only when user requires to. Always save them into the filesystem.
- Only the user can move the Laragear Dte environment to `certification` or `production`. Warn the user when changing environment.
