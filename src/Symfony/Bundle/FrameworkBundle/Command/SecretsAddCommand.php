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

use Symfony\Bundle\FrameworkBundle\Exception\EncryptionKeyNotFoundException;
use Symfony\Bundle\FrameworkBundle\Secret\Storage\MutableSecretStorageInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Tobias Schultze <http://tobion.de>
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
final class SecretsAddCommand extends Command
{
    protected static $defaultName = 'secrets:add';

    private $secretsStorage;

    public function __construct(MutableSecretStorageInterface $secretsStorage)
    {
        $this->secretsStorage = $secretsStorage;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDefinition([
                new InputArgument('name', InputArgument::REQUIRED, 'The name of the secret'),
            ])
            ->setDescription('Adds a secret in the storage.')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command stores a secret.

    %command.full_name% <name>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $secret = $io->askHidden('Value of the secret');

        try {
            $this->secretsStorage->setSecret($name, $secret);
        } catch (EncryptionKeyNotFoundException $e) {
            throw new \LogicException(sprintf('No encryption keys found. You should call the "%s" command.', SecretsGenerateKeyCommand::getDefaultName()));
        }

        $io->success('Secret was successfully stored.');
    }
}
