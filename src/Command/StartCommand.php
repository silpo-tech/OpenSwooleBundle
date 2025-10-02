<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class AppServerStartCommand.
 */
class StartCommand extends ServerCommand
{
    protected function configure()
    {
        $this
            ->setName('openswoole:server:start')
            ->setDescription('Start Swoole HTTP Server.')
            ->addOption(
                'host',
                null,
                InputOption::VALUE_OPTIONAL,
                'If your want to override the default configuration host, use these method.',
            )
            ->addOption(
                'port',
                null,
                InputOption::VALUE_OPTIONAL,
                'If your want to override the default configuration port, use these method.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        try {
            if ($this->server->isRunning()) {
                $style->warning('Server is running! Please stop the server before starting.');
            } else {
                $host = $input->getOption('host');
                if ($host) {
                    $this->server->setHost($host);
                }

                $port = $input->getOption('port');
                if ($port) {
                    $this->server->setPort((int) $port);
                }

                $this->server->start(static function (string $message) use ($style) {
                    $style->success($message);
                });
            }
        } catch (\Exception $exception) {
            $style->error($exception->getMessage());

            return 1;
        }

        return 0;
    }
}
