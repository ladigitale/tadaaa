<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User link detectors (account-level URL token patterns)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD link_detectors JSON DEFAULT '[]'::json NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP link_detectors');
    }
}
