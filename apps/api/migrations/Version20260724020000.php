<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todo optional start_at / end_at (date-only calendar fields)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todos ADD start_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE todos ADD end_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todos DROP start_at');
        $this->addSql('ALTER TABLE todos DROP end_at');
    }
}
