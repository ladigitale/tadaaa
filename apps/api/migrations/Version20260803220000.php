<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email verification tokens + storage/bandwidth quota overrides on users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD email_verify_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_verify_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD storage_quota_bytes BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD bandwidth_quota_month_bytes BIGINT DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN users.email_verify_expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.email_verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USERS_EMAIL_VERIFY_TOKEN ON users (email_verify_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USERS_EMAIL_VERIFY_TOKEN');
        $this->addSql('ALTER TABLE users DROP email_verify_token');
        $this->addSql('ALTER TABLE users DROP email_verify_expires_at');
        $this->addSql('ALTER TABLE users DROP email_verified_at');
        $this->addSql('ALTER TABLE users DROP storage_quota_bytes');
        $this->addSql('ALTER TABLE users DROP bandwidth_quota_month_bytes');
    }
}
