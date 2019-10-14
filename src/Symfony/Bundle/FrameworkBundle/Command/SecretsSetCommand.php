<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Command;

use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Tobias Schultze <http://tobion.de>
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
final class SecretsSetCommand extends Command
{
    protected static $defaultName = 'secrets:set';

    private $vault;

    public function __construct(SodiumVault $vault)
    {
        $this->vault = $vault;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDefinition([
                new InputArgument('name', InputArgument::REQUIRED, 'The name of the secret'),
            ])
            ->setDescription('Sets a secret in the storage.')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command stores a secret.

    %command.full_name% <name>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $secret = $io->askHidden('Please type the secret value 🤫');

        if ($this->vault->generateKeys()) {
            $io->success('New encryption keys have been generated.');
            $io->caution('DO NOT COMMIT THE DECRYPTION KEY FOR THE PROD ENVIRONMENT⚠️');
        }

        $this->vault->seal($name, $secret);

        $io->success('Secret was successfully stored.');

        return 0;
    }
}
