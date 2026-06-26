<?php
require __DIR__ . '/vendor/autoload.php';

use Abraham\TwitterOAuth\TwitterOAuth;


class TwitterPost {

    private TwitterOAuth $connection;

    public function __construct(TwitterOAuth $connection)
    {

        $this->connection = $connection;
    }

    public static function getInstanceFromKeys(string $apiKey, string $apiKeySecret, string $accessToken, string $accessTokenSecret): TwitterPost
    {
        $connection = new TwitterOAuth($apiKey, $apiKeySecret, $accessToken, $accessTokenSecret);
        return new self($connection);
    }

    public function postTextTweet(string $text, $in_reply_to_tweet_id = null)
    {
        $parameters = [
            'text' => $text,
        ];
        if ($in_reply_to_tweet_id) {
            $parameters['in_reply_to_tweet_id'] = $in_reply_to_tweet_id;
        }
        $this->connection->setApiVersion('2');
        return $this->connection->post('tweets', $parameters, true);
    }


    public function postTweet(string $text, array $mediaFilePaths = [], $in_reply_to_tweet_id = null)
    {
        $this->connection->setApiVersion('1.1');

        $mediaIds = [];
        foreach ($mediaFilePaths as $mediaFilePath) {
            $media = $this->connection->upload('media/upload', ['media' => $mediaFilePath]);
            $mediaIds[] = $media->media_id_string;
        }
        $this->connection->setApiVersion('2');
        $parameters = [
            'text' => $text,
        ];
        if ($in_reply_to_tweet_id) {
            $parameters['in_reply_to_tweet_id'] = $in_reply_to_tweet_id;
        }
        if ($mediaIds) {
            $parameters['media'] = ['media_ids' => $mediaIds];
        }

        return $this->connection->post('tweets', $parameters, true);
    }

    public function postTweetWithMedia(string $text, ?string $in_reply_to_tweet_id = null, ?array $mediaFilePaths = [], ?string $alt_text = null)
    {
        $this->connection->setApiVersion('1.1');

        $mediaIds = [];
        foreach ($mediaFilePaths as $mediaFilePath) {
            $media = $this->connection->upload('media/upload', ['media' => $mediaFilePath]);
            $mediaIds[] = $media->media_id_string;
        }
        $parameters = [
            'text' => $text,
        ];
        if ($in_reply_to_tweet_id) {
            $parameters['reply']['in_reply_to_tweet_id'] = $in_reply_to_tweet_id;
        }
        if ($mediaIds) {
            $parameters['media'] = ['media_ids' => $mediaIds];
            if ($alt_text) {
                $this->postMetadata($alt_text, $mediaIds[0]);
            }
        }
        $this->connection->setApiVersion('2');

        return $this->connection->post('tweets', $parameters, true);
    }

    public function postMetadata(string $alt_text, string $media_id)
    {
        $this->connection->setApiVersion('1.1');

        $metadata_payload = [
            'media_id' => $media_id,
            'alt_text' => [
                'text' => $alt_text,
            ],
        ];
        return $this->connection->post('media/metadata/create', $metadata_payload);
    }
}