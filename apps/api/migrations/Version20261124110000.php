<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes : réinitialisation du mot de passe par lien (jeton à usage unique)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD password_reset_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD password_reset_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_password_reset_token ON users (password_reset_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_users_password_reset_token');
        $this->addSql('ALTER TABLE users DROP password_reset_token');
        $this->addSql('ALTER TABLE users DROP password_reset_expires_at');
    }
}
