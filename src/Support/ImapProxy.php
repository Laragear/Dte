<?php

namespace Laragear\Dte\Support;

use IMAP\Connection;

class ImapProxy
{
    /**
     * Read the header of the message.
     */
    public function headerinfo(Connection $connection, int $uid): object|false
    {
        return imap_headerinfo($connection, $uid);
    }

    /**
     * Read the message body.
     */
    public function body(Connection $connection, int $uid, int $flags = 0): string|false
    {
        return imap_body($connection, $uid, $flags);
    }

    /**
     * Close an IMAP stream.
     */
    public function close(Connection $connection): true
    {
        return imap_close($connection);
    }

    /**
     * Returns an array of messages matching the given search criteria
     *
     * @return int[]|string[]
     */
    public function search(
        Connection $connection,
        string $criteria,
        int $flags = SE_FREE,
        string $charset = '',
    ): array|false {
        return imap_search($connection, $criteria, $flags, $charset);
    }

    /**
     * Sets flags on messages
     */
    public function setflag_full(Connection $connection, string $sequence, string $flag, int $options = 0): true
    {
        return imap_setflag_full($connection, $sequence, $flag, $options);
    }

    /**
     * Open an IMAP stream to a mailbox
     */
    public function open(
        string $mailbox,
        string $user,
        string $password,
        int $flags = 0,
        int $retries = 0,
        array $options = [],
    ): Connection|false {
        return imap_open($mailbox, $user, $password, $flags, $retries, $options);
    }

    /**
     * Gets the last IMAP error that occurred during this page request.
     */
    public function last_error(): string|false
    {
        return imap_last_error();
    }
}
