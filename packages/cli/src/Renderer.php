<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;

/**
 * Owns terminal output. Plain text inherits terminal colours on every theme.
 * @phpstan-import-type Summary from Statistics
 */
final class Renderer
{
    private readonly int $width;
    public readonly string $theme;
    private readonly OutputInterface $errors;

    public function __construct(private readonly OutputInterface $output, ?int $width = null, string $theme = 'auto', ?OutputInterface $errors = null)
    {
        $this->width = max(60, $width ?? (new Terminal())->getWidth());
        $this->theme = Theme::resolve($theme, getenv('COLORFGBG') ?: '');
        $this->errors = $errors ?? new NullOutput();
        if ($this->theme === 'plain' || getenv('NO_COLOR') !== false
            || ($output instanceof StreamOutput && !stream_isatty($output->getStream()))) {
            $output->setDecorated(false);
        }
    }

    public function header(string $file, int $count, string $mode): void
    {
        $mode = in_array($mode, ['detect', 'enforce', 'mixed'], true) ? $mode : '-';
        $this->html('<div><span class="font-bold"> SHIELD </span> ' . $mode . ' | '
            . EventFormatter::text(basename($file), max(10, $this->width - 35)) . ' | ' . $count . ' events</div>');
    }

    /** @param array<string, mixed> $event */
    public function event(array $event): void
    {
        $this->html((new EventFormatter())->format($event, $this->width));
    }

    public function raw(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    public function footer(int $skipped, bool $json = false): void
    {
        if ($skipped > 0) {
            $this->notice($skipped . ' lines skipped', $json);
        }
    }

    public function notice(string $message, bool $json = false): void
    {
        if ($json) {
            $this->errors->writeln(html_entity_decode(Sanitiser::forTerminal($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'), OutputInterface::OUTPUT_RAW);
        } else {
            $this->html('<div class="' . Theme::SECONDARY . '">' . EventFormatter::text($message) . '</div>');
        }
    }

    /** @param Summary $summary */
    public function stats(array $summary): void
    {
        if ($summary['total'] === 0) {
            $this->panel('SHIELD', ['No events in this window.']);

            return;
        }
        $this->panel('Summary', ['Total events: ' . $summary['total'], 'Would block: ' . $summary['would_block'], 'Blocked: ' . $summary['blocked']]);
        foreach (['modes' => 'Modes', 'rules' => 'Top rules', 'ips' => 'Top IPs', 'paths' => 'Top paths'] as $key => $title) {
            $lines = [];
            foreach ($summary[$key] as $label => $count) {
                $lines[] = $count . '  ' . $label;
            }
            $this->panel($title, $lines);
        }
        $hours = [Statistics::barLine(array_values($summary['hours']))];
        foreach ($summary['hours'] as $hour => $count) {
            $hours[] = $hour . '  ' . $count;
        }
        $this->panel('Events per hour', $hours);
    }

    /** @param list<string> $lines */
    private function panel(string $title, array $lines): void
    {
        $edge = str_repeat('─', $this->width - 2);
        $html = '<div><div>┌' . $edge . '┐</div>' . $this->panelRow($title, true);
        foreach ($lines as $line) {
            $html .= $this->panelRow($line);
        }
        $this->html($html . '<div>└' . $edge . '┘</div></div>');
    }

    private function panelRow(string $line, bool $bold = false): string
    {
        $safe = EventFormatter::text($line, $this->width - 4);
        $visible = str_replace(['\\<', '\\>'], ['<', '>'], html_entity_decode($safe, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $visible = preg_replace('/ +/u', ' ', $visible) ?? '';
        $padding = max(0, $this->width - 4 - mb_strwidth($visible, 'UTF-8'));

        return '<div>│ <span class="' . ($bold ? 'font-bold' : '') . '">' . $safe . '</span>'
            . str_repeat('&nbsp;', $padding) . ' │</div>';
    }

    private function html(string $html): void
    {
        if (!$this->output->isDecorated()) {
            $html = str_replace('class="' . Theme::SECONDARY . '"', 'class=""', $html);
        }
        \Termwind\renderUsing($this->output);
        \Termwind\render($html);
    }
}
