<?php

namespace App\Support;

class AmazonRotationMessageParser
{
    /**
     * @return array<int, string>
     */
    public static function extractClientSecretsFromSqsBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['Message']) && is_string($decoded['Message'])) {
            $inner = json_decode($decoded['Message'], true);
            if (is_array($inner)) {
                $decoded = $inner;
            }
        }

        return array_values(array_unique(self::collectSecrets($decoded)));
    }

    /**
     * @param mixed $node
     * @return array<int, string>
     */
    protected static function collectSecrets(mixed $node): array
    {
        $secrets = [];

        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (
                    is_string($key)
                    && is_string($value)
                    && stripos($key, 'secret') !== false
                    && str_starts_with($value, 'amzn1.oa2-cs.')
                ) {
                    $secrets[] = $value;
                }

                if (is_array($value)) {
                    $secrets = array_merge($secrets, self::collectSecrets($value));
                }
            }
        }

        return $secrets;
    }
}
