<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107065721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE images (image_id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, uploaded_by INT DEFAULT NULL, UNIQUE INDEX UNIQ_E01FBE6A3C0BE965 (filename), INDEX IDX_E01FBE6AE3E73126 (uploaded_by), PRIMARY KEY (image_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE order_items (item_id INT AUTO_INCREMENT NOT NULL, quantity INT NOT NULL, price_at_purchase NUMERIC(10, 2) NOT NULL, order_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_62809DB08D9F6D38 (order_id), INDEX IDX_62809DB04584665A (product_id), PRIMARY KEY (item_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE orders (order_id INT AUTO_INCREMENT NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, order_date DATETIME NOT NULL, status VARCHAR(50) NOT NULL, user_id INT NOT NULL, INDEX IDX_E52FFDEEA76ED395 (user_id), PRIMARY KEY (order_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products (product_id INT AUTO_INCREMENT NOT NULL, product_name VARCHAR(255) NOT NULL, product_description LONGTEXT NOT NULL, product_price NUMERIC(10, 2) NOT NULL, product_stock_quantity INT NOT NULL, product_img VARCHAR(255) NOT NULL, PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (user_id INT AUTO_INCREMENT NOT NULL, user_name VARCHAR(255) NOT NULL, user_email VARCHAR(180) NOT NULL, user_role VARCHAR(50) NOT NULL, user_password VARCHAR(255) NOT NULL, user_profile_pic VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (user_email), PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE images ADD CONSTRAINT FK_E01FBE6AE3E73126 FOREIGN KEY (uploaded_by) REFERENCES users (user_id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB08D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (order_id)');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB04584665A FOREIGN KEY (product_id) REFERENCES products (product_id)');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEEA76ED395 FOREIGN KEY (user_id) REFERENCES users (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE images DROP FOREIGN KEY FK_E01FBE6AE3E73126');
        $this->addSql('ALTER TABLE order_items DROP FOREIGN KEY FK_62809DB08D9F6D38');
        $this->addSql('ALTER TABLE order_items DROP FOREIGN KEY FK_62809DB04584665A');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEEA76ED395');
        $this->addSql('DROP TABLE images');
        $this->addSql('DROP TABLE order_items');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
