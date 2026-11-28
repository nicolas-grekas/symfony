<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

#[Group('functional')]
class DebugDomainsCommandTest extends AbstractWebTestCase
{
    #[DataProvider('provideKernelOptions')]
    public function testDumpsTheDomainsAsAMermaidFlowchart(string $rootConfig, bool $debug)
    {
        static::bootKernel(['test_case' => 'DebugDomains', 'root_config' => $rootConfig, 'debug' => $debug]);

        $tester = $this->runDebugDomains([]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(<<<'MERMAID'
            flowchart LR
                d_billing["billing"]
                d_shipping["shipping"]
                subgraph d_group_infrastructure ["infrastructure"]
                    d_infrastructure_cache["cache"]
                    d_infrastructure_doctrine["doctrine"]
                end
                d_billing -->|"1"| d_infrastructure_cache
                d_billing -->|"1"| d_shipping
                d_shipping -->|"1"| d_infrastructure_doctrine
                classDef domain fill:#d1e7dd,stroke:#2f6f5a,color:#16201b
                class d_billing,d_infrastructure_cache,d_infrastructure_doctrine,d_shipping domain

            MERMAID, $tester->getDisplay(true));
    }

    #[DataProvider('provideKernelOptions')]
    public function testCollapsesTheDomainsThatShareAPrefix(string $rootConfig, bool $debug)
    {
        static::bootKernel(['test_case' => 'DebugDomains', 'root_config' => $rootConfig, 'debug' => $debug]);

        $tester = $this->runDebugDomains(['--collapse' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('d_infrastructure["infrastructure.*"]', $display);
        $this->assertStringContainsString('d_billing -->|"1"| d_infrastructure', $display);
        $this->assertStringContainsString('d_shipping -->|"1"| d_infrastructure', $display);
        $this->assertStringContainsString('class d_infrastructure group', $display);
    }

    public function testListsTheReferencesAsMarkdown()
    {
        static::bootKernel(['test_case' => 'DebugDomains', 'root_config' => 'config.yml', 'debug' => true]);

        $tester = $this->runDebugDomains(['--format' => 'md']);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(<<<'MARKDOWN'
            ### billing → infrastructure.cache (1)

            - `billing.invoices` → `infrastructure.cache.pool`

            ### billing → shipping (1)

            - `billing.checkout` → `shipping.shipments`

            ### shipping → infrastructure.doctrine (1)

            - `shipping.shipments` → `infrastructure.doctrine.factory`

            MARKDOWN, $tester->getDisplay(true));
    }

    public function testRejectsAnUnknownFormat()
    {
        static::bootKernel(['test_case' => 'DebugDomains', 'root_config' => 'config.yml', 'debug' => true]);

        $tester = $this->runDebugDomains(['--format' => 'dot']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Supported formats are "mermaid", "md".', $tester->getDisplay(true));
    }

    public static function provideKernelOptions(): iterable
    {
        yield 'dumped container' => ['config.yml', true];
        yield 'no dump' => ['no_dump.yml', true];
        yield 'no debug' => ['config.yml', false];
    }

    private function runDebugDomains(array $input): ApplicationTester
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'debug:domains'] + $input, ['decorated' => false]);

        return $tester;
    }
}
