<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GoogleCalendarConnection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Google Calendar API v3 client (events + calendarList + watch).
 */
final class GoogleCalendarApi
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleOAuthClient $oauth,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{id: string, summary: string, primary: bool, accessRole: string}>
     */
    public function listCalendars(GoogleCalendarConnection $connection): array
    {
        $data = $this->request($connection, 'GET', '/users/me/calendarList', [
            'query' => ['minAccessRole' => 'writer', 'maxResults' => 250],
        ]);
        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_string($item['id'])) {
                continue;
            }
            $out[] = [
                'id' => $item['id'],
                'summary' => isset($item['summary']) && is_string($item['summary']) ? $item['summary'] : $item['id'],
                'primary' => !empty($item['primary']),
                'accessRole' => isset($item['accessRole']) && is_string($item['accessRole']) ? $item['accessRole'] : '',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function insertEvent(GoogleCalendarConnection $connection, string $calendarId, array $body): array
    {
        return $this->request($connection, 'POST', '/calendars/'.rawurlencode($calendarId).'/events', [
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function patchEvent(GoogleCalendarConnection $connection, string $calendarId, string $eventId, array $body): array
    {
        return $this->request($connection, 'PATCH', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), [
            'json' => $body,
        ]);
    }

    public function deleteEvent(GoogleCalendarConnection $connection, string $calendarId, string $eventId): void
    {
        try {
            $this->request($connection, 'DELETE', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));
        } catch (\Throwable $e) {
            $this->logger->info('Google event delete ignored: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextSyncToken?: string, nextPageToken?: string}
     */
    public function listEvents(
        GoogleCalendarConnection $connection,
        string $calendarId,
        ?string $syncToken = null,
        ?string $pageToken = null,
    ): array {
        $query = [
            'singleEvents' => 'true',
            'showDeleted' => 'true',
            'maxResults' => 250,
        ];
        if ($syncToken !== null && $syncToken !== '') {
            $query['syncToken'] = $syncToken;
        } else {
            $query['timeMin'] = (new \DateTimeImmutable('-1 year'))->format(\DateTimeInterface::ATOM);
        }
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        /** @var array{items?: list<array<string, mixed>>, nextSyncToken?: string, nextPageToken?: string} $data */
        $data = $this->request($connection, 'GET', '/calendars/'.rawurlencode($calendarId).'/events', [
            'query' => $query,
        ]);

        return [
            'items' => isset($data['items']) && is_array($data['items']) ? $data['items'] : [],
            'nextSyncToken' => isset($data['nextSyncToken']) && is_string($data['nextSyncToken']) ? $data['nextSyncToken'] : null,
            'nextPageToken' => isset($data['nextPageToken']) && is_string($data['nextPageToken']) ? $data['nextPageToken'] : null,
        ];
    }

    /**
     * @return array{id: string, resourceId: string, expiration?: string}
     */
    public function watchEvents(
        GoogleCalendarConnection $connection,
        string $calendarId,
        string $channelId,
        string $webhookUrl,
        string $token,
    ): array {
        /** @var array{id?: string, resourceId?: string, expiration?: string} $data */
        $data = $this->request($connection, 'POST', '/calendars/'.rawurlencode($calendarId).'/events/watch', [
            'json' => [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => $webhookUrl,
                'token' => $token,
            ],
        ]);
        if (!isset($data['id'], $data['resourceId']) || !is_string($data['id']) || !is_string($data['resourceId'])) {
            throw new \RuntimeException('Google watch channel response incomplete.');
        }

        return [
            'id' => $data['id'],
            'resourceId' => $data['resourceId'],
            'expiration' => isset($data['expiration']) && is_string($data['expiration']) ? $data['expiration'] : null,
        ];
    }

    public function stopWatch(GoogleCalendarConnection $connection, string $channelId, string $resourceId): void
    {
        try {
            $this->request($connection, 'POST', '/channels/stop', [
                'json' => [
                    'id' => $channelId,
                    'resourceId' => $resourceId,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->info('Google watch stop ignored: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(GoogleCalendarConnection $connection, string $method, string $path, array $options = []): array
    {
        $token = $this->oauth->getValidAccessToken($connection);
        $headers = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
        unset($options['headers']);

        $response = $this->httpClient->request($method, self::BASE.$path, array_merge($options, [
            'headers' => $headers,
            'timeout' => 20,
        ]));

        $status = $response->getStatusCode();
        if ($status === 204 || $status === 410) {
            return [];
        }
        if ($status < 200 || $status >= 300) {
            $body = $response->getContent(false);
            throw new \RuntimeException('Google Calendar API HTTP '.$status.': '.mb_substr($body, 0, 300));
        }

        $content = $response->getContent(false);
        if ($content === '') {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
