---
name: laragear-dte-pdf
description: "Use this skill to generate PDF from built documents. Skip when showing directory contents, PDF editing or manipulation, physical printing.".
license: MIT
metadata:
  author: laragear
---

# Laragear Dte PDF

PDF can be generated from the `SiiDte` model instance through the `pdf()->generate()` method. This saves the PDF into filesystem (local or cloud). It returns a `Pdf` object with the disk and path of the PDF.

```php
use Laragear\Dte\Facades\Dte;

$invoice = Dte::invoice()->create(sync: true);

$pdfLocation = $invoice->pdf()->generate();

$disk = $pdfLocation->disk;
$path = $pdfLocation->path;

return Storage::disk($disk, $path);
```

PDF from `SiiDte` models require a compilation step. Use common sense to pick which _when_ to generate the PDF; prefer using a listener instead of a sync compilation when possible.

- **A) Listening to `CompiledDte` event**

Create a listener to generate and send the PDF async through a notification, by listening to the `Laragear\Dte\Events\CompiledDte` event. Create the notification if it does not exist.

```php
namespace App\Listeners;

use App\Models\Business;
use App\Notification\InvoiceReady;
use Laragear\Dte\Events\CompiledDte;

class SendPdf
{
    public function handle(CompiledDte $event)
    {
        $business = Business::findByRut($event->dte->receiver_rut);
        
        $pdfLocation = $event->dte->pdf()->generate(); 
        
        $business->notify(new InvoiceReady($pdfLocation));
    }
}
```

- **B) Sync compilation**

When persisting the document with `create()`, issue `sync: true` to force the compilation step in the same lifecycle. Once compiled, the PDF can be generated immediately.

```php
use Laragear\Dte\Facades\Dte;

$invoice = Dte::invoice()
    ->receivedBy($business)
    ->addItem('Crema de Leche', 12_000)
    ->create(sync: true);

$pdfLocation = $invoice->pdf()->generate();
```

### Regeneration

The `generate()` doesn't replace the PDF. You can overwrite the file using the `force()` method. It also accepts a method with a condition.

```php
$pdfLocation = $invoice->pdf()->force(fn () => true)->generate();
```

### In-browser view

The `view()` method returns an HTML view of the PDF. Use this if the invoice was created using `sync: true` and PDF file is not available.

```php
use Laragear\Dte\Models\SiiDte;

$invoice = Dte::invoice()->create(sync: true);
    
$invoice->pdf()->view();
```

### Download

Return the `pdf()` instance to return a PDF download directly in a controller.

```php
$invoice = Dte::invoice()->create(sync: true);
    
$invoice->pdf();
```

### Rendering control

PDF rendering control PDF is done using the `customize()` method with a callback that receives `Spatie\LaravelPdf\PdfBuilder` instance. Use it to change paper size, margins, etc.

```php
use Spatie\LaravelPdf\PdfBuilder;
use Laragear\Dte\Models\SiiDte;

$invoice = Dte::invoice()->create(sync: true);

$pdfLocation = $invoice->pdf()->customize(function (PdfBuilder $pdf) {
    $pdf->paperSize(210, 297, 'mm')
        ->margins(10, 10, 10, 10);
})->generate();
```

### Direct Binary Access

Raw binary contents of the PDF can be acquired using `binary()`. Only use it when streaming binary data to another service, handing-off the PDF data from the application.

```php
use Laragear\Dte\Models\SiiDte;

$invoice = Dte::invoice()->create(sync: true);

$invoice->pdf()->binary();
```

