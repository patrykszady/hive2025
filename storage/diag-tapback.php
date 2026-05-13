<?php

$text = 'Dodano „kciuk w górę” do „Siema Grzesiek, będziemy używać ten numer zęby Grzesiek i ja mieliśmy te same informacje cały czas. - Patryk
-PS”';

echo 'HEX of opening quote bytes around „kciuk: '.bin2hex(mb_substr($text, 6, 3)).PHP_EOL;
echo 'HEX of closing quote after górę: '.bin2hex(mb_substr($text, 19, 3)).PHP_EOL;

$msg = new App\Models\SmsMessage(['text' => $text]);
var_export($msg->parseTapback());
echo PHP_EOL;
