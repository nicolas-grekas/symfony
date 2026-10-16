<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form\Tests\Extension\Validator\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\GroupSequence;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

class CollectionTypeValidatorExtensionTest extends BaseValidatorExtensionTestCase
{
    protected function createForm(array $options = [])
    {
        return $this->factory->create(CollectionType::class, null, $options);
    }

    #[DataProvider('providePartiallyTransformedCollections')]
    public function testConstraintsDoNotValidatePartiallyTransformedData(array $submitted, int $parentErrors, int $entryErrors, bool $sequence)
    {
        $rootValidated = $graphValidated = false;
        $validator = Validation::createValidator();
        $validator->getMetadataFor(\stdClass::class)->addConstraint(new Callback(static function () use (&$graphValidated) { $graphValidated = true; }));
        $factory = Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension($validator))->getFormFactory();
        $form = $factory->createBuilder(FormType::class, (object) ['numbers' => [], 'name' => ''], [
            'constraints' => new Callback(static function () use (&$rootValidated) { $rootValidated = true; }),
            'validation_groups' => $sequence ? new GroupSequence(['Default', 'Next']) : ['Default'],
        ])
            ->add('numbers', CollectionType::class, [
                'allow_add' => true,
                'entry_type' => IntegerType::class,
                'constraints' => [new NotBlank(), new Count(min: 3)],
                'error_bubbling' => false,
            ])
            ->add('name', TextType::class, ['constraints' => new NotBlank()])
            ->getForm();

        $form->submit(['numbers' => $submitted, 'name' => '']);

        self::assertCount($parentErrors, $form->get('numbers')->getErrors());
        self::assertCount($parentErrors + $entryErrors, $form->get('numbers')->getErrors(true));
        self::assertCount(1, $form->get('name')->getErrors());
        self::assertSame(0 === $entryErrors, $rootValidated);
        self::assertSame(0 === $entryErrors, $graphValidated);
    }

    public static function providePartiallyTransformedCollections(): iterable
    {
        foreach ([false, true] as $sequence) {
            yield [[], 2, 0, $sequence];
            yield [['foo', 'bar'], 0, 2, $sequence];
            yield [['foo', 123, 'bar'], 0, 2, $sequence];
            yield [[123], 1, 0, $sequence];
            yield [[1, 2, 3], 0, 0, $sequence];
        }
    }

    public function testInvalidMessage()
    {
        $form = $this->createForm();

        $this->assertSame('The collection is invalid.', $form->getConfig()->getOption('invalid_message'));
    }
}
