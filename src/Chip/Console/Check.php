<?php
/**
 * Created by PhpStorm.
 * User: phithon
 * Date: 2019/4/23
 * Time: 2:15
 */

namespace Chip\Console;

use Chip\Alarm;
use Chip\AlarmLevel;
use Chip\ChipFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class Check extends Command
{
    protected static $defaultName = 'check';

    protected $chip = null;

    protected $level = ['info', 'warning', 'danger', 'critical'];

    public function __construct(?string $name = null)
    {
        parent::__construct($name);
        $this->chip = (new ChipFactory)->create();
    }

    protected function configure()
    {
        $this->setDescription('Check a file or directory.')->setHelp('This command allows you to detect potential security threat in a file or directory.');
        $this->addArgument("file", InputArgument::REQUIRED, "filename or directory path");
        $this->addOption(
            "level",
            '-l',
            InputOption::VALUE_OPTIONAL,
            'Display message above this level, choice is [' . implode(', ', $this->level) . ']',
            'warning'
        );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $level = $input->getOption('level');
        if (!in_array($level, $this->level, true)) {
            throw new RuntimeException('level must be one of [' . implode(', ', $this->level) . ' ]');
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws \Chip\Exception\FormatException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument("file");
        $finder = new Finder();

        if (is_dir($file)) {
            $finder->files()->in($file)->name("*.php");
        } elseif (is_file($file)) {
            $finder->files()->in(realpath(dirname($file)))->name(basename($file));
        } else {
            throw new RuntimeException("file ${file} doesn't exists");
        }

        $level = strtoupper($input->getOption('level'));
        foreach ($finder as $fileobj) {
            $content = $fileobj->getContents();
            foreach ($this->checkCode($content) as $alarm) {
                if ($alarm->getLevel()->getValue() >= AlarmLevel::$level()->getValue()) {
                    $output->writeln("\n==========");
                    $this->showAlarm($output, $fileobj->getPathname(), $content, $alarm);
                }
            }
        }
    }

    /**
     * @param string $code
     * @return \Generator
     * @throws \Chip\Exception\FormatException
     */
    protected function checkCode(string $code)
    {
        $alarms = $this->chip->detect($code);
        foreach ($alarms as $alarm) {
            yield $alarm;
        }
    }

    protected function showAlarm(OutputInterface $output, string $filename, string $code, Alarm $alarm)
    {
        $color = 'red';

        $level = $alarm->getLevel()->getKey();
        $message = $alarm->getMessage();
        $output->writeln("<bg={$color};fg=white>\n{$level}:{$filename}\n{$message}</>", OutputInterface::VERBOSITY_QUIET);
        $output->writeln('');

        $node = $alarm->getNode();
        $function = $alarm->getFunction();

        if (!$node) {
            return $output->writeln($code);
        }

        list($nodeStartLine, $nodeEndLine) = [$node->getStartLine(), $node->getEndLine()];

        if ($function) {
            list($functionStartLine, $functionEndLine) = [$function->getStartLine(), $function->getEndLine()];
            $arr = array_slice(explode("\n", $code), $functionStartLine - 1, $functionEndLine - $functionStartLine + 1);
            $start = $functionStartLine;
        } else {
            $arr = array_slice(explode("\n", $code), $nodeStartLine - 1, $nodeEndLine - $nodeStartLine + 1);
            $start = $nodeStartLine;
        }

        return array_map(function ($key, $line) use ($output, $start, $nodeStartLine, $nodeEndLine) {
            $startKey = $start + $key;

            if ($nodeStartLine <= $startKey && $startKey <= $nodeEndLine) {
                $output->writeln("<fg=red;options=bold>{$startKey}:{$line}</>");
            } else {
                $output->writeln("{$startKey}:{$line}");
            }
        }, array_keys($arr), $arr);
    }
}
