# Several User Models

The trait works on every Eloquent model with a `last_seen_at` column, but the published migration only adds the column to
the table of `models.user`. For another model, for example an `Admin`, add the column with a migration of your own and use
the trait on that model:

```php
Schema::table('admins', function (Blueprint $table) {
    $table->timestamp('last_seen_at')->nullable()->index();
});
```

The middleware records the user of the guard that is authenticated once the request has been handled, so put it on the
routes that use `auth:admin`, or in the middleware group of the application. The `trackUsing()` callback receives every
tracked model, so type-hint `Model` instead of a single class. To forget old values of another model, pass it to
`php artisan last-seen:forget --days=90 --model="App\Models\Admin"`.
