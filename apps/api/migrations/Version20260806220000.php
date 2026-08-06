<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User themeId for shared appearance (Tadaaa ↔ Belts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD theme_id VARCHAR(32) DEFAULT 'default' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP theme_id');
    }
}
