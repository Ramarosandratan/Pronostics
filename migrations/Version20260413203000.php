<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extended Letrot race fields on race table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race ADD distance_meters INT DEFAULT NULL');
        $this->addSql('ALTER TABLE race ADD allocation BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE race ADD race_category VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE race ADD race_time VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE race ADD track_type VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE race ADD track_rope VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE race ADD autostart BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race DROP distance_meters');
        $this->addSql('ALTER TABLE race DROP allocation');
        $this->addSql('ALTER TABLE race DROP race_category');
        $this->addSql('ALTER TABLE race DROP race_time');
        $this->addSql('ALTER TABLE race DROP track_type');
        $this->addSql('ALTER TABLE race DROP track_rope');
        $this->addSql('ALTER TABLE race DROP autostart');
    }
}
