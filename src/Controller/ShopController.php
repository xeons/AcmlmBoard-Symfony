<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Item;
use App\Entity\ItemCategory;
use App\Repository\ItemCategoryRepository;
use App\Repository\ItemRepository;
use App\Repository\RpgProfileRepository;
use App\Service\RpgCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The RPG item shop.
 *
 * The status panel was a GD-rendered PNG (status.php) in the original; it is HTML
 * and CSS here, which makes it themeable, selectable and legible on a high-DPI
 * screen - and removes the dependency on the gd extension.
 */
#[Route('/shop')]
final class ShopController extends AbstractBoardController
{
    #[Route('', name: 'app_shop', methods: ['GET'])]
    public function index(
        ItemCategoryRepository $categories,
        ItemRepository $items,
        RpgProfileRepository $profiles,
        RpgCalculator $rpg,
    ): Response {
        $user = $this->requireBoardUser();
        $profile = $profiles->findOrCreateFor($user);

        $equipped = $items->findByIdsIndexed(array_values($profile->getLoadout()));

        return $this->render('shop/index.html.twig', [
            'categories' => $categories->findAllOrdered(),
            'profile' => $profile,
            'equipped' => $equipped,
            'stats' => $rpg->statsFor($user, $equipped),
            'baseStats' => $rpg->statsFor($user),
            'coins' => $rpg->availableCoins($user, $profile),
        ]);
    }

    #[Route('/{id<\d+>}', name: 'app_shop_category', methods: ['GET'])]
    public function category(
        ItemCategory $category,
        ItemRepository $items,
        RpgProfileRepository $profiles,
        RpgCalculator $rpg,
    ): Response {
        $user = $this->requireBoardUser();
        $profile = $profiles->findOrCreateFor($user);

        $equipped = $items->findByIdsIndexed(array_values($profile->getLoadout()));
        $currentId = $profile->getEquippedItemId((int) $category->getId());

        return $this->render('shop/category.html.twig', [
            'category' => $category,
            'items' => $items->findForShop($category),
            'profile' => $profile,
            'equippedId' => $currentId,
            'equippedItem' => null !== $currentId ? ($equipped[$currentId] ?? null) : null,
            'stats' => $rpg->statsFor($user, $equipped),
            'coins' => $rpg->availableCoins($user, $profile),
            'statKeys' => Item::STATS,
        ]);
    }

    /**
     * Buys and equips an item.
     *
     * The original did this over GET (`shop.php?action=buy&id=N`), interpolated the
     * id unescaped, and computed the new balance as
     * `spent = spent - previousPrice*0.6 + newPrice` - with no check that the user
     * could still afford it after the refund, and no transaction, so two concurrent
     * requests could buy two items for the price of one.
     */
    #[Route('/{id<\d+>}/buy', name: 'app_shop_buy', methods: ['POST'])]
    public function buy(
        ItemCategory $category,
        Request $request,
        ItemRepository $items,
        RpgProfileRepository $profiles,
        RpgCalculator $rpg,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'shop'.$category->getId());

        $item = $items->find($request->request->getInt('item'));

        // The item must actually be sold in this shop.
        if (null === $item || (null !== $item->getCategory() && $item->getCategory() !== $category)) {
            $this->addFlash('error', 'That item is not sold here.');

            return $this->redirectToRoute('app_shop_category', ['id' => $category->getId()]);
        }

        $em->wrapInTransaction(function () use ($user, $category, $item, $items, $profiles, $rpg, $em): void {
            $profile = $profiles->findOrCreateFor($user);
            $available = $rpg->availableCoins($user, $profile);

            if ($item->getPrice() > $available) {
                $this->addFlash('error', 'You cannot afford that item.');

                return;
            }

            // Selling the currently equipped item funds part of the purchase.
            $currentId = $profile->getEquippedItemId((int) $category->getId());
            $refund = 0;
            if (null !== $currentId && null !== $current = $items->find($currentId)) {
                $refund = $rpg->resaleValue($current);
            }

            $profile->addSpent($item->getPrice() - $refund);
            $profile->equip((int) $category->getId(), (int) $item->getId());

            $em->flush();

            $this->addFlash('success', \sprintf('The %s has been bought and equipped.', $item->getName()));
        });

        return $this->redirectToRoute('app_shop_category', ['id' => $category->getId()]);
    }

    #[Route('/{id<\d+>}/sell', name: 'app_shop_sell', methods: ['POST'])]
    public function sell(
        ItemCategory $category,
        Request $request,
        ItemRepository $items,
        RpgProfileRepository $profiles,
        RpgCalculator $rpg,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'shop'.$category->getId());

        $profile = $profiles->findOrCreateFor($user);
        $currentId = $profile->getEquippedItemId((int) $category->getId());

        if (null === $currentId || null === $item = $items->find($currentId)) {
            $this->addFlash('error', 'You have nothing equipped in that slot.');

            return $this->redirectToRoute('app_shop_category', ['id' => $category->getId()]);
        }

        $profile->addSpent(-$rpg->resaleValue($item));
        $profile->unequip((int) $category->getId());
        $em->flush();

        $this->addFlash('success', \sprintf('The %s has been unequipped and sold.', $item->getName()));

        return $this->redirectToRoute('app_shop_category', ['id' => $category->getId()]);
    }
}
