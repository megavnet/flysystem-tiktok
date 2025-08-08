<?php

use GuzzleHttp\Psr7\Response;
use League\Flysystem\Config;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToMoveFile;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use GuzzleHttp\Client as GuzzleClient;
use League\Flysystem\UnableToWriteFile;
use Megavn\FlysystemTikTok\TikTokAdapter;
use Megavn\FlysystemTikTok\TikTokRequestException;

uses(
    TestCase::class,
    ProphecyTrait::class
);

beforeEach(function () {
    $this->tiktokAdapter = new TikTokAdapter([
        'app_id' => '',
        'secret' => '',
        'access_token' => '',
    ]);
});

it('can put image', function () {
    $image_url = 'https://raw.githubusercontent.com/mediaelement/mediaelement-files/refs/heads/master/big_buck_bunny.jpg';
    $tmp_file = tempnam(sys_get_temp_dir(), 'image_') . '.jpg';
    file_put_contents($tmp_file, file_get_contents($image_url));
    $result1 = $this->tiktokAdapter->put($tmp_file, file_get_contents($tmp_file));
    $this->assertArrayHasKey('image_id', $result1);
});

it('can put video', function () {
    $video_url = 'https://raw.githubusercontent.com/mediaelement/mediaelement-files/refs/heads/master/echo-hereweare.mp4';
    // download video to tmp file
    $tmp_file = tempnam(sys_get_temp_dir(), 'video_') . '.mp4';
    file_put_contents($tmp_file, file_get_contents($video_url));
    $video_info = $this->tiktokAdapter->put($tmp_file, file_get_contents($tmp_file));
    $this->assertArrayHasKey('video_id', $video_info);
    $this->assertArrayHasKey('preview_url', $video_info);
});
