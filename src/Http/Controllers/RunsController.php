<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Role;
use Throwable;

/**
 * Background runs on a label (PRD §7.5). Users see and cancel their own runs;
 * admins of the label see and cancel everyone's. Other users' runs are a 404,
 * so their ids can't be probed.
 */
class RunsController
{
    public const PER_PAGE = 50;

    /**
     * Runs newest first, `page` by page, optionally filtered by `status` and `type`.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', RunStatus::ALL)],
            'type' => ['sometimes', 'string', 'max:32'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = (int) ($input['page'] ?? 1);

        $runs = $this->visibleRuns($request)
            ->when(isset($input['status']), fn (Builder $query) => $query->where('status', $input['status']))
            ->when(isset($input['type']), fn (Builder $query) => $query->where('type', $input['type']))
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get()
            ->map(fn (Run $run): array => $run->toApi())
            ->all();

        return new JsonResponse([
            'data' => array_slice($runs, 0, self::PER_PAGE),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => count($runs) > self::PER_PAGE],
        ]);
    }

    public function show(Request $request, string $label, string $run): JsonResponse
    {
        return new JsonResponse(['data' => $this->findRun($request, $run)->toApi()]);
    }

    /**
     * Request cancellation: 202 while the run is active (again on a repeat),
     * 409 once it has finished. Each request is audited (`run.cancel`). A queued run is cancelled straight away; a
     * running one gets `pg_cancel_backend()` on its backend, through the run
     * role's own connection, and the job marks it cancelled.
     */
    public function cancel(Request $request, DbExplorer $explorer, DatabaseManager $db, string $label, string $run): JsonResponse
    {
        $model = $this->findRun($request, $run);

        $requested = Run::query()
            ->whereKey($model->id)
            ->whereIn('status', RunStatus::ACTIVE)
            ->update(['cancel_requested' => true]);

        $model->refresh();

        if ($requested === 0) {
            return new JsonResponse(['error' => ['message' => 'The run has already finished.'], 'data' => $model->toApi()], 409);
        }

        $explorer->audit(['action' => 'run.cancel', 'meta' => ['run_id' => $model->id, 'type' => $model->type]]);

        if ($model->status === RunStatus::QUEUED) {
            $model->finish(RunStatus::CANCELLED);
        } elseif ($model->pid !== null && $model->label !== null && $model->role !== null) {
            $this->cancelBackend($explorer, $db, $model->label, $model->role, $model->pid);
        }

        return new JsonResponse(['data' => $model->refresh()->toApi()], 202);
    }

    /**
     * A 10-minute signed URL for a finished run's result file (S16, PRD
     * §7.5). The signed route re-checks the requester, so a shared or leaked
     * link stops working with the signature.
     */
    public function download(Request $request, string $label, string $run): JsonResponse
    {
        $model = $this->findRun($request, $run);

        abort_unless($model->hasResult(), 404);

        return new JsonResponse(['data' => [
            'url' => URL::temporarySignedRoute('dbexplorer.runs.file', Date::now()->addMinutes(10), ['run' => $model->id]),
            'expires_at' => Date::now()->addMinutes(10)->toIso8601String(),
        ]]);
    }

    /**
     * The result file itself, behind a valid signature and the requester
     * check from the download route: the owner, or an admin of the run's
     * label (PRD §7.5). Anything else is a 404, so run ids can't be probed.
     */
    public function file(Request $request, DbExplorer $explorer, string $run): StreamedResponse
    {
        abort_unless(ctype_digit($run), 404);

        $model = Run::query()->whereKey((int) $run)->first();

        abort_unless($model instanceof Run && $model->hasResult(), 404);

        $user = $request->user();
        $id = $user?->getAuthIdentifier();
        $admin = $user !== null && $explorer->roleFor($user, (string) $model->label) === Role::ADMIN;

        abort_unless(($model->user_id !== null && is_scalar($id) && (string) $id === $model->user_id) || $admin, 404);

        return Storage::disk((string) $model->result_disk)->download($model->result_path, 'dbx-'.basename((string) $model->result_path));
    }

    /**
     * Cancel the run's current statement. Only backends of the same database
     * login are touched, so a pid reused by someone else's session is safe.
     */
    private function cancelBackend(DbExplorer $explorer, DatabaseManager $db, string $label, string $role, int $pid): void
    {
        try {
            $db->connection($explorer->connectionFor($label, $role))->select(
                'SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE pid = ? AND usename = current_user',
                [$pid],
            );
        } catch (ConnectionNotAllowedException) {
            // The role lost its connection; the job fails the run itself.
        } catch (Throwable $e) {
            // The cancel flag is set either way: the job stops at its next check.
            report($e);
        }
    }

    /**
     * @return Builder<Run>
     */
    private function visibleRuns(Request $request): Builder
    {
        return Run::query()->visibleTo($request);
    }

    private function findRun(Request $request, string $id): Run
    {
        abort_unless(ctype_digit($id), 404);

        $run = $this->visibleRuns($request)->whereKey((int) $id)->first();

        abort_unless($run instanceof Run, 404);

        return $run;
    }
}
