<?php

use App\Support\AmazonRotationMessageParser;

it('extracts client secret from direct sqs payload', function () {
    $payload = json_encode([
        'notificationType' => 'APPLICATION_OAUTH_CLIENT_NEW_SECRET',
        'payload' => [
            'applicationOAuthClientSecret' => [
                'clientId' => 'amzn1.application-oa2-client.example',
                'clientSecret' => 'amzn1.oa2-cs.secretvalue12345',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $secrets = AmazonRotationMessageParser::extractClientSecretsFromSqsBody($payload);

    expect($secrets)->toBe(['amzn1.oa2-cs.secretvalue12345']);
});

it('extracts client secret from sns wrapped sqs payload', function () {
    $inner = json_encode([
        'notificationType' => 'APPLICATION_OAUTH_CLIENT_NEW_SECRET',
        'payload' => [
            'applicationOAuthClientSecret' => [
                'clientSecret' => 'amzn1.oa2-cs.secretvalue67890',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $outer = json_encode([
        'Type' => 'Notification',
        'Message' => $inner,
    ], JSON_THROW_ON_ERROR);

    $secrets = AmazonRotationMessageParser::extractClientSecretsFromSqsBody($outer);

    expect($secrets)->toBe(['amzn1.oa2-cs.secretvalue67890']);
});
