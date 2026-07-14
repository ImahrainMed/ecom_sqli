<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        Request $request,
        CategoryRepository $categoryRepository,
        ProductRepository $productRepository
    ): Response {
        $categorySlug = $request->query->get('category');
        $keyword = $request->query->get('q');

        $products = $productRepository->search($categorySlug, $keyword);

        $groupedProducts = [];
        foreach ($products as $product) {
            $categoryName = $product->getCategory()->getName();
            $groupedProducts[$categoryName][] = $product;
        }

        return $this->render('home/index.html.twig', [
            'allCategories' => $categoryRepository->findAll(),
            'groupedProducts' => $groupedProducts,
            'selectedCategory' => $categorySlug,
            'searchKeyword' => $keyword,
            'hasResults' => count($products) > 0,
        ]);
    }
}
