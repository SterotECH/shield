<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Agent;

use RuntimeException;
use Throwable;

/** Publishes owned instruction files and an isolated AGENTS.md block using atomic writes. */
final class AgentFilesPublisher
{
    private const string MARKER = '<!-- shield-agent-file: managed by shield:install, regenerated on upgrade -->';

    private const string BEGIN = '<!-- shield:begin -->';

    private const string END = '<!-- shield:end -->';

    private const string DESCRIPTION = 'Use when working with the stero/shield Composer package in a PHP or Laravel app: installing it, enabling or tuning it, reading its security events, fixing a false positive, allowlisting an IP, or debugging shield:status. Not for adding detection rules; that happens in the Shield repository.';

    /**
     * Validate the root and both sources before writing. Filesystem failures are per-target results.
     *
     * @param list<AgentTarget> $targets
     * @return list<PublishResult>
     */
    public function publish(string $projectRoot, string $sourceDir, array $targets, bool $force): array
    {
        try {
            if (! is_dir($projectRoot)) {
                throw new RuntimeException('Project root must be an existing directory.');
            }
            $reference = $this->read($sourceDir . '/shield.md');
            $summary = $this->read($sourceDir . '/summary.md');
        } catch (Throwable $error) {
            return array_map(static fn(AgentTarget $target): PublishResult => new PublishResult($target->path(), 'failed', $error->getMessage()), $targets);
        }

        $results = [];
        foreach ($targets as $target) {
            try {
                $results[] = $this->publishTarget($projectRoot, $target, $reference, $summary, $force);
            } catch (Throwable $error) {
                $results[] = new PublishResult($target->path(), 'failed', $error->getMessage());
            }
        }

        return $results;
    }

    private function publishTarget(string $root, AgentTarget $target, string $reference, string $summary, bool $force): PublishResult
    {
        $relative = $target->path();
        $path = $root . '/' . $relative;
        if (is_link($path)) {
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new RuntimeException('Cannot resolve instruction file symlink.');
            }
            $path = $resolved;
        }
        $exists = file_exists($path);
        $old = $exists ? $this->read($path) : '';
        if ($target === AgentTarget::Agents) {
            $content = $this->agentsContent($old, $summary);
        } else {
            $frontmatter = match ($target) {
                AgentTarget::Claude, AgentTarget::Shared => 'name: shield' . "\n" . 'description: "' . self::DESCRIPTION . '"',
                AgentTarget::Cursor => 'description: "' . self::DESCRIPTION . '"' . "\n" . 'alwaysApply: false',
                AgentTarget::Copilot => 'applyTo: "**/*.php"',
            };
            $body = in_array($target, [AgentTarget::Claude, AgentTarget::Shared], true) ? $reference : $summary;
            $content = "---\n" . $frontmatter . "\n---\n" . self::MARKER . "\n" . $body;
            if ($exists && $old !== $content && ! $force && mb_strpos($old, self::MARKER, 0, 'UTF-8') === false) {
                return new PublishResult($relative, 'skipped', 'User-owned file; --force is needed (--force-agents on shield:install).');
            }
        }
        if ($exists && $old === $content) {
            return new PublishResult($relative, 'unchanged', 'Already current.');
        }
        $this->write($path, $content);

        return new PublishResult($relative, $exists ? 'updated' : 'created', 'Published Shield instructions.');
    }

    private function agentsContent(string $old, string $summary): string
    {
        $newline = mb_strpos($old, "\r\n", 0, 'UTF-8') !== false ? "\r\n" : "\n";
        $block = self::BEGIN . "\nShield instructions managed by shield:install.\n" . $summary . self::END;
        if ($newline === "\r\n") {
            $block = implode($newline, explode("\n", $block));
        }
        $begins = mb_substr_count($old, self::BEGIN, 'UTF-8');
        $ends = mb_substr_count($old, self::END, 'UTF-8');
        if ($begins === 0 && $ends === 0) {
            $separator = '';
            if ($old !== '') {
                $separator = match (true) {
                    mb_substr($old, -2 * mb_strlen($newline, 'UTF-8'), null, 'UTF-8') === $newline . $newline => '',
                    mb_substr($old, -mb_strlen($newline, 'UTF-8'), null, 'UTF-8') === $newline => $newline,
                    default => $newline . $newline,
                };
            }

            return $old . $separator . $block . $newline;
        }
        $begin = mb_strpos($old, self::BEGIN, 0, 'UTF-8');
        $end = mb_strpos($old, self::END, 0, 'UTF-8');
        if ($begins !== 1 || $ends !== 1 || $begin === false || $end === false || $end < $begin) {
            throw new RuntimeException('Malformed Shield markers in AGENTS.md; repair them before installing.');
        }

        return mb_substr($old, 0, $begin, 'UTF-8') . $block . mb_substr($old, $end + mb_strlen(self::END, 'UTF-8'), null, 'UTF-8');
    }

    private function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Instruction file is not readable: ' . $path);
        }
        $content = @file_get_contents($path);
        if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('Cannot read UTF-8 instruction file: ' . $path);
        }

        return $content;
    }

    private function write(string $path, string $content): void
    {
        $directory = dirname($path);
        $ancestor = $directory;
        while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        if (! is_dir($ancestor)) {
            throw new RuntimeException('Instruction parent is not a directory.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create instruction directory.');
        }
        $temporary = null;
        try {
            // Exclusive creation in the destination directory avoids tempnam's fallback directory.
            $temporary = $directory . '/.shield-agent-' . bin2hex(random_bytes(12));
            $handle = @fopen($temporary, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Cannot create temporary instruction file.');
            }
            try {
                $written = @fwrite($handle, $content);
                if ($written !== mb_strlen($content, '8bit') || ! @fflush($handle)) {
                    throw new RuntimeException('Cannot write complete instruction file.');
                }
            } finally {
                fclose($handle);
            }
            if (! @chmod($temporary, 0664 & ~umask()) || ! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot replace instruction file atomically.');
            }
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
