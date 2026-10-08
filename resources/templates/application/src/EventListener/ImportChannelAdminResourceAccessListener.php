<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\Voter\ImportChannelAdminVoter;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AsEventListener(event: KernelEvents::CONTROLLER, method: 'onController', priority: 0)]
final class ImportChannelAdminResourceAccessListener
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $resource = $request->attributes->get('_resource');

        if (!\is_object($resource)) {
            return;
        }

        if (!$resource instanceof ProductInterface
            && !$resource instanceof TaxonInterface
            && !$resource instanceof ProductVariantInterface) {
            return;
        }

        if (!$this->authorizationChecker->isGranted(ImportChannelAdminVoter::VIEW, $resource)) {
            throw new AccessDeniedHttpException();
        }
    }
}
