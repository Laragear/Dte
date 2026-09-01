---
name: laragear-dte-setup
description: "Use this skill to setup the library the first time after install; skip when the project already has a digital certificate at `storage/app/dte/`".
license: MIT
metadata:
  author: laragear
---

# Laragear Dte Setup

To enable handling legal SII DTE (documents) in the application, prepare it to be used with the library in this order:

1. Create a fake certificate: Execute the Artisan command. Infer the project's business RUT, name, otherwise leave blank. The certificate created uses `secret` as default password.

```shell
php artisan dte:make-fake-cert --rut="76.123.456-0" --name="My project business" 
```

2. Create a fake CAF: Execute the Artisan command. Use the same business RUT than the command before, otherwise leave blank. Create for Invoices (Code 33) and Receipt (Code 39).

```shell
php artisan dte:make-fake-caf --rut="76.123.456-0" --type:33
php artisan dte:make-fake-caf --rut="76.123.456-0" --type:39
```

3. Schedule commands for continuous operation. Add the following lines to the `routes/console.php` to schedule the required commands, if these are not present already.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dte:check-cafs')->everyTwoHours();
Schedule::command('dte:process-envelope')->everyTenMinutes();
Schedule::command('dte:fetch-mailbox')->hourly();
Schedule::command('dte:poll-track-status')->hourly();
Schedule::command('dte:reject-phantom-invoices')->twiceDaily();
```

4. If you have access to the Laravel Scheduler and Queue, restart them, otherwise tell the user to restart these manually. 
