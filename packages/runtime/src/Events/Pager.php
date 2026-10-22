<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

/** Clamp a page without changing the caller's row order. */
final class Pager
{
    /** @template T
     * @param list<T> $rows
     * @return array{rows: list<T>, page: int, pages: int, total: int}
     */
    public static function slice(array $rows, int $page, int $perPage): array
    {
        $perPage = $perPage < 1 ? 15 : $perPage;
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }
}
