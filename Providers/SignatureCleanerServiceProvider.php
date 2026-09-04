<?php

namespace Modules\SignatureCleaner\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SignatureCleaner\Services\AgranaSignatureCleaner;

class SignatureCleanerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            if (empty($data['body']) || !is_string($data['body'])) {
                return $data;
            }

            $cleaner = app(AgranaSignatureCleaner::class);

            $result = $cleaner->cleanWithResult($data['body']);

            $data['body'] = $result['body'];

            if ($result['signature_removed'] && !empty($data['attachments'])) {
                $data['attachments'] = $cleaner->filterSignatureAttachments($data['attachments']);
            }

            return $data;
        }, 20, 1);

        \Eventy::addFilter('fetch_emails.should_save_thread', function ($shouldSave, $data) {
            if ($shouldSave === false || !is_array($data)) {
                return $shouldSave;
            }

            $cleaner = app(AgranaSignatureCleaner::class);

            if ($cleaner->isReactionNotification($data['body'] ?? '', $data['subject'] ?? '')) {
                return false;
            }

            return $shouldSave;
        }, 20, 2);
    }

    public function register()
    {
        $this->app->singleton(AgranaSignatureCleaner::class, function () {
            return new AgranaSignatureCleaner();
        });
    }
}
