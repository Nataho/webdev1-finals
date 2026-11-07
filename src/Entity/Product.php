<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'product_id')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $productName = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $productDescription = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $productPrice = null;

    #[ORM\Column]
    private ?int $productStockQuantity = null;

    #[ORM\Column(length: 255)]
    private ?string $productImg = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): static
    {
        $this->productName = $productName;

        return $this;
    }

    public function getProductDescription(): ?string
    {
        return $this->productDescription;
    }

    public function setProductDescription(string $productDescription): static
    {
        $this->productDescription = $productDescription;

        return $this;
    }

    public function getProductPrice(): ?string
    {
        return $this->productPrice;
    }

    public function setProductPrice(string $productPrice): static
    {
        $this->productPrice = $productPrice;

        return $this;
    }

    public function getProductStockQuantity(): ?int
    {
        return $this->productStockQuantity;
    }

    public function setProductStockQuantity(int $productStockQuantity): static
    {
        $this->productStockQuantity = $productStockQuantity;

        return $this;
    }

    public function getProductImg(): ?string
    {
        return $this->productImg;
    }

    public function setProductImg(string $productImg): static
    {
        $this->productImg = $productImg;

        return $this;
    }
}
