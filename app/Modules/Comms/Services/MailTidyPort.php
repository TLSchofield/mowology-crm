<?php
/**
 * MailTidyPort — the mailbox as the Tidy assistant sees it. The real one is ImapTidyPort
 * (reads read-only through ImapReader, writes only through ImapWriter); tests use a fake.
 *
 * No namespace / no autoloader in production: require_once.
 */
interface MailTidyPort
{
    /** Folder names on the server (IMAP modified UTF-7). @return string[] */
    public function folders(): array;

    /** @return array{messages: int, uidvalidity: int, uidnext: int}|null */
    public function folderInfo(string $folder): ?array;

    /**
     * Envelopes of messages with UID > $afterUid, ascending, at most $limit (no bodies).
     * @return array<int, array{uid: int, from: string, to: string, subject: string, date: string, udate: int, message_id: ?string}>
     */
    public function scan(string $folder, int $afterUid, int $limit): array;

    /** Raw header block of one message (never the text). */
    public function headers(string $folder, int $uid): string;

    /** Plain text of one message, peeked (never marks it read). */
    public function body(string $folder, int $uid): string;

    /** uid => Message-ID for the given UIDs that still exist. @return array<int,string> */
    public function messageIds(string $folder, array $uids): array;

    /** UIDs in a folder carrying this Message-ID. @return int[] */
    public function findByMessageId(string $folder, string $messageId): array;

    public function createFolder(string $name): bool;

    /** @return array{moved: int[], error: ?string} */
    public function move(string $from, array $uids, string $to): array;

    public function close(): void;
}
