<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Import\ImportChannelAdminContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 8)]
final class ImportChannelAdminAccessListener
{
    /**
     * Configuration and global catalog metadata — not exposed in the channel admin menu.
     *
     * @var list<string>
     */
    private const DENIED_ROUTE_PREFIXES = [
        'sylius_admin_channel_',
        'sylius_admin_country_',
        'sylius_admin_zone_',
        'sylius_admin_currency_',
        'sylius_admin_exchange_rate_',
        'sylius_admin_locale_',
        'sylius_admin_payment_method_',
        'sylius_admin_shipping_method_',
        'sylius_admin_shipping_category_',
        'sylius_admin_tax_category_',
        'sylius_admin_tax_rate_',
        'sylius_admin_admin_user_',
        'sylius_admin_product_attribute_',
        'sylius_admin_product_option_',
        'sylius_admin_product_association_type_',
    ];

    public function __construct(
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->importChannelAdminContext->isChannelAdmin()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');

        if ('' === $route || !$this->isDeniedRoute($route)) {
            return;
        }

        $event->setResponse(new Response('Access denied.', Response::HTTP_FORBIDDEN));
    }

    private function isDeniedRoute(string $route): bool
    {
        if (!str_starts_with($route, 'sylius_admin_')) {
            return false;
        }

        foreach (self::DENIED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
