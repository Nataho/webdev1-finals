<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store testimonials: the home page feedback section now renders whatever
 * rows staff/admin curate here, and disappears entirely while the table
 * holds none. Ships empty by design.
 *
 * Hand-written like Version20261112100000 / Version20261112110000 — the
 * account domain owns this table, so make:migration cannot see it.
 */
final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create feedback table for admin-managed home page testimonials (starts empty)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE feedback (
                feedback_id INT AUTO_INCREMENT NOT NULL PRIMARY KEY,
                customer_name VARCHAR(120) NOT NULL,
                message TEXT NOT NULL,
                role_label VARCHAR(60) NOT NULL DEFAULT \'Customer\',
                avatar VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE feedback');
    }
}
