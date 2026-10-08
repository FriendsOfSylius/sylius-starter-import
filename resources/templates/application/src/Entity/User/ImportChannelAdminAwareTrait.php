<?php

declare(strict_types=1);

namespace App\Entity\User;

use Doctrine\ORM\Mapping as ORM;

trait ImportChannelAdminAwareTrait
{
    #[ORM\Column(name: 'import_channel_code', length: 255, nullable: true)]
    private ?string $channelCode = null;

    #[ORM\Column(name: 'import_code_prefix', length: 64, nullable: true)]
    private ?string $importCodePrefix = null;

    public function getChannelCode(): ?string
    {
        return $this->channelCode;
    }

    public function setChannelCode(?string $channelCode): void
    {
        $this->channelCode = null === $channelCode || '' === trim($channelCode) ? null : trim($channelCode);
    }

    public function getImportCodePrefix(): ?string
    {
        return $this->importCodePrefix;
    }

    public function setImportCodePrefix(?string $importCodePrefix): void
    {
        $this->importCodePrefix = null === $importCodePrefix || '' === trim($importCodePrefix) ? null : trim($importCodePrefix);
    }
}
