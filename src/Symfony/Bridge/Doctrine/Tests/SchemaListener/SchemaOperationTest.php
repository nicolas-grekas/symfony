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

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\SchemaListener\DoctrineDbalCacheAdapterSchemaListener;
use Symfony\Bridge\Doctrine\SchemaListener\LockStoreSchemaListener;
use Symfony\Bridge\Doctrine\SchemaListener\MessengerInboxDoctrineSchemaListener;
use Symfony\Bridge\Doctrine\SchemaListener\MessengerTransportDoctrineSchemaListener;
use Symfony\Bridge\Doctrine\SchemaListener\PdoSessionHandlerSchemaListener;
use Symfony\Bridge\Doctrine\SchemaListener\RememberMeTokenProviderDoctrineSchemaListener;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class SchemaOperationTest extends TestCase
{
    #[DataProvider('provideExplicitSchemaOperations')]
    public function testExplicitSchemaOperationsDoNotConfigureStandaloneTables(string $listenerClass, string $operation)
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('getConnection');
        $schema = new Schema();
        $event = new class($entityManager, $schema, $operation) extends GenerateSchemaEventArgs {
            public function __construct(EntityManagerInterface $entityManager, Schema $schema, private readonly string $operation)
            {
                parent::__construct($entityManager, $schema);
            }

            public function getOperation(): ?string
            {
                return $this->operation;
            }
        };
        $listener = PdoSessionHandlerSchemaListener::class === $listenerClass
            ? new PdoSessionHandlerSchemaListener($this->createStub(PdoSessionHandler::class))
            : new $listenerClass([]);

        $listener->postGenerateSchema($event);

        $this->assertSame($schema, $event->getSchema());
        $this->assertSame([], $schema->getTables());
    }

    public static function provideExplicitSchemaOperations(): iterable
    {
        foreach ([
            DoctrineDbalCacheAdapterSchemaListener::class,
            LockStoreSchemaListener::class,
            MessengerInboxDoctrineSchemaListener::class,
            MessengerTransportDoctrineSchemaListener::class,
            PdoSessionHandlerSchemaListener::class,
            RememberMeTokenProviderDoctrineSchemaListener::class,
        ] as $class) {
            foreach (['create', 'drop'] as $operation) {
                yield $class.' '.$operation => [$class, $operation];
            }
        }
    }
}
