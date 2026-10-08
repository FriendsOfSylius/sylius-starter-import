<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User\ImportChannelAdminAwareInterface;
use App\Import\ImportChannelAdminContext;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, mixed>
 */
final class ImportChannelAdminVoter extends Voter
{
    public const VIEW = 'import_channel_admin_view';

    public const UPDATE = 'import_channel_admin_update';

    public const DELETE = 'import_channel_admin_delete';

    public function __construct(
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, [self::VIEW, self::UPDATE, self::DELETE], true)) {
            return false;
        }

        return $subject instanceof ProductInterface
            || $subject instanceof TaxonInterface
            || $subject instanceof ProductVariantInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$this->importChannelAdminContext->isChannelAdmin()) {
            return true;
        }

        $channel = $this->importChannelAdminContext->getChannel();

        if (null === $channel) {
            return false;
        }

        if ($subject instanceof ProductInterface) {
            return $subject->hasChannel($channel);
        }

        if ($subject instanceof ProductVariantInterface) {
            $product = $subject->getProduct();

            return null !== $product && $product->hasChannel($channel);
        }

        if ($subject instanceof TaxonInterface) {
            $prefix = $this->importChannelAdminContext->getImportCodePrefix();

            if (null === $prefix) {
                return false;
            }

            $code = (string) $subject->getCode();

            return str_starts_with($code, $prefix . '_') || $code === $prefix . '_category';
        }

        return false;
    }
}
