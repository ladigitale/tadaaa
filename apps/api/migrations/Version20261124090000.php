<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lieux partagés : blaze de l\'auteur sur les lieux et les messages, blaze rattachable à un compte ; fin des tags « passés par là »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_place_tags');
        $this->addSql('ALTER TABLE artifact_place_visitors ADD user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_place_visitors ADD CONSTRAINT FK_ARTIFACT_PLACE_VISITORS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_artifact_place_visitor_user ON artifact_place_visitors (user_id)');
        $this->addSql('ALTER TABLE artifact_place_messages ADD visitor_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_place_messages ADD CONSTRAINT FK_ARTIFACT_PLACE_MESSAGES_VISITOR FOREIGN KEY (visitor_id) REFERENCES artifact_place_visitors (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_artifact_place_message_visitor ON artifact_place_messages (visitor_id)');
        $this->addSql('ALTER TABLE artifact_places ADD visitor_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_places ADD CONSTRAINT FK_ARTIFACT_PLACES_VISITOR FOREIGN KEY (visitor_id) REFERENCES artifact_place_visitors (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_artifact_place_visitor ON artifact_places (visitor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_places DROP CONSTRAINT FK_ARTIFACT_PLACES_VISITOR');
        $this->addSql('ALTER TABLE artifact_places DROP visitor_id');
        $this->addSql('ALTER TABLE artifact_place_messages DROP CONSTRAINT FK_ARTIFACT_PLACE_MESSAGES_VISITOR');
        $this->addSql('ALTER TABLE artifact_place_messages DROP visitor_id');
        $this->addSql('ALTER TABLE artifact_place_visitors DROP CONSTRAINT FK_ARTIFACT_PLACE_VISITORS_USER');
        $this->addSql('ALTER TABLE artifact_place_visitors DROP user_id');
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_place_tags (
                id UUID NOT NULL,
                place_id UUID NOT NULL,
                visitor_id UUID NOT NULL,
                first_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
    }
}
