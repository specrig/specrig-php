<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reminders (id SERIAL PRIMARY KEY, order_id INT NOT NULL, amount INT NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX reminders_order ON reminders (order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reminders');
    }
}
