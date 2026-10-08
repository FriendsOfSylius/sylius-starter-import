<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Import\ImportChannelAdminContext;
use Sylius\Bundle\ProductBundle\Form\Type\ProductType;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class ImportChannelAdminProductTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {}

    public static function getExtendedTypes(): iterable
    {
        return [ProductType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            if (!$this->importChannelAdminContext->isChannelAdmin()) {
                return;
            }

            $product = $event->getData();

            if (!$product instanceof ProductInterface) {
                return;
            }

            $channel = $this->importChannelAdminContext->getChannel();

            if (null === $channel) {
                return;
            }

            if (0 === $product->getChannels()->count()) {
                $product->addChannel($channel);
            }

            $form = $event->getForm();

            if ($form->has('channels')) {
                $form->remove('channels');
            }
        });
    }
}
