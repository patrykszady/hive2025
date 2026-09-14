<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A person's name from the display name on an email's From header.
 *
 * Enquirers sign off with a first name ("Thanks, Will") while their mail
 * client sends "William Johnson89 wa" — the account's display name, plus
 * whatever the provider or the person tacked on. The header is where the
 * surname lives; the sign-off is what they like to be called. Pairing the two
 * is what gives a lead both names — and the contact provisioner creates no
 * contact at all for a lead with only one.
 */
final class SenderName
{
    /** Words that turn up in display names and are never part of a name. */
    private const NOISE = [
        'via', 'iphone', 'ipad', 'android', 'mail', 'email', 'gmail', 'outlook', 'yahoo', 'icloud',
        'phone', 'mobile', 'cell', 'home', 'work', 'office', 'team', 'support', 'info', 'admin',
        'sales', 'noreply', 'no-reply', 'contact', 'hello', 'me',
        'mr', 'mrs', 'ms', 'miss', 'dr', 'jr', 'sr', 'ii', 'iii', 'iv',
    ];

    /**
     * Formal first name => short forms people sign with, for the pairs a
     * prefix test can't see. "Will" → "William" needs no entry; "Bill" does.
     * Keys and values are ASCII lowercase (see key()).
     *
     * @var array<string, array<int, string>>
     */
    private const NICKNAMES = [
        'william' => ['bill', 'billy', 'liam'],
        'robert' => ['bob', 'bobby', 'bert'],
        'richard' => ['dick', 'rick', 'ricky', 'rich'],
        'james' => ['jim', 'jimmy', 'jamie'],
        'john' => ['jack', 'johnny', 'jon'],
        'jonathan' => ['jon', 'jonny'],
        'michael' => ['mike', 'mikey', 'mick'],
        'edward' => ['ed', 'eddie', 'ted', 'teddy', 'ned'],
        'theodore' => ['ted', 'teddy', 'theo'],
        'charles' => ['chuck', 'charlie', 'chaz'],
        'henry' => ['hank', 'harry'],
        'harold' => ['harry', 'hal'],
        'anthony' => ['tony'],
        'thomas' => ['tom', 'tommy'],
        'david' => ['dave', 'davey'],
        'stephen' => ['steve', 'stevie'],
        'steven' => ['steve', 'stevie'],
        'lawrence' => ['larry'],
        'gerald' => ['jerry', 'gerry'],
        'jerome' => ['jerry'],
        'joseph' => ['joe', 'joey'],
        'jacob' => ['jake'],
        'andrew' => ['andy', 'drew'],
        'nicholas' => ['nick'],
        'francis' => ['frank'],
        'frederick' => ['fred', 'freddie'],
        'alexander' => ['alex', 'al', 'sasha'],
        'albert' => ['al', 'bert'],
        'alfred' => ['al', 'fred'],
        'eugene' => ['gene'],
        'vincent' => ['vince', 'vinny'],
        'peter' => ['pete'],
        'philip' => ['phil'],
        'phillip' => ['phil'],
        'douglas' => ['doug'],
        'russell' => ['russ'],
        'jeffrey' => ['jeff'],
        'nathaniel' => ['nate', 'nat'],
        'nathan' => ['nate'],
        'kenneth' => ['ken', 'kenny'],
        'donald' => ['don'],
        'ronald' => ['ron', 'ronnie'],
        'raymond' => ['ray'],
        'leonard' => ['leo', 'lenny'],
        'walter' => ['walt'],
        'zachary' => ['zach', 'zack'],
        'timothy' => ['tim'],
        'daniel' => ['dan', 'danny'],
        'matthew' => ['matt'],
        'benjamin' => ['ben', 'benny'],
        'samuel' => ['sam', 'sammy'],
        'gregory' => ['greg'],
        'christopher' => ['chris', 'kit'],
        'margaret' => ['peggy', 'meg', 'maggie', 'marge'],
        'elizabeth' => ['liz', 'beth', 'betty', 'betsy', 'eliza', 'lisa'],
        'katherine' => ['kate', 'katie', 'kathy', 'kat', 'kitty'],
        'catherine' => ['cathy', 'cat', 'kate'],
        'rebecca' => ['becky', 'becca'],
        'amanda' => ['mandy'],
        'patricia' => ['pat', 'patty', 'trish', 'tricia'],
        'virginia' => ['ginny'],
        'deborah' => ['deb', 'debbie'],
        'jennifer' => ['jen', 'jenny'],
        'susan' => ['sue', 'susie'],
        'barbara' => ['barb', 'babs', 'basia'],
        'dorothy' => ['dot', 'dottie'],
        'christine' => ['chris', 'chrissy'],
        'samantha' => ['sam'],
        'alexandra' => ['alex', 'sandy', 'sasha'],
        'abigail' => ['abby'],
        'cynthia' => ['cindy'],
        'jessica' => ['jess', 'jessie'],
        'kimberly' => ['kim'],
        'pamela' => ['pam'],
        'sandra' => ['sandy'],
        'victoria' => ['vicky', 'tori'],
        'josephine' => ['jo', 'josie'],
        'joanna' => ['jo', 'asia'],
        'eleanor' => ['ellie', 'nell'],
        'gabriel' => ['gabe'],
        // Spanish
        'guillermo' => ['memo'],
        'francisco' => ['paco', 'pancho', 'frank'],
        'ignacio' => ['nacho'],
        'jesus' => ['chuy'],
        'eduardo' => ['lalo'],
        'alberto' => ['beto'],
        'roberto' => ['beto'],
        'jose' => ['pepe'],
        'enrique' => ['quique'],
        // Polish
        'stanislaw' => ['stas', 'stasiu', 'stanley'],
        'katarzyna' => ['kasia', 'kate'],
        'malgorzata' => ['gosia', 'margaret'],
        'aleksandra' => ['ola', 'alex'],
        'jakub' => ['kuba'],
        'bartlomiej' => ['bartek'],
        'tomasz' => ['tomek', 'tom'],
        'piotr' => ['piotrek', 'peter'],
        'krzysztof' => ['krzysiek', 'chris'],
        'wojciech' => ['wojtek'],
        'grzegorz' => ['greg'],
        'andrzej' => ['andrew', 'andy'],
        'agnieszka' => ['aga'],
        'magdalena' => ['magda'],
        'zofia' => ['zosia'],
        'antoni' => ['antek'],
        'michal' => ['michael', 'mike'],
        'pawel' => ['paul'],
        'marcin' => ['martin'],
        'lukasz' => ['luke'],
        'jan' => ['john', 'janek'],
    ];

    /**
     * The person's name a From display name carries, or null when it carries
     * none. The address's own local part, a device, an address, a couple —
     * none of those is a name.
     *
     *   "William Johnson89 wa"  → "William Johnson"
     *   "JOHNSON, WILLIAM"      → "William Johnson"
     *   "Mr. William Johnson Jr" → "William Johnson"
     *   "willjohn1089"          → null
     *   "Bill's iPhone"         → null
     */
    public static function fromHeader(?string $display, ?string $email = null): ?string
    {
        $display = trim((string) $display);
        // Quotes and anything bracketed: "Will" <x@y>, Will (Home).
        $display = trim((string) preg_replace('/<[^>]*>|\([^)]*\)|["“”]/u', ' ', $display));

        if ($display === '' || str_contains($display, '@')) {
            return null;
        }

        // Two people share a header only by accident of a joint account —
        // whose surname is whose is not ours to guess.
        if (preg_match('/\s(?:and|&|\+)\s/iu', $display)) {
            return null;
        }

        // "JOHNSON, WILLIAM" → "WILLIAM JOHNSON".
        if (substr_count($display, ',') === 1) {
            [$last, $first] = array_map('trim', explode(',', $display, 2));
            $display = trim($first.' '.$last);
        }

        $kept = [];

        foreach (preg_split('/[\s,;|]+/u', $display) ?: [] as $token) {
            // A possessive is a device or a label ("Bill's iPhone"), not a name.
            if (preg_match("/['’]s$/iu", $token)) {
                return null;
            }

            $letters = trim((string) preg_replace("/[^\p{L}'’\-]/u", '', $token), "'’-");

            if ($letters === '' || in_array(mb_strtolower($letters), self::NOISE, true)) {
                continue;
            }

            $kept[] = ['text' => $letters, 'capitalised' => (bool) preg_match('/^\p{Lu}/u', $letters)];
        }

        // Where the sender capitalised their name, lowercase leftovers ("wa")
        // are what the provider appended, not a third name.
        if (array_filter($kept, fn (array $t) => $t['capitalised']) !== []) {
            $kept = array_values(array_filter($kept, fn (array $t) => $t['capitalised']));
        } elseif ($email !== null && $kept !== []) {
            // An all-lowercase name that is just the address's local part
            // ("willjohn1089", "will.john") is the account, not a person.
            $letters = mb_strtolower(implode('', array_column($kept, 'text')));
            $local = mb_strtolower((string) preg_replace('/[^\p{L}]/u', '', Str::before($email, '@')));

            if ($local !== '' && $letters === $local) {
                return null;
            }
        }

        $names = array_map(fn (array $t) => self::caseName($t['text']), array_slice($kept, 0, 4));

        return $names === [] ? null : implode(' ', $names);
    }

    /**
     * The name a lead should carry, given what the message itself said and
     * what the From header and address say.
     *
     * A name the message wrote out in full is theirs — including a couple's
     * ("Amy Dusto and Chris Ecker") — though the header decides its spelling
     * ("Michael Dimarco" → "Michael DiMarco"). A lone first name takes the
     * header's full name when the two are the same person: "Will" and
     * "William Johnson89 wa" → "William Johnson". When the header is no help
     * ("MiMi DiDi"), the address may be: "Michael" from michael_dimarco@ →
     * "Michael Dimarco". When nothing fits ("Mark" writing from "Mary
     * Johnson"'s account), the sign-off stands alone: the surname may well be
     * theirs, but "may well" is not how a customer record gets a name.
     */
    public static function complete(?string $extracted, ?string $display, ?string $email = null): ?string
    {
        // A stray number in the name slot ("Toby 312") is not a second name.
        $extracted = implode(' ', self::nameWords((string) $extracted));
        $header = self::fromHeader($display, $email);

        if ($extracted === '') {
            if ($header === null) {
                return null;
            }

            // Nothing signed: the header stands in, and a lone first name
            // there can still be completed from the address below.
            $extracted = $header;
        }

        // Same name both places, cased differently: the header is how they
        // spell it — when they cased it at all. A shouted or lowercase
        // header ("MICHAEL DIMARCO") knows nothing about "DiMarco".
        if ($header !== null && $header !== $extracted && self::sameName($header, $extracted) && self::isCased($display)) {
            return $header;
        }

        $extractedWords = explode(' ', $extracted);

        if (count($extractedWords) > 1) {
            return $extracted;
        }

        $signOff = $extractedWords[0];
        $headerWords = $header !== null ? explode(' ', $header) : [];

        if (count($headerWords) >= 2) {
            $first = $headerWords[0];
            $last = $headerWords[count($headerWords) - 1];

            // Signed with the surname alone ("— Johnson").
            if (self::sameName($signOff, $last)) {
                return $header;
            }

            if (self::sameFirstName($signOff, $first)) {
                // A bare initial in the header ("W Johnson"): keep the name
                // they signed with, take the surname.
                return mb_strlen($first) === 1
                    ? $signOff.' '.implode(' ', array_slice($headerWords, 1))
                    : $header;
            }
        }

        // The address spells the name out ("michael_dimarco"): the part that
        // is their first name says which part is the surname.
        $parts = self::addressParts($email);

        if ($parts !== null) {
            if (self::sameFirstName($signOff, $parts[0])) {
                return $signOff.' '.self::caseName($parts[1]);
            }

            if (self::sameFirstName($signOff, $parts[1])) {
                return $signOff.' '.self::caseName($parts[0]);
            }
        }

        return $extracted;
    }

    /**
     * The two word parts of an address's local part — "michael_dimarco" →
     * ["michael", "dimarco"] — or null: one part ("willjohn1089") has no
     * split, three ("ecker.chris.r") no clear order.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function addressParts(?string $email): ?array
    {
        $local = Str::before(mb_strtolower(trim((string) $email)), '@');
        $parts = array_values(array_filter(preg_split('/[^\p{L}]+/u', $local) ?: [], fn (string $p) => $p !== ''));

        // Exactly two word parts, neither an initial ("j.smith" names nobody
        // we could greet) nor a label.
        if (count($parts) !== 2
            || mb_strlen($parts[0]) < 2 || mb_strlen($parts[1]) < 2
            || in_array($parts[0], self::NOISE, true) || in_array($parts[1], self::NOISE, true)) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /** Mixed case, as a person types their own name — not shouted, not lowercase. */
    private static function isCased(?string $display): bool
    {
        $display = trim((string) $display);

        return $display !== '' && $display !== mb_strtoupper($display) && $display !== mb_strtolower($display);
    }

    /**
     * The words of a name that are actually words — "Toby 312" has one.
     *
     * @return array<int, string>
     */
    public static function nameWords(string $name): array
    {
        return array_values(array_filter(
            explode(' ', Str::squish($name)),
            fn (string $word) => preg_match('/\p{L}/u', $word) === 1,
        ));
    }

    /** The same letters, whatever the case, accents or punctuation. */
    public static function sameName(string $a, string $b): bool
    {
        return self::key($a) !== '' && self::key($a) === self::key($b);
    }

    /**
     * "Will" and "William", "Bill" and "William", "W" and "William" — the
     * same person. "Dan" and "Dana" are not: a prefix has to be a real
     * shortening, three letters or more with at least two dropped.
     */
    private static function sameFirstName(string $a, string $b): bool
    {
        $a = self::key($a);
        $b = self::key($b);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (strlen($a) === 1 || strlen($b) === 1) {
            return $a[0] === $b[0];
        }

        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        if (strlen($short) >= 3 && strlen($long) >= strlen($short) + 2 && str_starts_with($long, $short)) {
            return true;
        }

        return in_array($short, self::NICKNAMES[$long] ?? [], true)
            || in_array($long, self::NICKNAMES[$short] ?? [], true);
    }

    /** ASCII lowercase letters only, so "Michał" and "Michal" compare. */
    private static function key(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', Str::ascii($name)));
    }

    /** "WILLIAM" and "william" → "William"; "McDonald" stays as typed. */
    private static function caseName(string $word): string
    {
        if ($word !== mb_strtolower($word) && $word !== mb_strtoupper($word)) {
            return $word;
        }

        // Capitalise after apostrophes and hyphens too: O'Brien, Smith-Jones.
        return (string) preg_replace_callback(
            "/(^|['’\\-])(\\p{Ll})/u",
            fn (array $m) => $m[1].mb_strtoupper($m[2]),
            mb_strtolower($word),
        );
    }
}
