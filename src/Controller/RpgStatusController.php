<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ItemRepository;
use App\Repository\RpgProfileRepository;
use App\Service\RpgStatusImage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The RPG status screen as a PNG. Ported from status.php.
 *
 * Passing ?item= swaps that item into its own slot without buying it, which is what
 * the shop uses to preview an item's effect on the member's stats.
 */
final class RpgStatusController extends AbstractBoardController
{
    #[Route('/rpg/status/{id<\d+>}.png', name: 'app_rpg_status', methods: ['GET'])]
    public function status(
        User $user,
        Request $request,
        RpgStatusImage $image,
        RpgProfileRepository $profiles,
        ItemRepository $items,
    ): Response {
        $profile = $profiles->findOrCreateFor($user);
        $loadout = $profile->getLoadout();

        // Preview: substitute the candidate into the slot its category owns, so the
        // picture answers "what would I look like wearing this".
        $previewId = $request->query->getInt('item');
        if ($previewId > 0 && null !== $candidate = $items->find($previewId)) {
            $category = $candidate->getCategory();
            if (null !== $category) {
                $loadout[(string) $category->getId()] = $candidate->getId();
            }
        }

        $equipped = $items->findByIdsIndexed(array_values($loadout));

        $response = new Response(
            $image->render($user, $profile, $equipped),
            Response::HTTP_OK,
            ['Content-Type' => 'image/png'],
        );

        // Stats move with post count and account age, so this is never stale for
        // long, but it must not be cached across members or across previews.
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }
}
