<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('theme', TextType::class, ['label' => 'form-label-of-a-type-without-translation']);
        $builder->add('locale', TextType::class, ['label' => 'form-label-translated-in-a-type-without-translation', 'translation_domain' => 'settings']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('translation_domain', false);
    }
}
