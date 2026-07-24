<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dataset members and invite links for shared access';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dataset_members (id UUID NOT NULL, dataset_id UUID NOT NULL, user_id UUID NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DATASET_MEMBERS_DATASET ON dataset_members (dataset_id)');
        $this->addSql('CREATE INDEX IDX_DATASET_MEMBERS_USER ON dataset_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_dataset_member_user ON dataset_members (dataset_id, user_id)');
        $this->addSql('COMMENT ON COLUMN dataset_members.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN dataset_members.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN dataset_members.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE dataset_members ADD CONSTRAINT FK_DATASET_MEMBERS_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dataset_members ADD CONSTRAINT FK_DATASET_MEMBERS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE dataset_invites (id UUID NOT NULL, dataset_id UUID NOT NULL, created_by_id UUID NOT NULL, accepted_by_id UUID DEFAULT NULL, token VARCHAR(64) NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DATASET_INVITES_DATASET ON dataset_invites (dataset_id)');
        $this->addSql('CREATE INDEX IDX_DATASET_INVITES_CREATED_BY ON dataset_invites (created_by_id)');
        $this->addSql('CREATE INDEX IDX_DATASET_INVITES_ACCEPTED_BY ON dataset_invites (accepted_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_dataset_invite_token ON dataset_invites (token)');
        $this->addSql('COMMENT ON COLUMN dataset_invites.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN dataset_invites.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN dataset_invites.created_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN dataset_invites.accepted_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE dataset_invites ADD CONSTRAINT FK_DATASET_INVITES_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dataset_invites ADD CONSTRAINT FK_DATASET_INVITES_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dataset_invites ADD CONSTRAINT FK_DATASET_INVITES_ACCEPTED_BY FOREIGN KEY (accepted_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dataset_invites DROP CONSTRAINT FK_DATASET_INVITES_DATASET');
        $this->addSql('ALTER TABLE dataset_invites DROP CONSTRAINT FK_DATASET_INVITES_CREATED_BY');
        $this->addSql('ALTER TABLE dataset_invites DROP CONSTRAINT FK_DATASET_INVITES_ACCEPTED_BY');
        $this->addSql('ALTER TABLE dataset_members DROP CONSTRAINT FK_DATASET_MEMBERS_DATASET');
        $this->addSql('ALTER TABLE dataset_members DROP CONSTRAINT FK_DATASET_MEMBERS_USER');
        $this->addSql('DROP TABLE dataset_invites');
        $this->addSql('DROP TABLE dataset_members');
    }
}
