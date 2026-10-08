<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Import\ImportChannelAdminContext;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'sylius.menu.admin.main', method: 'restrictMenu', priority: -128)]
final class ImportChannelAdminMenuListener
{
    private const TOP_LEVEL_KEYS = [
        'dashboard',
        'configuration',
        'official_support',
        'sylius.ui.administration',
    ];

    private const CATALOG_CHILD_KEYS = [
        'attributes',
        'options',
        'association_types',
    ];

    public function __construct(
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {}

    public function restrictMenu(MenuBuilderEvent $event): void
    {
        if (!$this->importChannelAdminContext->isChannelAdmin()) {
            return;
        }

        $menu = $event->getMenu();

        foreach (self::TOP_LEVEL_KEYS as $key) {
            if (null !== $menu->getChild($key)) {
                $menu->removeChild($key);
            }
        }

        $catalog = $menu->getChild('catalog');

        if (null === $catalog) {
            return;
        }

        foreach (self::CATALOG_CHILD_KEYS as $key) {
            if (null !== $catalog->getChild($key)) {
                $catalog->removeChild($key);
            }
        }
    }
}
