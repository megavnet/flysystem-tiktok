<?php

namespace Megavn\FlysystemTikTok;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;

class TikTokServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Storage::extend('tiktok', function ($app, $config) {
            $adapter = new TikTokAdapter($config);

            return $adapter;
        });
    }
}
