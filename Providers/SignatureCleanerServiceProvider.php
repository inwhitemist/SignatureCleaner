<?php

namespace Modules\SignatureCleaner\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SignatureCleaner\Services\AgranaSignatureCleaner;

class SignatureCleanerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->hooks();
    }

    public function register()
    {
        $this->app->singleton(AgranaSignatureCleaner::class, function () {
            return new AgranaSignatureCleaner();
        });
    }

    protected function hooks()
    {
        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            if (empty($data['body']) || !is_string($data['body'])) {
                return $data;
            }

            // Чистим только входящие письма клиентов.
            // В массиве data есть message_from_customer.
            if (isset($data['message_from_customer']) && !$data['message_from_customer']) {
                return $data;
            }

            $cleaner = app(AgranaSignatureCleaner::class);

            $originalBody = $data['body'];
            $cleanedBody = $cleaner->clean($originalBody);

            if ($cleanedBody !== $originalBody) {
                $data['body'] = $cleanedBody;
            }

            return $data;
        }, 20, 1);
    }
}