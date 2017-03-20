<?php
namespace Vxzhong\Flysystem\AliyunOss;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Vxzhong\Flysystem\AliyunOss\AliyunOssAdapter;

class AliyunOssStorageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        Storage::extend('aliyun_oss', function ($app, $config) {
            $adapter = new AliyunOssAdapter(
                $config['access_key'], $config['secret_key'],
                $config['bucket'], $config['domain'],
                $config['is_cname']
            );
            return new Filesystem($adapter);
        });
    }
    /**
     * Register any application services.
     */
    public function register()
    {
    }
}