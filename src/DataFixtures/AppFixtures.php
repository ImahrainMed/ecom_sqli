<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $categoriesData = [
            'Electronics' => 'electronics',
            'Clothing' => 'clothing',
            'Home & Kitchen' => 'home-kitchen',
            'Books' => 'books',
        ];

        $categories = [];
        foreach ($categoriesData as $name => $slug) {
            $category = new Category();
            $category->setName($name);
            $category->setSlug($slug);
            $manager->persist($category);
            $categories[$name] = $category;
        }

        $productsData = [
            // Electronics
            ['Wireless Bluetooth Headphones', 'Electronics', 59.99, 45],
            ['27-inch 4K Monitor', 'Electronics', 329.99, 12],
            ['Mechanical Keyboard RGB', 'Electronics', 89.99, 0],
            ['Portable Power Bank 20000mAh', 'Electronics', 34.50, 60],
            ['Smart Watch Fitness Tracker', 'Electronics', 149.00, 25],

            // Clothing
            ['Men\'s Slim Fit Denim Jacket', 'Clothing', 74.99, 30],
            ['Women\'s Running Sneakers', 'Clothing', 64.99, 18],
            ['Cotton Crew Neck T-Shirt', 'Clothing', 14.99, 100],
            ['Wool Blend Winter Coat', 'Clothing', 129.99, 0],
            ['Classic Leather Belt', 'Clothing', 24.99, 40],

            // Home & Kitchen
            ['Stainless Steel Cookware Set', 'Home & Kitchen', 149.99, 15],
            ['Non-Stick Frying Pan 28cm', 'Home & Kitchen', 22.50, 35],
            ['Electric Kettle 1.7L', 'Home & Kitchen', 29.99, 0],
            ['Memory Foam Pillow', 'Home & Kitchen', 19.99, 50],
            ['LED Desk Lamp Adjustable', 'Home & Kitchen', 27.99, 22],

            // Books
            ['Clean Code by Robert Martin', 'Books', 32.99, 20],
            ['Atomic Habits by James Clear', 'Books', 18.99, 55],
            ['The Pragmatic Programmer', 'Books', 34.99, 14],
            ['Sapiens by Yuval Noah Harari', 'Books', 21.99, 8],
            ['Design Patterns (Gang of Four)', 'Books', 44.99, 6],
        ];

        foreach ($productsData as [$name, $categoryName, $price, $stock]) {
            $product = new Product();
            $product->setName($name);
            $product->setDescription(sprintf('High-quality %s, perfect for everyday use.', strtolower($name)));
            $product->setPrice((string) $price);
            $product->setStock($stock);
            $product->setImageUrl(null);
            $product->setCategory($categories[$categoryName]);
            $manager->persist($product);
        }

        $manager->flush();
    }
}
