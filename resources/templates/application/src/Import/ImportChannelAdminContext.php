<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\User\AdminUser;
use App\Entity\User\ImportChannelAdminAwareInterface;
use App\Fixture\ImportAdminUserFixture;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class ImportChannelAdminContext
{
    public function __construct(
        private readonly Security $security,
        private readonly ChannelRepositoryInterface $channelRepository,
    ) {}

    public function isChannelAdmin(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof AdminUserInterface
            && \in_array(ImportAdminUserFixture::ROLE_IMPORT_CHANNEL_ADMIN, $user->getRoles(), true);
    }

    public function isSuperAdmin(): bool
    {
        $user = $this->getAdminUser();

        if (null === $user) {
            return false;
        }

        if (!$user instanceof ImportChannelAdminAwareInterface) {
            return true;
        }

        return null === $user->getChannelCode();
    }

    public function getChannel(): ?ChannelInterface
    {
        $user = $this->getAdminUser();

        if (!$user instanceof ImportChannelAdminAwareInterface) {
            return null;
        }

        $channelCode = $user->getChannelCode();

        if (null === $channelCode || '' === $channelCode) {
            return null;
        }

        /** @var ChannelInterface|null $channel */
        $channel = $this->channelRepository->findOneBy(['code' => $channelCode]);

        return $channel;
    }

    public function getImportCodePrefix(): ?string
    {
        $user = $this->getAdminUser();

        if (!$user instanceof ImportChannelAdminAwareInterface) {
            return null;
        }

        $prefix = $user->getImportCodePrefix();

        return null === $prefix || '' === $prefix ? null : $prefix;
    }

    private function getAdminUser(): ?AdminUserInterface
    {
        $user = $this->security->getUser();

        return $user instanceof AdminUserInterface ? $user : null;
    }
}
