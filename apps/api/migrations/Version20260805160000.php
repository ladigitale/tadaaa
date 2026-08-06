<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.terms_accepted_at for registration consent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP terms_accepted_at');
    }
}
