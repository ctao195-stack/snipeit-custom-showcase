<?php

namespace App\Providers;
use App\Models\Actionlog;
use App\Observers\ActionlogWechatObserver;
use App\Listeners\CheckoutableListener;
use App\Listeners\LogListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'Illuminate\Auth\Events\Login' => [
            \App\Listeners\LogSuccessfulLogin::class,
        ],

        'Illuminate\Auth\Events\Failed' => [
            \App\Listeners\LogFailedLogin::class,
        ],
    ];

    /**
     * The subscriber classes to register.
     *
     * @var array
     */
    protected $subscribe = [
        LogListener::class,
        CheckoutableListener::class,
    ];
	public function boot(): void
    {
    Actionlog::observe(ActionlogWechatObserver::class);
    }
}
