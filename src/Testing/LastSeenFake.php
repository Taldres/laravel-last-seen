<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Testing;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\Fake;
use PHPUnit\Framework\Assert as PHPUnit;
use Taldres\LastSeen\LastSeenManager;

/**
 * Replaces the manager in tests. It writes nothing and ignores the update threshold, but keeps the
 * configuration and the trackUsing() callback of the real manager.
 */
class LastSeenFake extends LastSeenManager implements Fake
{
    private readonly LastSeenManager $manager;

    /**
     * @var list<Model>
     */
    private array $recorded = [];

    /**
     * @var list<Model>
     */
    private array $forgotten = [];

    public function __construct(LastSeenManager $manager)
    {
        // Faking twice wraps the real manager again instead of the first fake.
        $this->manager = $manager instanceof self ? $manager->manager : $manager;
    }

    /**
     * Counts the user as recorded if the real manager would track it. Every call counts, because
     * nothing is written and the update threshold is ignored.
     */
    public function record(Model $user): bool
    {
        if (! $user->exists || ! $this->shouldTrack($user)) {
            return false;
        }

        $this->recorded[] = $user;

        return true;
    }

    /**
     * Counts the user as forgotten without writing anything.
     */
    public function forget(Model $user): void
    {
        if ($user->exists) {
            $this->forgotten[] = $user;
        }
    }

    public function recentlySeen(Model $user): bool
    {
        return $this->manager->recentlySeen($user);
    }

    public function recentlySeenSince(): CarbonInterface
    {
        return $this->manager->recentlySeenSince();
    }

    public function shouldTrack(Model $user): bool
    {
        return $this->manager->shouldTrack($user);
    }

    /**
     * @param  (Closure(Model): bool)|null  $callback
     */
    public function trackUsing(?Closure $callback): void
    {
        $this->manager->trackUsing($callback);
    }

    /**
     * @param  Model|(Closure(Model): bool)  $user
     */
    public function assertRecorded(Model|Closure $user): void
    {
        PHPUnit::assertTrue(
            $this->recorded($user)->isNotEmpty(),
            "The expected {$this->describe($user)} was not recorded."
        );
    }

    /**
     * @param  Model|(Closure(Model): bool)  $user
     */
    public function assertRecordedTimes(Model|Closure $user, int $times = 1): void
    {
        $count = $this->recorded($user)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected {$this->describe($user)} was recorded {$count} times instead of {$times} times."
        );
    }

    /**
     * @param  Model|(Closure(Model): bool)  $user
     */
    public function assertNotRecorded(Model|Closure $user): void
    {
        PHPUnit::assertTrue(
            $this->recorded($user)->isEmpty(),
            "The unexpected {$this->describe($user)} was recorded."
        );
    }

    public function assertNothingRecorded(): void
    {
        $count = count($this->recorded);

        PHPUnit::assertSame(0, $count, "{$count} unexpected users were recorded.");
    }

    /**
     * @param  Model|(Closure(Model): bool)  $user
     */
    public function assertForgotten(Model|Closure $user): void
    {
        PHPUnit::assertTrue(
            $this->forgotten($user)->isNotEmpty(),
            "The expected {$this->describe($user)} was not forgotten."
        );
    }

    /**
     * @param  Model|(Closure(Model): bool)  $user
     */
    public function assertNotForgotten(Model|Closure $user): void
    {
        PHPUnit::assertTrue(
            $this->forgotten($user)->isEmpty(),
            "The unexpected {$this->describe($user)} was forgotten."
        );
    }

    public function assertNothingForgotten(): void
    {
        $count = count($this->forgotten);

        PHPUnit::assertSame(0, $count, "{$count} unexpected users were forgotten.");
    }

    /**
     * The users passed to record() that would have been tracked, once per call.
     *
     * @param  Model|(Closure(Model): bool)|null  $user
     * @return Collection<int, Model>
     */
    public function recorded(Model|Closure|null $user = null): Collection
    {
        return $this->matching($this->recorded, $user);
    }

    /**
     * The users passed to forget(), once per call.
     *
     * @param  Model|(Closure(Model): bool)|null  $user
     * @return Collection<int, Model>
     */
    public function forgotten(Model|Closure|null $user = null): Collection
    {
        return $this->matching($this->forgotten, $user);
    }

    /**
     * @param  list<Model>  $users
     * @param  Model|(Closure(Model): bool)|null  $user
     * @return Collection<int, Model>
     */
    private function matching(array $users, Model|Closure|null $user): Collection
    {
        $callback = $user instanceof Model
            ? fn (Model $candidate): bool => $candidate->is($user)
            : $user;

        return Collection::make($users)->filter($callback)->values();
    }

    /**
     * @param  Model|(Closure(Model): bool)  $user
     */
    private function describe(Model|Closure $user): string
    {
        if ($user instanceof Closure) {
            return 'user matching the given callback';
        }

        $key = $user->getKey();

        return 'user ['.$user::class.':'.(is_scalar($key) ? $key : '?').']';
    }
}
