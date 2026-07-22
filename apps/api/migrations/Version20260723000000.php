<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User account status (pending / active / rejected / disabled)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("UPDATE users SET status = 'active'");
        $this->addSql('ALTER TABLE users ALTER COLUMN status DROP DEFAULT');
        $this->addSql('CREATE INDEX IDX_1483A5E97B00651C ON users (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_1483A5E97B00651C');
        $this->addSql('ALTER TABLE users DROP status');
    }
}
