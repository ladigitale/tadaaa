<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todo simple recurrence (none|daily|weekly|monthly)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE todos ADD recurrence VARCHAR(16) DEFAULT 'none' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todos DROP recurrence');
    }
}
