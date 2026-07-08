<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        $categoryNames = ['Electronics', 'Clothing', 'Home & Kitchen', 'Books'];

        $categories = [];
        foreach ($categoryNames as $name) {
            $category = new Category();
            $category->setName($name);
            $category->setSlug($faker->slug(2));
            $manager->persist($category);
            $categories[] = $category;
        }

           // produit
          for ($i = 0; $i < 20; $i++) {
            $product = new Product();
            $product->setName(ucwords($faker->words(3, true)));
            $product->setDescription($faker->sentence(12));
            $product->setPrice((string) $faker->randomFloat(2, 9.99, 349.99));

            // 3 produits en rupture de stock
            $stock = in_array($i, [0, 5, 10]) ? 0 : $faker->numberBetween(1, 100);
            $product->setStock($stock);

            $product->setImageUrl(null);
            $product->setCategory($faker->randomElement($categories));

            $manager->persist($product);
        }

        $manager->flush();
    }
}
