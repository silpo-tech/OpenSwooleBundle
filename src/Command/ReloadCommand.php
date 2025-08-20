<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReloadCommand extends ServerCommand
{
    protected function configure()
    {
        $this
            ->setName('openswoole:server:reload')
            ->setDescription('Reload OpenSwoole HTTP Server.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        try {
            if ($this->server->isRunning()) {
                $this->server->reload();
                $style->success('OpenSwoole server reloaded!');
            } else {
                $style->warning('Server not running! Please before start the server.');
            }
        } catch (\Exception $exception) {
            $style->error($exception->getMessage());

            return 1;
        }

        return 0;
    }
}
