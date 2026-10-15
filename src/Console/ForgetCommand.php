<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Taldres\LastSeen\Trait\LastSeen;

class ForgetCommand extends Command
{
    protected $signature = 'last-seen:forget
                            {--years= : Forget last_seen_at values older than this many years, up to 100}
                            {--months= : Forget last_seen_at values older than this many months, up to 120}
                            {--days= : Forget last_seen_at values older than this many days, up to 365}
                            {--model= : The model to forget values of, defaults to the model of the last-seen configuration}
                            {--pretend : Only report how many values would be forgotten}';

    protected $description = 'Forget last_seen_at values older than the given age';

    public function handle(): int
    {
        $amounts = [];

        foreach (['years' => 100, 'months' => 120, 'days' => 365] as $unit => $maximum) {
            $value = $this->option($unit);
            // Artisan::call() hands over integers, the command line strings.
            $value = is_int($value) ? (string) $value : $value;

            if ($value !== null && (! is_string($value) || ! ctype_digit($value) || strlen($value) > 3 || (int) $value > $maximum)) {
                $this->components->error("The --{$unit} option must be a whole number from 0 to {$maximum}.");

                return self::FAILURE;
            }

            $amounts[$unit] = (int) $value;
        }

        if (array_sum($amounts) < 1) {
            $this->components->error('Give an age of at least one year, month or day, for example --days=90.');

            return self::FAILURE;
        }

        $class = $this->option('model') ?? config('last-seen.models.user');

        if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! (new ReflectionClass($class))->isInstantiable()) {
            $this->components->error('The model must be the class name of an Eloquent model.');

            return self::FAILURE;
        }

        if (! in_array(LastSeen::class, class_uses_recursive($class), true)) {
            $this->components->error("[{$class}] does not use the LastSeen trait.");

            return self::FAILURE;
        }

        // Soft deleted rows still hold the value, so global scopes must not hide them.
        $cutoff = now()
            ->subYearsNoOverflow($amounts['years'])
            ->subMonthsNoOverflow($amounts['months'])
            ->subDays($amounts['days']);

        $query = $class::query()->withoutGlobalScopes();
        $query->scopes(['lastSeenBefore' => [$cutoff]]);

        if ($this->option('pretend')) {
            $this->components->info($query->count()." [{$class}] last_seen_at values would be forgotten.");

            return self::SUCCESS;
        }

        $forgotten = $query->toBase()->update(['last_seen_at' => null]);

        $this->components->info("{$forgotten} [{$class}] last_seen_at values forgotten.");

        return self::SUCCESS;
    }
}
