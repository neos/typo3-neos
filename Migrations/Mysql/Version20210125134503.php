<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210125134503 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add dimensions hash to node event model';
    }

    /**
     * @param Schema $schema
     * @throws \Doctrine\DBAL\Exception
     */
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE neos_neos_eventlog_domain_model_event ADD dimensionshash VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX dimensionshash ON neos_neos_eventlog_domain_model_event (dimensionshash)');
    }

    /**
     * @param Schema $schema
     * @throws \Doctrine\DBAL\Exception
     */
    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX dimensionshash ON neos_neos_eventlog_domain_model_event');
        $this->addSql('ALTER TABLE neos_neos_eventlog_domain_model_event DROP dimensionshash');
    }

    /**
     * @param Schema $schema
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function postUp(Schema $schema): void
    {
        // Disabled for Neos 9 - the Event Log needs to be rewritten anyways based on ES CR
    }
}
