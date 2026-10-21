<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use Generator;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Three read-only views of the same JSON Lines event contract. */
final class ViewerCommand extends Command implements SignalableCommandInterface
{
    private bool $following = true;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->setDescription(match ($name) {
            'tail' => 'Show recent events and follow the log',
            'stats' => 'Summarise a time window',
            default => 'Show filtered events',
        });
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Event log (SHIELD_EVENTS, otherwise ./events.jsonl)');
        if ($name === 'tail') {
            $this->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Initial event count', '20');
        } else {
            $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Relative duration or ISO 8601 time', $name === 'stats' ? '24h' : null);
        }
        if ($name === 'events') {
            foreach (['rule', 'ip', 'tenant', 'severity'] as $filter) {
                $this->addOption($filter, null, InputOption::VALUE_REQUIRED, $filter === 'severity' ? 'Minimum severity' : 'Exact ' . $filter);
            }
            $this->addOption('would-block', null, InputOption::VALUE_NONE, 'Only events that would block');
            $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum matches, newest last', '50');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file') ?? (getenv('SHIELD_EVENTS') ?: './events.jsonl');
        if (!is_string($file) || $file === '') {
            throw new InvalidArgumentException('Invalid --file');
        }
        $theme = $input->getOption('theme') ?? (getenv('SHIELD_THEME') ?: 'auto');
        if (!is_string($theme)) {
            throw new InvalidArgumentException('Invalid --theme');
        }
        $renderer = new Renderer($output, theme: $theme, errors: $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : null);
        $json = $input->getOption('json') === true;
        if ($this->getName() === 'tail') {
            return $this->follow($file, self::countOption($input, 'lines'), $renderer, $json);
        }
        $reader = new JsonLineReader();
        $options = [];
        foreach ($input->getOptions() as $key => $value) {
            $options[(string) $key] = $value;
        }
        $filter = new EventFilter($options);
        if ($this->getName() === 'stats') {
            if ($json) {
                foreach ($this->matching($reader, $file, $filter) as $row) {
                    $renderer->raw($row['raw']);
                }
            } else {
                $events = (function () use ($reader, $file, $filter): Generator {
                    foreach ($this->matching($reader, $file, $filter) as $row) {
                        yield $row['event'];
                    }
                })();
                $summary = (new Statistics())->summarise($events);
                $mode = count($summary['modes']) === 1 ? (string) array_key_first($summary['modes']) : 'mixed';
                $renderer->header($file, $summary['total'], $mode);
                $renderer->stats($summary);
            }
        } else {
            $limit = self::countOption($input, 'limit');
            $rows = [];
            foreach ($this->matching($reader, $file, $filter) as $row) {
                if ($limit > 0) {
                    $rows[] = $row;
                    if (count($rows) > $limit) {
                        array_shift($rows);
                    }
                }
            }
            $this->show($renderer, $file, $rows, $json);
        }
        $renderer->footer($reader->skipped, $json);

        return 0;
    }

    /** @return Generator<int, array{raw: string, event: array<string, mixed>}> */
    private function matching(JsonLineReader $reader, string $file, EventFilter $filter): Generator
    {
        foreach ($reader->read($file) as $row) {
            if ($filter->matches($row['event'])) {
                yield $row;
            }
        }
    }

    /** @param list<array{raw: string, event: array<string, mixed>}> $rows */
    private function show(Renderer $renderer, string $file, array $rows, bool $json): void
    {
        if (!$json) {
            $modes = [];
            foreach ($rows as $row) {
                $mode = match ($row['event']['mode'] ?? null) {
                    'detect' => 'detect', 'enforce' => 'enforce', default => '-'
                };
                $modes[$mode] = true;
            }
            $renderer->header($file, count($rows), count($modes) === 1 ? (string) array_key_first($modes) : 'mixed');
        }
        foreach ($rows as $row) {
            $json ? $renderer->raw($row['raw']) : $renderer->event($row['event']);
        }
    }

    private function follow(string $file, int $lines, Renderer $renderer, bool $json): int
    {
        $follower = new TailFollower($file, $lines);
        $waitingShown = false;
        $skipped = 0;
        $this->following = true;
        while ($this->following) {
            $rows = $follower->poll();
            if ($follower->waiting && !$waitingShown) {
                $renderer->notice('Waiting for event file: ' . $file, $json);
                $waitingShown = true;
            }
            if ($rows !== []) {
                $this->show($renderer, $file, $rows, $json);
            }
            $renderer->footer($follower->reader->skipped - $skipped, $json);
            $skipped = $follower->reader->skipped;
            $follower->pause();
        }

        return 0;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') && defined('SIGTERM') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->following = false;

        return false;
    }

    private static function countOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($count)) {
            throw new InvalidArgumentException('Invalid --' . $name . ': use a non-negative integer');
        }

        return $count;
    }
}
