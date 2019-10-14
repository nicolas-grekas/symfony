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
final class SecretsListCommand extends Command
{
    protected static $defaultName = 'debug:secrets';

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
                new InputOption('reveal', 'r', InputOption::VALUE_NONE, 'Display decrypted values alongside names'),
            ])
            ->setDescription('Lists all secrets.')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command list all stored secrets.

    %command.full_name%

When the option <info>--reveal</info> is provided, the decrypted secrets are also displayed.

    %command.full_name% --reveal
EOF
            )
        ;

        $this
            ->setDescription('Lists all secrets.')
            ->addOption('reveal', 'r', InputOption::VALUE_NONE, 'Display decrypted values alongside names');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$reveal = $input->getOption('reveal')) {
            $io->comment(sprintf('To reveal the secrets run <info>php %s %s --reveal</info>', $_SERVER['PHP_SELF'], $this->getName()));
        }

        $secrets = $this->vault->list($reveal);

        $rows = [];
        foreach ($secrets as $name => $value) {
            $rows[] = [$name, $value ?? '********'];
        }
        $io->table(['name', 'secret'], $rows);

        if ($reveal && $secrets && null === $value) {
            $io->comment('Secrets could not be revealed as not decryption key has been found.');
        }

        return 0;
    }
}
