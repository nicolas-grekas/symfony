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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Dumper\MarkdownDomainDumper;
use Symfony\Component\DependencyInjection\Dumper\MermaidDomainDumper;

/**
 * A console command for the service domains.
 *
 * @internal
 */
#[AsCommand(name: 'debug:domains', description: 'Dump the service domains and the references between them')]
final class DebugDomainsCommand extends Command
{
    use BuildDebugContainerTrait;

    private const FORMATS = ['mermaid', 'md'];

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputOption('format', null, InputOption::VALUE_REQUIRED, \sprintf('The output format ("%s")', implode('", "', self::FORMATS)), 'mermaid', self::FORMATS),
                new InputOption('collapse', null, InputOption::VALUE_NONE, 'Merge the domains that share a prefix, the part of their name before the first dot'),
            ])
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command dumps the service domains as a Mermaid flowchart.
                An arrow points from a domain to a domain it references services of, and counts these references:

                  <info>php %command.full_name%</info>

                The domains that share a prefix, like <comment>infrastructure.doctrine</comment> and <comment>infrastructure.cache</comment>,
                are drawn together. Use the <info>--collapse</info> option to draw only one node per prefix:

                  <info>php %command.full_name% --collapse</info>

                Use the <info>--format=md</info> option to list the services behind each arrow:

                  <info>php %command.full_name% --format=md</info>

                EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');

        if (!\in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException(\sprintf('Supported formats are "%s".', implode('", "', self::FORMATS)));
        }

        $container = $this->getContainerBuilder($this->getApplication()->getKernel());
        $options = ['collapse' => $input->getOption('collapse')];

        $dump = 'md' === $format
            ? (new MarkdownDomainDumper($container))->dump($options)
            : (new MermaidDomainDumper($container))->dump($options);

        $output->write($dump, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
