<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import channel scoping fields to sylius_admin_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_admin_user ADD import_channel_code VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE sylius_admin_user ADD import_code_prefix VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_admin_user DROP import_channel_code');
        $this->addSql('ALTER TABLE sylius_admin_user DROP import_code_prefix');
    }
}
