<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scoring mode and scoring weights to pronostic snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pronostic_snapshot ADD scoring_mode VARCHAR(20) NOT NULL DEFAULT 'conservative'");
        $this->addSql("ALTER TABLE pronostic_snapshot ADD scoring_weights JSON NOT NULL DEFAULT '{}' ");
        $this->addSql("UPDATE pronostic_snapshot SET scoring_mode = 'conservative', scoring_weights = '{\"position\":45,\"odds\":25,\"performance\":15,\"earnings\":10,\"age\":5}'");
        $this->addSql('ALTER TABLE pronostic_snapshot ALTER COLUMN scoring_weights DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pronostic_snapshot DROP scoring_weights');
        $this->addSql('ALTER TABLE pronostic_snapshot DROP scoring_mode');
    }
}
