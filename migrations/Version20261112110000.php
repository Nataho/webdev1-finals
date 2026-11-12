<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records when each account was created so the admin users tab can show
 * the "Date created" detail the admin record list requires.
 * Existing rows keep NULL (rendered as an em dash) because their true
 * creation dates are unknowable; every new account gets a real timestamp
 * via the column default — inserts never mention the column.
 *
 * Hand-written like Version20261112100000: the account domain speaks raw
 * DBAL, so make:migration cannot see this change.
 */
final class Version20261112110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_created_at timestamp column to users (NULL for existing rows, CURRENT_TIMESTAMP for new ones)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD user_created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP user_created_at');
    }
}
