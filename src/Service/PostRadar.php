<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * The post-count race shown in the header: "You are 12 ahead of Alice (340), 5
 * behind Bob (357)."
 *
 * Reproduces postradar() from lib/function.php, minus its habit of declaring a
 * nested function inside a loop - which fatally errored on the second call in a
 * single request.
 */
final class PostRadar
{
    /**
     * @return list<array{rival: User, difference: int, ahead: bool}> ordered by the
     *                                                               rival's post count, descending
     */
    public function compare(User $user): array
    {
        $rivals = $user->getPostRadar()->toArray();

        usort($rivals, static fn (User $a, User $b): int => $b->getPosts() <=> $a->getPosts());

        return array_map(
            static function (User $rival) use ($user): array {
                $difference = $user->getPosts() - $rival->getPosts();

                return [
                    'rival' => $rival,
                    'difference' => abs($difference),
                    'ahead' => $difference > 0,
                ];
            },
            $rivals,
        );
    }
}
