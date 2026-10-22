<?php

declare(strict_types=1);

namespace Stero\Shield\Laravel\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Stero\Shield\Runtime\Events\EventFilter;
use Stero\Shield\Runtime\Events\EventReader;
use Stero\Shield\Runtime\Events\Facets;
use Stero\Shield\Runtime\Events\IncidentGrouper;
use Stero\Shield\Runtime\Events\Pager;
use Stero\Shield\Runtime\Events\PathFormatter;
use Stero\Shield\Runtime\Sanitiser;
use Stero\Shield\Runtime\Settings;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/** Translate options and shared runtime event data into an Artisan table. */
final class LogsCommand extends Command
{
    protected $signature = 'shield:logs
        {--ip= : Exact client IP}
        {--rule= : Exact rule id}
        {--severity= : Exact severity}
        {--verdict=any : blocked, would_block or any}
        {--since= : Relative duration such as 15m, 2h or 7d}
        {--path= : Case insensitive path text}
        {--tenant= : Exact tenant}
        {--page=1 : Page number}
        {--per-page=15 : Rows per page}
        {--group : Group incidents by IP and rule}
        {--full : Show complete paths without wrapping}
        {--json : Export the filtered events as a JSON array}
        {--file= : Override the configured events file}';

    protected $description = 'Browse Shield events with filters, pagination and incident grouping';

    private int $width = 80;

    public function handle(Settings $settings): int
    {
        // Plain output keeps redirected streams and NO_COLOR free of ESC bytes.
        $this->output->setDecorated(false);
        $this->width = max(24, (new Terminal)->getWidth());
        try {
            $page = $this->integerOption('page');
            $perPage = $this->integerOption('per-page');
            $filters = [];
            foreach (['ip', 'rule', 'severity', 'verdict', 'since', 'path', 'tenant'] as $field) {
                $filters[$field] = $this->stringOption($field);
            }
            $filter = $this->filter($filters);
            $file = $this->stringOption('file') ?? $settings->eventsPath;
            $reader = new EventReader;
            $events = $reader->read($file);
            if ($reader->reason !== null) {
                $this->lineSafe($reader->reason);

                return self::FAILURE;
            }
            usort($events, static fn (array $a, array $b): int => new DateTimeImmutable((string) $b['ts']) <=> new DateTimeImmutable((string) $a['ts']));
            if ($this->option('json') === true) {
                $this->output->writeln(json_encode($filter->apply($events), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            $interactive = $this->input->isInteractive() && defined('STDIN') && defined('STDOUT') && stream_isatty(STDIN) && stream_isatty(STDOUT);
            $group = $this->option('group') === true;
            do {
                $filtered = $this->filter($filters)->apply($events);
                $rows = $group ? $this->incidentRows($filtered) : $filtered;
                $slice = Pager::slice($rows, $page, $perPage);
                $page = $slice['page'];
                $this->render($slice, $filtered, $file, $filters, $group);
                if (! $interactive) {
                    break;
                }
                // Use Laravel's plain fallbacks so prompts share the table's plain theme.
                Prompt::fallbackWhen(true);
                if (getenv('NO_COLOR') !== false) {
                    QuestionHelper::disableStty();
                }
                $choice = select('Shield logs', ['Next page', 'Previous page', 'Filter', 'Inspect row', 'Group toggle', 'Clear filters', 'Quit']);
                switch ($choice) {
                    case 'Next page': $page++;
                        break;
                    case 'Previous page': $page--;
                        break;
                    case 'Filter': $filters = $this->chooseFilter($filters, $events);
                        $page = 1;
                        break;
                    case 'Inspect row': $this->inspect($slice['rows'], $events, $group);
                        break;
                    case 'Group toggle': $group = ! $group;
                        $page = 1;
                        break;
                    case 'Clear filters': $filters = [];
                        $page = 1;
                        break;
                    case 'Quit': return self::SUCCESS;
                }
            } while (true);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->lineSafe($error->getMessage());

            return self::FAILURE;
        }
    }

    private function integerOption(string $name): int
    {
        $value = $this->stringOption($name);
        if ($value === null || ! mb_check_encoding($value, 'UTF-8') || preg_match('/\A-?[0-9]+\z/u', $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Invalid --'.$name.': expected an integer');
        }

        return (int) $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Invalid --'.$name.': expected text');
        }

        return $value;
    }

    /** @param array<string, string|null> $values */
    private function filter(array $values): EventFilter
    {
        return new EventFilter(verdict: $values['verdict'] ?? 'any', severity: $values['severity'] ?? null, ip: $values['ip'] ?? null,
            rule: $values['rule'] ?? null, tenant: $values['tenant'] ?? null,
            since: isset($values['since']) ? EventFilter::parseSince($values['since']) : null, pathContains: $values['path'] ?? null);
    }

    /** @param list<array<string, scalar|null>> $events
     * @return list<array<string, scalar|null>>
     */
    private function incidentRows(array $events): array
    {
        $rows = [];
        foreach (IncidentGrouper::group($events) as $incident) {
            $rows[] = ['ts' => $incident->lastTs, 'first_ts' => $incident->firstTs, 'ip' => $incident->ip, 'rule' => $incident->rule,
                'severity' => $incident->severity, 'tenant' => $incident->tenant, 'count' => $incident->count,
                'path' => $incident->samplePath, 'blocked' => $incident->verdict === 'blocked', 'would_block' => true];
        }

        return $rows;
    }

    /** @param array{rows: list<array<string, scalar|null>>, page: int, pages: int, total: int} $slice
     * @param  list<array<string, scalar|null>>  $events
     * @param  array<string, string|null>  $filters
     */
    private function render(array $slice, array $events, string $file, array $filters, bool $group): void
    {
        $rows = $slice['rows'];
        $modes = array_unique(array_column($events, 'mode'));
        $mode = count($modes) === 1 ? (string) reset($modes) : 'mixed';
        $perPage = max(1, $this->integerOption('per-page') < 1 ? 15 : $this->integerOption('per-page'));
        $start = $slice['total'] === 0 ? 0 : ($slice['page'] - 1) * $perPage + 1;
        $end = $start === 0 ? 0 : $start + count($rows) - 1;
        $summary = ' | '.count($events).' events | rows '.$start.'-'.$end.' | page '.$slice['page'].' of '.$slice['pages'];
        $prefix = 'SHIELD '.$mode.' | ';
        $nameWidth = max(12, $this->width - mb_strwidth($prefix.$summary, 'UTF-8'));
        $this->lineSafe($prefix.PathFormatter::fit(basename($file), $nameWidth).$summary);
        $bar = [];
        foreach (['verdict', 'severity', 'ip', 'rule', 'tenant', 'since', 'path'] as $field) {
            $bar[] = $field.'='.($filters[$field] ?? 'any');
        }
        $this->lineSafe(implode(' | ', $bar));
        if ($rows === []) {
            $this->lineSafe('No events match');

            return;
        }
        $columns = $group ? ['ts' => 'TIME', 'verdict' => 'VERDICT', 'severity' => 'SEV', 'ip' => 'IP', 'rule' => 'RULE', 'count' => 'COUNT']
            : ['ts' => 'TIME', 'verdict' => 'VERDICT', 'severity' => 'SEV', 'ip' => 'IP', 'method' => 'METHOD'];
        $footer = [];
        if (! $group) {
            foreach (['rule', 'tenant'] as $field) {
                $values = array_unique(array_column($rows, $field));
                if (count($values) === 1) {
                    $footer[] = $field.' '.((string) reset($values) ?: '(none)');
                } elseif ($this->width >= 100) {
                    $columns[$field] = mb_strtoupper($field, 'UTF-8');
                }
            }
        }
        $full = $this->option('full') === true;
        $cells = [];
        foreach ($rows as $row) {
            $cell = [];
            foreach ($columns as $field => $label) {
                $value = $field === 'verdict' ? EventReader::verdict($row) : (string) ($row[$field] ?? '-');
                if ($field === 'ts') {
                    $value = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format($full ? 'H:i' : 'H:i:s');
                }
                $cell[$field] = $this->safe($value);
            }
            // Sanitise before either fitting or rendering complete paths.
            $cell['path'] = $this->safe((string) ($row['path'] ?? ''));
            $cells[] = $cell;
        }
        $widths = [];
        foreach ($columns as $field => $label) {
            $widths[$field] = max(mb_strwidth($label, 'UTF-8'), ...array_map(static fn (array $row): int => mb_strwidth($row[$field] ?? '', 'UTF-8'), $cells));
        }
        $pathWidth = max(24, $this->width - array_sum($widths) - (2 * (count($columns) + 1) + 1));
        if ($full && isset($columns['method'])) {
            $longest = max(array_map(static fn (array $row): int => mb_strwidth($row['path'], 'UTF-8'), $cells));
            if ($longest > $pathWidth) {
                unset($columns['method'], $widths['method']);
                $pathWidth = max(24, $this->width - array_sum($widths) - (2 * (count($columns) + 1) + 1));
            }
        }
        // Bound verbose request fields too, so they cannot force Table to wrap PATH.
        if (! $full && array_sum($widths) + $pathWidth + 2 * (count($columns) + 1) + 1 > $this->width) {
            foreach (['tenant', 'rule', 'method', 'ip'] as $field) {
                if (! isset($widths[$field])) {
                    continue;
                }
                $excess = array_sum($widths) + 24 + 2 * (count($columns) + 1) + 1 - $this->width;
                $widths[$field] = max(mb_strwidth($columns[$field] ?? '', 'UTF-8'), $widths[$field] - max(0, $excess));
            }
            $pathWidth = max(24, $this->width - array_sum($widths) - (2 * (count($columns) + 1) + 1));
        }
        $tableRows = [];
        foreach ($cells as $cell) {
            $values = [];
            foreach ($columns as $field => $label) {
                $values[] = OutputFormatter::escape(mb_strimwidth($cell[$field] ?? '', 0, $widths[$field] ?? 0, '', 'UTF-8'));
            }
            $path = $full ? $cell['path'] : PathFormatter::fit($cell['path'], $pathWidth);
            $values[] = OutputFormatter::escape($path);
            $tableRows[] = $values;
        }
        $style = clone Table::getStyleDefinition('box');
        $style->setCellRowContentFormat(' %s')->setCellHeaderFormat('%s')->setBorderFormat('%s');
        (new Table($this->output))->setStyle($style)->setColumnWidths([...array_values($widths), $pathWidth])
            ->setHeaders([...array_values($columns), 'PATH'])->setRows($tableRows)->render();
        if ($footer !== []) {
            $this->lineSafe(implode(' and ', $footer).' are the same on every row');
        }
        if ($group) {
            $this->lineSafe('Top row: first seen '.(string) $rows[0]['first_ts'].' | last seen '.(string) $rows[0]['ts']);
        }
        $this->lineSafe('Menu available in an interactive terminal.');
    }

    private function safe(string $value): string
    {
        return html_entity_decode(Sanitiser::forTerminal($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function lineSafe(string $value): void
    {
        $value = $this->safe($value);
        while (mb_strwidth($value, 'UTF-8') > $this->width) {
            $part = mb_strimwidth($value, 0, $this->width, '', 'UTF-8');
            $space = mb_strrpos($part, ' ', 0, 'UTF-8');
            if ($space !== false && $space > 0) {
                $part = mb_substr($part, 0, $space, 'UTF-8');
            }
            $this->output->writeln(OutputFormatter::escape($part));
            $value = mb_ltrim(mb_substr($value, mb_strlen($part, 'UTF-8'), null, 'UTF-8'), ' ', 'UTF-8');
        }
        $this->output->writeln(OutputFormatter::escape($value));
    }

    /** @param array<string, string|null> $filters
     * @param  list<array<string, scalar|null>>  $events
     * @return array<string, string|null>
     */
    private function chooseFilter(array $filters, array $events): array
    {
        $field = (string) select('Filter field', ['verdict', 'severity', 'ip', 'rule', 'tenant', 'since', 'path']);
        if ($field === 'since') {
            $filters[$field] = (string) select('Since', ['15m', '1h', '6h', '24h', '7d']);
        } elseif ($field === 'path') {
            $filters[$field] = text('Path text', default: $filters[$field] ?? '');
        } else {
            $counts = Facets::count($events, $field);
            $values = array_map(strval(...), array_keys($counts));
            $labels = ['any' => 'any (clear filter)'];
            $choices = [];
            foreach ($values as $index => $value) {
                $key = 'value_'.$index;
                $choices[$key] = $value;
                $labels[$key] = OutputFormatter::escape($this->safe($value === '' ? '(none)' : $value)).' ('.$counts[$value].')';
            }
            $selected = search('Filter '.$field, static fn (string $query): array => array_filter($labels, static fn (string $label): bool => mb_check_encoding($query, 'UTF-8') && mb_stripos($label, $query, 0, 'UTF-8') !== false));
            $filters[$field] = $choices[$selected] ?? null;
        }

        return $filters;
    }

    /** @param list<array<string, scalar|null>> $rows
     * @param  list<array<string, scalar|null>>  $events
     */
    private function inspect(array $rows, array $events, bool $group): void
    {
        if ($rows === []) {
            return;
        }
        $labels = [];
        $choices = [];
        foreach ($rows as $index => $row) {
            $key = 'row_'.$index;
            $choices[$key] = $row;
            $labels[$key] = OutputFormatter::escape(($index + 1).'. '.$this->safe((string) $row['ip']).' '.PathFormatter::fit((string) $row['path'], 50));
        }
        $selected = select('Inspect row', $labels);
        if (! isset($choices[$selected])) {
            throw new InvalidArgumentException('Invalid row selection');
        }
        $row = $choices[$selected];
        if ($group) {
            foreach ($events as $event) {
                if ($event['ip'] === $row['ip'] && $event['rule'] === $row['rule'] && $event['ts'] === $row['ts']) {
                    $row = $event;
                    break;
                }
            }
        }
        $this->lineSafe('Event details');
        foreach (['path', 'decoded_path', 'method', 'ts', 'ip', 'rule', 'severity', 'verdict', 'tenant', 'mode', 'evidence'] as $field) {
            $value = match ($field) {
                'decoded_path' => rawurldecode((string) $row['path']),
                'verdict' => EventReader::verdict($row),
                'ts' => (new DateTimeImmutable((string) $row['ts']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC',
                default => (string) ($row[$field] ?? '(none)'),
            };
            $this->lineSafe($field.': '.$value);
        }
    }
}
