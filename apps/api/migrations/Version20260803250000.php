<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop Google Calendar tables if a local DB applied Version20260803230000
 * while that feature lived only on feature/google-calendar.
 */
final class Version20260803250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Google Calendar tables (feature parked on feature/google-calendar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS todo_google_event_links CASCADE');
        $this->addSql('DROP TABLE IF EXISTS google_calendar_bindings CASCADE');
        $this->addSql('DROP TABLE IF EXISTS google_calendar_connections CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Re-create lives on feature/google-calendar (Version20260803230000).
    }
}
