<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, ['label' => 'form-label'])
            ->add('tags', TextType::class, ['label' => 'form-label-with-help', 'help' => 'form-help'])
            ->add('category', ChoiceType::class, ['placeholder' => 'form-placeholder'])
            ->add('summary', TextType::class, ['label' => $options['is_new'] ? 'form-label-new' : 'form-label-edit'])
            ->add('author', TextType::class, ['label' => 'form-label-other-domain', 'help' => 'form-help-other-domain', 'translation_domain' => 'form_domain'])
            ->add('slug', TextType::class, ['label' => 'form-label-not-translated', 'translation_domain' => false])
            ->add('hidden', TextType::class, ['label' => false, 'help' => ''])
            ->add($builder->create('publishedAt', TextType::class, ['label' => 'form-label-from-create']))
            ->add('content', TextType::class, options: ['label' => 'form-label-from-named-argument'])
        ;

        $collection->add('item', ['label' => 'not-a-form-label']);
    }
}
