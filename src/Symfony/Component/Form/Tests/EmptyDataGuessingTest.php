<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;
use Symfony\Component\Form\Guess\ValueGuess;
use Symfony\Component\Form\Test\FormIntegrationTestCase;
use Symfony\Component\Form\Tests\Fixtures\TypedProperties;

class EmptyDataGuessingTest extends FormIntegrationTestCase
{
    public array $typeGuesses = [];

    public function testSubmitEmptyStringOnNonNullableProperty()
    {
        $data = new TypedProperties();
        $form = $this->createForm($data, 'name');

        $form->submit(['name' => '']);

        $this->assertSame('', $data->name);
    }

    public function testSubmitMissingFieldOnNonNullableProperty()
    {
        $data = new TypedProperties();
        $form = $this->createForm($data, 'name');

        $form->submit([]);

        $this->assertSame('', $data->name);
    }

    #[DataProvider('provideGuessedEmptyData')]
    public function testGuessedEmptyData(string $property, mixed $expected)
    {
        $form = $this->createForm(new TypedProperties(), $property);

        $this->assertSame($expected, $form->get($property)->getConfig()->getEmptyData());
    }

    public static function provideGuessedEmptyData(): iterable
    {
        yield 'string' => ['name', ''];
        yield 'int' => ['age', '0'];
        yield 'float' => ['height', '0'];
        yield 'bool' => ['active', false];
    }

    #[DataProvider('provideNotGuessedEmptyData')]
    public function testNotGuessedEmptyData(string $property)
    {
        $form = $this->createForm(new TypedProperties(), $property);

        $this->assertInstanceOf(\Closure::class, $form->get($property)->getConfig()->getEmptyData());
    }

    public static function provideNotGuessedEmptyData(): iterable
    {
        yield 'nullable' => ['nickname'];
        yield 'nullable mutator' => ['slug'];
        yield 'array' => ['tags'];
        yield 'mixed' => ['extra'];
        yield 'union' => ['identifier'];
    }

    public function testExplicitEmptyDataWins()
    {
        $data = new TypedProperties();
        $form = $this->createForm($data, 'name', options: ['empty_data' => 'default']);

        $form->submit(['name' => '']);

        $this->assertSame('default', $data->name);
    }

    public function testUnmappedFieldsAreNotGuessed()
    {
        $form = $this->createForm(new TypedProperties(), 'name', options: ['mapped' => false]);

        $this->assertInstanceOf(\Closure::class, $form->get('name')->getConfig()->getEmptyData());
    }

    public function testFieldsWithAPropertyPathAreNotGuessed()
    {
        $form = $this->createForm(new TypedProperties(), 'name', options: ['property_path' => 'name']);

        $this->assertInstanceOf(\Closure::class, $form->get('name')->getConfig()->getEmptyData());
    }

    public function testExplicitlyTypedFieldsAreNotGuessed()
    {
        $form = $this->createForm(new TypedProperties(), 'name', TextType::class);

        $this->assertInstanceOf(\Closure::class, $form->get('name')->getConfig()->getEmptyData());
    }

    public function testGuessedIntegerTypeIsSubmittedAsZero()
    {
        $this->typeGuesses['age'] = new TypeGuess(IntegerType::class, [], Guess::HIGH_CONFIDENCE);

        $data = new TypedProperties();
        $form = $this->createForm($data, 'age');

        $form->submit(['age' => '']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(0, $data->age);
    }

    public function testUncheckedCheckboxKeepsReturningFalse()
    {
        $this->typeGuesses['active'] = new TypeGuess(CheckboxType::class, [], Guess::HIGH_CONFIDENCE);

        $data = new TypedProperties();
        $data->active = true;
        $form = $this->createForm($data, 'active');

        $form->submit([]);

        $this->assertTrue($form->isSynchronized());
        $this->assertFalse($data->active);
    }

    protected function getTypeGuessers(): array
    {
        return [new class($this) implements FormTypeGuesserInterface {
            public function __construct(private EmptyDataGuessingTest $test)
            {
            }

            public function guessType(string $class, string $property): ?TypeGuess
            {
                return $this->test->typeGuesses[$property] ?? null;
            }

            public function guessRequired(string $class, string $property): ?ValueGuess
            {
                return null;
            }

            public function guessMaxLength(string $class, string $property): ?ValueGuess
            {
                return null;
            }

            public function guessPattern(string $class, string $property): ?ValueGuess
            {
                return null;
            }
        }];
    }

    private function createForm(TypedProperties $data, string $property, ?string $type = null, array $options = []): FormInterface
    {
        return $this->factory
            ->createBuilder(options: ['data_class' => TypedProperties::class, 'data' => $data])
            ->add($property, $type, $options)
            ->getForm();
    }
}
