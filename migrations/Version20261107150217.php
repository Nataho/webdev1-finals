<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107150217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_addresses (address_id INT AUTO_INCREMENT NOT NULL, label VARCHAR(50) DEFAULT NULL, recipient_name VARCHAR(255) NOT NULL, phone_number VARCHAR(50) NOT NULL, region VARCHAR(100) NOT NULL, province VARCHAR(100) DEFAULT NULL, city VARCHAR(100) NOT NULL, barangay VARCHAR(100) NOT NULL, postal_code VARCHAR(20) DEFAULT NULL, street_address VARCHAR(255) NOT NULL, is_default TINYINT NOT NULL, user_id INT NOT NULL, INDEX IDX_6F2AF8F2A76ED395 (user_id), PRIMARY KEY (address_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_addresses ADD CONSTRAINT FK_6F2AF8F2A76ED395 FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders ADD payment_details VARCHAR(100) DEFAULT NULL, ADD delivery_address LONGTEXT DEFAULT NULL, ADD recipient_name VARCHAR(255) DEFAULT NULL, ADD recipient_phone VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_addresses DROP FOREIGN KEY FK_6F2AF8F2A76ED395');
        $this->addSql('DROP TABLE user_addresses');
        $this->addSql('ALTER TABLE orders DROP payment_details, DROP delivery_address, DROP recipient_name, DROP recipient_phone');
    }
}
