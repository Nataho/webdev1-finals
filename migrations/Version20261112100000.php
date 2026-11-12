<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records who created each catalog product so staff members can only
 * manage their own listings while admins keep full control.
 * Existing rows are attributed to the shop's first admin account.
 *
 * Hand-written because the products table lives outside Doctrine's
 * entity metadata (raw DBAL), so make:migration cannot detect it.
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_created_by ownership column to products and backfill existing rows to the admin account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD product_created_by INT DEFAULT NULL');
        $this->addSql("UPDATE products SET product_created_by = (SELECT user_id FROM users WHERE user_role = 'admin' ORDER BY user_id ASC LIMIT 1) WHERE product_created_by IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP product_created_by');
    }
}
