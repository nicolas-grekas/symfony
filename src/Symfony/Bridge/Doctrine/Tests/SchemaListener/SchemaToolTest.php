<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\SchemaListener;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\SchemaListener\MessengerInboxDoctrineSchemaListener;
use Symfony\Bridge\Doctrine\SchemaListener\MessengerTransportDoctrineSchemaListener;
use Symfony\Bridge\Doctrine\Tests\DoctrineTestHelper;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdEntity;
use Symfony\Component\Messenger\Bridge\Doctrine\Inbox\DoctrineInbox;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[RequiresPhpExtension('pdo_sqlite')]
class SchemaToolTest extends TestCase
{
    protected function setUp(): void
    {
        if (!method_exists(GenerateSchemaEventArgs::class, 'getOperation')) {
            $this->markTestSkipped('Doctrine ORM does not report the schema operation.');
        }
    }

    public function testRecreatingAnEntityPreservesQueuedMessagesAndInboxRecords()
    {
        [$entityManager, $transport, $inbox] = $this->createEntityManager();
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata(SingleIntIdEntity::class);
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema([$metadata]);
        $transport->setup();
        $inbox->setup();
        $transport->send(new Envelope((object) ['text' => 'queued message']));
        $connection->transactional(static fn () => $inbox->record('consumer', 'handled-message'));

        $schemaTool->dropSchema([$metadata]);
        $schemaTool->createSchema([$metadata]);

        $messages = iterator_to_array($transport->all());
        $this->assertSame([1, ['handled-message']], [\count($messages), $connection->fetchFirstColumn('SELECT message_id FROM messenger_inbox')]);
        $this->assertSame('queued message', $messages[0]->getMessage()->text);
        $this->assertTrue($connection->createSchemaManager()->tablesExist([$metadata->getTableName()]));
    }

    public function testCreatingAnEntityOnAnEmptyDatabaseOnlyCreatesItsTable()
    {
        [$entityManager] = $this->createEntityManager();
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata(SingleIntIdEntity::class);
        $schemaTool = new SchemaTool($entityManager);

        $this->assertFalse($connection->isConnected());
        $sql = implode(';', $schemaTool->getCreateSchemaSQL([$metadata]));
        $this->assertFalse($connection->isConnected());
        $this->assertStringNotContainsString('messenger_messages', $sql);
        $this->assertStringNotContainsString('messenger_inbox', $sql);

        $schemaTool->createSchema([$metadata]);

        $this->assertTrue($connection->createSchemaManager()->tablesExist([$metadata->getTableName()]));
        $this->assertFalse($connection->createSchemaManager()->tablesExist(['messenger_messages']));
        $this->assertFalse($connection->createSchemaManager()->tablesExist(['messenger_inbox']));
    }

    public function testSchemaUpdatesIncludeStandaloneTables()
    {
        [$entityManager, $transport, $inbox] = $this->createEntityManager();
        $metadata = $entityManager->getClassMetadata(SingleIntIdEntity::class);
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema([$metadata]);

        $schemaTool->updateSchema([$metadata]);

        $this->assertSame(0, $transport->getMessageCount());
        $this->assertTrue($entityManager->getConnection()->transactional(static fn () => $inbox->record('consumer', 'new-message')));
    }

    public function testDirectSchemaGenerationIncludesStandaloneTables()
    {
        [$entityManager] = $this->createEntityManager();
        $schema = (new SchemaTool($entityManager))->getSchemaFromMetadata([$entityManager->getClassMetadata(SingleIntIdEntity::class)]);

        $this->assertTrue($schema->hasTable('messenger_messages'));
        $this->assertTrue($schema->hasTable('messenger_inbox'));
    }

    private function createEntityManager(): array
    {
        $entityManager = DoctrineTestHelper::createTestEntityManager();
        $connection = $entityManager->getConnection();
        $transport = new DoctrineTransport(new Connection(['auto_setup' => false], $connection), new PhpSerializer());
        $inbox = new DoctrineInbox($connection);
        $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchema], new MessengerTransportDoctrineSchemaListener([$transport]));
        $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchema], new MessengerInboxDoctrineSchemaListener([$inbox]));

        return [$entityManager, $transport, $inbox];
    }
}
