<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * "Contains" search that ignores capitals and treats % and _ as ordinary characters.
 *
 * Postgres' LIKE is case-sensitive, so a plain where('name', 'like', '%eden%') found nothing for
 * "Eden Nature Park" (SQLite, used in the tests, is not, which is why nothing caught it).
 */
final class SearchTerm
{
    /** The character that marks the next one as literal in the pattern. */
    private const ESCAPE = '!';

    public static function contains(Builder $query, string $column, string $term): Builder
    {
        $term = mb_strtolower(trim($term));
        $escaped = str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $term);

        return $query->whereRaw('LOWER('.$column.') LIKE ? ESCAPE \''.self::ESCAPE.'\'', ['%'.$escaped.'%']);
    }
}
