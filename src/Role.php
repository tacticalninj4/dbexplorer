<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

final class Role
{
    public const VIEWER = 'viewer';

    public const EDITOR = 'editor';

    public const ADMIN = 'admin';

    /**
     * Every role, lowest to highest.
     */
    public const ALL = [self::VIEWER, self::EDITOR, self::ADMIN];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    /**
     * Whether `$have` grants at least what `$need` requires. Unknown roles never do.
     */
    public static function atLeast(string $have, string $need): bool
    {
        $haveRank = array_search($have, self::ALL, true);
        $needRank = array_search($need, self::ALL, true);

        return $haveRank !== false && $needRank !== false && $haveRank >= $needRank;
    }
}
