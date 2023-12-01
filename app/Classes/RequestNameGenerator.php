<?php

namespace App\Classes;

use OpenAI;
use function config;
use function json_encode;
use function Laravel\Prompts\{table, intro, warning, info, error};

class RequestNameGenerator
{
    public static function single(string $method, string $path, ?string $summary = null): string
    {
        $content = ['method' => $method, 'path' => $path, 'summary' => $summary];
        $content = json_encode($content);
        return static::query($content);
    }

    public static function collection(array $endpoints): string
    {
        return static::query(json_encode($endpoints));
    }


    protected static function query(string $content): string
    {
        $client = OpenAI::client(config('openai.api_key'));

        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $content],
        ];

        intro('Sending these messages to OpenAI...');
        info('<fg=yellow>System</>: ' . $messages[0]['content']);
        info('<fg=green>User</>: ' . $messages[1]['content']);

        $result = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => $messages,
        ]);

        return $result->choices[0]->message->content;
    }

    protected static function systemPrompt(): string
    {
        return "You are a expert software analysis assistant. You're are an expert an classifying APIs and generating SDKs from API concepts. Convert a given JSON input that is the endpoint's method, path, and a summary of what the API call does. Generate JSON output that is a safe PHP classname for each request. Only include the method, path, and class in the output.";
    }
}
