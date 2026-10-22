<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use Stero\Shield\Runtime\Sanitiser;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/** Console entry boundary: bad input and file failures never produce a trace. */
final class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('Shield');
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);
        $this->getDefinition()->addOptions([
            new InputOption('json', null, InputOption::VALUE_NONE, 'Print matching raw JSON lines'),
            new InputOption('theme', null, InputOption::VALUE_REQUIRED, 'auto, light, dark or plain (SHIELD_THEME)'),
        ]);
        foreach (['events', 'stats', 'tail'] as $name) {
            $this->addCommand(new ViewerCommand($name));
        }
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input ??= new ArgvInput();
        $output ??= new ConsoleOutput();
        try {
            return parent::run($input, $output);
        } catch (Throwable $error) {
            $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $target->writeln('Error: ' . html_entity_decode(Sanitiser::forTerminal($error->getMessage()), ENT_QUOTES | ENT_HTML5, 'UTF-8'), OutputInterface::OUTPUT_RAW);

            return 1;
        }
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $theme = $input->getParameterOption('--theme', getenv('SHIELD_THEME') ?: 'auto');
        Theme::resolve(is_string($theme) ? $theme : '', getenv('COLORFGBG') ?: '');
        if ($theme === 'plain' || getenv('NO_COLOR') !== false
            || $input->hasParameterOption('--no-ansi')
            || ($output instanceof StreamOutput && !stream_isatty($output->getStream()))) {
            $output->setDecorated(false);
        }

        return parent::doRun($input, $output);
    }
}
