<?php

namespace SegmentHoldings\SsgPublish\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SegmentHoldings\SsgPublish\Exceptions\PublishException;
use SegmentHoldings\SsgPublish\Http\PublishPage;
use SegmentHoldings\SsgPublish\Publishing\Publisher;
use Statamic\Facades\User;

class PublishController
{
    public function store(Request $request, Publisher $publisher, PublishPage $page): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['nullable', 'string'],
        ]);

        try {
            $result = $publisher->publish(
                $validated['target'] ?? null,
                'manual',
                User::current()?->email(),
            );
        } catch (PublishException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'page' => $page->toArray(),
            ], 422);
        }

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'state' => $result->state,
            'page' => $page->toArray(),
        ], $result->ok ? 200 : 422);
    }

    public function status(PublishPage $page): JsonResponse
    {
        return response()->json($page->toArray());
    }
}
