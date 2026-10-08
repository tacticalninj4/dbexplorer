<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Audit;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use TacticalNinj4\DbExplorer\Models\AuditEntry;

/**
 * Writes and verifies the HMAC hash-chained audit log (PRD S17–S19, S22).
 *
 * Each row stores prev_hash and hash = HMAC-SHA256(key, prev_hash . canonical_json(fields)).
 * Inserts are serialized by locking the single dbx_audit_head row, so the chain
 * cannot fork under concurrent writers.
 */
final class AuditLog
{
    /** The prev_hash of the very first entry. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** The action of the checkpoint entry dbx:prune writes before deleting. */
    public const PRUNED = 'pruned';

    /** The columns covered by the hash, i.e. everything except id, prev_hash and hash. */
    private const HASHED = [
        'created_at', 'action', 'user_id', 'role', 'label', 'ip', 'user_agent', 'sql',
        'classification', 'duration_ms', 'row_count', 'outcome', 'error', 'meta',
    ];

    private const STRINGS = [
        'action', 'user_id', 'role', 'label', 'ip', 'user_agent', 'sql', 'classification', 'outcome', 'error',
    ];

    private const INTEGERS = ['duration_ms', 'row_count'];

    public function __construct(private Repository $config, private ConnectionResolverInterface $db)
    {
        //
    }

    /**
     * Append an entry to the chain and forward it to the configured log channel.
     *
     * Keys: action (required), user_id, role, label, ip, user_agent, sql, classification,
     * duration_ms, row_count, outcome (default "success"), error, meta (array).
     *
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): AuditEntry
    {
        $row = $this->normalize($entry);
        $key = $this->key();
        $connection = $this->connection();

        /** @var array<string, int|string|null> $row */
        $row = $connection->transaction(function () use ($connection, $row, $key): array {
            $row['prev_hash'] = $this->lockHead($connection) ?? self::GENESIS;
            $row['hash'] = $this->hash($row['prev_hash'], $row, $key);
            $row['id'] = (int) $connection->table('dbx_audit_log')->insertGetId($row);

            $connection->table('dbx_audit_head')->where('id', 1)->update(['hash' => $row['hash']]);

            return $row;
        }, 3);

        $this->forward($row);

        return (new AuditEntry)->newFromBuilder($row);
    }

    /**
     * Recompute the chain from the oldest remaining entry.
     *
     * @return array{checked: int, broken_id: int|null, reason: string|null}
     */
    public function verify(): array
    {
        $key = $this->key();
        $connection = $this->connection();
        $checked = 0;
        $previous = null;

        foreach ($connection->table('dbx_audit_log')->lazyById(500) as $record) {
            $row = (array) $record;
            $id = $this->toInt($row['id']);
            $prevHash = $this->toString($row['prev_hash']);

            if ($previous === null) {
                if ($prevHash !== self::GENESIS && $prevHash !== $this->pruneAnchor($connection)) {
                    return $this->broken($checked, $id, 'the oldest entry neither starts the chain nor follows the last prune checkpoint');
                }
            } elseif ($prevHash !== $previous) {
                return $this->broken($checked, $id, 'prev_hash does not match the previous entry (an entry was deleted, inserted or reordered)');
            }

            $hash = $this->toString($row['hash']);

            if (! hash_equals($this->hash($prevHash, $row, $key), $hash)) {
                return $this->broken($checked, $id, 'hash does not match the entry (the entry was edited, or the key is wrong)');
            }

            $previous = $hash;
            $checked++;
        }

        $head = $connection->table('dbx_audit_head')->where('id', 1)->value('hash');

        if ((is_string($head) ? $head : self::GENESIS) !== ($previous ?? self::GENESIS)) {
            return $this->broken($checked, null, 'the newest entry does not match dbx_audit_head (entries were deleted from the end)');
        }

        return ['checked' => $checked, 'broken_id' => null, 'reason' => null];
    }

    /**
     * Write a "pruned" checkpoint, then delete every entry created before $before.
     *
     * The checkpoint records the hash of the last deleted entry, which is the
     * anchor verify() expects as the oldest remaining entry's prev_hash.
     *
     * @return int The number of entries deleted.
     */
    public function prune(DateTimeInterface $before): int
    {
        $connection = $this->connection();
        $cutoff = $before->format('Y-m-d H:i:s');

        $last = (array) $connection->table('dbx_audit_log')
            ->where('created_at', '<', $cutoff)
            ->orderByDesc('id')
            ->first(['id', 'hash']);

        if ($last === []) {
            return 0;
        }

        $throughId = $this->toInt($last['id']);
        $query = $connection->table('dbx_audit_log')->where('id', '<=', $throughId);

        $this->record([
            'action' => self::PRUNED,
            'meta' => [
                'before' => $cutoff,
                'through_id' => $throughId,
                'anchor_hash' => $this->toString($last['hash']),
                'entries' => (clone $query)->count(),
            ],
        ]);

        return $query->delete();
    }

    /**
     * Lock the head row and return the hash of the newest entry.
     */
    private function lockHead(ConnectionInterface $connection): ?string
    {
        $head = $connection->table('dbx_audit_head')->where('id', 1)->lockForUpdate()->first();

        if ($head === null) {
            // The seeded row is gone: recreate it from the newest entry, then lock it.
            $connection->table('dbx_audit_head')->insertOrIgnore([
                'id' => 1,
                'hash' => $connection->table('dbx_audit_log')->orderByDesc('id')->value('hash'),
            ]);

            $head = $connection->table('dbx_audit_head')->where('id', 1)->lockForUpdate()->first();
        }

        $hash = ((array) $head)['hash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    private function pruneAnchor(ConnectionInterface $connection): ?string
    {
        $meta = $connection->table('dbx_audit_log')
            ->where('action', self::PRUNED)
            ->orderByDesc('id')
            ->value('meta');

        $meta = is_string($meta) ? json_decode($meta, true) : null;

        return is_array($meta) && is_string($meta['anchor_hash'] ?? null) ? $meta['anchor_hash'] : null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function hash(string $prevHash, array $row, string $key): string
    {
        $fields = [];

        // Values are compared as strings, so a driver returning "5" instead of 5 still verifies.
        foreach (self::HASHED as $field) {
            $value = $row[$field] ?? null;
            $fields[$field] = $value === null ? null : $this->toString($value);
        }

        return hash_hmac('sha256', $prevHash.$this->canonicalJson($fields), $key);
    }

    /**
     * JSON with recursively sorted keys and fixed flags, so equal data always encodes the same.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, int|string|null>
     */
    private function normalize(array $entry): array
    {
        $unknown = array_diff(array_keys($entry), self::STRINGS, self::INTEGERS, ['meta']);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown audit entry keys: '.implode(', ', $unknown).'.');
        }

        if (! is_string($entry['action'] ?? null) || $entry['action'] === '') {
            throw new InvalidArgumentException('An audit entry needs an action.');
        }

        $row = ['created_at' => Date::now()->format('Y-m-d H:i:s')];

        foreach (self::STRINGS as $field) {
            $value = $entry[$field] ?? null;

            if ($value !== null && ! is_scalar($value) && ! $value instanceof Stringable) {
                throw new InvalidArgumentException("The audit entry field [{$field}] must be a string.");
            }

            $row[$field] = $value === null ? null : (string) $value;
        }

        $row['outcome'] = $row['outcome'] ?? 'success';

        foreach (self::INTEGERS as $field) {
            $value = $entry[$field] ?? null;

            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("The audit entry field [{$field}] must be a number.");
            }

            $row[$field] = $value === null ? null : (int) round($value);
        }

        $meta = $entry['meta'] ?? null;

        if ($meta !== null && ! is_array($meta)) {
            throw new InvalidArgumentException('The audit entry field [meta] must be an array.');
        }

        // Stored as canonical JSON text, so it reads back exactly as it was hashed.
        $row['meta'] = $meta === null ? null : $this->canonicalJson($meta);

        return $row;
    }

    /**
     * @param  array<string, int|string|null>  $row
     */
    private function forward(array $row): void
    {
        $channel = $this->config->get('dbexplorer.audit.log_channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->info('dbexplorer.audit', $row);
        }
    }

    /**
     * The HMAC key: audit.key, falling back to app.key. "base64:" values are decoded.
     */
    private function key(): string
    {
        $key = $this->config->get('dbexplorer.audit.key');

        if (! is_string($key) || $key === '') {
            $key = $this->config->get('app.key');
        }

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The DbExplorer audit log needs a key: set DBEXPLORER_AUDIT_KEY or APP_KEY.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('The DbExplorer audit key is not valid base64.');
            }

            return $decoded;
        }

        return $key;
    }

    private function connection(): ConnectionInterface
    {
        $name = $this->config->get('dbexplorer.storage.connection');

        return $this->db->connection(is_string($name) ? $name : null);
    }

    /**
     * @return array{checked: int, broken_id: int|null, reason: string}
     */
    private function broken(int $checked, ?int $id, string $reason): array
    {
        return ['checked' => $checked, 'broken_id' => $id, 'reason' => $reason];
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
