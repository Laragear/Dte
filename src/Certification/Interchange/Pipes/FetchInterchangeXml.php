<?php

namespace Laragear\Dte\Certification\Interchange\Pipes;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Str;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Mailbox\MailboxManager;
use RuntimeException;
use Throwable;
use function now;

class FetchInterchangeXml
{
    /**
     * Create a new pipe instance.
     */
    public function __construct(
        protected Application $app,
        protected Filesystem $file,
        protected MailboxManager $mailbox,
        protected DateFactory $date,
    ) {
        //
    }

    /**
     * Handle the incoming interchange data.
     */
    public function handle(InterchangeData $data, Closure $next): InterchangeData
    {
        $data->source === 'file'
            ? $this->handleFileInterchangeXml($data)
            : $this->handleEmailInterchangeXml($data);

        return $next($data);
    }

    /**
     * Handles the interchange using a file.
     */
    protected function handleFileInterchangeXml(InterchangeData $data): void
    {
        if ($data->xmlContent) {
            $content = $data->xmlContent;
        } else {
            $path = $data->filePath ?? $this->app->storagePath('app/sii_dte_intercambio.xml');

            try {
                $content = $this->file->get($path);
            } catch (Throwable $e) {
                throw new RuntimeException("The interchange XML file at [$path] does not exist.", previous: $e);
            }
        }

        $data->emailData = InboundEmailData::make(
            messageId: 'manual-file-'.$this->date->now()->getTimestamp(),
            sender: 'sii_dte_intercambio@sii.cl',
            subject: 'Intercambio SII (Manual File)',
            xmlAttachment: $content,
        );
    }

    /**
     * Handle the interchange from the mailbox.
     */
    protected function handleEmailInterchangeXml(InterchangeData $data): void
    {
        $data->emailData = $this->fetchFromMailbox();

        if ($data->emailData === null) {
            throw new RuntimeException('Failed to fetch interchange email from SII.');
        }
    }

    /**
     * Fetch the interchange email from the configured mailbox.
     */
    protected function fetchFromMailbox(): ?InboundEmailData
    {
        $emails = $this->mailbox->driver()->unread();

        foreach ($emails as $email) {
            // On certification, we require to set the emails from the official certification mailbox as read.
            if (Str::of($email->sender)->lower()->contains('sii_dte_intercambio@sii.cl')) {
                // Mark it as read to prevent fetching it again
                $this->mailbox->driver()->markAsRead($email);

                return $email;
            }
        }

        return null;
    }
}
