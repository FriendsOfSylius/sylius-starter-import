<?php

declare(strict_types=1);

namespace App\Entity\User;

interface ImportChannelAdminAwareInterface
{
    public function getChannelCode(): ?string;

    public function setChannelCode(?string $channelCode): void;

    public function getImportCodePrefix(): ?string;

    public function setImportCodePrefix(?string $importCodePrefix): void;
}
