<?php

declare(strict_types=1);

namespace xorik\YtUpload\Service;

use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Psr\Http\Message\RequestInterface;
use xorik\YtUpload\Model\PrivacyStatus;
use xorik\YtUpload\Model\VideoDetails;

class YoutubeApi
{
    private const CHUNK_SIZE = 1024 * 1024;

    private Client $client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
    ) {
        $this->client = new Client([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUrl,
            'scopes' => [YouTube::YOUTUBE],
            'access_type' => 'offline',
        ]);
    }

    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function auth(string $code): array
    {
        return $this->client->fetchAccessTokenWithAuthCode($code);
    }

    public function refreshToken(array $token): ?array
    {
        $this->client->setAccessToken($token);

        if (!$this->client->isAccessTokenExpired()) {
            return null;
        }

        return $this->client->refreshToken($this->client->getRefreshToken());
    }

    public function insertVideo(
        array $token,
        VideoDetails $details,
    ): RequestInterface {
        $this->client->setAccessToken($token);

        $youtube = new YouTube($this->client);

        $snippet = new VideoSnippet();
        $snippet->setChannelId((string) $details->category->value);
        $snippet->setDescription($details->description);
        $snippet->setTitle($details->title);
        $snippet->setTags($details->tags);

        // Set private until processing is over
        $status = new VideoStatus();
        $status->setPrivacyStatus(PrivacyStatus::PRIVATE->value);

        $video = new Video();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        // TODO: set thumbnail & playlist & license

        $this->client->setDefer(true);

        /** @var RequestInterface $request */
        $request = $youtube->videos->insert('status,snippet', $video);

        return $request;
    }

    public function uploadVideo(
        array $token,
        string $path,
        RequestInterface $insertRequest,
        callable $progressCallback,
        ?string $resumeUrl = null,
    ): string {
        $this->client->setAccessToken($token);

        $media = new MediaFileUpload(
            $this->client,
            $insertRequest,
            'video/*',
            null,
            true,
            self::CHUNK_SIZE,
        );
        $filesize = filesize($path);
        $media->setFileSize($filesize);

        /** @var Video|false $status */
        $status = false;
        $handle = fopen($path, 'r');

        if ($resumeUrl !== null) {
            $media->resume($resumeUrl);
            fseek($handle, $media->getProgress());
        }

        while (!$status && !feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            $status = $media->nextChunk($chunk);
            $progressCallback($media->getProgress(), $filesize, $media->getResumeUri());
        }
        fclose($handle);

        return $status->getId();
    }

    public function getProcessingDetails(array $token, string $videoId): Video
    {
        $this->client->setAccessToken($token);

        $youtube = new YouTube($this->client);
        $videos = $youtube->videos->listVideos('processingDetails,snippet,status', ['id' => $videoId])->getItems();
        if (\count($videos) === 0) {
            throw new \RuntimeException('Video is not found: ' . $videoId);
        }

        return $videos[0];
    }

    public function updatePrivacyStatus(array $token, string $videoId, PrivacyStatus $privacyStatus): void
    {
        $this->client->setAccessToken($token);

        $youtube = new YouTube($this->client);

        $video = new Video();
        $video->setId($videoId);

        $videoStatus = new VideoStatus();
        $videoStatus->setPrivacyStatus($privacyStatus->value);
        $video->setStatus($videoStatus);

        $youtube->videos->update('status', $video);
    }
}
