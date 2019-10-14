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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Tobias Schultze <http://tobion.de>
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
final class SecretsGenerateKeysCommand extends Command
{
    protected static $defaultName = 'secrets:generate-keys';

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
                new InputOption('rotate', 'r', InputOption::VALUE_NONE, 'Re-encrypts existing secrets with the newly generated keys.'),
            ])
            ->setDescription('Generates new encryption keys.')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command generates a new encryption key.

    %command.full_name%

If encryption keys already exist, the command must be called with
the <info>--rotate</info> option in order to override those keys and re-encrypt
exiting secrets.

    %command.full_name% --rotate
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('rotate')) {
            if ($this->vault->generateKeys()) {
                $io->success('New keys have been generated.');

                return 0;
            }

            $io->error('Some keys already exist and won\'t be overridden.');

            return 1;
        }

        $secrets = [];
        foreach ($this->vault->list(true) as $name => $decryptedSecret) {
            $secrets[$name] = $decryptedSecret;
        }

        $this->vault->generateKeys(true);
        $io->success('New keys have been generated.');

        if ($secrets) {
            foreach ($secrets as $name => &$decryptedSecret) {
                $this->vault->seal($name, $decryptedSecret);
            }

            $io->success('Existing secrets have been rotated to the new keys.');
        }

        $io->caution('DO NOT COMMIT THE DECRYPTION KEY FOR THE PROD ENVIRONMENT⚠️');

        return 0;
    }
}
