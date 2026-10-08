<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;

/**
 * A PDOException shaped like pdo_pgsql's, with errorInfo[2] holding the server message.
 */
function pgsqlError(string $state, string $message): PDOException
{
    $e = new PDOException("SQLSTATE[{$state}]: ".$message);
    $e->errorInfo = [$state, 7, $message];

    return $e;
}

it('keeps the message, DETAIL and HINT, and drops the LINE snippet', function () {
    $e = QueryFailed::fromPdo(pgsqlError('42703', "ERROR:  column \"relnam\" does not exist\nLINE 1: SELECT relnam FROM pg_class\n               ^\nHINT:  Perhaps you meant \"relname\"."), 'SELECT relnam FROM pg_class');

    expect($e->getMessage())->toBe("column \"relnam\" does not exist\nHINT:  Perhaps you meant \"relname\".")
        ->and($e->sqlState)->toBe('42703')
        ->and($e->lineNumber)->toBe(1)
        ->and($e->position)->toBe(8)
        ->and($e->getPrevious())->toBeNull();
});

it('maps the line through the runner prefix', function () {
    $sql = "SELECT 1,\n\t'é', x frm y";
    $e = QueryFailed::fromPdo(pgsqlError('42601', "ERROR:  syntax error at or near \"y\"\nLINE 3:  'é', x frm y\n                    ^"), $sql, 1);

    expect($e->lineNumber)->toBe(2)
        ->and($e->position)->toBe(mb_strpos($sql, 'y') + 1);
});

it('finds the position on a long line the server shortened', function () {
    $sql = 'SELECT '.str_repeat('1+', 60).'1 frm x';
    $e = QueryFailed::fromPdo(pgsqlError('42601', "ERROR:  syntax error at or near \"x\"\nLINE 1: ...+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1+1 frm x\n                                                                      ^"), $sql);

    expect($e->position)->toBe(strlen($sql));
});

it('falls back to a generic message when the driver gives none', function () {
    $e = QueryFailed::fromPdo(pgsqlError('HY000', ''), 'COPY t TO STDOUT');

    expect($e->getMessage())->toContain('COPY')
        ->and($e->position)->toBeNull()
        ->and($e->lineNumber)->toBeNull();
});
