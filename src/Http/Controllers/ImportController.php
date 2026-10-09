<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\Import;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Role;

/**
 * The import API (PRD §7.6), editors and admins only: upload a CSV to the
 * private disk, preview its first rows, then queue the {@see Import} job.
 *
 * Uploads live under `imports/{user id}-{random token}.csv` on the storage
 * disk: the token is unguessable and only its owner (or an admin) can
 * preview or run it, so one user's upload can't be probed by another.
 */
class ImportController
{
    public const PREVIEW_ROWS = 20;

    /**
     * What the server's content sniffing (finfo, not the browser's claim)
     * may call a CSV upload. libmagic names CSV text differently across
     * versions: text/csv on current ones, application/csv on the one PHP
     * 8.0 bundles, text/plain when it can't tell, text/x-csv on some
     * distributions. application/vnd.ms-excel is left out on purpose: finfo
     * never says it for text, only for real binary .xls files.
     */
    public const CSV_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'];

    /**
     * Store `file` and return its token. Only content sniffed as CSV or
     * plain text ({@see CSV_MIME_TYPES}) is accepted, capped at
     * limits.import.max_upload_kb; nothing is parsed into the database here.
     */
    public function upload(Request $request): JsonResponse
    {
        $this->authorize($request);

        $maxKb = (int) config('dbexplorer.limits.import.max_upload_kb', 51200);

        $request->validate([
            'file' => ['required', 'file', 'max:'.($maxKb), 'mimetypes:'.implode(',', self::CSV_MIME_TYPES)],
        ]);

        $file = $request->file('file');
        $token = $this->userId($request).'-'.bin2hex(random_bytes(20));

        $file->storeAs('imports', $token.'.csv', ['disk' => (string) config('dbexplorer.storage.disk', 'local')]);

        return new JsonResponse(['data' => [
            'token' => $token,
            'size' => (int) $file->getSize(),
        ]], 201);
    }

    /**
     * The first rows and detected columns of an upload: `{data: {columns,
     * rows}}`, at most 20 rows, values as strings. The first row counts as
     * the header unless `header` is false.
     *
     * @throws ValidationException
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize($request);

        $input = $request->validate(['token' => ['required', 'string']]);

        $handle = $this->open($request, (string) $input['token']);

        $header = $request->boolean('header', true);
        $columns = [];
        $rows = [];

        while (is_array($row = fgetcsv($handle, 0, ',', '"', '')) && count($rows) < self::PREVIEW_ROWS) {
            if ($header && $columns === []) {
                $columns = $row;

                continue;
            }

            $rows[] = $row;

            if ($columns === []) {
                $columns = array_fill(0, count($row), '');
            }
        }

        fclose($handle);

        if ($columns === []) {
            throw ValidationException::withMessages(['token' => ['The file has no rows to import.']]);
        }

        return new JsonResponse(['data' => [
            'columns' => array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $columns),
            'rows' => $rows,
        ]]);
    }

    /**
     * Queue the import: `{token, table: {schema, name}, map, mode, header,
     * empty_as_null}` → 202 with the run. `map` is the CSV column order:
     * each entry is the target column name, or null to skip that CSV
     * column. `mode` is 'transaction' (default: all or nothing) or 'skip'
     * (bad rows are rolled back per batch and reported).
     *
     * @throws ValidationException
     */
    public function run(Request $request, DatabaseManager $db): JsonResponse
    {
        $role = $this->authorize($request);

        $input = $request->validate([
            'token' => ['required', 'string'],
            'table' => ['required', 'array'],
            'table.schema' => ['required', 'string', 'max:63'],
            'table.name' => ['required', 'string', 'max:63'],
            'map' => ['required', 'array', 'min:1'],
            'map.*' => ['nullable', 'string', 'max:63'],
            'mode' => ['sometimes', 'in:'.Import::TRANSACTION.','.Import::SKIP],
            'header' => ['sometimes', 'boolean'],
            'empty_as_null' => ['sometimes', 'boolean'],
        ]);

        $this->open($request, (string) $input['token'], close: true);

        $connection = (string) $request->attributes->get(ResolveRole::CONNECTION);

        // The map must name real columns of a real table (S12): identifiers
        // never reach SQL without this check.
        $targets = array_values(array_filter($input['map'], fn (mixed $column): bool => is_string($column) && $column !== ''));

        abort_if($targets === [], 422, 'The import maps no column.');

        $source = (new Introspector($db->connection($connection)))->rowSource((string) $input['table']['schema'], (string) $input['table']['name']);
        $known = array_column($source['columns'], 'name');

        foreach (array_unique($targets) as $column) {
            if (! in_array($column, $known, true)) {
                throw ValidationException::withMessages(['map' => ["Column [{$column}] does not exist on the table."]]);
            }
        }

        $run = Run::queueFor($request, Import::TYPE, [
            'token' => (string) $input['token'],
            'table' => ['schema' => (string) $input['table']['schema'], 'name' => (string) $input['table']['name']],
            'map' => array_map(fn (mixed $column): ?string => is_string($column) && $column !== '' ? $column : null, (array) $input['map']),
            'mode' => (string) ($input['mode'] ?? Import::TRANSACTION),
            'header' => (bool) ($input['header'] ?? true),
            'empty_as_null' => (bool) ($input['empty_as_null'] ?? true),
            'role_at_dispatch' => $role,
        ]);

        Import::dispatch($run->id);

        return new JsonResponse(['data' => $run->toApi()], 202);
    }

    /**
     * Editor floor for the whole import flow (PRD §7.6).
     */
    private function authorize(Request $request): string
    {
        $role = (string) $request->attributes->get(ResolveRole::ROLE);

        abort_unless(Role::atLeast($role, Role::EDITOR), 403, 'Importing needs the editor role.');

        return $role;
    }

    private function userId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : '0';
    }

    /**
     * The upload's file handle, verified to belong to this user (or an
     * admin): the token's prefix is the uploader's id.
     *
     * @return resource
     *
     * @throws ValidationException
     */
    private function open(Request $request, string $token, bool $close = false): mixed
    {
        if (! preg_match('/^(\d+)-([a-f0-9]{40})$/', $token, $matches)) {
            throw ValidationException::withMessages(['token' => ['Unknown import token.']]);
        }

        $role = (string) $request->attributes->get(ResolveRole::ROLE);

        if ($matches[1] !== $this->userId($request) && $role !== Role::ADMIN) {
            throw ValidationException::withMessages(['token' => ['Unknown import token.']]);
        }

        $path = 'imports/'.$token.'.csv';

        if (! Storage::disk((string) config('dbexplorer.storage.disk', 'local'))->exists($path)) {
            throw ValidationException::withMessages(['token' => ['Unknown import token.']]);
        }

        $handle = fopen(Storage::disk((string) config('dbexplorer.storage.disk', 'local'))->path($path), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['token' => ['The upload could not be read.']]);
        }

        if ($close) {
            fclose($handle);

            return $handle;
        }

        return $handle;
    }
}
