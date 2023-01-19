<?php

declare(strict_types=1);

/*
 * This file is part of the box project.
 *
 * (c) Kevin Herrera <kevin@herrera.io>
 *     Théo Fidry <theo.fidry@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace KevinGH\Box\Composer;

use Fidry\Console\Input\IO;
use Humbug\PhpScoper\Autoload\ScoperAutoloadGenerator;
use Humbug\PhpScoper\Symbol\SymbolsRegistry;
use KevinGH\Box\Console\Logger\CompilerLogger;
use KevinGH\Box\NotInstantiable;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use function KevinGH\Box\FileSystem\dump_file;
use function KevinGH\Box\FileSystem\file_contents;
use function preg_replace;
use function str_replace;
use function trim;
use const KevinGH\Box\BOX_ALLOW_XDEBUG;
use const PHP_EOL;

/**
 * @private
 */
final class ComposerOrchestrator
{
    use NotInstantiable;

    public static function getVersion(): string
    {
        $composerExecutable = self::retrieveComposerExecutable();
        $command = [$composerExecutable, '--version'];

        $getVersionProcess = new Process($command);

        $getVersionProcess->run(null, self::getDefaultEnvVars());

        if (false === $getVersionProcess->isSuccessful()) {
            throw new RuntimeException(
                'Could not determine the Composer version.',
                0,
                new ProcessFailedException($getVersionProcess),
            );
        }

        $output = $getVersionProcess->getOutput();

        if (preg_match('/^Composer version ([^\\s]+)/', $output, $match) > 0) {
            return $match[1];
        }

        throw new RuntimeException('Could not determine the Composer version.');
    }

    public static function dumpAutoload(
        SymbolsRegistry $symbolsRegistry,
        string $prefix,
        bool $excludeDevFiles,
        ?IO $io = null,
    ): void {
        $io ??= IO::createNull();

        $logger = new CompilerLogger($io);

        $composerExecutable = self::retrieveComposerExecutable();

        self::dumpAutoloader($composerExecutable, true === $excludeDevFiles, $logger);

        if ('' !== $prefix) {
            $autoloadFile = self::retrieveAutoloadFile($composerExecutable, $logger);

            $autoloadContents = self::generateAutoloadStatements(
                $symbolsRegistry,
                file_contents($autoloadFile),
            );

            dump_file($autoloadFile, $autoloadContents);
        }
    }

    private static function generateAutoloadStatements(
        SymbolsRegistry $symbolsRegistry,
        string $autoload,
    ): string {
        if (0 === $symbolsRegistry->count()) {
            return $autoload;
        }

        $autoload = str_replace('<?php', '', $autoload);

        $autoload = preg_replace(
            '/return (ComposerAutoloaderInit.+::getLoader\(\));/',
            '\$loader = $1;',
            $autoload,
        );

        $scoperStatements = (new ScoperAutoloadGenerator($symbolsRegistry))->dump();

        $scoperStatements = preg_replace(
            '/scoper\-autoload\.php \@generated by PhpScoper/',
            '@generated by Humbug Box',
            $scoperStatements,
        );

        $scoperStatements = preg_replace(
            '/(\s*\\$loader \= .*)/',
            $autoload,
            $scoperStatements,
        );

        return preg_replace(
            '/\n{2,}/m',
            PHP_EOL.PHP_EOL,
            $scoperStatements,
        );
    }

    private static function retrieveComposerExecutable(): string
    {
        $executableFinder = new ExecutableFinder();
        $executableFinder->addSuffix('.phar');

        if (null === $composer = $executableFinder->find('composer')) {
            throw new RuntimeException('Could not find a Composer executable.');
        }

        return $composer;
    }

    private static function dumpAutoloader(string $composerExecutable, bool $noDev, CompilerLogger $logger): void
    {
        $composerCommand = [$composerExecutable, 'dump-autoload', '--classmap-authoritative'];

        if (true === $noDev) {
            $composerCommand[] = '--no-dev';
        }

        if (null !== $verbosity = self::retrieveSubProcessVerbosity($logger->getIO())) {
            $composerCommand[] = $verbosity;
        }

        if ($logger->getIO()->isDecorated()) {
            $composerCommand[] = '--ansi';
        }

        $dumpAutoloadProcess = new Process($composerCommand);

        $logger->log(
            CompilerLogger::CHEVRON_PREFIX,
            $dumpAutoloadProcess->getCommandLine(),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        $dumpAutoloadProcess->run(null, self::getDefaultEnvVars());

        if (false === $dumpAutoloadProcess->isSuccessful()) {
            throw new RuntimeException(
                'Could not dump the autoloader.',
                0,
                new ProcessFailedException($dumpAutoloadProcess),
            );
        }

        if ('' !== $output = $dumpAutoloadProcess->getOutput()) {
            $logger->getIO()->writeln($output, OutputInterface::VERBOSITY_VERBOSE);
        }

        if ('' !== $output = $dumpAutoloadProcess->getErrorOutput()) {
            $logger->getIO()->writeln($output, OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    private static function retrieveAutoloadFile(string $composerExecutable, CompilerLogger $logger): string
    {
        $command = [$composerExecutable, 'config', 'vendor-dir'];

        if ($logger->getIO()->isDecorated()) {
            $command[] = '--ansi';
        }

        $vendorDirProcess = new Process($command);

        $logger->log(
            CompilerLogger::CHEVRON_PREFIX,
            $vendorDirProcess->getCommandLine(),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        $vendorDirProcess->run(null, self::getDefaultEnvVars());

        if (false === $vendorDirProcess->isSuccessful()) {
            throw new RuntimeException(
                'Could not retrieve the vendor dir.',
                0,
                new ProcessFailedException($vendorDirProcess),
            );
        }

        return trim($vendorDirProcess->getOutput()).'/autoload.php';
    }

    private static function retrieveSubProcessVerbosity(IO $io): ?string
    {
        if ($io->isDebug()) {
            return '-vvv';
        }

        if ($io->isVeryVerbose()) {
            return '-v';
        }

        return null;
    }

    private static function getDefaultEnvVars(): array
    {
        $vars = [];

        if ('1' === (string) getenv(BOX_ALLOW_XDEBUG)) {
            $vars['COMPOSER_ALLOW_XDEBUG'] = '1';
        }

        return $vars;
    }
}
